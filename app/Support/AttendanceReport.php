<?php

namespace App\Support;

use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The monthly attendance report (Phase 5b): one row per employee active on
 * any day of the month in the viewer's scope (EmployeeScope — today's team,
 * the whole month), ordered by employee code like the overtime report.
 *
 * Every row is AttendanceSummary's — the same functions, not a second set of
 * rules, so a row always equals that employee's My attendance summary for the
 * month (AttendanceReportTest compares them). What the report adds is only
 * how it loads: one query for the month's rows of everyone in it, one for
 * their leave, one for their schedules, one for holidays — the same number
 * for 10 employees as for 50.
 */
final class AttendanceReport
{
    /** The figures every row (and the totals) has, in column order. */
    public const FIGURES = [
        'workdays', 'present', 'absent', 'incomplete', 'leave', 'leave_tenths', 'days_off_worked',
        'late', 'incomplete_late', 'late_minutes', 'early_leave_days', 'early_leave_minutes',
        'total_worked_minutes', 'not_calculated',
    ];

    /**
     * @return array{
     *     rows: list<array{employee: Employee, joined: ?Carbon, left: ?Carbon, summary: array<string, int>}>,
     *     totals: array<string, int>,
     *     open: bool,
     *     calculated: bool,
     *     departments: array<int, string>,
     *     department: ?int,
     * }
     *
     * `calculated` is false when no row of the month exists for anyone in the
     * report — before go-live, say — so the page can say so instead of a
     * table of zeros. `department` is the filter actually applied: one
     * outside the viewer's options is ignored, never widening the scope.
     */
    public static function forMonth(CarbonInterface $month, User $viewer, ?int $departmentId = null): array
    {
        $from = Carbon::instance($month)->startOfMonth();
        $to = $from->copy()->endOfMonth()->startOfDay();
        $scope = EmployeeScope::for($viewer, 'Attendance report')->ids;

        $departments = Department::query()
            ->when($scope !== null, fn (Builder $query) => $query->whereHas('employees', fn (Builder $q) => $q->whereIn('id', $scope)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
        $departmentId = array_key_exists((int) $departmentId, $departments) ? (int) $departmentId : null;

        $employees = Employee::query()
            ->activeBetween($from, $to)
            ->when($scope !== null, fn (Builder $query) => $query->whereIn('id', $scope))
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->with(['department', 'scheduleAssignments'])
            ->orderBy('employee_code')
            ->get();

        $records = $employees->isEmpty() ? collect() : DailyAttendance::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('work_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get();
        // Once for everyone: AttendanceSummary's own call then finds every
        // row already resolved.
        DailyAttendance::withLeaveDays($records);
        $byEmployee = $records->groupBy('employee_id');
        $holidays = WorkdayCalendar::holidaysBetween($from, $to);

        $rows = [];
        $totals = array_fill_keys(self::FIGURES, 0);

        foreach ($employees as $employee) {
            $own = $byEmployee->get($employee->id, collect());
            $summary = [
                ...AttendanceSummary::fromRecords($own, $employee, $holidays),
                'not_calculated' => AttendanceSummary::notCalculated($employee, $from, $own, $holidays),
            ];

            foreach (self::FIGURES as $figure) {
                $totals[$figure] += $summary[$figure];
            }

            $rows[] = [
                'employee' => $employee,
                'joined' => $employee->join_date->isSameMonth($from) ? $employee->join_date : null,
                'left' => $employee->left_on?->isSameMonth($from) ? $employee->left_on : null,
                'summary' => $summary,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'open' => $to->gte(today()),
            'calculated' => $records->isNotEmpty(),
            'departments' => $departments,
            'department' => $departmentId,
        ];
    }

    /** Late days of a summary: Present and Incomplete days that started late. */
    public static function lateDays(array $summary): int
    {
        return $summary['late'] + $summary['incomplete_late'];
    }
}
