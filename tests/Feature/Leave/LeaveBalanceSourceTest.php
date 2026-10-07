<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveBalanceSource;
use App\Enums\LeaveStatus;
use App\Exceptions\InvalidLeaveTypeException;
use App\Exceptions\LeaveTypeLockedException;
use App\Livewire\LeaveTypes\Index;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\Balance;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveGranter;
use App\Support\LeaveBalanceSourceBackfill;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4a — leave_types.balance_source: yearly (granted each year), earned
 * (a balance built from adjustments only — Time off in lieu) or none.
 * Amounts are tenths of a day.
 */
class LeaveBalanceSourceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        WorkSchedule::factory()->create(['is_default' => true]); // Mon–Fri
        $this->employee = Employee::factory()->create(['join_date' => '2020-01-01']);
    }

    private function earned(?string $cap = null): LeaveType
    {
        return LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => $cap]);
    }

    private function adjust(LeaveType $type, int $year, string $days): void
    {
        LeaveAdjustment::factory()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'year' => $year, 'days' => $days]);
    }

    private function take(LeaveType $type, string $date): void
    {
        Leave::factory()->create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
            'start_date' => $date, 'end_date' => $date, 'status' => LeaveStatus::Approved, 'current_step' => null,
        ]);
    }

    private function balance(LeaveType $type, int $year): Balance
    {
        return app(LeaveBalance::class)->for($this->employee->fresh(), $type, $year);
    }

    // ── Invariants ─────────────────────────────────────────────────────

    public function test_a_yearly_type_needs_days_per_year(): void
    {
        $this->expectException(InvalidLeaveTypeException::class);
        $this->expectExceptionMessage('A yearly balance needs days per year.');

        LeaveType::factory()->create(['balance_source' => LeaveBalanceSource::Yearly, 'days_per_year' => null]);
    }

    public function test_earned_and_no_balance_types_have_no_yearly_grant_options(): void
    {
        $cases = [
            [LeaveBalanceSource::Earned, ['days_per_year' => 5], 'a balance earned from overtime has no yearly grant'],
            [LeaveBalanceSource::Earned, ['seniority_bonus' => true], 'need a yearly balance'],
            [LeaveBalanceSource::Earned, ['min_service_months' => 12], 'need a yearly balance'],
            [LeaveBalanceSource::None, ['days_per_year' => 5], 'a type with no balance has no yearly grant'],
            [LeaveBalanceSource::None, ['seniority_bonus' => true], 'need a yearly balance'],
            [LeaveBalanceSource::None, ['min_service_months' => 12], 'need a yearly balance'],
            [LeaveBalanceSource::None, ['carry_over_cap' => 3], 'A type with no balance can\'t carry over'],
        ];

        foreach ($cases as [$source, $fields, $message]) {
            $state = $source === LeaveBalanceSource::Earned ? 'earned' : 'withoutBalance';

            try {
                LeaveType::factory()->{$state}()->create($fields);
                $this->fail("{$source->value} accepted ".json_encode($fields));
            } catch (InvalidLeaveTypeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        // An earned balance may carry over.
        $this->assertSame('3.0', LeaveType::factory()->earned()->create(['carry_over_cap' => 3])->carry_over_cap);
    }

    public function test_changing_the_source_is_checked_against_the_fields_it_leaves_behind(): void
    {
        $annual = LeaveType::factory()->create(['days_per_year' => 18]);

        $this->expectException(InvalidLeaveTypeException::class);

        $annual->update(['balance_source' => LeaveBalanceSource::Earned]);
    }

    public function test_the_source_locks_once_leave_is_taken_with_the_type_and_not_before(): void
    {
        $type = LeaveType::factory()->create();
        LeaveEntitlement::factory()->for($type)->forYear(2020)->create();
        LeaveAdjustment::factory()->for($type)->create();

        // An entitlement or adjustment alone doesn't lock it.
        $type->update(['balance_source' => LeaveBalanceSource::None, 'days_per_year' => null]);
        $this->assertSame(LeaveBalanceSource::None, $type->fresh()->balance_source);

        Leave::factory()->for($type)->create();

        $this->expectException(LeaveTypeLockedException::class);
        $this->expectExceptionMessage('where its balance comes from');

        $type->fresh()->update(['balance_source' => LeaveBalanceSource::Yearly, 'days_per_year' => 10]);
    }

    // ── Backfill ───────────────────────────────────────────────────────

    public function test_the_backfill_follows_days_per_year_exactly(): void
    {
        $annual = LeaveType::factory()->create(['days_per_year' => 18]);
        $unpaid = LeaveType::factory()->withoutBalance()->create();
        // As if the column had just been added: its default on every row.
        DB::table('leave_types')->update(['balance_source' => 'earned']);

        LeaveBalanceSourceBackfill::run();

        $this->assertSame(LeaveBalanceSource::Yearly, $annual->fresh()->balance_source);
        $this->assertSame(LeaveBalanceSource::None, $unpaid->fresh()->balance_source);
    }

    // ── Granting and balances ──────────────────────────────────────────

    public function test_the_granter_skips_earned_and_no_balance_types(): void
    {
        $annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $toil = $this->earned('6');
        $unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid']);

        $created = app(LeaveGranter::class)->grant($this->employee, 2026, today());

        $this->assertSame(['Annual' => 1], $created);
        $this->assertSame(0, LeaveEntitlement::whereIn('leave_type_id', [$toil->id, $unpaid->id])->count());
        $this->assertTrue(LeaveEntitlement::where('leave_type_id', $annual->id)->exists());

        // A new employee's creation grants go through the same granter.
        $joiner = Employee::factory()->create(['join_date' => '2026-06-01']);
        $this->assertSame([$annual->id], $joiner->leaveEntitlements()->pluck('leave_type_id')->all());
    }

    public function test_an_earned_balance_is_its_adjustments_less_its_usage(): void
    {
        $toil = $this->earned();
        $this->adjust($toil, 2026, '1.5');
        $this->adjust($toil, 2026, '0.5');
        $this->take($toil, '2026-06-08'); // a Monday

        $balance = $this->balance($toil, 2026);

        $this->assertTrue($balance->hasBalance);
        $this->assertSame(0, $balance->entitled);
        $this->assertSame(20, $balance->adjustments);
        $this->assertSame(10, $balance->used);
        $this->assertSame(10, $balance->available());
        $this->assertNull($balance->usableFrom);
        $this->assertNull($balance->earnedToLastDay);
    }

    public function test_an_earned_balance_carries_over_two_years_with_the_cap_applied_each_year(): void
    {
        $toil = $this->earned('2.5');

        // 2024: 4 earned, nothing used → 2.5 carries (capped).
        $this->adjust($toil, 2024, '4');
        // 2025: 2.5 carried + 2 earned − 1 used = 3.5 left → 2.5 carries (capped again).
        $this->adjust($toil, 2025, '2');
        $this->take($toil, '2025-03-03'); // a Monday

        $this->assertSame(25, $this->balance($toil, 2025)->carriedIn);
        $this->assertSame(35, $this->balance($toil, 2025)->available());

        $balance2026 = $this->balance($toil, 2026);
        $this->assertSame(25, $balance2026->carriedIn);
        $this->assertSame(0, $balance2026->entitled);
        $this->assertSame(25, $balance2026->available());
    }

    /**
     * The chain runs back to the first year with an adjustment: a year with
     * only usage, or with nothing at all, still carries min(cap, what's left).
     */
    public function test_an_earned_carry_runs_through_quiet_years_back_to_the_first_adjustment(): void
    {
        $toil = $this->earned('2.5');

        // 2023: nothing yet — before the first adjustment, so nothing carries out of it.
        // 2024: 4 earned → 2.5 carries (capped).
        $this->adjust($toil, 2024, '4');
        // 2025: only usage — 2.5 carried − 1 used = 1.5 carries on.
        $this->take($toil, '2025-03-03'); // a Monday
        // 2026: 1 earned → 1.5 + 1 = 2.5 available.
        $this->adjust($toil, 2026, '1');

        $this->assertSame(0, $this->balance($toil, 2024)->carriedIn);
        $this->assertSame(25, $this->balance($toil, 2025)->carriedIn);
        $this->assertSame(15, $this->balance($toil, 2026)->carriedIn);
        $this->assertSame(25, $this->balance($toil, 2026)->available());

        // A completely quiet year carries too: 2027 has no activity, 2028 still gets 2.5.
        $this->assertSame(25, $this->balance($toil, 2027)->carriedIn);
        $this->assertSame(25, $this->balance($toil, 2028)->carriedIn);
    }

    public function test_an_earned_type_without_a_cap_carries_nothing(): void
    {
        $toil = $this->earned();
        $this->adjust($toil, 2025, '3');

        $this->assertSame(0, $this->balance($toil, 2026)->carriedIn);
    }

    // ── Policies → Leave types ─────────────────────────────────────────

    public function test_the_form_shows_only_the_fields_the_chosen_balance_has_and_saves_only_those(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)->test(Index::class)
            ->call('create')
            ->assertSee('Days per year')
            ->set('balance_source', 'earned')
            ->assertDontSee('Days per year')
            ->assertDontSee('Usable after')
            ->assertSee('Carry over up to')
            ->set('balance_source', 'none')
            ->assertDontSee('Carry over up to')
            ->set('balance_source', 'yearly')
            ->set('name', 'Study')
            ->set('days_per_year', '')
            ->call('save')
            ->assertHasErrors(['days_per_year' => 'required_if'])
            ->assertSee('A yearly balance needs days per year.');

        // Values typed before switching to a source that hides them aren't saved.
        Livewire::actingAs($admin)->test(Index::class)
            ->call('create')
            ->set('name', 'Lieu')
            ->set('days_per_year', '5')
            ->set('seniority_bonus', true)
            ->set('carry_over_cap', '3')
            ->set('balance_source', 'earned')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Earned from overtime · carry over up to 3 days');

        $lieu = LeaveType::where('name', 'Lieu')->sole();
        $this->assertSame([LeaveBalanceSource::Earned, null, false, '3.0'], [$lieu->balance_source, $lieu->days_per_year, $lieu->seniority_bonus, $lieu->carry_over_cap]);
    }
}
