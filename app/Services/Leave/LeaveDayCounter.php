<?php

namespace App\Services\Leave;

use App\Enums\LeaveCounting;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Support\HolidayRange;
use App\Support\LeaveDays;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * How many days a leave costs, split by calendar year — each year's balance
 * pays for its own dates, so 30 Dec – 3 Jan costs both. Amounts are integer
 * tenths (LeaveDays).
 *
 * - workdays types: a date counts when it's a workday for this employee
 *   (WorkdayCalendar::isWorkday(): the schedule in force on that date, not a
 *   holiday), so a schedule reassignment partway through is handled per date.
 * - calendar_days types (Maternity): every date counts.
 * - a half day (a single date) counts half, if the date counts at all.
 *
 * Always derived, never stored: a holiday added inside an approved leave
 * lowers the count — and refunds the balance — with no other step.
 */
class LeaveDayCounter
{
    /**
     * $holidays is a WorkdayCalendar::preload() covering the dates, for a
     * caller counting many leaves at once; without it, one query here.
     *
     * @return array<int, int> tenths, keyed by year; years with nothing to count are left out
     */
    public function count(Employee $employee, LeaveType $type, CarbonInterface $start, CarbonInterface $end, bool $halfDay = false, ?HolidayRange $holidays = null): array
    {
        $from = Carbon::instance($start)->startOfDay();
        $to = Carbon::instance($end)->startOfDay();
        $perDay = $halfDay ? LeaveDays::HALF : LeaveDays::DAY;
        $byCalendar = $type->counts === LeaveCounting::CalendarDays;
        $holidays = $byCalendar ? [] : ($holidays ?? WorkdayCalendar::holidaysBetween($from, $to));

        $byYear = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($byCalendar || WorkdayCalendar::isWorkday($employee, $day, $holidays)) {
                $byYear[$day->year] = ($byYear[$day->year] ?? 0) + $perDay;
            }
        }

        return $byYear;
    }

    /**
     * @return array<int, int> tenths, keyed by year
     */
    public function countLeave(Leave $leave, ?HolidayRange $holidays = null): array
    {
        return $this->count($leave->employee, $leave->leaveType, $leave->start_date, $leave->end_date, $leave->half !== null, $holidays);
    }

    /**
     * The holidays of every date $leaves cover, in one query — for
     * countLeave() over many of them.
     *
     * @param  iterable<Leave>  $leaves
     */
    public static function holidaysFor(iterable $leaves): ?HolidayRange
    {
        $leaves = collect($leaves);

        if ($leaves->isEmpty()) {
            return null;
        }

        return WorkdayCalendar::preload($leaves->min('start_date'), $leaves->max('end_date'));
    }
}
