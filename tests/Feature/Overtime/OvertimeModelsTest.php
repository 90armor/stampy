<?php

namespace Tests\Feature\Overtime;

use App\Contracts\Approvable;
use App\Enums\ApprovalOutcome;
use App\Enums\LeaveBalanceSource;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Exceptions\AppendOnlyRecordException;
use App\Exceptions\InvalidLeaveAdjustmentException;
use App\Exceptions\InvalidLeaveTypeException;
use App\Exceptions\InvalidOvertimeRequestException;
use App\Exceptions\InvalidOvertimeSettingsException;
use App\Exceptions\InvalidOvertimeTransitionException;
use App\Exceptions\LeaveTypeInUseException;
use App\Exceptions\OvertimeSettingsRowException;
use App\Models\ApprovalStep;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4a — the overtime tables' structure: OvertimeRequest's window and
 * status rules, its place in the approval engine (morph 'overtime'), the
 * overtime settings row and its rules, the TOIL leave type and the system
 * adjustments that link to a request. Request rules (limits, claim window,
 * one active request per date) are Phase 4c.
 */
class OvertimeModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo('2026-04-15 12:00:00');
        WorkSchedule::factory()->create(['is_default' => true]);
    }

    // ── OvertimeRequest: the window ─────────────────────────────────────

    public function test_every_factory_state_makes_a_valid_request(): void
    {
        $states = ['planned', 'claim', 'pay', 'timeOff', 'pending', 'approved', 'rejected', 'cancelled', 'overnight'];

        foreach ($states as $state) {
            $request = OvertimeRequest::factory()->{$state}()->create()->fresh();
            $this->assertSame('2026-04-15', $request->date->format('Y-m-d'), $state);
        }

        $overnight = OvertimeRequest::factory()->overnight()->create()->fresh();
        $this->assertSame(['2026-04-15 20:00', '2026-04-16 01:00'], [$overnight->starts_at->format('Y-m-d H:i'), $overnight->ends_at->format('Y-m-d H:i')]);

        $claim = OvertimeRequest::factory()->claim()->timeOff()->create()->fresh();
        $this->assertSame([OvertimeKind::Claim, OvertimeCompensation::TimeOff, OvertimeStatus::Pending, 1], [$claim->kind, $claim->compensation, $claim->status, $claim->current_step]);
    }

    public function test_the_window_must_end_after_it_starts(): void
    {
        foreach (['18:00', '17:00'] as $end) {
            try {
                OvertimeRequest::factory()->create(['starts_at' => '2026-04-15 18:00', 'ends_at' => "2026-04-15 {$end}"]);
                $this->fail("Accepted an end at {$end}.");
            } catch (InvalidOvertimeRequestException $e) {
                $this->assertStringContainsString('end after it starts', $e->getMessage());
            }
        }
    }

    public function test_the_window_starts_on_its_work_date_and_may_end_after_midnight(): void
    {
        $request = OvertimeRequest::factory()->window('2026-04-15', '20:00', '01:00')->create();
        $this->assertSame('2026-04-16 01:00', $request->fresh()->ends_at->format('Y-m-d H:i'));

        $this->expectException(InvalidOvertimeRequestException::class);
        $this->expectExceptionMessage('start on its work date');

        OvertimeRequest::factory()->create(['date' => '2026-04-15', 'starts_at' => '2026-04-16 00:30', 'ends_at' => '2026-04-16 02:00']);
    }

    public function test_the_window_is_at_most_twelve_hours(): void
    {
        $twelve = OvertimeRequest::factory()->window('2026-04-15', '08:00', '20:00')->create();
        $this->assertTrue($twelve->exists);

        $this->expectException(InvalidOvertimeRequestException::class);
        $this->expectExceptionMessage('longer than 12 hours');

        $twelve->update(['ends_at' => '2026-04-15 20:01']);
    }

    // ── OvertimeRequest: status ─────────────────────────────────────────

    public function test_status_moves_only_as_a_leaves_does(): void
    {
        $allowed = [
            ['pending', 'approved'], ['pending', 'rejected'], ['pending', 'cancelled'], ['approved', 'cancelled'],
        ];

        foreach ($allowed as [$from, $to]) {
            $request = OvertimeRequest::factory()->{$from}()->create();
            $request->update(['status' => $to, 'current_step' => null]);
            $this->assertSame($to, $request->fresh()->status->value);
        }

        $refused = [
            ['approved', 'pending'], ['approved', 'rejected'], ['rejected', 'approved'], ['rejected', 'pending'],
            ['cancelled', 'approved'], ['cancelled', 'pending'],
        ];

        foreach ($refused as [$from, $to]) {
            $request = OvertimeRequest::factory()->{$from}()->create();

            try {
                $request->update(['status' => $to, 'current_step' => $to === 'pending' ? 1 : null]);
                $this->fail("{$from} → {$to} was allowed.");
            } catch (InvalidOvertimeTransitionException $e) {
                $this->assertSame("A {$from} overtime request can't become {$to}.", $e->getMessage());
            }
        }
    }

    public function test_a_step_waits_exactly_while_the_request_is_pending(): void
    {
        try {
            OvertimeRequest::factory()->create(['status' => OvertimeStatus::Pending, 'current_step' => null]);
            $this->fail('A pending request with no step was allowed.');
        } catch (InvalidOvertimeTransitionException $e) {
            $this->assertStringContainsString('must be waiting at a step', $e->getMessage());
        }

        $this->expectException(InvalidOvertimeTransitionException::class);
        $this->expectExceptionMessage('has no step waiting');

        OvertimeRequest::factory()->create(['status' => OvertimeStatus::Approved, 'current_step' => 2]);
    }

    // ── OvertimeRequest: the approval engine and relations ──────────────

    public function test_it_is_an_approvable_under_the_overtime_morph_alias(): void
    {
        $employee = Employee::factory()->create();
        $request = OvertimeRequest::factory()->for($employee)->pending(2)->create();

        $this->assertInstanceOf(Approvable::class, $request);
        $this->assertTrue($request->approvalSubject()->is($employee));
        $this->assertSame(2, $request->currentApprovalStep());

        $step = $request->approvalSteps()->create(['step' => 1, 'outcome' => ApprovalOutcome::Approved, 'decided_at' => now()]);

        $this->assertSame('overtime', DB::table('approval_steps')->where('id', $step->id)->value('approvable_type'));
        $this->assertTrue(ApprovalStep::find($step->id)->approvable->is($request));
        $this->assertTrue($employee->overtimeRequests()->sole()->is($request));
    }

    public function test_a_system_toil_adjustment_links_to_the_request_both_ways_and_stays_append_only(): void
    {
        $toil = LeaveType::factory()->earned()->create();
        $request = OvertimeRequest::factory()->timeOff()->approved()->create();

        $adjustment = LeaveAdjustment::factory()->create([
            'employee_id' => $request->employee_id, 'leave_type_id' => $toil->id, 'year' => 2026,
            'days' => '0.5', 'note' => 'Time off in lieu', 'overtime_request_id' => $request->id, 'created_by' => null,
        ]);

        $this->assertTrue($adjustment->overtimeRequest->is($request));
        $this->assertTrue($request->leaveAdjustments()->sole()->is($adjustment));

        try {
            $adjustment->update(['days' => '1.0']);
            $this->fail('An adjustment was edited.');
        } catch (AppendOnlyRecordException) {
        }

        // A request-linked adjustment is system-authored: no author.
        $this->expectException(InvalidLeaveAdjustmentException::class);

        LeaveAdjustment::factory()->create([
            'employee_id' => $request->employee_id, 'leave_type_id' => $toil->id,
            'overtime_request_id' => $request->id, 'created_by' => User::factory()->create()->id,
        ]);
    }

    /**
     * leave_adjustments.overtime_request_id is restrictOnDelete, but both
     * tables cascade from employees: deleting an employee (which the app never
     * does — CLAUDE.md, Employee lifecycle) still removes both, not a FK error.
     */
    public function test_deleting_an_employee_still_cascades_through_a_linked_toil_adjustment(): void
    {
        $toil = LeaveType::factory()->earned()->create();
        $request = OvertimeRequest::factory()->approved()->create();
        LeaveAdjustment::factory()->create(['employee_id' => $request->employee_id, 'leave_type_id' => $toil->id, 'overtime_request_id' => $request->id]);

        $request->employee->delete();

        $this->assertSame([0, 0], [OvertimeRequest::count(), LeaveAdjustment::count()]);
    }

    public function test_a_day_has_overtime_minutes_per_category_defaulting_to_zero(): void
    {
        $request = OvertimeRequest::factory()->approved()->create();
        $day = DailyAttendance::create([
            'employee_id' => $request->employee_id, 'work_date' => '2026-04-15', 'status' => 'present',
            'overtime_request_id' => $request->id, 'overtime_night_minutes' => 60,
        ])->fresh();

        $this->assertSame([0, 60, 0, 0], [$day->overtime_workday_minutes, $day->overtime_night_minutes, $day->overtime_rest_day_minutes, $day->overtime_holiday_minutes]);
        $this->assertTrue($day->overtimeRequest->is($request));
    }

    // ── OvertimeSettings ────────────────────────────────────────────────

    public function test_the_migration_creates_the_settings_row_with_the_legal_defaults(): void
    {
        $settings = OvertimeSettings::current();

        $this->assertSame(1, OvertimeSettings::count());
        $this->assertSame(
            [150, 200, 200, 200, '22:00:00', '05:00:00', 120, 600, 7, 100, 240],
            [
                $settings->workday_rate_percent, $settings->night_rate_percent, $settings->rest_day_rate_percent, $settings->holiday_rate_percent,
                $settings->night_starts, $settings->night_ends, $settings->max_overtime_minutes_per_day, $settings->max_work_minutes_per_day,
                $settings->claim_window_days, $settings->toil_ratio_percent, $settings->toil_block_minutes,
            ],
        );
    }

    public function test_current_reads_the_row_once_per_request_and_a_save_refreshes_it(): void
    {
        $first = OvertimeSettings::current();

        DB::enableQueryLog();
        $this->assertSame($first, OvertimeSettings::current());
        $this->assertSame([], DB::getQueryLog());

        $first->update(['claim_window_days' => 14]);

        $this->assertNotSame($first, OvertimeSettings::current());
        $this->assertSame(14, OvertimeSettings::current()->claim_window_days);
    }

    public function test_the_row_cannot_be_missing_duplicated_or_deleted(): void
    {
        try {
            OvertimeSettings::create([]);
            $this->fail('A second settings row was created.');
        } catch (OvertimeSettingsRowException $e) {
            $this->assertStringContainsString('only one overtime settings row', $e->getMessage());
        }

        try {
            OvertimeSettings::current()->delete();
            $this->fail('The settings row was deleted.');
        } catch (OvertimeSettingsRowException $e) {
            $this->assertStringContainsString('can\'t be deleted', $e->getMessage());
        }

        $this->assertSame(1, OvertimeSettings::count());

        // Missing (only possible behind the model's back): current() says so.
        DB::table('overtime_settings')->delete();
        app()->forgetInstance('overtime.settings');

        $this->expectException(OvertimeSettingsRowException::class);
        $this->expectExceptionMessage('row is missing');

        OvertimeSettings::current();
    }

    public function test_every_number_is_positive(): void
    {
        foreach (['max_overtime_minutes_per_day', 'max_work_minutes_per_day', 'claim_window_days', 'toil_ratio_percent', 'toil_block_minutes'] as $field) {
            try {
                OvertimeSettings::current()->fresh()->update([$field => 0]);
                $this->fail("{$field} = 0 was accepted.");
            } catch (InvalidOvertimeSettingsException $e) {
                $this->assertStringContainsString('must be greater than 0', $e->getMessage());
            }
        }
    }

    public function test_rates_are_ordered_so_the_category_precedence_never_pays_less(): void
    {
        $outOfOrder = [
            ['holiday_rate_percent' => 190],                 // holiday < rest day
            ['rest_day_rate_percent' => 180, 'holiday_rate_percent' => 200, 'night_rate_percent' => 190], // rest day < night
            ['night_rate_percent' => 140],                   // night < workday
            ['workday_rate_percent' => 90],                  // workday < 100
        ];

        foreach ($outOfOrder as $change) {
            try {
                OvertimeSettings::current()->fresh()->update($change);
                $this->fail('Accepted '.json_encode($change));
            } catch (InvalidOvertimeSettingsException $e) {
                $this->assertStringContainsString('holiday ≥ rest day ≥ night ≥ workday ≥ 100%', $e->getMessage());
                $this->assertStringContainsString('precedence relies on this ordering', $e->getMessage());
            }
        }

        // Equal rates are fine, and HR may set categories apart in order.
        OvertimeSettings::current()->fresh()->update(['workday_rate_percent' => 100, 'night_rate_percent' => 100, 'rest_day_rate_percent' => 100, 'holiday_rate_percent' => 100]);
        OvertimeSettings::current()->fresh()->update(['workday_rate_percent' => 150, 'night_rate_percent' => 175, 'rest_day_rate_percent' => 200, 'holiday_rate_percent' => 300]);
        $this->assertSame(175, OvertimeSettings::current()->night_rate_percent);
    }

    public function test_the_toil_block_is_whole_half_hours(): void
    {
        OvertimeSettings::current()->fresh()->update(['toil_block_minutes' => 210]);
        $this->assertSame(210, OvertimeSettings::current()->toil_block_minutes);

        $this->expectException(InvalidOvertimeSettingsException::class);
        $this->expectExceptionMessage('multiple of 30 minutes');

        OvertimeSettings::current()->fresh()->update(['toil_block_minutes' => 225]);
    }

    public function test_the_toil_type_must_be_earned_and_stays_earned(): void
    {
        $annual = LeaveType::factory()->create(['name' => 'Annual']);

        try {
            OvertimeSettings::current()->fresh()->update(['toil_leave_type_id' => $annual->id]);
            $this->fail('A yearly type was accepted as the TOIL type.');
        } catch (InvalidOvertimeSettingsException $e) {
            $this->assertStringContainsString('Annual isn\'t one', $e->getMessage());
        }

        $toil = LeaveType::factory()->earned()->create(['name' => 'Lieu']);
        OvertimeSettings::current()->fresh()->update(['toil_leave_type_id' => $toil->id]);
        $this->assertTrue(OvertimeSettings::current()->toilLeaveType->is($toil));

        // While it's the TOIL type it can't stop being earned, nor be deleted.
        try {
            $toil->update(['balance_source' => LeaveBalanceSource::None, 'carry_over_cap' => null]);
            $this->fail('The TOIL type stopped being earned.');
        } catch (InvalidLeaveTypeException $e) {
            $this->assertStringContainsString('must stay earned', $e->getMessage());
        }

        $this->expectException(LeaveTypeInUseException::class);

        $toil->delete();
    }

    // ── Seed data ───────────────────────────────────────────────────────

    public function test_the_seeder_adds_time_off_in_lieu_and_points_the_settings_at_it_idempotently(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $toil = LeaveType::where('name', 'Time off in lieu')->sole();
        $this->assertSame(
            [LeaveBalanceSource::Earned, null, '5.0', 'workdays', true, true, true],
            [$toil->balance_source, $toil->days_per_year, $toil->carry_over_cap, $toil->counts->value, $toil->allows_half_day, $toil->is_paid, $toil->is_active],
        );
        $this->assertSame($toil->id, OvertimeSettings::current()->toil_leave_type_id);
        $this->assertSame(0, $toil->entitlements()->count());

        // An admin's choice is never overwritten.
        $other = LeaveType::factory()->earned()->create(['name' => 'Comp time']);
        OvertimeSettings::current()->fresh()->update(['toil_leave_type_id' => $other->id]);

        $this->seed(LeaveTypeSeeder::class);

        $this->assertSame($other->id, OvertimeSettings::current()->toil_leave_type_id);
        $this->assertSame(1, LeaveType::where('name', 'Time off in lieu')->count());
    }
}
