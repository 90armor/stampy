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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

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
 *
 * An earned type (LeaveBalanceSource::Earned — Time off in lieu) has no grant:
 * its balance is its adjustments. What overtime earned — the system-posted
 * ones (created_by null, overtime_request_id set) — is shown as its
 * entitlement ("earned from overtime", Phase 4d), and adjustments keeps only
 * the rest (an admin's corrections), so a posted half day doesn't read as a
 * manual HR change; available() is the same either way. Its carry-over
 * chain can't stop at "no grant", since there never is one; it runs back to
 * the employee's first year with an adjustment, and every year after it
 * carries min(cap, what was left) — a quiet year included, so nothing lapses
 * that no one configured to lapse (owner, Phase 4a).
 */
class LeaveBalance
{
    public function __construct(
        private LeaveDayCounter $counter,
        private EntitlementCalculator $calculator,
    ) {}

    public function for(Employee $employee, LeaveType $type, int $year): Balance
    {
        return $this->forMany([$employee], $type, $year)[$employee->id];
    }

    /**
     * for() for many employees at once (the leave report, Approvals), keyed
     * by employee id: every row's stored facts — grants, adjustments, leaves
     * and the holidays they cover — are loaded in one query each for all of
     * them, then each Balance is computed exactly as for() computes it, since
     * for() is this with one employee.
     *
     * @param  iterable<Employee>  $employees
     * @return array<int, Balance>
     */
    public function forMany(iterable $employees, LeaveType $type, int $year): array
    {
        $employees = collect($employees)->keyBy('id');

        if ($employees->isEmpty()) {
            return [];
        }

        $ids = $employees->keys()->all();
        $usage = $this->usage($employees, $type, $year);

        if (! $type->hasBalance()) {
            // No balance to show, but its days still count (Unpaid,
            // Maternity, Special — the leave report's Used, Phase 5b): the
            // same usage count as below, so there's one definition.
            return $employees->map(function (Employee $employee) use ($usage, $type, $year) {
                [$approved, $pending, $usedByType, $pendingByType] = $usage[$employee->id];

                return new Balance(
                    type: $type,
                    year: $year,
                    hasBalance: false,
                    used: $approved[$year] ?? 0,
                    pending: $pending[$year] ?? 0,
                    usedByType: $usedByType,
                    pendingByType: $pendingByType,
                );
            })->all();
        }

        $entitlements = ! $type->isGrantedYearly() ? collect() : LeaveEntitlement::query()
            ->whereIn('employee_id', $ids)
            ->where('leave_type_id', $type->id)
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (LeaveEntitlement $row) => [$row->year => LeaveDays::fromDecimal($row->days)])->all());

        $adjustments = LeaveAdjustment::query()
            ->whereIn('employee_id', $ids)
            ->where('leave_type_id', $type->id)
            ->selectRaw('employee_id, year, sum(days) as total')
            ->groupBy('employee_id', 'year')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->year => LeaveDays::fromDecimal((string) $row->total)])->all());

        // An earned type: this year's system-posted days are its entitlement.
        $earned = ! $type->balance_source->isEarned() ? collect() : LeaveAdjustment::query()
            ->whereIn('employee_id', $ids)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->whereNull('created_by')
            ->whereNotNull('overtime_request_id')
            ->selectRaw('employee_id, sum(days) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id')
            ->map(fn ($total) => LeaveDays::fromDecimal((string) $total));

        return $employees->map(fn (Employee $employee) => $this->balance(
            $employee, $type, $year,
            $entitlements->get($employee->id, []),
            $adjustments->get($employee->id, []),
            $earned->get($employee->id, 0),
            $usage[$employee->id],
        ))->all();
    }

    /**
     * One employee's Balance of a type with a balance, from its stored facts.
     *
     * @param  array<int, int>  $entitlements  grants by year
     * @param  array<int, int>  $adjustments  adjustment totals by year
     * @param  int  $earned  an earned type's system-posted days this year
     * @param  array{0: array<int, int>, 1: array<int, int>, 2: array<string, int>, 3: array<string, int>}  $usage
     */
    private function balance(Employee $employee, LeaveType $type, int $year, array $entitlements, array $adjustments, int $earned, array $usage): Balance
    {
        [$approved, $pending, $usedByType, $pendingByType] = $usage;

        $eligibleOn = CarbonImmutable::instance($this->calculator->eligibleOn($employee, $type));
        $carriedIn = $this->carriedInto($year, $type, $entitlements, $adjustments, $approved, $eligibleOn);
        $used = $approved[$year] ?? 0;
        $usedFromCarry = min($used, $carriedIn);

        return new Balance(
            type: $type,
            year: $year,
            hasBalance: true,
            entitled: ($entitlements[$year] ?? 0) + $earned,
            carriedIn: $carriedIn,
            adjustments: ($adjustments[$year] ?? 0) - $earned,
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
     * that deduct from it — per year, plus $year's breakdown by leave type,
     * for each employee (keyed by id).
     *
     * @param  Collection<int, Employee>  $employees  keyed by id
     * @return array<int, array{0: array<int, int>, 1: array<int, int>, 2: array<string, int>, 3: array<string, int>}>
     */
    private function usage(Collection $employees, LeaveType $type, int $year): array
    {
        $typeIds = LeaveType::query()->where('deducts_from_leave_type_id', $type->id)->pluck('id')->push($type->id);

        $leaves = Leave::query()
            ->whereIn('employee_id', $employees->keys())
            ->whereIn('leave_type_id', $typeIds)
            ->whereIn('status', [LeaveStatus::Approved->value, LeaveStatus::Pending->value])
            ->with('leaveType')
            ->get();
        $holidays = LeaveDayCounter::holidaysFor($leaves);
        $byEmployee = $leaves->groupBy('employee_id');
        // The counter reads each date's schedule (Employee::scheduleOn()).
        EloquentCollection::make($employees->only($byEmployee->keys()->all())->values())->loadMissing('scheduleAssignments');

        return $employees->map(function (Employee $employee) use ($byEmployee, $holidays, $year) {
            $approved = $pending = $usedByType = $pendingByType = [];

            foreach ($byEmployee->get($employee->id, []) as $leave) {
                $leave->setRelation('employee', $employee);
                $isApproved = $leave->status === LeaveStatus::Approved;

                foreach ($this->counter->countLeave($leave, $holidays) as $leaveYear => $days) {
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
        })->all();
    }

    /**
     * @param  array<int, int>  $entitlements
     * @param  array<int, int>  $adjustments
     * @param  array<int, int>  $approved
     */
    private function carriedInto(int $year, LeaveType $type, array $entitlements, array $adjustments, array $approved, CarbonImmutable $eligibleOn): int
    {
        $previous = $year - 1;
        $inChain = $type->isGrantedYearly()
            ? isset($entitlements[$previous])
            : $adjustments !== [] && $previous >= min(array_keys($adjustments));

        if ($type->carry_over_cap === null || ! $inChain) {
            return 0;
        }

        $left = max(0, ($entitlements[$previous] ?? 0)
            + $this->carriedInto($previous, $type, $entitlements, $adjustments, $approved, $eligibleOn)
            + ($adjustments[$previous] ?? 0)
            - ($approved[$previous] ?? 0));

        $firstEligibleYear = $type->min_service_months !== null && $type->min_service_months > 0 && $previous === $eligibleOn->year;

        return $firstEligibleYear ? $left : min(LeaveDays::fromDecimal($type->carry_over_cap), $left);
    }
}
