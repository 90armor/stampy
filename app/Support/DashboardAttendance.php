<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Real attendance figures for the dashboard, replacing the placeholder
 * DemoAttendance class now that daily_attendances/attendance_logs actually
 * exist. Every method takes $employeeIds — null for no restriction (an
 * admin), or a list of ids (a manager's own scope: themself plus transitive
 * subordinates, mirroring Attendance\Index::scopedEmployeeIds()) — since
 * this is real data now, it must respect the same row-level access rule
 * every other Attendance view already does, which a fake-data dashboard
 * never needed to.
 *
 * Every count of employees here is "active on the day" — today for the
 * strip, breakdown, Needs attention and Department card, each day for the
 * trend (scopedEmployeesActiveOn(), Employee::scopeActiveOn()) — never
 * `status`. One definition, the one the builder selects by, so a future
 * joiner is never "not in yet" and a leaver counts up to their last day.
 */
class DashboardAttendance
{
    /**
     * "Checked in" on the dashboard: any punch today, in or out. An out-only
     * day (Incomplete) still means the person came in, and counting it keeps
     * the Department card's total equal to the strip's At work + Left.
     */
    private const CHECKED_IN = '(daily_attendances.first_in is not null or daily_attendances.last_out is not null)';

    /**
     * @param  int[]|null  $employeeIds
     * @return array{
     *     total: int,
     *     builtToday: bool,
     *     segments: list<array{key: string, label: string, count: int, percent: float, dot: string}>,
     *     present: array{count: int, percent: float},
     *     late: array{count: int, percent: float},
     *     earlyLeave: array{count: int, percent: float},
     * }
     */
    public static function todayBreakdown(?array $employeeIds): array
    {
        $total = self::scopedEmployeesActiveOn($employeeIds, today())->count();

        $counts = self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $built = (int) $counts->sum();

        // 'late'/'early leave' are a breakdown of Present, not a peer bucket
        // (see CLAUDE.md's "Status vs. timing" note) — a late day IS a
        // present day, so it must not also claim its own slice of the bar
        // below, which would double count it.
        $lateCount = self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->where('late_minutes', '>', 0)
            ->count();
        $earlyCount = self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->where('early_leave_minutes', '>', 0)
            ->count();

        $percent = fn (int $count): float => $total > 0 ? round($count / $total * 100, 1) : 0.0;

        // Every segment that can exist for "today", in the order they
        // stack — always sums to exactly 100% of $total by construction:
        // 'not_calculated' is the remainder for anyone with no row yet
        // (there's no scheduled job running attendance:build-daily — see
        // CLAUDE.md's Local environment note), so the bar never silently
        // falls short of full width. 'leave' is included for completeness
        // even though nothing assigns it yet (Phase 3) — it'll simply
        // render as a zero-width segment until it does.
        $definitions = [
            ['key' => 'present', 'label' => 'Present', 'dot' => 'bg-green-500'],
            ['key' => 'absent', 'label' => 'Absent', 'dot' => 'bg-red-400'],
            ['key' => 'incomplete', 'label' => 'Incomplete', 'dot' => 'bg-violet-400'],
            ['key' => 'in_progress', 'label' => 'In progress', 'dot' => 'bg-blue-400'],
            ['key' => 'holiday', 'label' => 'Holiday', 'dot' => 'bg-fuchsia-400'],
            ['key' => 'off', 'label' => 'Off', 'dot' => 'bg-slate-300 dark:bg-slate-600'],
            ['key' => 'leave', 'label' => 'Leave', 'dot' => 'bg-accent-400'],
        ];

        $segments = [];

        foreach ($definitions as $definition) {
            $count = (int) $counts->get($definition['key'], 0);

            if ($count === 0) {
                continue;
            }

            $segments[] = [...$definition, 'count' => $count, 'percent' => $percent($count)];
        }

        $notCalculated = $total - $built;

        if ($notCalculated > 0) {
            $segments[] = [
                'key' => 'not_calculated',
                'label' => 'Not calculated yet',
                'dot' => 'bg-slate-100 dark:bg-slate-800',
                'count' => $notCalculated,
                'percent' => $percent($notCalculated),
            ];
        }

        return [
            'total' => $total,
            'builtToday' => $built > 0,
            'segments' => $segments,
            'present' => ['count' => (int) $counts->get('present', 0), 'percent' => $percent((int) $counts->get('present', 0))],
            'late' => ['count' => $lateCount, 'percent' => $percent($lateCount)],
            'earlyLeave' => ['count' => $earlyCount, 'percent' => $percent($earlyCount)],
        ];
    }

    /**
     * Who needs a look today, worst-first: Absent, then Incomplete, then a
     * late arrival, then "Not in yet", then — least urgent — anyone who
     * punched during approved leave (DailyAttendance::workedOnLeave(), Phase
     * 3d: plain text, no badge; the admin decides whether to cancel the leave).
     * One entry per person, under their most urgent kind. It follows the builder's statuses
     * (Phase 2.7): an Absent row — a punchless day whose schedule end has
     * passed — shows as Absent, as in the attendance table. A late arrival is
     * included whatever its status, including a day still In progress,
     * because the point of this list is "who might need a nudge". "Not in
     * yet" is only a punchless In progress row past the expected start +
     * grace_minutes — start_time, or the PM start on an AM-leave day
     * (DailyAttendance::isNotInYet()), and its "due" time is that start. Neither late nor "not in yet" is a
     * status, so neither carries a badge. Capped at $limit, most urgent
     * first; needsAttentionTotal() is the uncapped count for the header.
     *
     * @param  int[]|null  $employeeIds
     * @return list<array{name: string, kind: string, label: string, badge: ?string, detail: ?string}>
     */
    public static function needsAttention(?array $employeeIds, int $limit = 8): array
    {
        return self::needsAttentionItems($employeeIds)->take($limit)->map(function (array $item) {
            $row = $item['row'];

            [$label, $badge, $detail] = match ($item['kind']) {
                'absent' => ['Absent', 'red', null],
                'incomplete' => ['Incomplete', 'violet', null],
                'late' => ['Late', null, Duration::format($row->late_minutes).' late'],
                'not_in_yet' => ['Not in yet', null, 'Not in yet · due '.AttendanceTime::format($row->expectedWindow()->start)],
                'worked_on_leave' => ['On approved leave', null, 'Punched on approved leave'],
            };

            return [
                'name' => $row->employee->full_name,
                'kind' => $item['kind'],
                'label' => $label,
                'badge' => $badge,
                'detail' => $detail,
            ];
        })->values()->all();
    }

    /**
     * How many people Needs attention covers today, uncapped — the header
     * says "35 today" even though the list shows the 8 most urgent.
     *
     * @param  int[]|null  $employeeIds
     */
    public static function needsAttentionTotal(?array $employeeIds): int
    {
        return self::needsAttentionItems($employeeIds)->count();
    }

    /**
     * @param  int[]|null  $employeeIds
     * @return Collection<int, array{row: DailyAttendance, kind: string}>
     */
    private static function needsAttentionItems(?array $employeeIds): Collection
    {
        // The OR must be nested inside its own where() — a top-level
        // orWhere() here would escape both the employee scope and the
        // whereDate above (SQL's OR binds looser than AND), which would
        // silently leak other employees'/other days' rows into a manager's
        // "needs attention" list.
        return self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->where(function ($query) {
                $query->whereIn('status', [AttendanceStatus::Absent->value, AttendanceStatus::Incomplete->value])
                    ->orWhere('late_minutes', '>', 0)
                    ->orWhere(fn ($q) => $q->where('status', AttendanceStatus::InProgress->value)
                        ->whereNull('first_in')
                        ->whereNull('last_out'))
                    ->orWhereNotNull('daily_attendances.leave_id');
            })
            ->with(['employee', 'workSchedule'])
            ->get()
            ->tap(fn (Collection $rows) => DailyAttendance::withLeaveDays($rows))
            ->map(fn (DailyAttendance $row) => [
                'row' => $row,
                'kind' => match (true) {
                    $row->status === AttendanceStatus::Absent => 'absent',
                    $row->status === AttendanceStatus::Incomplete => 'incomplete',
                    $row->isLate() => 'late',
                    $row->isNotInYet() => 'not_in_yet',
                    $row->workedOnLeave() => 'worked_on_leave',
                    default => null,
                },
            ])
            // A punchless In progress row before start + grace is simply in
            // progress, not "not in yet" — it drops out here.
            ->filter(fn (array $item) => $item['kind'] !== null)
            // Sorted in PHP rather than a SQL ORDER BY FIELD(): the set is
            // small (bounded by today's employee count).
            ->sortBy(fn (array $item) => [
                ['absent' => 0, 'incomplete' => 1, 'late' => 2, 'not_in_yet' => 3, 'worked_on_leave' => 4][$item['kind']],
                $item['kind'] === 'late' ? -$item['row']->late_minutes : 0,
                $item['row']->employee->full_name,
            ])
            ->values();
    }

    /**
     * The attendance rate for each of the last $days days — attended ÷ the
     * employees active on that day (Employee::scopeActiveOn()) less those on
     * full-day approved leave (a `leave` row, Phase 3d), where attended = present + incomplete (Phase 2.7: an
     * incomplete day was attended, a punch is just missing). Every day gets
     * a value, a marker, or both, so no bar is ever blank without saying why:
     *
     * - Off / Holiday: every scoped row is Off or Holiday — not a working day,
     *   no bar. Leave: everyone expected is on full-day leave — no bar.
     * - Pending: the day is still open — today while todayIsPending(), or an
     *   earlier day with an In progress row still inside its pairing window
     *   (an in-only row; a late in-punch can keep yesterday open past
     *   midnight). A lighter provisional bar of attended so far (anyone with
     *   a punch, CHECKED_IN), with a "Today" or "Pending" marker; no bar while nobody
     *   has checked in.
     * - Not calculated: a past day with no rows, or with In progress rows
     *   whose window has already closed (the builder didn't run). Never 0%,
     *   never absent.
     * - Closed: the rate as a bar; a closed workday with 0 attended gets a
     *   "0%" marker instead of an empty slot.
     *
     * @param  int[]|null  $employeeIds
     * @return list<array{label: string, date: string, value: ?float, marker: ?string, pending: bool}>
     */
    public static function weeklyTrend(?array $employeeIds, int $days = 7): array
    {
        $start = today()->copy()->subDays($days - 1);

        // Each day is measured against the employees active on that day
        // (Employee::scopeActiveOn()), in the denominator and the numerator
        // alike: someone who left on Wednesday counts Monday to Wednesday,
        // and not after.
        $totals = [];

        for ($cursor = $start->copy(); $cursor->lte(today()); $cursor->addDay()) {
            $totals[$cursor->format('Y-m-d')] = self::scopedEmployeesActiveOn($employeeIds, $cursor)->count();
        }

        if (array_sum($totals) === 0) {
            return [];
        }

        $nonWorking = [AttendanceStatus::Off->value, AttendanceStatus::Holiday->value];
        $attendedStatuses = [AttendanceStatus::Present->value, AttendanceStatus::Incomplete->value];
        $todayKey = today()->format('Y-m-d');
        $todayPending = self::todayIsPending($employeeIds);

        $trend = [];

        for ($cursor = $start->copy(); $cursor->lte(today()); $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $total = $totals[$key];
            $rows = self::rowsOfEmployeesActiveOn($employeeIds, $cursor)
                ->selectRaw('status, count(*) as total, sum('.self::CHECKED_IN.') as checked_in')
                ->groupBy('status')
                ->get();
            // Whoever is on full-day approved leave isn't expected that day
            // (Phase 3d): out of the denominator, so five people on leave
            // don't read as an attendance drop. Half-day leave stays in.
            $expected = $total - (int) $rows->firstWhere('status', AttendanceStatus::Leave)?->total;
            // An In progress row on an earlier day is either still open (an
            // in-only row inside its pairing window) or stale (the builder
            // hasn't run since it should have closed it).
            $isStale = $key !== $todayKey && self::rowsOfEmployeesActiveOn($employeeIds, $cursor)
                ->where('status', AttendanceStatus::InProgress->value)
                ->get(['work_date', 'first_in', 'last_out'])
                ->contains(fn (DailyAttendance $row) => ! self::isOpen($row));
            $byStatus = $rows->mapWithKeys(fn (DailyAttendance $row) => [$row->status->value => (int) $row->total]);
            $statuses = $byStatus->keys();
            $checkedIn = (int) $rows->sum('checked_in');
            $attended = (int) $byStatus->only($attendedStatuses)->sum();
            $isToday = $key === $todayKey;
            $isNonWorkingDay = $statuses->isNotEmpty() && $statuses->every(fn (string $status) => in_array($status, $nonWorking, true));
            $hasOpenRows = $statuses->contains(AttendanceStatus::InProgress->value);

            [$value, $marker, $pending] = match (true) {
                $isNonWorkingDay => [null, $statuses->contains(AttendanceStatus::Off->value) ? 'Off' : 'Holiday', false],
                // Everyone expected that day is on full-day leave.
                $expected <= 0 && $statuses->contains(AttendanceStatus::Leave->value) => [null, 'Leave', false],
                $isToday && $todayPending => [$checkedIn > 0 ? round($checkedIn / $expected * 100, 1) : null, 'Today', true],
                ! $isToday && ($rows->isEmpty() || $isStale) => [null, 'Not calculated', false],
                ! $isToday && $hasOpenRows => [$checkedIn > 0 ? round($checkedIn / $expected * 100, 1) : null, 'Pending', true],
                $attended === 0 => [null, '0%', false],
                default => [round($attended / $expected * 100, 1), null, false],
            };

            $trend[] = [
                'label' => $cursor->format('D'),
                'date' => $key,
                'value' => $value,
                'marker' => $marker,
                'pending' => $pending,
            ];
        }

        return $trend;
    }

    /**
     * Today's attendance per department, as counts, never a bare percentage:
     * "Checked in N / M" (anyone with a punch today, in or out — so the
     * departments' total is the strip's At work + Left) while today is pending
     * (todayIsPending()), "Attended N / M" once it is closed — attended =
     * present + incomplete, the same rate as the trend.
     *
     * @param  Collection<int, Department>  $departments  already scoped by
     *                                                    the caller (see routes/web.php)
     * @param  int[]|null  $employeeIds
     *                                   'expected' is the denominator: the department's headcount less anyone on
     *                                   full-day approved leave today (Phase 3d); the view shows "On leave"
     *                                   instead of 0 / 0 when that's everyone.
     * @return list<array{name: string, employees: int, expected: int, attended: int, checkedIn: int, pending: bool}>
     */
    public static function departmentAttendance(Collection $departments, ?array $employeeIds): array
    {
        $pending = self::todayIsPending($employeeIds);

        // Employees active today only, like the strip and each department's
        // employee count (routes/web.php).
        $byDepartment = self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->selectRaw('employees.department_id')
            ->selectRaw('sum(daily_attendances.status in (?, ?)) as attended', [AttendanceStatus::Present->value, AttendanceStatus::Incomplete->value])
            ->selectRaw('sum('.self::CHECKED_IN.') as checked_in')
            ->selectRaw('sum(daily_attendances.status = ?) as on_leave', [AttendanceStatus::Leave->value])
            ->groupBy('employees.department_id')
            ->get()
            ->keyBy('department_id');

        return $departments->map(function (Department $department) use ($byDepartment, $pending) {
            $row = $byDepartment->get($department->id);

            return [
                'name' => $department->name,
                'employees' => $department->employees_count,
                // The N / M denominator: the headcount less anyone on
                // full-day approved leave today (Phase 3d), as in the trend.
                'expected' => $department->employees_count - (int) ($row->on_leave ?? 0),
                'attended' => (int) ($row->attended ?? 0),
                'checkedIn' => (int) ($row->checked_in ?? 0),
                'pending' => $pending,
            ];
        })->values()->all();
    }

    /**
     * The live "who is here now" view of today, derived from today's
     * existing rows (no builder involvement), as a partition of the active
     * employees in scope **by punches** — the three groups never overlap and
     * always sum to 'total':
     *
     * - atWork: an in-punch and no out-punch yet (an open day, or an in-only
     *   day whose window closed). 'atWorkPastEnd' of them are past their
     *   schedule's end (Phase 2.7 — overtime, or a missing out-punch).
     * - left: has an out-punch — Present, or an out-only Incomplete day.
     * - notIn: no punches today. Its state sub-counts partition it exactly
     *   (they always sum to notIn): 'notInDue' (a punchless In progress row,
     *   or no row built yet), 'notInAbsent', 'notInOff', 'notInHoliday' and
     *   'notInLeave' — a row with no punches can only be one of those.
     *   'notInLeave' is a full-day leave, or (Phase 3d) a punchless In
     *   progress row still inside its AM leave, before the PM start + grace —
     *   not due yet, so not counted as due (DailyAttendance::isAwayOnLeaveNow()).
     *
     * Timing counts — 'atWorkLate', 'leftLate', 'leftEarly' — annotate their
     * group (a subset of it), never a fourth group. 'checkedIn' (anyone with a
     * punch today, CHECKED_IN) is the Department card's and the trend's
     * pending-bar figure; it always equals atWork + left, and the strip itself
     * never shows it.
     *
     * @param  int[]|null  $employeeIds
     * @return array{atWork: int, atWorkLate: int, atWorkPastEnd: int, left: int, leftLate: int, leftEarly: int, notIn: int, notInDue: int, notInAbsent: int, notInOff: int, notInHoliday: int, notInLeave: int, checkedIn: int, total: int}
     */
    public static function liveToday(?array $employeeIds): array
    {
        $punchless = 'daily_attendances.first_in is null and daily_attendances.last_out is null';
        $atWork = 'daily_attendances.first_in is not null and daily_attendances.last_out is null';

        // "Past end time" compares against a PHP-supplied now(), never MySQL's
        // own clock (CLAUDE.md, Local environment: Timezone).
        $row = self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->leftJoin('work_schedules', 'work_schedules.id', '=', 'daily_attendances.work_schedule_id')
            ->selectRaw('count(*) as rows_built')
            ->selectRaw("sum({$atWork}) as at_work")
            ->selectRaw("sum({$atWork} and daily_attendances.late_minutes > 0) as at_work_late")
            ->selectRaw("sum({$atWork} and timestamp(daily_attendances.work_date, work_schedules.end_time) <= ?) as at_work_past_end", [now()->format('Y-m-d H:i:s')])
            ->selectRaw('sum(daily_attendances.last_out is not null) as left_count')
            ->selectRaw('sum(daily_attendances.last_out is not null and daily_attendances.late_minutes > 0) as left_late')
            ->selectRaw('sum(daily_attendances.last_out is not null and daily_attendances.early_leave_minutes > 0) as left_early')
            ->selectRaw("sum({$punchless} and daily_attendances.status = ?) as due", [AttendanceStatus::InProgress->value])
            ->selectRaw("sum({$punchless} and daily_attendances.status = ?) as absent", [AttendanceStatus::Absent->value])
            ->selectRaw("sum({$punchless} and daily_attendances.status = ?) as off_count", [AttendanceStatus::Off->value])
            ->selectRaw("sum({$punchless} and daily_attendances.status = ?) as holiday", [AttendanceStatus::Holiday->value])
            ->selectRaw("sum({$punchless} and daily_attendances.status = ?) as on_leave", [AttendanceStatus::Leave->value])
            ->selectRaw('sum('.self::CHECKED_IN.') as checked_in')
            ->toBase()
            ->first();

        $total = self::scopedEmployeesActiveOn($employeeIds, today())->count();
        $atWorkCount = (int) ($row->at_work ?? 0);
        $left = (int) ($row->left_count ?? 0);
        $notBuilt = max(0, $total - (int) ($row->rows_built ?? 0));

        // Punchless In progress rows still inside a half-day leave (an AM
        // leave, before the PM start + grace) aren't due yet: they move from
        // "due" to "on leave", so the sub-line still sums to the cell.
        $awayOnLeave = self::rowsOfEmployeesActiveOn($employeeIds, today())
            ->where('status', AttendanceStatus::InProgress->value)
            ->whereNull('first_in')
            ->whereNull('last_out')
            ->whereNotNull('leave_id')
            ->with('workSchedule')
            ->get()
            ->tap(fn (Collection $rows) => DailyAttendance::withLeaveDays($rows))
            ->filter(fn (DailyAttendance $row) => $row->isAwayOnLeaveNow())
            ->count();

        return [
            'atWork' => $atWorkCount,
            'atWorkLate' => (int) ($row->at_work_late ?? 0),
            'atWorkPastEnd' => (int) ($row->at_work_past_end ?? 0),
            'left' => $left,
            'leftLate' => (int) ($row->left_late ?? 0),
            'leftEarly' => (int) ($row->left_early ?? 0),
            'notIn' => max(0, $total - $atWorkCount - $left),
            // Not built yet reads as "due", like a punchless In progress row: today's build simply hasn't reached them.
            'notInDue' => (int) ($row->due ?? 0) + $notBuilt - $awayOnLeave,
            'notInAbsent' => (int) ($row->absent ?? 0),
            'notInOff' => (int) ($row->off_count ?? 0),
            'notInHoliday' => (int) ($row->holiday ?? 0),
            'notInLeave' => (int) ($row->on_leave ?? 0) + $awayOnLeave,
            'checkedIn' => (int) ($row->checked_in ?? 0),
            'total' => $total,
        ];
    }

    /**
     * The live strip's three cells, shared by the Dashboard and the
     * Attendance page so both say exactly the same thing:
     * "At work 27 (4 late · 2 past end time) · Left 3 (1 late · 3 early) ·
     * Not in 5 (3 due · 2 absent)" — a partition that sums to active
     * employees, with each annotation as a sub-line.
     *
     * @param  array{atWork: int, atWorkLate: int, atWorkPastEnd: int, left: int, leftLate: int, leftEarly: int, notIn: int, notInDue: int, notInAbsent: int, notInOff: int, notInHoliday: int, notInLeave: int, checkedIn: int, total: int}  $live
     * @return list<array{icon: string, label: string, value: string, subtext: ?string}>
     */
    public static function liveTodayCells(array $live): array
    {
        $subline = fn (array $parts) => implode(' · ', array_filter($parts)) ?: null;

        return [
            ['icon' => 'check', 'label' => 'At work', 'value' => (string) $live['atWork'], 'subtext' => $subline([
                $live['atWorkLate'] > 0 ? $live['atWorkLate'].' late' : null,
                $live['atWorkPastEnd'] > 0 ? $live['atWorkPastEnd'].' past end time' : null,
            ])],
            ['icon' => 'logout', 'label' => 'Left', 'value' => (string) $live['left'], 'subtext' => $subline([
                $live['leftLate'] > 0 ? $live['leftLate'].' late' : null,
                $live['leftEarly'] > 0 ? $live['leftEarly'].' early' : null,
            ])],
            // Not in's sub-line is its exact breakdown by state: the parts always sum to the cell.
            ['icon' => 'clock', 'label' => 'Not in', 'value' => (string) $live['notIn'], 'subtext' => $subline([
                $live['notInDue'] > 0 ? $live['notInDue'].' due' : null,
                $live['notInAbsent'] > 0 ? $live['notInAbsent'].' absent' : null,
                $live['notInOff'] > 0 ? $live['notInOff'].' off' : null,
                $live['notInHoliday'] > 0 ? $live['notInHoliday'].' on holiday' : null,
                $live['notInLeave'] > 0 ? $live['notInLeave'].' on leave' : null,
            ])],
        ];
    }

    /**
     * Today is pending — not yet a final figure — while any scoped row for
     * today is still In progress, or some employee in scope active today
     * (Employee::scopeActiveOn(), so including anyone whose left_on is today)
     * has no row for today yet (not calculated). Pending attendance must never be
     * shown as 0% or as an absence (docs/ATTENDANCE_UI.md).
     *
     * @param  int[]|null  $employeeIds
     */
    public static function todayIsPending(?array $employeeIds): bool
    {
        $rows = self::rowsOfEmployeesActiveOn($employeeIds, today());

        return (clone $rows)->where('status', AttendanceStatus::InProgress->value)->exists()
            || $rows->count() < self::scopedEmployeesActiveOn($employeeIds, today())->count();
    }

    /**
     * The most recent real punches, not a fabricated activity feed. This is
     * a log: "Punched in" / "Punched out" in neutral text, with no timing.
     * Event-log language on purpose: "Checked in" is reserved for the
     * any-punch aggregate on the Department card and the trend.
     * The late fact is shown once, in Needs attention, not repeated here.
     *
     * @param  int[]|null  $employeeIds
     * @return list<array{name: string, action: string, time: string, date: ?string}>
     */
    public static function recentActivity(?array $employeeIds, int $limit = 6): array
    {
        return AttendanceLog::query()
            ->notVoided()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->with('employee')
            ->orderByDesc('punched_at')
            ->limit($limit)
            ->get()
            ->map(fn (AttendanceLog $log) => [
                'name' => $log->employee->full_name,
                'action' => $log->punch_type->value === 'in' ? 'Punched in' : 'Punched out',
                'time' => AttendanceTime::format($log->punched_at),
                // Only for an entry that isn't from today, so an older punch
                // can't read as this morning's.
                'date' => $log->punched_at->isToday() ? null : DisplayDate::compact($log->punched_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether an In progress row is still open by the builder's own rule
     * (DailySummaryBuilder::isInProgress()): an in-only row until its
     * pairing window closes; a punchless or out-only row only today, until
     * its schedule's end. An In progress row that fails this is stale — the
     * builder hasn't run since it should have closed it.
     */
    private static function isOpen(DailyAttendance $row): bool
    {
        if ($row->first_in !== null && $row->last_out === null) {
            return now()->lte($row->first_in->copy()->addHours(DailySummaryBuilder::MAX_SHIFT_HOURS));
        }

        return $row->work_date->isToday();
    }

    /**
     * @param  int[]|null  $employeeIds
     */
    private static function scopedDailyAttendanceQuery(?array $employeeIds)
    {
        return self::applyEmployeeScope(DailyAttendance::query(), $employeeIds, 'employee_id');
    }

    /**
     * Employees in scope who were employed on $day (Employee::scopeActiveOn())
     * — the denominator of every figure on the dashboard, for today (strip,
     * breakdown, Needs attention, Department card) and for each trend day. Not
     * `status`: a deactivated employee still counts up to their left_on, and a
     * future joiner isn't counted (or "not in yet") before their join date.
     *
     * @param  int[]|null  $employeeIds
     */
    private static function scopedEmployeesActiveOn(?array $employeeIds, Carbon $day)
    {
        return self::applyEmployeeScope(Employee::query()->activeOn($day), $employeeIds, 'id');
    }

    /**
     * $day's rows of the employees scopedEmployeesActiveOn() counts — the
     * numerator to match that denominator.
     *
     * @param  int[]|null  $employeeIds
     */
    private static function rowsOfEmployeesActiveOn(?array $employeeIds, Carbon $day)
    {
        return self::scopedDailyAttendanceQuery($employeeIds)
            ->whereDate('daily_attendances.work_date', $day->format('Y-m-d'))
            ->whereIn('daily_attendances.employee_id', Employee::query()->activeOn($day)->select('id'));
    }

    /**
     * @param  int[]|null  $employeeIds
     */
    private static function applyEmployeeScope($query, ?array $employeeIds, string $column)
    {
        if ($employeeIds === null) {
            return $query;
        }

        return $query->whereIn($column, $employeeIds);
    }
}
