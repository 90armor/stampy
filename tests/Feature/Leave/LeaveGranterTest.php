<?php

namespace Tests\Feature\Leave;

use App\Console\LeaveSchedule;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveGranter;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3b — granting: leave:grant, the creation grant, the join_date
 * re-grant, and the schedule (CLAUDE.md, Phase 3, Grants).
 */
class LeaveGranterTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private LeaveType $medical;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => true, 'carry_over_cap' => 6]);
        $this->medical = LeaveType::factory()->create(['name' => 'Medical', 'days_per_year' => 30]);
        LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid', 'is_paid' => false]);
    }

    /** @return array<string, string> leave type name → days, for one year */
    private function grants(Employee $employee, int $year): array
    {
        return LeaveEntitlement::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('leaveType')
            ->get()
            ->mapWithKeys(fn (LeaveEntitlement $row) => [$row->leaveType->name => $row->days])
            ->sortKeys()
            ->all();
    }

    // ── Creation ───────────────────────────────────────────────────────

    public function test_a_backdated_new_employee_gets_a_full_year_plus_seniority_at_creation(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);

        // Six completed years by 31 Dec 2026: 18 + 2. No grant for a type without a balance.
        $this->assertSame(['Annual' => '20.0', 'Medical' => '30.0'], $this->grants($employee, 2026));
    }

    public function test_someone_joining_today_gets_pro_rated_medical_and_no_annual_yet(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2026-04-15']);

        // 30 × 261/365 = 21.45 → 21.5; Annual waits for 15 Apr 2027.
        $this->assertSame(['Medical' => '21.5'], $this->grants($employee, 2026));
    }

    public function test_creation_with_no_leave_types_grants_nothing_and_does_not_throw(): void
    {
        LeaveType::query()->delete();

        $employee = Employee::factory()->create();

        $this->assertTrue($employee->exists);
        $this->assertSame(0, LeaveEntitlement::count());
    }

    public function test_a_failed_creation_grant_leaves_no_employee_and_no_grant(): void
    {
        $this->mock(LeaveGranter::class, fn ($mock) => $mock->shouldReceive('grant')->andThrow(new RuntimeException('grant failed')));

        try {
            Employee::factory()->create(['employee_code' => 'EMP-9999']);
            $this->fail('The grant failure must surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('grant failed', $e->getMessage());
        }

        $this->assertFalse(Employee::where('employee_code', 'EMP-9999')->exists());
        $this->assertSame(0, LeaveEntitlement::count());
    }

    // ── leave:grant ────────────────────────────────────────────────────

    public function test_1_january_creates_the_regular_grants_and_a_second_run_creates_nothing(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);

        $this->travelTo(Carbon::parse('2027-01-01 00:05:00'));
        $this->artisan('leave:grant')
            ->expectsOutputToContain('Granted 2027 leave as of 2027-01-01')
            ->expectsOutputToContain('Annual: 1 created')
            ->expectsOutputToContain('Medical: 1 created')
            ->assertSuccessful();

        // Seven completed years by 31 Dec 2027: 18 + 2.
        $this->assertSame(['Annual' => '20.0', 'Medical' => '30.0'], $this->grants($employee, 2027));

        $this->artisan('leave:grant')->expectsOutputToContain('Nothing to grant')->assertSuccessful();
        $this->assertSame(2, LeaveEntitlement::where('year', 2027)->count());
    }

    public function test_the_first_eligibility_grant_arrives_on_the_eligibility_date_and_a_missed_run_catches_up(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2026-03-01']);

        $this->travelTo(Carbon::parse('2027-02-28 00:05:00'));
        $this->artisan('leave:grant')->assertSuccessful();
        $this->assertSame(['Medical' => '30.0'], $this->grants($employee, 2027));

        $this->travelTo(Carbon::parse('2027-03-01 00:05:00'));
        $this->artisan('leave:grant')->expectsOutputToContain('Annual: 1 created')->assertSuccessful();
        // 15.5 for 2026 folded in, plus 18.
        $this->assertSame(['Annual' => '33.5', 'Medical' => '30.0'], $this->grants($employee, 2027));

        // Someone whose eligibility date passed while the task wasn't running.
        $missed = Employee::factory()->create(['join_date' => '2026-03-02']);
        $this->travelTo(Carbon::parse('2027-03-09 00:05:00'));
        $this->artisan('leave:grant')->assertSuccessful();
        $this->assertSame('33.5', $this->grants($missed, 2027)['Annual']);
    }

    public function test_inactive_employees_are_skipped(): void
    {
        $gone = Employee::factory()->inactive('2026-03-31')->create(['join_date' => '2020-01-01']);

        $this->artisan('leave:grant')->assertSuccessful();

        $this->assertSame([], $this->grants($gone, 2026));
    }

    public function test_next_year_can_be_granted_early_but_not_to_anyone_leaving(): void
    {
        $staying = Employee::factory()->create(['join_date' => '2020-01-01']);
        $leaving = Employee::factory()->inactive('2026-04-15')->create(['join_date' => '2020-01-01']); // last day today
        $notYetEligible = Employee::factory()->create(['join_date' => '2026-02-01']);                  // eligible 1 Feb 2027

        $this->artisan('leave:grant', ['--year' => 2027])->assertSuccessful();

        $this->assertSame(['Annual' => '20.0', 'Medical' => '30.0'], $this->grants($staying, 2027));
        $this->assertSame([], $this->grants($leaving, 2027));
        $this->assertSame(['Medical' => '30.0'], $this->grants($notYetEligible, 2027));
    }

    public function test_a_past_year_is_refused(): void
    {
        $this->artisan('leave:grant', ['--year' => 2025])->expectsOutputToContain('in the past')->assertFailed();
    }

    // ── join_date correction ───────────────────────────────────────────

    public function test_a_join_date_correction_regrants_automatic_rows_and_leaves_the_rest_alone(): void
    {
        $admin = User::factory()->create();
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $this->assertSame('20.0', $this->grants($employee, 2026)['Annual']);

        // An earlier year's grant, an admin-entered grant and an adjustment — none of them recomputed.
        LeaveEntitlement::create(['employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2025, 'days' => '19.0']);
        LeaveEntitlement::where('employee_id', $employee->id)->where('leave_type_id', $this->medical->id)->where('year', 2026)
            ->update(['days' => '25.0', 'granted_by' => $admin->id]);
        LeaveAdjustment::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'year' => 2026, 'days' => '2.0']);

        $employee->update(['join_date' => '2025-01-01']);

        // Eligible 1 Jan 2026: 2025 in full folded in, plus 2026 → 36.
        $this->assertSame(['Annual' => '36.0', 'Medical' => '25.0'], $this->grants($employee, 2026));
        $this->assertSame('19.0', $this->grants($employee, 2025)['Annual']);
        $this->assertSame(1, LeaveAdjustment::where('employee_id', $employee->id)->count());
    }

    public function test_a_join_date_correction_that_makes_someone_not_yet_eligible_removes_the_automatic_grant(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);

        $employee->update(['join_date' => '2026-01-05']);

        // Annual now waits for 5 Jan 2027; Medical is pro-rated from the new date (30 × 361/365 = 29.67 → 30.0).
        $this->assertSame(['Medical' => '30.0'], $this->grants($employee, 2026));
    }

    // ── Schedule ───────────────────────────────────────────────────────

    public function test_leave_grant_is_scheduled_daily_at_00_05_without_overlapping(): void
    {
        $schedule = new Schedule;
        LeaveSchedule::register($schedule);

        $events = array_values(array_filter($schedule->events(), fn (Event $e) => str_contains($e->command, 'leave:grant')));

        $this->assertCount(1, $events);
        $this->assertSame('5 0 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);

        // And the real application schedule registers it.
        $this->artisan('schedule:list')->assertSuccessful();
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn (Event $e) => str_contains($e->command, 'leave:grant')));
    }
}
