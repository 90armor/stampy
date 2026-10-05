<?php

namespace App\Support;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\Leave\EntitlementCalculator;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use Carbon\CarbonImmutable;

/**
 * What the dashboard shows someone without the team figures (Phase 3e):
 * their own leave balances, this month's attendance and their pending
 * requests. Every figure comes from the shared definitions — LeaveBalance,
 * EntitlementCalculator, LeaveDayCounter and AttendanceSummary — the same
 * ones Time off and My attendance show, so the three pages can't disagree.
 */
final class EmployeeDashboard
{
    /**
     * @return array{balances: list<array{name: string, available: int, usableFrom: ?CarbonImmutable, earnedSoFar: ?int}>, month: array<string, int>, pending: list<array{leave: Leave, days: int}>}
     */
    public static function for(Employee $employee): array
    {
        $balances = app(LeaveBalance::class);
        $calculator = app(EntitlementCalculator::class);
        $counter = app(LeaveDayCounter::class);

        return [
            'balances' => LeaveType::query()
                ->where('is_active', true)
                ->whereNotNull('days_per_year')
                ->orderBy('id')
                ->get()
                ->map(function (LeaveType $type) use ($employee, $balances, $calculator) {
                    $balance = $balances->for($employee, $type, today()->year);

                    return [
                        'name' => $type->name,
                        'available' => $balance->available(),
                        'usableFrom' => $balance->usableFrom,
                        'earnedSoFar' => $calculator->earnedSoFar($employee, $type, today()),
                    ];
                })
                ->all(),
            'month' => AttendanceSummary::forMonth($employee, today()),
            'pending' => Leave::query()
                ->where('employee_id', $employee->id)
                ->where('status', LeaveStatus::Pending->value)
                ->with('leaveType')
                ->orderBy('start_date')
                ->get()
                ->map(function (Leave $leave) use ($employee, $counter) {
                    $leave->setRelation('employee', $employee);

                    return ['leave' => $leave, 'days' => array_sum($counter->countLeave($leave))];
                })
                ->all(),
        ];
    }
}
