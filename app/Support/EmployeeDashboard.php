<?php

namespace App\Support;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\EntitlementCalculator;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use Carbon\CarbonImmutable;

/**
 * What the dashboard shows someone without the team figures (Phase 3e):
 * requests decided since they last opened Time off (LeaveDecisions — with
 * no email, this is where an employee finds out), their leave balances, the
 * next approved leave, their pending requests and this month's attendance.
 * Every figure comes from the shared definitions — LeaveBalance,
 * EntitlementCalculator, LeaveDayCounter, LeaveDecisions and
 * AttendanceSummary — the same ones Time off and My attendance show, so the
 * pages can't disagree. The dashboard doesn't mark decisions seen: they stay
 * here until Time off is opened.
 */
final class EmployeeDashboard
{
    /**
     * @return array{decided: list<Leave>, balances: list<array{name: string, available: int, usableFrom: ?CarbonImmutable, earnedSoFar: ?int}>, next: ?array{leave: Leave, days: int}, month: array<string, int>, pending: list<array{leave: Leave, days: int}>}
     */
    public static function for(Employee $employee, User $user): array
    {
        $balances = app(LeaveBalance::class);
        $calculator = app(EntitlementCalculator::class);
        $counter = app(LeaveDayCounter::class);

        $withDays = function (Leave $leave) use ($employee, $counter) {
            $leave->setRelation('employee', $employee);

            return ['leave' => $leave, 'days' => array_sum($counter->countLeave($leave))];
        };
        $next = Leave::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('end_date', '>=', today())
            ->with('leaveType')
            ->orderBy('start_date')
            ->orderByRaw("half = 'pm'")
            ->first();

        return [
            'decided' => LeaveDecisions::since($employee, $user, $user->time_off_seen_at)->all(),
            'next' => $next !== null ? $withDays($next) : null,
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
                ->map($withDays)
                ->all(),
        ];
    }
}
