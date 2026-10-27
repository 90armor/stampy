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
 * workdays" are the present, absent, incomplete and leave rows on the
 * employee's scheduled workdays (WorkdayCalendar::isWorkday — the schedule
 * in force on the date, not a holiday; Phase 5b), so the four status counts
 * always add up to it. A present day on a day off — a non-workday or a
 * holiday — is a "day off worked" instead (owner, Phase 5b): it keeps its
 * status and colour on the day, it just isn't one of the workdays. A
 * one-punch day off counts nowhere (owner): not a workday, and without both
 * punches not a day worked either — the day itself still shows Incomplete.
 * "Late"/"early_leave_days" and "Total worked" are plain counts/sums over
 * the calculated rows, not anything interpreted. Late and early leave come
 * from late_minutes/early_leave_minutes, not from status — timing is not a
 * status (see AttendanceStatus's doc comment) — so a day can contribute to
 * both counts at once.
 */
final class AttendanceSummary
{
    /**
     * @return array<string, int>
     */
    public static function forMonth(Employee $employee, CarbonInterface $month): array
    {
        return self::fromRecords(self::monthRecords($employee, $month), $employee);
    }

    /**
     * Past workdays of the month with no finished row — the dashboard's "Not
     * calculated": dates inside the employment period, before today, that
     * are a working day for this employee (WorkdayCalendar::isWorkday) and
     * have no row, or an In progress row that is no longer open
     * (DailyAttendance::isOpen() — the builder hasn't run since it should
     * have closed it). Today counts nowhere until it closes, and neither does
     * an earlier day still inside its pairing window: both are pending, not
     * missing.
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
        $to = Carbon::instance($month)->endOfMonth()->startOfDay()->min(today()->subDay());
        if ($employee->left_on !== null) {
            $to = $to->min($employee->left_on);
        }

        if ($from->gt($to)) {
            return 0;
        }

        $holidays ??= WorkdayCalendar::holidaysBetween($from, $to);
        $finished = $records
            ->reject(fn (DailyAttendance $row) => $row->status === AttendanceStatus::InProgress && ! $row->isOpen())
            ->mapWithKeys(fn (DailyAttendance $row) => [$row->work_date->format('Y-m-d') => true]);

        $count = 0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (! $finished->has($date->format('Y-m-d')) && WorkdayCalendar::isWorkday($employee, $date, $holidays)) {
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
     * @param  Collection<int, DailyAttendance>  $records  the employee's rows
     * @param  array<string, true>|null  $holidays  a WorkdayCalendar::holidaysBetween() result covering the rows, to save the query
     * @return array<string, int>
     */
    public static function fromRecords(Collection $records, Employee $employee, ?array $holidays = null): array
    {
        $holidays ??= $records->isEmpty() ? [] : WorkdayCalendar::holidaysBetween($records->min('work_date'), $records->max('work_date'));
        [$onWorkdays, $onDaysOff] = $records->partition(fn (DailyAttendance $row) => WorkdayCalendar::isWorkday($employee, $row->work_date, $holidays));

        $counts = $onWorkdays->countBy(fn (DailyAttendance $row) => $row->status->value);

        $workdayStatuses = [
            AttendanceStatus::Present,
            AttendanceStatus::Absent,
            AttendanceStatus::Incomplete,
            AttendanceStatus::Leave,
        ];
        $workdays = $onWorkdays->filter(fn (DailyAttendance $row) => in_array($row->status, $workdayStatuses, true));
        DailyAttendance::withLeaveDays($workdays);

        return [
            'workdays' => $workdays->count(),
            // Leave taken on those workdays, in tenths of a day (LeaveDays): a
            // full day 1, a half day 0.5 — a half-day Present day counts once
            // in workdays and 0.5 here. An off day or holiday inside a leave
            // costs nothing, so it isn't a workday and isn't counted — worked
            // or not.
            'leave_tenths' => $workdays->whereNotNull('leave_id')->sum(
                fn (DailyAttendance $row) => $row->leaveDay()->fullDay ? LeaveDays::DAY : LeaveDays::HALF
            ),
            // Of those workdays, the ones worked on approved leave
            // (DailyAttendance::workedOnLeave() — the dashboard's and the
            // attendance views' definition): a punch on a full-day leave, or
            // into the half on leave. The leave stays charged (Phase 3 rule
            // 17), so it is still in leave_tenths.
            'worked_on_leave' => $workdays->filter(fn (DailyAttendance $row) => $row->workedOnLeave())->count(),
            'leave' => $counts->get(AttendanceStatus::Leave->value, 0),
            'present' => $counts->get(AttendanceStatus::Present->value, 0),
            // A present day on a non-workday or holiday (both punches) — not
            // one of the workdays. A one-punch day off counts nowhere.
            'days_off_worked' => $onDaysOff->filter(fn (DailyAttendance $row) => $row->status === AttendanceStatus::Present)->count(),
            // "of which N late" is a breakdown of Present, so only Present
            // days count here, even though since Phase 2.6 an incomplete day
            // can carry late minutes too (it is still shown in its own row).
            'late' => $onWorkdays->filter(fn (DailyAttendance $row) => $row->status === AttendanceStatus::Present && $row->isLate())->count(),
            'absent' => $counts->get(AttendanceStatus::Absent->value, 0),
            'incomplete' => $counts->get(AttendanceStatus::Incomplete->value, 0),
            // Late annotates its own status group: an incomplete day with a
            // late in-punch is counted here, under Incomplete.
            'incomplete_late' => $onWorkdays->filter(fn (DailyAttendance $row) => $row->status === AttendanceStatus::Incomplete && $row->isLate())->count(),
            // The late minutes of exactly the days `late` and `incomplete_late`
            // count — an In progress day's late arrival waits until it closes.
            'late_minutes' => (int) $onWorkdays
                ->filter(fn (DailyAttendance $row) => in_array($row->status, [AttendanceStatus::Present, AttendanceStatus::Incomplete], true) && $row->isLate())
                ->sum('late_minutes'),
            'early_leave_days' => $onWorkdays->filter(fn (DailyAttendance $row) => $row->leftEarly())->count(),
            'early_leave_minutes' => (int) $onWorkdays->filter(fn (DailyAttendance $row) => $row->leftEarly())->sum('early_leave_minutes'),
            // Every worked minute of the month, days off worked included.
            'total_worked_minutes' => (int) $records->sum('worked_minutes'),
        ];
    }
}
