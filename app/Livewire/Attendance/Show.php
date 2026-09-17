<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\PunchSource;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Services\Attendance\DailySummaryBuilder;
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
     * 'Y-m-d' of the day whose add-punch mini-form is open, or null when
     * none is. Only one at a time — simpler than tracking per-day state,
     * and there's no case where an admin needs two open together.
     */
    public ?string $addingPunchFor = null;

    public string $newPunchDate = '';

    public string $newPunchTime = '';

    public string $newPunchType = 'in';

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

    public function addPunch(): void
    {
        $this->authorize('update', $this->employee);

        $this->validate([
            'newPunchDate' => ['required', 'date'],
            'newPunchTime' => ['required', 'date_format:H:i'],
            'newPunchType' => ['required', 'in:in,out'],
        ]);

        $punchedAt = Carbon::parse($this->newPunchDate.' '.$this->newPunchTime);

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
     * Rebuilds the punch's own date PLUS the day before and the day after.
     *
     * The brief that prompted this only asked for "the affected date and
     * the day before it" — the day-before case is the obvious one (an
     * overnight out-punch added/voided near midnight is the previous day's
     * last_out, via DailySummaryBuilder's forward 18h lookahead from that
     * day's first_in). But the day-after case is real too: when a day has
     * no first_in of its own, DailySummaryBuilder walks that day's out-punch
     * candidates and excludes any one already "claimed" by an in-punch up to
     * 18h *before* it — including an in-punch on the PREVIOUS calendar day.
     * Adding or voiding a late in-punch today can flip tomorrow's candidate
     * from claimed to unclaimed (or vice versa) without tomorrow's own data
     * changing at all. Rebuilding only today and yesterday would leave that
     * stale. Two days forward is never needed: 18h from even a midnight
     * in-punch can't reach the day after next.
     */
    private function rebuildAround(Carbon $punchedAt): void
    {
        $builder = app(DailySummaryBuilder::class);
        $day = $punchedAt->copy()->startOfDay();

        $builder->build($this->employee, $day->copy()->subDay());
        $builder->build($this->employee, $day);
        $builder->build($this->employee, $day->copy()->addDay());
    }

    /**
     * Every calendar day of the month paired with its daily_attendances row
     * (or null). A missing row means the builder hasn't run for that date
     * yet — it must never be conflated with "absent", which is why this
     * pads the whole month rather than only returning rows that exist.
     *
     * @return Collection<int, array{date: Carbon, record: ?DailyAttendance}>
     */
    private function calendarDays(): Collection
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();

        $existing = DailyAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('work_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'));

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
     * Counts only — no derived or payroll-adjacent figures. "Workdays" is
     * simply present+late+absent+incomplete (i.e. every calculated day that
     * isn't off/holiday/leave); "Early leave" and "Total worked" are plain
     * counts/sums over the same calculated rows, not anything interpreted.
     *
     * @param  Collection<int, array{date: Carbon, record: ?DailyAttendance}>  $days
     * @return array<string, int>
     */
    private function summary(Collection $days): array
    {
        $records = $days->pluck('record')->filter();

        $counts = $records->countBy(fn (DailyAttendance $row) => $row->status->value);

        $workdayStatuses = [
            AttendanceStatus::Present->value,
            AttendanceStatus::Late->value,
            AttendanceStatus::Absent->value,
            AttendanceStatus::Incomplete->value,
        ];

        return [
            'workdays' => $records->whereIn('status', array_map(
                fn (string $value) => AttendanceStatus::from($value),
                $workdayStatuses
            ))->count(),
            'present' => $counts->get(AttendanceStatus::Present->value, 0),
            'late' => $counts->get(AttendanceStatus::Late->value, 0),
            'absent' => $counts->get(AttendanceStatus::Absent->value, 0),
            'incomplete' => $counts->get(AttendanceStatus::Incomplete->value, 0),
            'early_leave_days' => $records->filter(fn (DailyAttendance $row) => $row->early_leave_minutes > 0)->count(),
            'total_worked_minutes' => (int) $records->sum('worked_minutes'),
        ];
    }

    public function render()
    {
        // request()->routeIs(), not stored mount()-time state: mount() only
        // runs on the initial load, but render() runs on every subsequent
        // request too (e.g. clicking prev/next month), and the route name
        // is stable and available either way.
        $viaSelfView = request()->routeIs('attendance.mine');

        if ($this->employee === null) {
            return view('livewire.attendance.show')
                ->layout('layouts.app', ['header' => 'My attendance']);
        }

        $days = $this->calendarDays();

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

        return view('livewire.attendance.show', [
            'days' => $days,
            'summary' => $this->summary($days),
            'monthLabel' => $this->monthStart()->format('F Y'),
            'isCurrentMonth' => $this->month === today()->format('Y-m'),
            'punchesByDate' => $this->punchesByDate(),
        ])->layout('layouts.app', $layoutData);
    }
}
