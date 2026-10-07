<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Services\Leave\Balance;
use App\Services\Leave\LeaveBalance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3b — a balance for (employee, type, year): the stored grant and
 * adjustments, everything else derived (CLAUDE.md, Phase 3, Policy rules 3
 * and 10). Amounts are tenths of a day. Weeks used below are Mon–Fri with no
 * holidays, so a leave's cost is its workday count.
 */
class LeaveBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        WorkSchedule::factory()->create(['is_default' => true]); // Mon–Fri
        // Before any leave type exists, so creation grants nothing automatically.
        $this->employee = Employee::factory()->create(['join_date' => '2020-01-01', 'employee_code' => 'EMP-0100']);
        $this->annual = LeaveType::factory()->create([
            'name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => true, 'carry_over_cap' => 6,
        ]);
    }

    /** Sets the year's grant — replacing the one an employee created mid-test may have been given automatically. */
    private function grant(int $year, string $days, ?Employee $employee = null, ?LeaveType $type = null): void
    {
        LeaveEntitlement::updateOrCreate(
            ['employee_id' => ($employee ?? $this->employee)->id, 'leave_type_id' => ($type ?? $this->annual)->id, 'year' => $year],
            ['days' => $days],
        );
    }

    private function leave(string $from, string $to, LeaveStatus $status = LeaveStatus::Approved, ?LeaveType $type = null, ?Employee $employee = null): Leave
    {
        return Leave::factory()->create([
            'employee_id' => ($employee ?? $this->employee)->id,
            'leave_type_id' => ($type ?? $this->annual)->id,
            'start_date' => $from,
            'end_date' => $to,
            'status' => $status,
            'current_step' => $status === LeaveStatus::Pending ? 1 : null,
        ]);
    }

    private function adjust(int $year, string $days): void
    {
        LeaveAdjustment::factory()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'year' => $year, 'days' => $days]);
    }

    private function balance(int $year, ?Employee $employee = null, ?LeaveType $type = null): Balance
    {
        return app(LeaveBalance::class)->for(($employee ?? $this->employee)->fresh(), $type ?? $this->annual, $year);
    }

    public function test_every_field(): void
    {
        $this->grant(2025, '18.0');
        $this->leave('2025-03-03', '2025-03-21');                        // 15 days approved in 2025 → 3 left → carry 3
        $this->grant(2026, '20.0');
        $this->adjust(2026, '1.0');
        $this->adjust(2026, '-0.5');
        $this->leave('2026-06-01', '2026-06-05');                        // 5 used
        $this->leave('2026-06-08', '2026-06-09', LeaveStatus::Pending);  // 2 pending

        $balance = $this->balance(2026);

        $this->assertTrue($balance->hasBalance);
        $this->assertSame(200, $balance->entitled);
        $this->assertSame(30, $balance->carriedIn);
        $this->assertSame(5, $balance->adjustments);
        $this->assertSame(50, $balance->used);
        $this->assertSame(20, $balance->pending);
        // 20 + 3 + 0.5 − 5 − 2
        $this->assertSame(165, $balance->available());
        // FIFO: the 3 carried days go first.
        $this->assertSame(30, $balance->usedFromCarry);
        $this->assertSame(20, $balance->usedFromGrant);
        $this->assertSame(['Annual' => 50], $balance->usedByType);
        $this->assertSame(['Annual' => 20], $balance->pendingByType);
        $this->assertNull($balance->usableFrom);
        $this->assertNull($balance->earnedToLastDay);
    }

    public function test_pending_reserves_days_and_rejected_or_cancelled_reserve_nothing(): void
    {
        $this->grant(2026, '18.0');
        $this->leave('2026-06-01', '2026-06-02', LeaveStatus::Pending);
        $this->leave('2026-06-08', '2026-06-12', LeaveStatus::Rejected);
        $this->leave('2026-06-15', '2026-06-19', LeaveStatus::Cancelled);

        $balance = $this->balance(2026);

        $this->assertSame(20, $balance->pending);
        $this->assertSame(0, $balance->used);
        $this->assertSame(160, $balance->available());
    }

    public function test_a_leave_across_new_year_costs_each_year_separately(): void
    {
        $this->grant(2025, '18.0');
        $this->grant(2026, '18.0');
        // Mon 29 Dec 2025 – Fri 2 Jan 2026: 3 days in 2025, 2 in 2026.
        $this->leave('2025-12-29', '2026-01-02');

        $this->assertSame(30, $this->balance(2025)->used);
        $this->assertSame(20, $this->balance(2026)->used);
    }

    public function test_a_deducting_type_counts_against_its_target_and_has_no_balance_of_its_own(): void
    {
        $special = LeaveType::factory()->deductsFrom($this->annual)->create(['name' => 'Special', 'max_days_per_request' => 7]);
        $this->grant(2026, '18.0');
        $this->leave('2026-06-01', '2026-06-02', type: $special);
        $this->leave('2026-06-03', '2026-06-03', LeaveStatus::Pending, $special);
        $this->leave('2026-06-08', '2026-06-08');

        $annual = $this->balance(2026);
        $this->assertSame(30, $annual->used);
        $this->assertSame(10, $annual->pending);
        $this->assertSame(['Special' => 20, 'Annual' => 10], $annual->usedByType);
        $this->assertSame(['Special' => 10], $annual->pendingByType);
        $this->assertSame(140, $annual->available());

        $this->assertFalse($this->balance(2026, type: $special)->hasBalance);
    }

    public function test_carry_over_is_capped(): void
    {
        $this->grant(2025, '18.0');
        $this->leave('2025-03-02', '2025-03-13'); // Mon 3 – Fri 13: 9 days → 9 left, cap 6
        $this->grant(2026, '18.0');

        $this->assertSame(60, $this->balance(2026)->carriedIn);
    }

    public function test_a_type_without_a_cap_carries_nothing_and_the_chain_stops_at_a_year_with_no_grant(): void
    {
        $medical = LeaveType::factory()->create(['name' => 'Medical', 'days_per_year' => 30, 'carry_over_cap' => null]);
        $this->grant(2025, '30.0', type: $medical);
        $this->grant(2026, '30.0', type: $medical);
        $this->assertSame(0, $this->balance(2026, type: $medical)->carriedIn);

        // Annual: no 2025 grant, so nothing carries into 2026, whatever came before.
        $this->grant(2024, '18.0');
        $this->grant(2026, '18.0');
        $this->assertSame(0, $this->balance(2026)->carriedIn);
    }

    public function test_the_first_eligible_years_remainder_carries_uncapped_then_the_cap_applies(): void
    {
        $joiner = Employee::factory()->create(['join_date' => '2025-03-01']); // eligible 1 Mar 2026
        $this->grant(2026, '33.5', $joiner);
        $this->leave('2026-06-01', '2026-06-05', employee: $joiner);          // 5 used → 28.5 left
        $this->grant(2027, '18.0', $joiner);
        $this->grant(2028, '18.0', $joiner);

        $this->assertSame(285, $this->balance(2027, $joiner)->carriedIn);
        // 2027 leaves 18 + 28.5 = 46.5 unused; capped at 6 from here on.
        $this->assertSame(60, $this->balance(2028, $joiner)->carriedIn);
    }

    public function test_cancelling_an_approved_leave_last_year_raises_this_years_carry(): void
    {
        $this->grant(2025, '18.0');
        $leave = $this->leave('2025-03-03', '2025-03-24'); // 16 days → 2 left
        $this->grant(2026, '18.0');
        $this->assertSame(20, $this->balance(2026)->carriedIn);

        $leave->update(['status' => LeaveStatus::Cancelled, 'cancelled_at' => now()]);

        $this->assertSame(60, $this->balance(2026)->carriedIn);
    }

    public function test_a_negative_remainder_carries_nothing(): void
    {
        $this->grant(2025, '18.0');
        $this->adjust(2025, '-20.0');
        $this->grant(2026, '18.0');

        $this->assertSame(0, $this->balance(2026)->carriedIn);
        $this->assertSame(180, $this->balance(2026)->available());
    }

    public function test_usable_from_shows_the_eligibility_date_until_it_arrives(): void
    {
        $joiner = Employee::factory()->create(['join_date' => '2025-09-01']); // eligible 1 Sep 2026; today is 15 Jun 2026

        $this->assertSame('2026-09-01', $this->balance(2026, $joiner)->usableFrom->format('Y-m-d'));
        $this->assertNull($this->balance(2026)->usableFrom);
    }

    public function test_a_leavers_earned_to_last_day(): void
    {
        $leaver = Employee::factory()->inactive('2026-03-31')->create(['join_date' => '2020-01-01']);
        // Six completed years by 31 Dec 2026: 20 a year; 20 × 90/365 = 4.93 → 5.0.
        $this->assertSame(50, $this->balance(2026, $leaver)->earnedToLastDay);

        // Left before eligibility: 1 Mar – 31 May 2026, earned but never usable. 18 × 92/365 = 4.54 → 5.0.
        $early = Employee::factory()->inactive('2026-05-31')->create(['join_date' => '2026-03-01']);
        $this->assertSame(50, $this->balance(2026, $early)->earnedToLastDay);
        $this->assertSame(0, $this->balance(2026, $early)->entitled);
    }

    public function test_leave_balance_command_prints_every_type(): void
    {
        LeaveType::factory()->deductsFrom($this->annual)->create(['name' => 'Special']);
        $this->grant(2026, '18.0');
        $this->leave('2026-06-01', '2026-06-03');

        $this->artisan('leave:balance', ['employee' => 'EMP-0100', '--year' => 2026])
            ->expectsOutputToContain('EMP-0100')
            ->expectsTable(
                ['Type', 'Entitled', 'Carried in', 'Adjustments', 'Used', 'Pending', 'Available', 'Notes'],
                [
                    ['Annual', '18', '0', '0', '3', '0', '15', ''],
                    ['Special', '—', '—', '—', '—', '—', '—', 'no balance of its own — counts against Annual'],
                ],
            )
            ->assertSuccessful();

        $this->artisan('leave:balance', ['employee' => 'NOPE'])->expectsOutputToContain('No employee found')->assertFailed();
    }
}
