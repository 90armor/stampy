<?php

namespace App\Services\Leave;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Support\LeaveDays;
use Carbon\CarbonImmutable;

/**
 * An employee's balance of one leave type for one year (CLAUDE.md, Phase 3,
 * Policy rules 3 and 10). Only the grant (leave_entitlements) and admin
 * adjustments are stored; usage, pending reservations and carry-over are
 * computed here every time, so cancelling a leave, adding a holiday or
 * correcting an adjustment changes the balance with no other step.
 *
 * Carry-over into year Y is min(carry_over_cap, what was left of Y−1), where
 * left = entitled + carried in + adjustments − approved usage, never below 0.
 * A type without a cap carries nothing. For a type with a service requirement,
 * the eligibility year's whole remainder carries over uncapped (its grant can
 * arrive with weeks left to use it); the cap applies from the next year on.
 * The chain stops at the first year with no grant.
 */
class LeaveBalance
{
    public function __construct(
        private LeaveDayCounter $counter,
        private EntitlementCalculator $calculator,
    ) {}

    public function for(Employee $employee, LeaveType $type, int $year): Balance
    {
        if ($type->days_per_year === null) {
            return new Balance($type, $year, hasBalance: false);
        }

        $entitlements = LeaveEntitlement::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->get()
            ->mapWithKeys(fn (LeaveEntitlement $row) => [$row->year => LeaveDays::fromDecimal($row->days)])
            ->all();

        $adjustments = LeaveAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->selectRaw('year, sum(days) as total')
            ->groupBy('year')
            ->pluck('total', 'year')
            ->map(fn ($total) => LeaveDays::fromDecimal((string) $total))
            ->all();

        [$approved, $pending, $usedByType, $pendingByType] = $this->usage($employee, $type, $year);

        $eligibleOn = CarbonImmutable::instance($this->calculator->eligibleOn($employee, $type));
        $carriedIn = $this->carriedInto($year, $type, $entitlements, $adjustments, $approved, $eligibleOn);
        $used = $approved[$year] ?? 0;
        $usedFromCarry = min($used, $carriedIn);

        return new Balance(
            type: $type,
            year: $year,
            hasBalance: true,
            entitled: $entitlements[$year] ?? 0,
            carriedIn: $carriedIn,
            adjustments: $adjustments[$year] ?? 0,
            used: $used,
            pending: $pending[$year] ?? 0,
            usedFromCarry: $usedFromCarry,
            usedFromGrant: $used - $usedFromCarry,
            usedByType: $usedByType,
            pendingByType: $pendingByType,
            usableFrom: $eligibleOn->gt(today()) ? $eligibleOn : null,
            earnedToLastDay: $this->calculator->earnedToLastDay($employee, $type, $year),
        );
    }

    /**
     * Approved and pending usage of $type — its own leaves and those of types
     * that deduct from it — per year, plus $year's breakdown by leave type.
     *
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<string, int>, 3: array<string, int>}
     */
    private function usage(Employee $employee, LeaveType $type, int $year): array
    {
        $typeIds = LeaveType::query()->where('deducts_from_leave_type_id', $type->id)->pluck('id')->push($type->id);

        $leaves = Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('leave_type_id', $typeIds)
            ->whereIn('status', [LeaveStatus::Approved->value, LeaveStatus::Pending->value])
            ->with('leaveType')
            ->get();

        $approved = $pending = $usedByType = $pendingByType = [];

        foreach ($leaves as $leave) {
            $leave->setRelation('employee', $employee);
            $isApproved = $leave->status === LeaveStatus::Approved;

            foreach ($this->counter->countLeave($leave) as $leaveYear => $days) {
                if ($isApproved) {
                    $approved[$leaveYear] = ($approved[$leaveYear] ?? 0) + $days;
                } else {
                    $pending[$leaveYear] = ($pending[$leaveYear] ?? 0) + $days;
                }

                if ($leaveYear === $year) {
                    $name = $leave->leaveType->name;

                    if ($isApproved) {
                        $usedByType[$name] = ($usedByType[$name] ?? 0) + $days;
                    } else {
                        $pendingByType[$name] = ($pendingByType[$name] ?? 0) + $days;
                    }
                }
            }
        }

        return [$approved, $pending, $usedByType, $pendingByType];
    }

    /**
     * @param  array<int, int>  $entitlements
     * @param  array<int, int>  $adjustments
     * @param  array<int, int>  $approved
     */
    private function carriedInto(int $year, LeaveType $type, array $entitlements, array $adjustments, array $approved, CarbonImmutable $eligibleOn): int
    {
        if ($type->carry_over_cap === null || ! isset($entitlements[$year - 1])) {
            return 0;
        }

        $previous = $year - 1;
        $left = max(0, $entitlements[$previous]
            + $this->carriedInto($previous, $type, $entitlements, $adjustments, $approved, $eligibleOn)
            + ($adjustments[$previous] ?? 0)
            - ($approved[$previous] ?? 0));

        $firstEligibleYear = $type->min_service_months !== null && $type->min_service_months > 0 && $previous === $eligibleOn->year;

        return $firstEligibleYear ? $left : min(LeaveDays::fromDecimal($type->carry_over_cap), $left);
    }
}
