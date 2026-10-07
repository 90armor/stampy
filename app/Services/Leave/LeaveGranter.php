<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Support\LeaveDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The one place a grant (leave_entitlements row) is created automatically —
 * by leave:grant (daily) and by Employee::booted() when an employee is
 * created or their join_date is corrected. For an employee, a year and an
 * as-of date it creates each missing grant the employee is eligible for,
 * for every active type with a yearly balance, at what EntitlementCalculator
 * says. Idempotent: existing rows are skipped, so a missed day is caught up
 * the next time it runs ("eligible and no row", not "today is the day").
 *
 * Skipped: an employee not active on $asOf (Employee::isActiveOn()); a year
 * after $asOf's for anyone with a left_on (an early grant for next year is
 * only for people who'll still be here); a type whose eligibility date is
 * after $asOf.
 */
class LeaveGranter
{
    public function __construct(private EntitlementCalculator $calculator) {}

    /**
     * @return array<string, int> grants created, keyed by leave type name
     */
    public function grant(Employee $employee, int $year, CarbonInterface $asOf): array
    {
        if (! $employee->isActiveOn($asOf) || ($employee->left_on !== null && $year > $asOf->year)) {
            return [];
        }

        $existing = LeaveEntitlement::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->pluck('leave_type_id')
            ->all();

        $created = [];

        foreach ($this->grantableTypes() as $type) {
            if (in_array($type->id, $existing, true)) {
                continue;
            }

            $entitlement = $this->calculator->forYear($employee, $type, $year);

            if (! $entitlement->isGrantableOn($asOf)) {
                continue;
            }

            LeaveEntitlement::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'days' => LeaveDays::toDecimal($entitlement->days),
                'granted_by' => null,
            ]);

            $created[$type->name] = 1;
        }

        return $created;
    }

    /**
     * After a join_date correction: the automatic grants (granted_by null) for
     * the current and later years were computed from the old date, so they're
     * deleted and granted again. Earlier years, admin-entered grants and
     * adjustments are left alone. A leaver is re-granted as of their last day,
     * the last date they were active. Runs inside the employee's save
     * transaction (Employee::save()).
     */
    public function regrantAfterJoinDateChange(Employee $employee): void
    {
        $thisYear = today()->year;
        $automatic = LeaveEntitlement::query()
            ->where('employee_id', $employee->id)
            ->whereNull('granted_by')
            ->where('year', '>=', $thisYear);

        $years = (clone $automatic)->distinct()->pluck('year')->push($thisYear)->unique()->sort()->values();
        $automatic->delete();

        $asOf = $employee->left_on !== null && $employee->left_on->lt(today()) ? $employee->left_on : today();

        foreach ($years as $year) {
            $this->grant($employee, $year, Carbon::instance($asOf));
        }
    }

    /**
     * @return Collection<int, LeaveType>
     */
    private function grantableTypes()
    {
        return LeaveType::query()
            ->where('is_active', true)
            ->whereNotNull('days_per_year')
            ->orderBy('id')
            ->get();
    }
}
