<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use Carbon\CarbonInterface;

/**
 * The one definition of "is this date a working day". DailySummaryBuilder
 * asks the two halves separately — a non-workday is `off` and a holiday is
 * `holiday`, with different precedence — and leave counting asks them
 * together (LeaveDayCounter: a workday of the schedule in force on the date,
 * and not a holiday). Both go through here, so neither can drift from the
 * other.
 */
final class WorkdayCalendar
{
    /** One of $schedule's workdays (ISO weekday numbers), holidays aside. */
    public static function isScheduledWorkday(WorkSchedule $schedule, CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $schedule->workdays, true);
    }

    /** A company holiday falls on $date (one holidays row per date). */
    public static function isHoliday(CarbonInterface $date): bool
    {
        return Holiday::query()->whereDate('date', $date->format('Y-m-d'))->exists();
    }

    /**
     * Every holiday date in $from..$to, for a caller that walks a range and
     * shouldn't query once per date.
     *
     * @return array<string, true> keyed 'Y-m-d'
     */
    public static function holidaysBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(fn () => true, self::holidayNamesBetween($from, $to));
    }

    /**
     * The same lookup with each holiday's name — what the attendance views
     * annotate a day with (a present day on a holiday says which, so its
     * zero late/early minutes are explained). One query for the range.
     *
     * @return array<string, string> 'Y-m-d' => name
     */
    public static function holidayNamesBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return Holiday::query()
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get(['date', 'name'])
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->format('Y-m-d') => $holiday->name])
            ->all();
    }

    /**
     * A working day for this employee: a workday of the schedule in force on
     * $date (Employee::scheduleOn()) that isn't a holiday. $holidays is an
     * optional holidaysBetween() result covering $date, to save the query.
     *
     * @param  array<string, true>|null  $holidays
     */
    public static function isWorkday(Employee $employee, CarbonInterface $date, ?array $holidays = null): bool
    {
        $isHoliday = $holidays !== null ? isset($holidays[$date->format('Y-m-d')]) : self::isHoliday($date);

        return self::isScheduledWorkday($employee->scheduleOn($date), $date) && ! $isHoliday;
    }
}
