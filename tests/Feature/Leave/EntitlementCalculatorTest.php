<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveBalanceSource;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\Leave\EntitlementCalculator;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Phase 3b — the grant for (employee, type, year), exact to the tenth
 * (CLAUDE.md, Phase 3, Policy rules 4–5). Pure: unsaved models, no database,
 * and no dependence on the clock.
 */
class EntitlementCalculatorTest extends TestCase
{
    private EntitlementCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new EntitlementCalculator;
    }

    /** Annual as seeded: 18 a year, 12 months' service, seniority bonus. */
    private function annual(): LeaveType
    {
        return new LeaveType(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => true, 'carry_over_cap' => 6]);
    }

    /** A type with no service requirement and no seniority. */
    private function plain(int $daysPerYear = 18): LeaveType
    {
        return new LeaveType(['name' => 'Medical', 'days_per_year' => $daysPerYear, 'min_service_months' => null, 'seniority_bonus' => false]);
    }

    private function joined(string $date, ?string $leftOn = null): Employee
    {
        return new Employee(['join_date' => $date, 'left_on' => $leftOn, 'status' => $leftOn ? 'inactive' : 'active']);
    }

    private function days(Employee $employee, LeaveType $type, int $year): ?int
    {
        return $this->calculator->forYear($employee, $type, $year)->days;
    }

    public function test_the_scopes_1_march_joiner_gets_33_5_days_on_eligibility(): void
    {
        $employee = $this->joined('2026-03-01');

        $before = $this->calculator->forYear($employee, $this->annual(), 2026);
        $this->assertFalse($before->isGrant());
        $this->assertSame('2027-03-01', $before->eligibleOn->format('Y-m-d'));

        $grant = $this->calculator->forYear($employee, $this->annual(), 2027);
        // 18 × 306/365 = 15.09 → 15.5, plus 2027 in full.
        $this->assertSame(335, $grant->days);
        $this->assertSame('2027-03-01', $grant->eligibleOn->format('Y-m-d'));
        $this->assertFalse($grant->isGrantableOn(Carbon::parse('2027-02-28')));
        $this->assertTrue($grant->isGrantableOn(Carbon::parse('2027-03-01')));

        $this->assertSame(180, $this->days($employee, $this->annual(), 2028));
    }

    public function test_a_1_november_joiner_gets_21_5_days_in_the_eligibility_year(): void
    {
        // 18 × 61/365 = 3.008 → 3.5, plus 18.
        $this->assertSame(215, $this->days($this->joined('2026-11-01'), $this->annual(), 2027));
    }

    public function test_a_1_january_joiner_gets_two_full_years_in_the_eligibility_year(): void
    {
        $this->assertNull($this->days($this->joined('2026-01-01'), $this->annual(), 2026));
        $this->assertSame(360, $this->days($this->joined('2026-01-01'), $this->annual(), 2027));
    }

    public function test_a_31_december_joiner_earns_half_a_day_for_their_first_day(): void
    {
        $employee = $this->joined('2026-12-31');

        // 18 × 1/365 = 0.049 → 0.5, plus 18; eligible on 31 Dec 2027.
        $grant = $this->calculator->forYear($employee, $this->annual(), 2027);
        $this->assertSame(185, $grant->days);
        $this->assertSame('2027-12-31', $grant->eligibleOn->format('Y-m-d'));
    }

    public function test_pro_rating_uses_the_real_length_of_a_leap_year(): void
    {
        // 183 days left in the year: 18 × 183/366 = 9.0 exactly in 2028; 18 × 183/365 = 9.02 → 9.5 in 2026.
        $this->assertSame(90, $this->days($this->joined('2028-07-02'), $this->plain(), 2028));
        $this->assertSame(95, $this->days($this->joined('2026-07-02'), $this->plain(), 2026));
        // A 2028 leap-year joiner under the service requirement: 18 × 306/366 = 15.05 → 15.5, plus 18 in 2029.
        $this->assertSame(335, $this->days($this->joined('2028-03-01'), $this->annual(), 2029));
    }

    public function test_a_29_february_joiner_is_eligible_on_28_february(): void
    {
        $this->assertSame('2029-02-28', $this->calculator->eligibleOn($this->joined('2028-02-29'), $this->annual())->format('Y-m-d'));
    }

    public function test_seniority_counts_completed_years_as_of_31_december(): void
    {
        // Exactly 3 years on 31 Dec 2026, and one day short of it.
        $this->assertSame(190, $this->days($this->joined('2023-12-31'), $this->annual(), 2026));
        $this->assertSame(180, $this->days($this->joined('2024-01-01'), $this->annual(), 2026));
        $this->assertSame(190, $this->days($this->joined('2024-01-01'), $this->annual(), 2027));
        // 6 years, and one day short.
        $this->assertSame(200, $this->days($this->joined('2020-12-31'), $this->annual(), 2026));
        $this->assertSame(190, $this->days($this->joined('2021-01-01'), $this->annual(), 2026));
    }

    public function test_a_type_without_a_service_requirement_is_pro_rated_in_the_join_year(): void
    {
        $employee = $this->joined('2026-03-01');

        // 30 × 306/365 = 25.15 → 25.5, usable from the join date.
        $grant = $this->calculator->forYear($employee, $this->plain(30), 2026);
        $this->assertSame(255, $grant->days);
        $this->assertSame('2026-03-01', $grant->eligibleOn->format('Y-m-d'));
        $this->assertSame(300, $this->days($employee, $this->plain(30), 2027));
        $this->assertFalse($this->calculator->forYear($employee, $this->plain(30), 2025)->isGrant());
    }

    public function test_a_type_without_a_yearly_balance_grants_nothing(): void
    {
        $unpaid = new LeaveType(['name' => 'Unpaid', 'balance_source' => LeaveBalanceSource::None, 'days_per_year' => null]);

        $entitlement = $this->calculator->forYear($this->joined('2020-01-01'), $unpaid, 2026);

        $this->assertFalse($entitlement->hasBalance);
        $this->assertFalse($entitlement->isGrant());
    }

    public function test_earned_to_last_day_pro_rates_the_leaving_year(): void
    {
        // An eligible leaver: 18 × 90/365 (1 Jan – 31 Mar) = 4.44 → 4.5.
        $this->assertSame(45, $this->calculator->earnedToLastDay($this->joined('2020-01-01', '2026-03-31'), $this->plain(), 2026));
        // Not the leaving year, or still employed: nothing.
        $this->assertNull($this->calculator->earnedToLastDay($this->joined('2020-01-01', '2026-03-31'), $this->plain(), 2025));
        $this->assertNull($this->calculator->earnedToLastDay($this->joined('2020-01-01'), $this->plain(), 2026));
    }

    public function test_earned_to_last_day_includes_what_a_leaver_before_eligibility_never_could_use(): void
    {
        // Joined 1 Mar 2026, left 31 Jan 2027, a month before eligibility:
        // 2026 pro-rated (15.5) plus 1–31 Jan 2027 (18 × 31/365 = 1.53 → 2.0).
        $this->assertSame(175, $this->calculator->earnedToLastDay($this->joined('2026-03-01', '2027-01-31'), $this->annual(), 2027));
        // Leaving in the join year: join date to last day (18 × 92/365 = 4.54 → 5.0, 1 Mar – 31 May).
        $this->assertSame(50, $this->calculator->earnedToLastDay($this->joined('2026-03-01', '2026-05-31'), $this->annual(), 2026));
    }
}
