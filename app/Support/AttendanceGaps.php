<?php

namespace App\Support;

use App\Models\DailyAttendance;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Past workdays with no daily_attendances row — what the attendance calendar
 * (Attendance\Show) and Daily attendance (Attendance\Index) warn about, one
 * definition for both (Phase 6e, F07). A day that hasn't happened yet, or is
 * outside the employment period, or isn't a workday for the employee
 * (WorkdayCalendar::isWorkday()) is never missing: there's nothing the builder
 * should have written there but an off row.
 */
final class AttendanceGaps
{
    /** The scheduler builds today every 15 minutes (AttendanceSchedule): today closes at the first run after its end. */
    public const CLOSE_AFTER_MINUTES = 15;

    /**
     * The last day that counts as past for $employee: today once the
     * scheduler has closed it — the first run after today's schedule end,
     * when a day with no punches is finalised — else yesterday.
     */
    public static function lastPastDay(Employee $employee): Carbon
    {
        $closes = Carbon::parse(today()->format('Y-m-d').' '.$employee->scheduleOn(today())->end_time)
            ->addMinutes(self::CLOSE_AFTER_MINUTES);

        return now()->gte($closes) ? today() : today()->subDay();
    }

    /**
     * $employee's past workdays from $from to $to, inside their employment
     * period, with no row.
     *
     * @param  array<string, true>  $built  the employee's built dates, keyed 'Y-m-d'
     * @param  array<string, true>|HolidayRange  $holidays  covering the range
     * @return list<Carbon>
     */
    public static function missingWorkdays(Employee $employee, CarbonInterface $from, CarbonInterface $to, array $built, array|HolidayRange $holidays): array
    {
        $start = Carbon::instance($from)->startOfDay()->max($employee->join_date);
        $end = Carbon::instance($to)->startOfDay()->min(self::lastPastDay($employee));

        if ($employee->left_on !== null) {
            $end = $end->min($employee->left_on);
        }

        $missing = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (! isset($built[$date->format('Y-m-d')]) && WorkdayCalendar::isWorkday($employee, $date, $holidays)) {
                $missing[] = $date->copy();
            }
        }

        return $missing;
    }

    /**
     * Every date from $from to $to that is a missing workday for anyone in
     * $employeeIds (null: everyone), sorted — Daily attendance's notice.
     * Three queries whatever the headcount: the employees with their
     * schedules, the range's built rows, the holidays.
     *
     * @param  int[]|null  $employeeIds
     * @return list<Carbon>
     */
    public static function missingDates(?array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $employees = Employee::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->activeBetween($from, $to)
            ->with('scheduleAssignments')
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $built = [];
        DailyAttendance::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('work_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get(['employee_id', 'work_date'])
            ->each(function (DailyAttendance $row) use (&$built) {
                $built[$row->employee_id][$row->work_date->format('Y-m-d')] = true;
            });

        $holidays = WorkdayCalendar::preload($from, $to);
        $dates = [];

        foreach ($employees as $employee) {
            foreach (self::missingWorkdays($employee, $from, $to, $built[$employee->id] ?? [], $holidays) as $date) {
                $dates[$date->format('Y-m-d')] = $date;
            }
        }

        ksort($dates);

        return array_values($dates);
    }

    /** "Mon 12 Oct", "Mon 12 Oct and Tue 13 Oct", "Mon 12 Oct, Tue 13 Oct and 5 more workdays". */
    public static function label(array $dates): string
    {
        $labels = array_map(fn (CarbonInterface $date) => DisplayDate::compact($date), $dates);

        return match (true) {
            count($labels) > 3 => implode(', ', array_slice($labels, 0, 2)).' and '.(count($labels) - 2).' more workdays',
            count($labels) === 1 => $labels[0],
            default => implode(', ', array_slice($labels, 0, -1)).' and '.end($labels),
        };
    }
}
