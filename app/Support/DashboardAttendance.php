<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
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
 */
class DashboardAttendance
{
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
        $total = self::scopedActiveEmployeeQuery($employeeIds)->count();

        $today = today()->format('Y-m-d');

        $counts = self::scopedDailyAttendanceQuery($employeeIds)
            ->whereDate('work_date', $today)
            ->select('status')
            ->selectRaw('count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $built = (int) $counts->sum();

        // 'late'/'early leave' are a breakdown of Present, not a peer bucket
        // (see CLAUDE.md's "Status vs. timing" note) — a late day IS a
        // present day, so it must not also claim its own slice of the bar
        // below, which would double count it.
        $lateCount = self::scopedDailyAttendanceQuery($employeeIds)
            ->whereDate('work_date', $today)
            ->where('late_minutes', '>', 0)
            ->count();
        $earlyCount = self::scopedDailyAttendanceQuery($employeeIds)
            ->whereDate('work_date', $today)
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
     * late arrival, then "Not in yet". A late arrival is included whatever
     * its status — since Phase 2.6 that includes a day still In progress —
     * because the point of this list is "who might need a nudge", not "who
     * is missing from the attendance count". "Not in yet" is the narrow,
     * actionable scope of that label: an In progress row with no punch whose
     * start_time + grace_minutes has passed (DailyAttendance::isNotInYet()),
     * always a subset of the live strip's wider "Not in yet" count. Neither
     * late nor "not in yet" is a status, so neither carries a badge.
     *
     * @param  int[]|null  $employeeIds
     * @return list<array{name: string, kind: string, label: string, badge: ?string, detail: ?string}>
     */
    public static function needsAttention(?array $employeeIds, int $limit = 8): array
    {
        // The OR must be nested inside its own where() — a top-level
        // orWhere() here would escape both the employee scope and the
        // whereDate above (SQL's OR binds looser than AND), which would
        // silently leak other employees'/other days' rows into a manager's
        // "needs attention" list.
        $rows = self::scopedDailyAttendanceQuery($employeeIds)
            ->whereDate('work_date', today()->format('Y-m-d'))
            ->where(function ($query) {
                $query->whereIn('status', [AttendanceStatus::Absent->value, AttendanceStatus::Incomplete->value])
                    ->orWhere('late_minutes', '>', 0)
                    ->orWhere(fn ($q) => $q->where('status', AttendanceStatus::InProgress->value)
                        ->whereNull('first_in')
                        ->whereNull('last_out'));
            })
            ->with(['employee', 'workSchedule'])
            ->get()
            ->map(fn (DailyAttendance $row) => [
                'row' => $row,
                'kind' => match (true) {
                    $row->status === AttendanceStatus::Absent => 'absent',
                    $row->status === AttendanceStatus::Incomplete => 'incomplete',
                    $row->isLate() => 'late',
                    $row->isNotInYet() => 'not_in_yet',
                    default => null,
                },
            ])
            // A punchless In progress row before start + grace is simply in
            // progress, not "not in yet" — it drops out here.
            ->filter(fn (array $item) => $item['kind'] !== null)
            // Sorted in PHP rather than a SQL ORDER BY FIELD(): the set is
            // small (bounded by today's employee count).
            ->sortBy(fn (array $item) => [
                ['absent' => 0, 'incomplete' => 1, 'late' => 2, 'not_in_yet' => 3][$item['kind']],
                $item['kind'] === 'late' ? -$item['row']->late_minutes : 0,
                $item['row']->employee->full_name,
            ])
            ->take($limit);

        return $rows->map(function (array $item) {
            $row = $item['row'];

            [$label, $badge, $detail] = match ($item['kind']) {
                'absent' => ['Absent', 'red', null],
                'incomplete' => ['Incomplete', 'violet', null],
                'late' => ['Late', null, Duration::format($row->late_minutes).' late'],
                'not_in_yet' => ['Not in yet', null, 'Not in yet · due '.AttendanceTime::format(
                    Carbon::parse($row->work_date->format('Y-m-d').' '.$row->workSchedule->start_time)
                )],
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
     * The present share of active employees for each of the last $days days.
     * A day whose scoped rows are all Off or Holiday is not a working day, so
     * its value is null and it carries a marker ('Off' / 'Holiday') for the
     * chart to render instead of a misleading 0% bar. A day with no rows at
     * all (not calculated yet) stays a plain 0.
     *
     * Today is pending until it is fully calculated (todayIsPending()):
     * in-progress or not-yet-calculated attendance must never read as 0% or
     * as an absence. A pending today carries the 'Today' marker, and its
     * value is the checked-in share so far (rows with an in-punch over
     * active employees, the same figure as the Department card's "Checked
     * in N / M") when anyone has checked in (null otherwise), flagged
     * 'pending' so the chart draws it as provisional.
     *
     * @param  int[]|null  $employeeIds
     * @return list<array{label: string, date: string, value: ?float, marker: ?string, pending: bool}>
     */
    public static function weeklyTrend(?array $employeeIds, int $days = 7): array
    {
        $total = self::scopedActiveEmployeeQuery($employeeIds)->count();

        if ($total === 0) {
            return [];
        }

        $start = today()->copy()->subDays($days - 1);

        $countsByDate = self::scopedDailyAttendanceQuery($employeeIds)
            ->whereBetween('work_date', [$start->format('Y-m-d'), today()->format('Y-m-d')])
            ->selectRaw('work_date, status, count(*) as total')
            ->groupBy('work_date', 'status')
            ->get()
            ->groupBy(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'))
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn (DailyAttendance $row) => [$row->status->value => (int) $row->total]));

        $nonWorking = [AttendanceStatus::Off->value, AttendanceStatus::Holiday->value];
        $todayKey = today()->format('Y-m-d');
        $todayPending = self::todayIsPending($employeeIds);
        $checkedInToday = $todayPending
            ? self::scopedDailyAttendanceQuery($employeeIds)->whereDate('work_date', $todayKey)->whereNotNull('first_in')->count()
            : 0;

        $trend = [];
        $cursor = $start->copy();

        while ($cursor->lte(today())) {
            $key = $cursor->format('Y-m-d');
            $counts = $countsByDate->get($key, collect());
            $isNonWorkingDay = $counts->isNotEmpty() && $counts->keys()->every(fn (string $status) => in_array($status, $nonWorking, true));
            $isPending = $key === $todayKey && $todayPending && ! $isNonWorkingDay;
            $present = (int) $counts->get(AttendanceStatus::Present->value, 0);

            $trend[] = [
                'label' => $cursor->format('D'),
                'date' => $key,
                'value' => match (true) {
                    $isNonWorkingDay => null,
                    $isPending && $checkedInToday === 0 => null,
                    $isPending => round($checkedInToday / $total * 100, 1),
                    default => round($present / $total * 100, 1),
                },
                'marker' => match (true) {
                    $isPending => 'Today',
                    ! $isNonWorkingDay => null,
                    $counts->has(AttendanceStatus::Off->value) => 'Off',
                    default => 'Holiday',
                },
                'pending' => $isPending,
            ];

            $cursor->addDay();
        }

        return $trend;
    }

    /**
     * Today's attendance per department. While today is pending
     * (todayIsPending()) a percentage would read in-progress people as
     * absent, so each department also carries 'checkedIn' — rows today with
     * a first punch — for a "Checked in N / M" so-far count instead.
     *
     * @param  Collection<int, Department>  $departments  already scoped by
     *                                                    the caller (see routes/web.php) — this only computes each one's percent
     * @param  int[]|null  $employeeIds
     * @return list<array{name: string, employees: int, attendance: float, checkedIn: int, pending: bool}>
     */
    public static function departmentAttendance(Collection $departments, ?array $employeeIds): array
    {
        $today = today()->format('Y-m-d');
        $pending = self::todayIsPending($employeeIds);

        $byDepartment = self::scopedDailyAttendanceQuery($employeeIds)
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->whereDate('daily_attendances.work_date', $today)
            ->selectRaw('employees.department_id')
            ->selectRaw('sum(daily_attendances.status = ?) as present', [AttendanceStatus::Present->value])
            ->selectRaw('sum(daily_attendances.first_in is not null) as checked_in')
            ->groupBy('employees.department_id')
            ->get()
            ->keyBy('department_id');

        return $departments->map(function (Department $department) use ($byDepartment, $pending) {
            $activeCount = $department->employees_count;
            $row = $byDepartment->get($department->id);
            $present = (int) ($row->present ?? 0);

            return [
                'name' => $department->name,
                'employees' => $activeCount,
                'attendance' => $activeCount > 0 ? round($present / $activeCount * 100, 1) : 0.0,
                'checkedIn' => (int) ($row->checked_in ?? 0),
                'pending' => $pending,
            ];
        })->values()->all();
    }

    /**
     * The live "who is here now" view of today, derived from today's
     * existing rows (no builder involvement), as a partition of the active
     * employees in scope — the three groups never overlap and always sum to
     * 'total':
     *
     * - atWork: has an in-punch today and hasn't finished (any non-Present
     *   row with a first_in);
     * - left: Present today (both punches), of which 'leftEarly' left early;
     * - notInYet: everyone else — no in-punch yet (punchless In progress,
     *   not calculated, or an out-only row).
     *
     * 'atWorkLate' and 'leftLate' annotate two of those groups with how
     * many arrived late (Phase 2.6: late is known from the in-punch) — late
     * is never a fourth group.
     *
     * 'checkedIn' is a different, overlapping figure — everyone with an
     * in-punch today, at work or already left — used by the Department card
     * and the trend's pending bar, never by the strip. Status counts answer
     * "did they attend" at end of day; this answers "who is here now" and is
     * only ever shown for today (docs/ATTENDANCE_UI.md).
     *
     * @param  int[]|null  $employeeIds
     * @return array{atWork: int, atWorkLate: int, left: int, leftLate: int, leftEarly: int, notInYet: int, checkedIn: int, total: int}
     */
    public static function liveToday(?array $employeeIds): array
    {
        $today = today()->format('Y-m-d');
        $present = AttendanceStatus::Present->value;

        $row = self::scopedDailyAttendanceQuery($employeeIds)
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->where('employees.status', 'active')
            ->whereDate('daily_attendances.work_date', $today)
            ->selectRaw('sum(daily_attendances.status <> ? and daily_attendances.first_in is not null) as at_work', [$present])
            ->selectRaw('sum(daily_attendances.status <> ? and daily_attendances.first_in is not null and daily_attendances.late_minutes > 0) as at_work_late', [$present])
            ->selectRaw('sum(daily_attendances.status = ?) as left_count', [$present])
            ->selectRaw('sum(daily_attendances.status = ? and daily_attendances.late_minutes > 0) as left_late', [$present])
            ->selectRaw('sum(daily_attendances.status = ? and daily_attendances.early_leave_minutes > 0) as left_early', [$present])
            ->selectRaw('sum(daily_attendances.first_in is not null) as checked_in')
            ->toBase()
            ->first();

        $total = self::scopedActiveEmployeeQuery($employeeIds)->count();
        $atWork = (int) ($row->at_work ?? 0);
        $left = (int) ($row->left_count ?? 0);

        return [
            'atWork' => $atWork,
            'atWorkLate' => (int) ($row->at_work_late ?? 0),
            'left' => $left,
            'leftLate' => (int) ($row->left_late ?? 0),
            'leftEarly' => (int) ($row->left_early ?? 0),
            'notInYet' => max(0, $total - $atWork - $left),
            'checkedIn' => (int) ($row->checked_in ?? 0),
            'total' => $total,
        ];
    }

    /**
     * The live strip's three cells, shared by the Dashboard and the
     * Attendance page so both say exactly the same thing:
     * "At work 27 (4 late) · Left 3 (1 late · 3 early) · Not in yet 5" — a
     * partition that sums to active employees, with late as a sub-line.
     *
     * @param  array{atWork: int, atWorkLate: int, left: int, leftLate: int, leftEarly: int, notInYet: int, checkedIn: int, total: int}  $live
     * @return list<array{icon: string, label: string, value: string, subtext: ?string}>
     */
    public static function liveTodayCells(array $live): array
    {
        return [
            ['icon' => 'check', 'label' => 'At work', 'value' => (string) $live['atWork'], 'subtext' => $live['atWorkLate'] > 0 ? $live['atWorkLate'].' late' : null],
            ['icon' => 'logout', 'label' => 'Left', 'value' => (string) $live['left'], 'subtext' => implode(' · ', array_filter([
                $live['leftLate'] > 0 ? $live['leftLate'].' late' : null,
                $live['leftEarly'] > 0 ? $live['leftEarly'].' early' : null,
            ])) ?: null],
            ['icon' => 'clock', 'label' => 'Not in yet', 'value' => (string) $live['notInYet'], 'subtext' => null],
        ];
    }

    /**
     * Today is pending — not yet a final figure — while any scoped row for
     * today is still In progress, or some active employee in scope has no
     * row for today yet (not calculated). Pending attendance must never be
     * shown as 0% or as an absence (docs/ATTENDANCE_UI.md).
     *
     * @param  int[]|null  $employeeIds
     */
    public static function todayIsPending(?array $employeeIds): bool
    {
        $today = today()->format('Y-m-d');
        $rows = self::scopedDailyAttendanceQuery($employeeIds)->whereDate('work_date', $today);

        return (clone $rows)->where('status', AttendanceStatus::InProgress->value)->exists()
            || $rows->count() < self::scopedActiveEmployeeQuery($employeeIds)->count();
    }

    /**
     * The most recent real punches, not a fabricated activity feed — each
     * one's tone is looked up from that day's already-built DailyAttendance
     * row (batched, not per-punch) so a late arrival reads as late here too.
     *
     * @param  int[]|null  $employeeIds
     * @return list<array{name: string, action: string, time: string, date: ?string, tone: string}>
     */
    public static function recentActivity(?array $employeeIds, int $limit = 6): array
    {
        $punches = AttendanceLog::query()
            ->notVoided()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->with('employee')
            ->orderByDesc('punched_at')
            ->limit($limit)
            ->get();

        if ($punches->isEmpty()) {
            return [];
        }

        // Batched, not one query per punch: every (employee, date) pair this
        // page of punches could reference, fetched once.
        $dailyRows = DailyAttendance::query()
            ->where(function ($query) use ($punches) {
                foreach ($punches->unique(fn (AttendanceLog $log) => $log->employee_id.'|'.$log->punched_at->format('Y-m-d')) as $log) {
                    $query->orWhere(function ($q) use ($log) {
                        $q->where('employee_id', $log->employee_id)
                            ->whereDate('work_date', $log->punched_at->format('Y-m-d'));
                    });
                }
            })
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->employee_id.'|'.$row->work_date->format('Y-m-d'));

        return $punches->map(function (AttendanceLog $log) use ($dailyRows) {
            $dayKey = $log->employee_id.'|'.$log->punched_at->format('Y-m-d');
            $day = $dailyRows->get($dayKey);
            $isIn = $log->punch_type->value === 'in';

            $tone = match (true) {
                $isIn && $day && $day->isLate() => 'late',
                $isIn => 'present',
                default => 'out',
            };

            $action = match (true) {
                $isIn && $day && $day->isLate() => 'Checked in '.Duration::format($day->late_minutes).' late',
                $isIn => 'Checked in',
                default => 'Checked out',
            };

            return [
                'name' => $log->employee->full_name,
                'action' => $action,
                'time' => AttendanceTime::format($log->punched_at),
                // Only for an entry that isn't from today, so an older punch
                // can't read as this morning's.
                'date' => $log->punched_at->isToday() ? null : DisplayDate::compact($log->punched_at),
                'tone' => $tone,
            ];
        })->values()->all();
    }

    /**
     * @param  int[]|null  $employeeIds
     */
    private static function scopedActiveEmployeeQuery(?array $employeeIds)
    {
        return self::applyEmployeeScope(Employee::query()->where('status', 'active'), $employeeIds, 'id');
    }

    /**
     * @param  int[]|null  $employeeIds
     */
    private static function scopedDailyAttendanceQuery(?array $employeeIds)
    {
        return self::applyEmployeeScope(DailyAttendance::query(), $employeeIds, 'employee_id');
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
