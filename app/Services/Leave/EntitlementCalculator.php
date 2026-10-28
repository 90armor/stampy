<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\LeaveDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * What a leave type grants an employee for a year — a pure function of
 * join_date (and left_on, for earnedToLastDay()), the type's settings and the
 * year. Writes nothing; LeaveGranter stores what this computes. Amounts are
 * integer tenths of a day (LeaveDays). CLAUDE.md, Phase 3, Policy rules 4–5.
 *
 * - A year's allowance is days_per_year plus, for a seniority type, +1 day per
 *   3 completed years of service as of 31 Dec of that year.
 * - A partial year is pro-rated: allowance × days employed in it (inclusive) ÷
 *   days in that year (366 in a leap year), rounded up to the next half day.
 *   Each year is rounded on its own.
 * - A service requirement (min_service_months) makes the type usable from
 *   join_date + n months (addMonthsNoOverflow: a 29 Feb joiner with 12 months
 *   is eligible on 28 Feb). Nothing is granted for the years before that; the
 *   eligibility year's grant folds in everything earned since joining — the
 *   join year pro-rated, any full years between, and the eligibility year in
 *   full.
 */
class EntitlementCalculator
{
    public function forYear(Employee $employee, LeaveType $type, int $year): Entitlement
    {
        if (! $type->isGrantedYearly()) {
            return Entitlement::noBalance();
        }

        $eligibleOn = $this->eligibleOn($employee, $type);

        if ($year < $eligibleOn->year) {
            return Entitlement::notEligible($eligibleOn);
        }

        $fromYear = $this->hasServiceRequirement($type) && $year === $eligibleOn->year
            ? $employee->join_date->year
            : $year;

        return Entitlement::grant(
            $this->earned($employee, $type, $fromYear, Carbon::create($year, 12, 31)),
            $eligibleOn,
        );
    }

    /**
     * When the type becomes usable: join_date plus the service requirement,
     * or join_date itself for a type without one.
     */
    public function eligibleOn(Employee $employee, LeaveType $type): Carbon
    {
        $join = $employee->join_date->copy()->startOfDay();

        return $this->hasServiceRequirement($type) ? $join->addMonthsNoOverflow($type->min_service_months) : $join;
    }

    /**
     * For a leaver, in the year of their left_on: what they earned up to and
     * including their last day — the same calculation as forYear(), with the
     * year cut at left_on. For someone who leaves before (or in) their
     * eligibility year that folds in every year since joining: the days earned
     * but never usable, which the law still owes them. Display only; nothing
     * acts on it. Null when there is no balance or left_on isn't in $year.
     */
    public function earnedToLastDay(Employee $employee, LeaveType $type, int $year): ?int
    {
        $leftOn = $employee->left_on;

        if (! $type->isGrantedYearly() || $leftOn === null || $leftOn->year !== $year) {
            return null;
        }

        $fromYear = $this->hasServiceRequirement($type) && $this->eligibleOn($employee, $type)->year >= $year
            ? $employee->join_date->year
            : $year;

        return $this->earned($employee, $type, $fromYear, $leftOn);
    }

    /**
     * What someone not yet eligible has earned from joining through $asOf —
     * the same calculation as the eligibility year's grant, cut at $asOf, for
     * Time off's "Usable from 1 Mar 2027 · 15.5 days earned so far" (Phase
     * 3e). Null when there is no balance or the type is already usable.
     */
    public function earnedSoFar(Employee $employee, LeaveType $type, CarbonInterface $asOf): ?int
    {
        if (! $type->isGrantedYearly() || $this->eligibleOn($employee, $type)->lte($asOf)) {
            return null;
        }

        return $this->earned($employee, $type, $employee->join_date->year, $asOf);
    }

    /**
     * Each year from $fromYear to $lastDay's year, from the later of 1 Jan and
     * join_date to the earlier of 31 Dec and $lastDay, pro-rated and rounded
     * on its own.
     */
    private function earned(Employee $employee, LeaveType $type, int $fromYear, CarbonInterface $lastDay): int
    {
        $join = $employee->join_date->copy()->startOfDay();
        $last = Carbon::instance($lastDay)->startOfDay();
        $perYear = LeaveDays::fromDecimal($type->days_per_year);
        $total = 0;

        for ($year = max($fromYear, $join->year); $year <= $last->year; $year++) {
            $from = Carbon::create($year, 1, 1)->max($join);
            $to = $year === $last->year ? $last : Carbon::create($year, 12, 31);

            if ($from->gt($to)) {
                continue;
            }

            $daysEmployed = (int) $from->diffInDays($to) + 1;
            $allowance = $perYear + $this->seniorityBonus($employee, $type, $year);

            $total += LeaveDays::ceilToHalf($allowance * $daysEmployed, Carbon::create($year)->daysInYear);
        }

        return $total;
    }

    /**
     * +1 day per 3 completed years of service, counted as of 31 Dec of $year —
     * the employee-favouring reading. Completed years as of 31 Dec are simply
     * $year − join year, whatever the join date: 31 Dec is the latest date in
     * any year.
     */
    private function seniorityBonus(Employee $employee, LeaveType $type, int $year): int
    {
        if (! $type->seniority_bonus) {
            return 0;
        }

        return intdiv(max(0, $year - $employee->join_date->year), 3) * LeaveDays::DAY;
    }

    private function hasServiceRequirement(LeaveType $type): bool
    {
        return $type->min_service_months !== null && $type->min_service_months > 0;
    }
}
