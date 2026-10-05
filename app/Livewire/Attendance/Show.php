<?php

namespace App\Livewire\Attendance;

use App\Enums\PunchSource;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Services\Attendance\DailySummaryBuilder;
use App\Support\AttendanceSummary;
use App\Support\AttendanceTime;
use App\Support\DisplayDate;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    public ?Employee $employee = null;

    public bool $noEmployeeRecord = false;

    #[Url(as: 'month', history: true)]
    public string $month = '';

    /**
     * Calendar is the default (Phase 2.4e) — the table stays available
     * (it shows worked/late/early minutes per row, which the calendar
     * can't fit) but is now the secondary view.
     */
    #[Url(as: 'view', history: true)]
    public string $view = 'calendar';

    /**
     * 'Y-m-d' of the day whose add-punch mini-form is open, or null when
     * none is. Only one at a time — simpler than tracking per-day state,
     * and there's no case where an admin needs two open together.
     */
    public ?string $addingPunchFor = null;

    public string $newPunchDate = '';

    public string $newPunchTime = '';

    public string $newPunchType = 'in';

    /**
     * Decoupled from $viewingDay on purpose (matching FormModal's
     * $showModal/$editing split): entangling the modal directly on a
     * nullable string works in principle (Alpine treats any non-empty
     * string as truthy), but the modal's own close paths (backdrop click,
     * Escape) assign JS `false` back through the two-way binding, which a
     * ?string-typed property has no clean way to receive. A dedicated
     * boolean sidesteps that entirely.
     */
    public bool $dayModalOpen = false;

    /**
     * 'Y-m-d' of the day the modal is currently showing — set once on open,
     * left alone on close so the closing transition doesn't blank first.
     */
    public ?string $viewingDay = null;

    /**
     * Route-model-bound when reached via /attendance/{employee} (admin or a
     * manager viewing someone else); left null when reached via
     * /my-attendance, which has no {employee} segment at all — that case
     * resolves the viewer's own linked employee record instead.
     */
    public function mount(?Employee $employee = null): void
    {
        if ($employee === null) {
            $employee = auth()->user()->employee;

            if ($employee === null) {
                $this->noEmployeeRecord = true;

                return;
            }
        }

        $this->authorize('view', $employee);

        $this->employee = $employee->load(['department', 'position']);

        if ($this->month === '') {
            $this->month = today()->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonthNoOverflow()->format('Y-m');
    }

    private function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
    }

    public function startAddingPunch(string $date): void
    {
        $this->authorize('update', $this->employee);

        $this->addingPunchFor = $date;
        $this->newPunchDate = $date;
        $this->newPunchTime = '';
        $this->newPunchType = 'in';
        $this->resetErrorBag();
    }

    public function cancelAddingPunch(): void
    {
        $this->addingPunchFor = null;
        $this->resetErrorBag();
    }

    /**
     * Only days within the currently-viewed month are meaningful — grid
     * cells for adjacent-month padding days aren't rendered with a click
     * handler at all (see gridDays()), but this guards the same rule
     * server-side rather than trusting that alone.
     */
    public function openDay(string $date): void
    {
        if (! Carbon::createFromFormat('Y-m-d', $date)->isSameMonth($this->monthStart())) {
            return;
        }

        $this->viewingDay = $date;
        $this->dayModalOpen = true;
    }

    public function closeDayModal(): void
    {
        $this->dayModalOpen = false;
        $this->addingPunchFor = null;
        $this->resetErrorBag();
    }

    public function addPunch(): void
    {
        $this->authorize('update', $this->employee);

        // A manual punch must fall inside the employment period (join_date to
        // left_on, Employee::scopeActiveOn()) and not in the future. A device or
        // CSV punch outside it is kept — raw hardware facts are never dropped —
        // but an admin entering one by hand is a mistake to catch here.
        $joinDate = $this->employee->join_date;
        $leftOn = $this->employee->left_on;
        $lastAllowed = $leftOn !== null && $leftOn->lt(today()) ? $leftOn : today();
        $outsideEmployment = "A punch must fall within this employee's employment (".$this->employmentPeriod().').';

        $this->validate([
            'newPunchDate' => ['required', 'date', 'after_or_equal:'.$joinDate->format('Y-m-d'), 'before_or_equal:'.$lastAllowed->format('Y-m-d')],
            'newPunchTime' => ['required', 'date_format:H:i'],
            'newPunchType' => ['required', 'in:in,out'],
        ], [
            'newPunchDate.after_or_equal' => $outsideEmployment,
            'newPunchDate.before_or_equal' => $leftOn !== null ? $outsideEmployment : "A punch can't be dated in the future.",
        ], [
            'newPunchDate' => 'punch date',
            'newPunchTime' => 'punch time',
            'newPunchType' => 'punch type',
        ]);

        $punchedAt = Carbon::parse($this->newPunchDate.' '.$this->newPunchTime);

        if ($punchedAt->gt(now())) {
            $this->addError('newPunchTime', "A punch can't be later than the current time (".AttendanceTime::format(now()).').');

            return;
        }

        // The unique index is (employee_id, punched_at, source) — no
        // voided_at involved (adding it would let MySQL treat every voided
        // row's NULL as distinct from every other NULL and stop enforcing
        // uniqueness among LIVE rows at all, breaking Phase 2.2's
        // idempotency guarantee — confirmed empirically, not assumed). So a
        // voided row permanently occupies its exact key, and a plain
        // create() would collide with it forever, not just once. Checking
        // for it first and reviving it — rather than inserting — is
        // correct here anyway: an admin re-adding a punch at the exact time
        // they just voided is correcting their own correction, not
        // creating new data.
        $existing = AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->where('punched_at', $punchedAt)
            ->where('source', PunchSource::Manual->value)
            ->first();

        if ($existing !== null) {
            if ($existing->voided_at === null) {
                $this->addError('newPunchTime', 'A punch already exists at this exact date and time.');

                return;
            }

            $existing->update([
                'punch_type' => $this->newPunchType,
                'voided_at' => null,
                'voided_by' => null,
                'created_by' => auth()->id(),
            ]);
        } else {
            try {
                AttendanceLog::create([
                    'employee_id' => $this->employee->id,
                    'punched_at' => $punchedAt,
                    'punch_type' => $this->newPunchType,
                    'source' => PunchSource::Manual,
                    'created_by' => auth()->id(),
                ]);
            } catch (QueryException $e) {
                // Belt-and-suspenders for a race between the check above and
                // this insert (e.g. two admins correcting the same day at
                // once) — the pre-check makes this the rare path, not the
                // normal one.
                if (str_contains($e->getMessage(), 'attendance_logs_employee_id_punched_at_source_unique')) {
                    $this->addError('newPunchTime', 'A punch already exists at this exact date and time.');

                    return;
                }

                throw $e;
            }
        }

        $this->rebuildAround($punchedAt);
        $this->addingPunchFor = null;
    }

    /**
     * "from Mon 2 Feb 2026", or "2 Feb – 10 Apr" once they've left — the
     * period a manual punch must fall in.
     */
    private function employmentPeriod(): string
    {
        $leftOn = $this->employee->left_on;

        return $leftOn === null
            ? 'from '.DisplayDate::compact($this->employee->join_date)
            : DisplayDate::range($this->employee->join_date, $leftOn);
    }

    public function voidPunch(int $punchId): void
    {
        $this->authorize('update', $this->employee);

        $log = AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->notVoided()
            ->findOrFail($punchId);

        $log->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
        ]);

        $this->rebuildAround($log->punched_at);
    }

    /**
     * The punch's own date plus the day before and after — the shared rule
     * lives in DailySummaryBuilder::rebuildAround(), with the reasoning.
     */
    private function rebuildAround(Carbon $punchedAt): void
    {
        app(DailySummaryBuilder::class)->rebuildAround($this->employee, $punchedAt);
    }

    /**
     * The month's daily_attendances rows, keyed by 'Y-m-d' — fetched once
     * per render() and shared by days(), gridDays(), and the day modal's
     * summary lookup, so the table view and the calendar view never issue
     * separate queries for the same month.
     *
     * @return Collection<string, DailyAttendance>
     */
    private function existingRecords(): Collection
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();

        return DailyAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('work_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'));
    }

    /**
     * Every calendar day of the month paired with its daily_attendances row
     * (or null). A missing row means the builder hasn't run for that date
     * yet — it must never be conflated with "absent", which is why this
     * pads the whole month rather than only returning rows that exist.
     * Powers the table view only — exactly the days in the month, no
     * leading/trailing padding (see gridDays() for that).
     *
     * @param  Collection<string, DailyAttendance>  $existing
     * @return Collection<int, array{date: Carbon, record: ?DailyAttendance}>
     */
    private function days(Collection $existing): Collection
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();

        $days = collect();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $days->push([
                'date' => $cursor->copy(),
                'record' => $existing->get($cursor->format('Y-m-d')),
            ]);

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * The full Sunday-first calendar grid for the month, including leading/
     * trailing days from adjacent months so every row has 7 cells (35 or 42
     * total). Explicit Carbon::SUNDAY/SATURDAY rather than the app-locale
     * default — startOfWeek()/endOfWeek() only fall back to locale when no
     * day is given, so this is first-column-Sunday regardless of
     * config('app.locale') or Carbon's runtime locale (confirmed against
     * both, not assumed).
     *
     * Padding cells carry inMonth=false and no record — they're rendered
     * muted, non-clickable, and excluded from the month summary purely to
     * keep the grid rectangular, so there's no reason to look up real
     * attendance data for a date outside the month being viewed.
     *
     * @param  Collection<string, DailyAttendance>  $existing
     * @return Collection<int, array{date: Carbon, inMonth: bool, record: ?DailyAttendance}>
     */
    private function gridDays(Collection $existing): Collection
    {
        $monthStart = $this->monthStart();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SATURDAY);

        $cells = collect();
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $inMonth = $cursor->month === $monthStart->month && $cursor->year === $monthStart->year;

            $cells->push([
                'date' => $cursor->copy(),
                'inMonth' => $inMonth,
                'record' => $inMonth ? $existing->get($cursor->format('Y-m-d')) : null,
            ]);

            $cursor->addDay();
        }

        return $cells;
    }

    /**
     * Raw punches for the month, including voided ones, grouped by calendar
     * date of punched_at — fetched once for the whole month (not per day
     * expanded) so opening every day's panel costs nothing extra. This is
     * deliberately the admin-troubleshooting view of "what punches exist on
     * this date", not DailySummaryBuilder's paired interpretation of them —
     * an overnight out-punch shows under the date it was actually punched,
     * even though the builder pairs it into the previous day's row.
     *
     * @return Collection<string, Collection<int, AttendanceLog>>
     */
    /**
     * Overnight shifts, so a day's punch list can say which shift a punch
     * belongs to. A (+1) out-punch is grouped under the date it was punched,
     * not the shift it closed: without this, the next day's list opens with
     * an unexplained 12:42 AM Out and the shift's own day never shows it.
     *
     * - 'outs': shift work_date ('Y-m-d') => the AttendanceLog that closed it
     *   the next day, listed on the shift's day with "(+1)".
     * - 'shifts': AttendanceLog id => the shift's work_date (Carbon), noted
     *   on the day the punch was recorded.
     *
     * Covers the day before the month too: the 1st's list can hold the
     * previous month's last overnight out. Two queries at most, the same in
     * both views (their query counts stay equal).
     *
     * @param  Collection<string, DailyAttendance>  $existing
     * @return array{outs: Collection<string, AttendanceLog>, shifts: Collection<int, Carbon>}
     */
    private function overnightPunches(Collection $existing): array
    {
        $dayBefore = $this->monthStart()->subDay();
        $rows = $existing->values()->push(
            DailyAttendance::query()
                ->where('employee_id', $this->employee->id)
                ->whereDate('work_date', $dayBefore)
                ->first(),
        )->filter(fn (?DailyAttendance $row) => $row?->isOvernightOut());

        if ($rows->isEmpty()) {
            return ['outs' => collect(), 'shifts' => collect()];
        }

        $logs = AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->where('punch_type', 'out')
            ->whereNull('voided_at')
            ->whereIn('punched_at', $rows->map(fn (DailyAttendance $row) => $row->last_out->format('Y-m-d H:i:s'))->all())
            ->with('createdBy', 'voidedBy')
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->punched_at->format('Y-m-d H:i:s'));

        $outs = collect();
        $shifts = collect();
        foreach ($rows as $row) {
            if ($log = $logs->get($row->last_out->format('Y-m-d H:i:s'))) {
                $outs->put($row->work_date->format('Y-m-d'), $log);
                $shifts->put($log->id, $row->work_date);
            }
        }

        return ['outs' => $outs, 'shifts' => $shifts];
    }

    private function punchesByDate(): Collection
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();

        return AttendanceLog::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('punched_at', [$start, $end->copy()->endOfDay()])
            ->with('createdBy', 'voidedBy')
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (AttendanceLog $log) => $log->punched_at->format('Y-m-d'));
    }

    /**
     * Company-wide holidays for the month, keyed by 'Y-m-d' — read straight
     * from `holidays`, not derived from `daily_attendances`. That's
     * deliberate: daily_attendances only has rows for dates the builder has
     * already reached, so a holiday three weeks out would have no row at
     * all if this were sourced from there — employees need to see upcoming
     * holidays, which is most of the point of showing them. Powers the
     * calendar grid only; not fetched or shown in the table view.
     *
     * @return Collection<string, Holiday>
     */
    private function holidaysByDate(): Collection
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();

        return Holiday::query()
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->format('Y-m-d'));
    }

    /**
     * The month's counts — the shared definition (AttendanceSummary), which
     * the employee dashboard uses too.
     *
     * @param  Collection<int, array{date: Carbon, record: ?DailyAttendance}>  $days
     * @return array<string, int>
     */
    private function summary(Collection $days): array
    {
        return AttendanceSummary::fromRecords($days->pluck('record')->filter()->values());
    }

    public function render()
    {
        // Every request ends here — re-assert access rather than trusting Livewire to keep $employee pinned.
        if ($this->employee !== null) {
            $this->authorize('view', $this->employee);
        }

        // request()->routeIs(), not stored mount()-time state: mount() only
        // runs on the initial load, but render() runs on every subsequent
        // request too (e.g. clicking prev/next month), and the route name
        // is stable and available either way.
        $viaSelfView = request()->routeIs('attendance.mine');

        if ($this->employee === null) {
            return view('livewire.attendance.show')
                ->layout('layouts.app', ['header' => 'My attendance']);
        }

        // Fetched once and shared by both views (and the day modal's summary
        // lookup) — the calendar's extra padding cells never need their own
        // query since they carry no real data (see gridDays()).
        $existing = $this->existingRecords();
        $days = $this->days($existing);

        // A plain employee reaching this page via /my-attendance can't
        // reach /attendance (role:admin|manager) at all, so a breadcrumb
        // linking back there would just be a dead end for them — use a
        // plain header instead, same as the admin/manager path uses
        // breadcrumbs (matching Employees\Show's convention).
        $layoutData = $viaSelfView
            ? ['header' => 'My attendance']
            : ['breadcrumbs' => [
                ['label' => 'Attendance', 'route' => route('attendance.index')],
                ['label' => $this->employee->full_name],
            ]];

        // Derived from $existing (already fetched above) rather than a
        // fresh MAX(work_date) query — Attendance\Index's list page can
        // afford a real global query since it's the only date-range fact
        // on that page, but here the query-count budget is already spent
        // on the two queries every render needs, and this page only ever
        // needs to know how far the CURRENT month got, which $existing
        // already answers for free.
        $lastBuiltInMonth = $existing->keys()->sort()->last();

        return view('livewire.attendance.show', [
            'days' => $days,
            'gridDays' => $this->gridDays($existing),
            'recordsByDate' => $existing,
            'summary' => $this->summary($days),
            'monthLabel' => DisplayDate::month($this->monthStart()),
            'isCurrentMonth' => $this->month === today()->format('Y-m'),
            'punchesByDate' => $this->punchesByDate(),
            'overnightPunches' => $this->overnightPunches($existing),
            'holidaysByDate' => $this->holidaysByDate(),
            'lastBuiltInMonth' => $lastBuiltInMonth,
            'monthFullyBuilt' => $lastBuiltInMonth === $days->last()['date']->format('Y-m-d'),
            // Same reasoning as $layoutData just above: nobody reaches
            // /my-attendance via the list (it's a sidebar destination, and a
            // plain employee can't open /attendance at all), so a "Back to
            // attendance" link there is a dead end for everyone, not just
            // employees — it used to render unconditionally regardless of
            // route.
            'viaSelfView' => $viaSelfView,
        ])->layout('layouts.app', $layoutData);
    }
}
