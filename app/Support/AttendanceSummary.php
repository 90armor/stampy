<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One employee's month in counts — the summary above the attendance calendar
 * (Attendance\Show), the employee dashboard's "This month" card and every
 * row of the monthly attendance report (AttendanceReport, Phase 5b), one
 * definition for all three (Phase 3e).
 *
 * Counts only — no derived or payroll-adjacent figures. "Calculated
 * workdays" is present + absent + incomplete + leave rows that exist (a day
 * of leave is still one of the workdays, Phase 3d rule 20), so the four
 * status counts always add up to it. "Late"/"early_leave_days" and "Total
 * worked" are plain counts/sums over the same calculated rows, not anything
 * interpreted. Late and early leave come from late_minutes/
 * early_leave_minutes, not from status — timing is not a status (see
 * AttendanceStatus's doc comment) — so a day can contribute to both counts
 * at once.
 */
final class AttendanceSummary
{
    /**
     * @return array<string, int>
     */
    public static function forMonth(Employee $employee, CarbonInterface $month): array
    {
        return self::fromRecords(self::monthRecords($employee, $month));
    }

    /**
     * Workdays of the month with no closed row: dates inside the employment
     * period, up to today, that are a working day for this employee
     * (WorkdayCalendar::isWorkday — the schedule in force on the date, not a
     * holiday) and have no row yet, or one still In progress. Days after
     * today count nowhere. Today's open day counts until it closes: it isn't
     * calculated yet, which is all this says — never absent.
     *
     * Not part of fromRecords(): it needs the employee and the month, not
     * just rows, and only the attendance report shows it.
     *
     * @param  Collection<int, DailyAttendance>|null  $records  the employee's rows for the month, if already loaded
     * @param  array<string, true>|null  $holidays  a WorkdayCalendar::holidaysBetween() result covering the month, to save the query
     */
    public static function notCalculated(Employee $employee, CarbonInterface $month, ?Collection $records = null, ?array $holidays = null): int
    {
        $records ??= self::monthRecords($employee, $month);

        $from = Carbon::instance($month)->startOfMonth()->max($employee->join_date);
        $to = Carbon::instance($month)->endOfMonth()->startOfDay()->min(today());
        if ($employee->left_on !== null) {
            $to = $to->min($employee->left_on);
        }

        if ($from->gt($to)) {
            return 0;
        }

        $holidays ??= WorkdayCalendar::holidaysBetween($from, $to);
        $closed = $records
            ->reject(fn (DailyAttendance $row) => $row->status === AttendanceStatus::InProgress)
            ->mapWithKeys(fn (DailyAttendance $row) => [$row->work_date->format('Y-m-d') => true]);

        $count = 0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (! $closed->has($date->format('Y-m-d')) && WorkdayCalendar::isWorkday($employee, $date, $holidays)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return Collection<int, DailyAttendance>
     */
    private static function monthRecords(Employee $employee, CarbonInterface $month): Collection
    {
        return DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$month->copy()->startOfMonth()->format('Y-m-d'), $month->copy()->endOfMonth()->format('Y-m-d')])
            ->get();
    }

    /**
     * @param  Collection<int, DailyAttendance>  $records
     * @return array<string, int>
     */
    public static function fromRecords(Collection $records): array
    {
        $counts = $records->countBy(fn (DailyAttendance $row) => $row->status->value);

        $workdayStatuses = [
            AttendanceStatus::Present,
            AttendanceStatus::Absent,
            AttendanceStatus::Incomplete,
            AttendanceStatus::Leave,
        ];
        $workdays = $records->filter(fn (DailyAttendance $row) => in_array($row->status, $workdayStatuses, true));
        DailyAttendance::withLeaveDays($workdays);

        return [
            'workdays' => $workdays->count(),
            // Leave taken on those workdays, in tenths of a day (LeaveDays): a
            // full day 1, a half day 0.5 — a half-day Present day counts once
            // in workdays and 0.5 here. An off day or holiday inside a leave
            // costs nothing, so it isn't a workday and isn't counted.
            'leave_tenths' => $workdays->whereNotNull('leave_id')->sum(
                fn (DailyAttendance $row) => $row->leaveDay()->fullDay ? LeaveDays::DAY : LeaveDays::HALF
            ),
            'leave' => $counts->get(AttendanceStatus::Leave->value, 0),
            'present' => $counts->get(AttendanceStatus::Present->value, 0),
            // "of which N late" is a breakdown of Present, so only Present
            // days count here, even though since Phase 2.6 an incomplete day
            // can carry late minutes too (it is still shown in its own row).
            'late' => $records->filter(fn (DailyAttendance $row) => $row->status === AttendanceStatus::Present && $row->isLate())->count(),
            'absent' => $counts->get(AttendanceStatus::Absent->value, 0),
            'incomplete' => $counts->get(AttendanceStatus::Incomplete->value, 0),
            // Late annotates its own status group: an incomplete day with a
            // late in-punch is counted here, under Incomplete.
            'incomplete_late' => $records->filter(fn (DailyAttendance $row) => $row->status === AttendanceStatus::Incomplete && $row->isLate())->count(),
            // The late minutes of exactly the days `late` and `incomplete_late`
            // count — an In progress day's late arrival waits until it closes.
            'late_minutes' => (int) $records
                ->filter(fn (DailyAttendance $row) => in_array($row->status, [AttendanceStatus::Present, AttendanceStatus::Incomplete], true) && $row->isLate())
                ->sum('late_minutes'),
            'early_leave_days' => $records->filter(fn (DailyAttendance $row) => $row->leftEarly())->count(),
            'early_leave_minutes' => (int) $records->filter(fn (DailyAttendance $row) => $row->leftEarly())->sum('early_leave_minutes'),
            'total_worked_minutes' => (int) $records->sum('worked_minutes'),
        ];
    }
}
