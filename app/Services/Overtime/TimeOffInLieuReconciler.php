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
        $minutes = (int) DailyAttendance::query()
            ->join('overtime_requests', 'overtime_requests.id', '=', 'daily_attendances.overtime_request_id')
            ->where('daily_attendances.employee_id', $employee->id)
            ->where('overtime_requests.employee_id', $employee->id)
            ->where('overtime_requests.status', OvertimeStatus::Approved->value)
            ->where('overtime_requests.compensation', OvertimeCompensation::TimeOff->value)
            ->sum(DB::raw('daily_attendances.overtime_workday_minutes + daily_attendances.overtime_night_minutes'
                .' + daily_attendances.overtime_rest_day_minutes + daily_attendances.overtime_holiday_minutes'));

        return intdiv($minutes * $settings->toil_ratio_percent, 100 * $settings->toil_block_minutes) * self::BLOCK_TENTHS;
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
