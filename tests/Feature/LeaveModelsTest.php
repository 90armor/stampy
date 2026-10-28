<?php

namespace Tests\Feature;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveCounting;
use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Exceptions\AppendOnlyRecordException;
use App\Exceptions\InvalidLeaveException;
use App\Exceptions\InvalidLeaveTypeException;
use App\Exceptions\LeaveTypeInUseException;
use App\Exceptions\LeaveTypeLockedException;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3a — the leave tables' structure: model invariants, the lock and
 * deletion rules on LeaveType, append-only adjustments and approval steps,
 * the morph map, and the leave-type seed data. Request rules (overlap,
 * balance, approval) are Phase 3c.
 */
class LeaveModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo('2026-04-15 12:00:00');
        WorkSchedule::factory()->create(['is_default' => true]);
    }

    // ── Leave ───────────────────────────────────────────────────────────

    public function test_a_leave_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidLeaveException::class);
        $this->expectExceptionMessage('end before it starts');

        Leave::factory()->between('2026-04-10', '2026-04-09')->create();
    }

    public function test_a_one_day_and_a_multi_day_leave_are_valid(): void
    {
        $one = Leave::factory()->between('2026-04-10', '2026-04-10')->create();
        $many = Leave::factory()->between('2026-04-10', '2026-04-14')->create();

        $this->assertSame('2026-04-10', $one->fresh()->end_date->format('Y-m-d'));
        $this->assertSame('2026-04-14', $many->fresh()->end_date->format('Y-m-d'));
    }

    public function test_a_half_day_covers_a_single_date(): void
    {
        $leave = Leave::factory()->between('2026-04-10', '2026-04-10')->halfDay('pm')->create();

        $this->assertSame(LeaveHalf::Pm, $leave->fresh()->half);

        $this->expectException(InvalidLeaveException::class);
        $this->expectExceptionMessage('single date');

        $leave->update(['end_date' => '2026-04-13']);
    }

    public function test_a_half_day_needs_a_type_that_allows_one(): void
    {
        $maternity = LeaveType::factory()->withoutBalance()->calendarDays()->withoutHalfDays()->create(['name' => 'Maternity']);

        $this->expectException(InvalidLeaveException::class);
        $this->expectExceptionMessage("Maternity leave can't be taken as a half day.");

        Leave::factory()->for($maternity)->halfDay('am')->create();
    }

    public function test_leave_casts_and_relations(): void
    {
        $employee = Employee::factory()->create();
        $leave = Leave::factory()->for($employee)->approved()->create();

        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
        $this->assertNull($leave->fresh()->current_step);
        $this->assertTrue($employee->leaves->first()->is($leave));
        $this->assertSame(LeaveStatus::Cancelled, Leave::factory()->cancelled()->create()->fresh()->status);
        $this->assertSame(LeaveStatus::Rejected, Leave::factory()->rejected()->create()->fresh()->status);
    }

    // ── Approval steps and the morph map ───────────────────────────────

    public function test_approval_steps_store_the_morph_alias_and_load_in_step_order(): void
    {
        $leave = Leave::factory()->create();
        ApprovalStep::factory()->for($leave, 'approvable')->step(2)->create();
        ApprovalStep::factory()->for($leave, 'approvable')->step(1)->skipped()->create();

        $this->assertSame(['leave'], DB::table('approval_steps')->distinct()->pluck('approvable_type')->all());
        $this->assertSame([1, 2], $leave->approvalSteps->pluck('step')->all());
        $this->assertSame(ApprovalOutcome::Skipped, $leave->approvalSteps->first()->outcome);
        $this->assertTrue($leave->approvalSteps->first()->approvable->is($leave));
    }

    public function test_a_step_is_recorded_once_per_request(): void
    {
        $leave = Leave::factory()->create();
        ApprovalStep::factory()->for($leave, 'approvable')->step(1)->create();

        $this->expectException(QueryException::class);

        ApprovalStep::factory()->for($leave, 'approvable')->step(1)->create();
    }

    public function test_approval_steps_are_append_only(): void
    {
        $step = ApprovalStep::factory()->create();

        try {
            $step->update(['outcome' => ApprovalOutcome::Rejected]);
            $this->fail('An approval step must not be updated.');
        } catch (AppendOnlyRecordException $e) {
            $this->assertStringContainsString("can't be updated", $e->getMessage());
        }

        try {
            $step->delete();
            $this->fail('An approval step must not be deleted.');
        } catch (AppendOnlyRecordException $e) {
            $this->assertStringContainsString("can't be deleted", $e->getMessage());
        }

        $this->assertSame(ApprovalOutcome::Approved, $step->fresh()->outcome);
    }

    // ── Entitlements and adjustments ───────────────────────────────────

    public function test_one_entitlement_per_employee_type_and_year(): void
    {
        $entitlement = LeaveEntitlement::factory()->forYear(2026)->create(['days' => 15.5]);

        $this->assertSame('15.5', $entitlement->fresh()->days);
        $this->assertTrue($entitlement->employee->leaveEntitlements->first()->is($entitlement));
        LeaveEntitlement::factory()->forYear(2027)->create(['employee_id' => $entitlement->employee_id, 'leave_type_id' => $entitlement->leave_type_id]);

        $this->expectException(QueryException::class);

        LeaveEntitlement::factory()->forYear(2026)->create(['employee_id' => $entitlement->employee_id, 'leave_type_id' => $entitlement->leave_type_id]);
    }

    public function test_leave_adjustments_are_append_only(): void
    {
        $adjustment = LeaveAdjustment::factory()->create(['days' => -1.5]);

        $this->assertSame('-1.5', $adjustment->fresh()->days);

        try {
            $adjustment->update(['days' => 2]);
            $this->fail('An adjustment must not be updated.');
        } catch (AppendOnlyRecordException) {
        }

        try {
            $adjustment->delete();
            $this->fail('An adjustment must not be deleted.');
        } catch (AppendOnlyRecordException) {
        }

        // The correction is a reversing adjustment.
        LeaveAdjustment::factory()->create([
            'employee_id' => $adjustment->employee_id,
            'leave_type_id' => $adjustment->leave_type_id,
            'year' => $adjustment->year,
            'days' => 1.5,
            'note' => 'Reverses the wrong correction',
        ]);

        $this->assertSame(0.0, (float) $adjustment->employee->leaveAdjustments()->sum('days'));
    }

    // ── LeaveType ──────────────────────────────────────────────────────

    public function test_a_type_without_a_balance_cannot_carry_over_or_earn_seniority(): void
    {
        foreach ([['carry_over_cap' => 3], ['seniority_bonus' => true]] as $option) {
            try {
                LeaveType::factory()->withoutBalance()->create($option);
                $this->fail('Balance options need a balance: '.json_encode($option));
            } catch (InvalidLeaveTypeException $e) {
                $this->assertStringContainsString('need a yearly balance', $e->getMessage());
            }
        }

        $type = LeaveType::factory()->create(['carry_over_cap' => 6, 'seniority_bonus' => true]);

        $this->expectException(InvalidLeaveTypeException::class);

        $type->update(['days_per_year' => null]);
    }

    public function test_a_type_cannot_deduct_from_itself(): void
    {
        $type = LeaveType::factory()->withoutBalance()->create();

        $this->expectException(InvalidLeaveTypeException::class);
        $this->expectExceptionMessage('deduct from itself');

        $type->update(['deducts_from_leave_type_id' => $type->id]);
    }

    public function test_deductions_are_one_level_deep_in_either_direction(): void
    {
        $annual = LeaveType::factory()->create(['name' => 'Annual']);
        $special = LeaveType::factory()->deductsFrom($annual)->create(['name' => 'Special']);

        $this->assertTrue($special->deductsFrom->is($annual));
        $this->assertTrue($annual->deductedBy->first()->is($special));

        // Deducting from a type that itself deducts.
        try {
            LeaveType::factory()->deductsFrom($special)->create();
            $this->fail('A deduction chain must be rejected.');
        } catch (InvalidLeaveTypeException $e) {
            $this->assertStringContainsString('deduct only from a type', $e->getMessage());
        }

        // A type others deduct from can't start deducting itself.
        $medical = LeaveType::factory()->create(['name' => 'Medical']);

        $this->expectException(InvalidLeaveTypeException::class);

        $annual->update(['deducts_from_leave_type_id' => $medical->id]);
    }

    public function test_counts_deductions_and_half_days_lock_once_a_leave_uses_the_type(): void
    {
        $annual = LeaveType::factory()->create();
        $type = LeaveType::factory()->create();
        Leave::factory()->for($type)->create();

        foreach ([
            ['counts' => LeaveCounting::CalendarDays],
            ['allows_half_day' => false],
            ['deducts_from_leave_type_id' => $annual->id, 'days_per_year' => null],
        ] as $change) {
            try {
                $type->fresh()->update($change);
                $this->fail('Locked: '.json_encode($change));
            } catch (LeaveTypeLockedException) {
            }
        }

        // Everything else stays editable — days_per_year affects future grants only.
        $type->update(['name' => 'Renamed', 'days_per_year' => 20, 'is_active' => false, 'max_days_per_request' => 5]);
        $this->assertSame('20.0', $type->fresh()->days_per_year);
    }

    /**
     * The lock looks at leaves only: an entitlement or adjustment alone
     * doesn't depend on how the type counts days.
     */
    public function test_entitlements_and_adjustments_alone_do_not_lock_a_type(): void
    {
        $type = LeaveType::factory()->create();
        LeaveEntitlement::factory()->for($type)->forYear(2020)->create();
        LeaveAdjustment::factory()->for($type)->create();

        $type->update(['counts' => LeaveCounting::CalendarDays, 'allows_half_day' => false]);

        $this->assertSame(LeaveCounting::CalendarDays, $type->fresh()->counts);
    }

    public function test_a_type_anything_points_at_cannot_be_deleted(): void
    {
        $referenced = [
            'a leave' => fn (LeaveType $type) => Leave::factory()->for($type)->create(),
            'an entitlement' => fn (LeaveType $type) => LeaveEntitlement::factory()->for($type)->forYear(2020)->create(),
            'an adjustment' => fn (LeaveType $type) => LeaveAdjustment::factory()->for($type)->create(),
            'a deducting type' => fn (LeaveType $type) => LeaveType::factory()->deductsFrom($type)->create(),
        ];

        foreach ($referenced as $what => $reference) {
            $type = LeaveType::factory()->create();
            $reference($type);

            try {
                $type->delete();
                $this->fail("A type with {$what} must not be deleted.");
            } catch (LeaveTypeInUseException $e) {
                $this->assertStringContainsString('Deactivate it instead', $e->getMessage());
            }

            $this->assertTrue(LeaveType::whereKey($type->id)->exists());
        }

        $unused = LeaveType::factory()->create();
        $unused->delete();
        $this->assertFalse(LeaveType::whereKey($unused->id)->exists());
    }

    // ── Seed data ──────────────────────────────────────────────────────

    public function test_the_leave_type_seeder_is_idempotent_and_matches_the_scope(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        // Time off in lieu since Phase 4a (OvertimeModelsTest checks its settings).
        $this->assertSame(['Annual', 'Maternity', 'Medical', 'Special', 'Time off in lieu', 'Unpaid'], LeaveType::orderBy('name')->pluck('name')->all());

        $types = LeaveType::all()->keyBy('name');

        $this->assertSame('18.0', $types['Annual']->days_per_year);
        $this->assertSame(12, $types['Annual']->min_service_months);
        $this->assertTrue($types['Annual']->seniority_bonus);
        $this->assertSame('6.0', $types['Annual']->carry_over_cap);
        $this->assertSame('30.0', $types['Medical']->days_per_year);
        $this->assertNull($types['Medical']->carry_over_cap);
        $this->assertTrue($types['Special']->deductsFrom->is($types['Annual']));
        $this->assertSame('7.0', $types['Special']->max_days_per_request);
        $this->assertNull($types['Special']->days_per_year);
        $this->assertSame(LeaveCounting::CalendarDays, $types['Maternity']->counts);
        $this->assertSame('90.0', $types['Maternity']->max_days_per_request);
        $this->assertFalse($types['Maternity']->allows_half_day);
        $this->assertNull($types['Unpaid']->days_per_year);
        $this->assertFalse($types['Unpaid']->is_paid);
        $this->assertTrue($types->every(fn (LeaveType $type) => $type->is_active));
    }
}
