<?php

namespace App\Services\Overtime;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Support\DisplayDate;
use App\Support\LeaveDays;
use Illuminate\Support\Facades\DB;

/**
 * Time off in lieu settlement (CLAUDE.md, Phase 4, rule 14): a
 * reconciliation, not a per-request ledger. For one employee:
 *
 * - target = floor(their credited minutes on approved time_off requests, all
 *   time, × toil_ratio_percent / 100 ÷ toil_block_minutes) × half a day — the
 *   ratio applied to the minutes first, the remainder below a block carried
 *   across years because the total is all-time;
 * - posted = the sum of their system-authored TOIL adjustments (created_by
 *   null, overtime_request_id set, on the settings' TOIL type);
 * - if they differ, one append-only adjustment for the difference, in the
 *   trigger request's work-date year, linked to the trigger — the request
 *   whose change set this off, not the only one the days were earned from.
 *
 * Idempotent: a second call finds them equal and writes nothing. Runs under
 * the employee row lock, like every leave mutation, so two runs can't both
 * post the same difference. The settings that would re-value past minutes
 * (ratio, block, TOIL type) lock once a system adjustment exists
 * (OvertimeSettings).
 */
class TimeOffInLieuReconciler
{
    /** Tenths of a day per block: each full block is half a day (LeaveDays). */
    private const BLOCK_TENTHS = 5;

    /**
     * @param  OvertimeRequest|null  $trigger  the request whose change set this off; null (a
     *                                         heal) takes the employee's most recently changed time_off request
     */
    public function reconcile(Employee $employee, ?OvertimeRequest $trigger = null): ToilReconciliation
    {
        return DB::transaction(function () use ($employee, $trigger) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $settings = OvertimeSettings::current();
            $target = $this->target($employee, $settings);

            if ($settings->toil_leave_type_id === null) {
                return $target > 0 ? ToilReconciliation::noToilType($target) : ToilReconciliation::settled(0);
            }

            $posted = LeaveDays::fromDecimal((string) $this->systemAdjustments($employee, $settings)->sum('days'));

            if ($target === $posted) {
                return ToilReconciliation::settled($target);
            }

            $trigger ??= OvertimeRequest::query()
                ->where('employee_id', $employee->id)
                ->where('compensation', OvertimeCompensation::TimeOff->value)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->firstOrFail();

            $adjustment = LeaveAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $settings->toil_leave_type_id,
                'year' => $trigger->date->year,
                'days' => LeaveDays::toDecimal($target - $posted),
                'note' => 'Time off in lieu: overtime on '.DisplayDate::compact($trigger->date),
                'overtime_request_id' => $trigger->id,
                'created_by' => null,
            ]);

            return ToilReconciliation::posted($target, $posted, $adjustment);
        });
    }

    /**
     * The time saved toward the next half day — the minutes (after the ratio)
     * below a full block, which the target leaves out and carries (Phase 4d:
     * "30m toward the next half day", so 4h 30m worked reads as 0.5 day plus
     * 30m, not as 30m lost). Null without a TOIL type.
     */
    public function remainderMinutes(Employee $employee): ?int
    {
        return $this->remainderMinutesFor([$employee->id])[$employee->id];
    }

    /**
     * remainderMinutes() for many employees at once (the leave report), keyed
     * by id: one query, whatever their number.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, ?int>
     */
    public function remainderMinutesFor(array $employeeIds): array
    {
        $settings = OvertimeSettings::current();

        if ($settings->toil_leave_type_id === null) {
            return array_fill_keys($employeeIds, null);
        }

        $credited = $this->creditedMinutesFor($employeeIds);

        return collect($employeeIds)
            ->mapWithKeys(fn (int $id) => [$id => intdiv(($credited[$id] ?? 0) * $settings->toil_ratio_percent, 100) % $settings->toil_block_minutes])
            ->all();
    }

    /**
     * The employees among $employeeIds with anything to reconcile — any
     * time_off request, whatever its status (a cancelled one may still need
     * its credit taken back). One query, for attendance:build-daily.
     *
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public function employeesWithTimeOffOvertime(array $employeeIds): array
    {
        return OvertimeRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('compensation', OvertimeCompensation::TimeOff->value)
            ->distinct()
            ->pluck('employee_id')
            ->all();
    }

    /** The target in tenths of a day. */
    private function target(Employee $employee, OvertimeSettings $settings): int
    {
        return intdiv($this->creditedMinutes($employee) * $settings->toil_ratio_percent, 100 * $settings->toil_block_minutes) * self::BLOCK_TENTHS;
    }

    /** All-time credited minutes on the employee's approved time_off requests. */
    private function creditedMinutes(Employee $employee): int
    {
        return $this->creditedMinutesFor([$employee->id])[$employee->id] ?? 0;
    }

    /**
     * The minutes credited on each employee's approved time_off requests, all
     * time, keyed by id (an employee with none is left out).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, int>
     */
    private function creditedMinutesFor(array $employeeIds): array
    {
        return DailyAttendance::query()
            ->join('overtime_requests', 'overtime_requests.id', '=', 'daily_attendances.overtime_request_id')
            ->whereIn('daily_attendances.employee_id', $employeeIds)
            ->whereColumn('overtime_requests.employee_id', 'daily_attendances.employee_id')
            ->where('overtime_requests.status', OvertimeStatus::Approved->value)
            ->where('overtime_requests.compensation', OvertimeCompensation::TimeOff->value)
            ->groupBy('daily_attendances.employee_id')
            ->selectRaw('daily_attendances.employee_id as employee_id, sum(daily_attendances.overtime_workday_minutes + daily_attendances.overtime_night_minutes'
                .' + daily_attendances.overtime_rest_day_minutes + daily_attendances.overtime_holiday_minutes) as minutes')
            ->pluck('minutes', 'employee_id')
            ->map(fn ($minutes) => (int) $minutes)
            ->all();
    }

    private function systemAdjustments(Employee $employee, OvertimeSettings $settings)
    {
        return LeaveAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $settings->toil_leave_type_id)
            ->whereNull('created_by')
            ->whereNotNull('overtime_request_id');
    }
}
