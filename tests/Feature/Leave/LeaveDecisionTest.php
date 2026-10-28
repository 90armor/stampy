<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Exceptions\StaleLeaveDecisionException;
use App\Livewire\Employees\StatusModal;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\EmployeeLifecycle;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3c — approving, rejecting and cancelling (CLAUDE.md, Phase 3, rules
 * 8, 12 and 15), and what deactivation does to leave after the last day.
 * The clock is Mon 15 Jun 2026; the schedule is Mon–Fri.
 */
class LeaveDecisionTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => true, 'carry_over_cap' => 6]);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id]);
    }

    private function service(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    private function request(string $from, string $to): Leave
    {
        return $this->service()->submit($this->employee, $this->annual, Carbon::parse($from), Carbon::parse($to), null, null, $this->employee->user)['leave'];
    }

    /** Expects exactly one rebuildBetween() call for $from..$to (or none, with null). */
    private function expectRebuild(?string $from, ?string $to = null): void
    {
        $this->mock(DailySummaryBuilder::class, function ($mock) use ($from, $to) {
            if ($from === null) {
                $mock->shouldNotReceive('rebuildBetween');

                return;
            }

            $mock->shouldReceive('rebuildBetween')->once()
                ->withArgs(fn ($employee, $first, $last) => $first->format('Y-m-d') === $from && $last->format('Y-m-d') === $to)
                ->andReturn(1);
        });
    }

    /** @return list<array{int, string, ?int}> step, outcome, decided_by */
    private function steps(Leave $leave): array
    {
        return $leave->fresh()->approvalSteps->map(fn (ApprovalStep $step) => [$step->step, $step->outcome->value, $step->decided_by])->all();
    }

    // ── Approving and rejecting ────────────────────────────────────────

    public function test_manager_then_admin_approval_approves_and_rebuilds(): void
    {
        $leave = $this->request('2026-06-22', '2026-06-26');

        $leave = $this->service()->approve($leave, $this->manager->user, 'Enjoy')['leave'];
        $this->assertSame(LeaveStatus::Pending, $leave->status);
        $this->assertSame(2, $leave->current_step);

        $this->expectRebuild(null); // entirely in the future: nothing to rebuild
        $leave = $this->service()->approve($leave, $this->admin)['leave'];

        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertNull($leave->current_step);
        $this->assertSame([[1, 'approved', $this->manager->user_id], [2, 'approved', $this->admin->id]], $this->steps($leave));
    }

    public function test_an_admin_overriding_step_one_completes_both_steps(): void
    {
        $leave = $this->service()->approve($this->request('2026-06-22', '2026-06-26'), $this->admin)['leave'];

        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertSame([[1, 'approved', $this->admin->id], [2, 'approved', $this->admin->id]], $this->steps($leave));
    }

    public function test_rejecting_at_either_step_ends_the_request(): void
    {
        $atOne = $this->service()->reject($this->request('2026-06-22', '2026-06-22'), $this->manager->user, 'Busy week');
        $this->assertSame(LeaveStatus::Rejected, $atOne->status);
        $this->assertNull($atOne->current_step);
        $this->assertSame([[1, 'rejected', $this->manager->user_id]], $this->steps($atOne));

        $atTwo = $this->service()->approve($this->request('2026-06-23', '2026-06-23'), $this->manager->user)['leave'];
        $atTwo = $this->service()->reject($atTwo, $this->admin);
        $this->assertSame(LeaveStatus::Rejected, $atTwo->status);
        $this->assertSame([[1, 'approved', $this->manager->user_id], [2, 'rejected', $this->admin->id]], $this->steps($atTwo));
    }

    public function test_a_manager_can_neither_decide_step_two_nor_their_non_reports_requests(): void
    {
        $leave = $this->service()->approve($this->request('2026-06-22', '2026-06-22'), $this->manager->user)['leave'];

        try {
            $this->service()->approve($leave, $this->manager->user);
            $this->fail('A manager must not decide step 2.');
        } catch (AuthorizationException) {
        }

        $stranger = $this->person('Other Manager', 'manager');
        $this->expectException(AuthorizationException::class);
        $this->service()->approve($this->request('2026-06-23', '2026-06-23'), $stranger->user);
    }

    public function test_a_sole_admins_own_request_is_self_approved_when_their_manager_approves(): void
    {
        $this->admin->delete();
        $soleAdmin = $this->person('Sole Admin', 'admin', $this->manager);
        $leave = $this->service()->submit($soleAdmin, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $soleAdmin->user)['leave'];

        $leave = $this->service()->approve($leave, $this->manager->user)['leave'];

        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertSame([[1, 'approved', $this->manager->user_id], [2, 'self_approved', $soleAdmin->user_id]], $this->steps($leave));
    }

    // ── Stale decisions ────────────────────────────────────────────────

    public function test_a_decision_on_a_request_that_moved_on_fails_cleanly(): void
    {
        $leave = $this->request('2026-06-22', '2026-06-22');
        $seenAtStepOne = $leave->fresh();

        $this->service()->approve($leave, $this->manager->user);

        // The admin's screen still showed step 1.
        try {
            $this->service()->approve($seenAtStepOne, $this->admin, null, 1);
            $this->fail('A decision on a moved-on request must fail.');
        } catch (StaleLeaveDecisionException $e) {
            $this->assertStringContainsString('moved on', $e->getMessage());
        }

        $this->service()->approve($leave, $this->admin, null, 2);

        try {
            $this->service()->reject($leave, $this->admin);
            $this->fail('A decided request must not be decided again.');
        } catch (StaleLeaveDecisionException $e) {
            $this->assertSame('This request has already been approved.', $e->getMessage());
        }

        $this->assertSame(2, ApprovalStep::count());
    }

    public function test_a_double_decision_that_reaches_the_unique_index_is_reported_as_stale(): void
    {
        $leave = $this->request('2026-06-22', '2026-06-22');
        // Someone else's step-1 decision landed, but the leave row hasn't moved yet.
        ApprovalStep::factory()->for($leave, 'approvable')->step(1)->create(['decided_by' => $this->manager->user_id]);

        $this->expectException(StaleLeaveDecisionException::class);

        $this->service()->approve($leave, $this->manager->user);
    }

    // ── Rebuild range ──────────────────────────────────────────────────

    public function test_the_rebuild_range_of_a_past_leave_and_one_spanning_today(): void
    {
        $past = $this->service()->submit($this->employee, $this->annual, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'), null, null, $this->employee->user)['leave'];
        $this->service()->approve($past, $this->manager->user);
        $this->expectRebuild('2026-06-01', '2026-06-05');
        $this->service()->approve($past, $this->admin);

        $spanning = $this->service()->submit($this->employee, $this->annual, Carbon::parse('2026-06-10'), Carbon::parse('2026-06-19'), null, null, $this->employee->user)['leave'];
        $this->service()->approve($spanning, $this->manager->user);
        $this->expectRebuild('2026-06-10', '2026-06-15');
        $this->service()->approve($spanning, $this->admin);
    }

    public function test_a_rebuild_failure_is_reported_and_the_approval_stays(): void
    {
        $leave = $this->request('2026-06-08', '2026-06-12');
        $this->service()->approve($leave, $this->manager->user);
        $this->mock(DailySummaryBuilder::class, fn ($mock) => $mock->shouldReceive('rebuildBetween')->andThrow(new RuntimeException('connection lost')));
        Log::spy();

        $result = $this->service()->approve($leave, $this->admin);

        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
        $this->assertSame(
            'Not every affected day may have been rebuilt. Run: php artisan attendance:build-daily --from=2026-06-08 --to=2026-06-12 --employee='.$this->employee->employee_code,
            $result['rebuildError'],
        );
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, "Leave #{$leave->id} approval for {$this->employee->employee_code}: rebuild failed partway (connection lost)"))->once();
    }

    // ── Cancelling ─────────────────────────────────────────────────────

    public function test_the_requester_cancels_before_the_start_and_an_approved_future_leave_rebuilds_nothing(): void
    {
        $pending = $this->request('2026-06-22', '2026-06-22');
        $cancelled = $this->service()->cancel($pending, $this->employee->user)['leave'];
        $this->assertSame(LeaveStatus::Cancelled, $cancelled->status);
        $this->assertSame($this->employee->user_id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);

        $approved = $this->service()->approve($this->request('2026-06-23', '2026-06-23'), $this->admin)['leave'];
        $this->expectRebuild(null);
        $this->assertSame(LeaveStatus::Cancelled, $this->service()->cancel($approved, $this->employee->user)['leave']->status);
        // Its steps stay as they were.
        $this->assertCount(2, $this->steps($approved));
    }

    public function test_the_requester_cannot_cancel_once_the_leave_has_started_but_an_admin_can(): void
    {
        $leave = $this->service()->submit($this->employee, $this->annual, Carbon::parse('2026-06-15'), Carbon::parse('2026-06-17'), null, null, $this->employee->user)['leave'];
        $leave = $this->service()->approve($leave, $this->admin)['leave'];

        try {
            $this->service()->cancel($leave, $this->employee->user);
            $this->fail('The requester must not cancel a leave that has started.');
        } catch (AuthorizationException) {
        }

        $this->expectRebuild('2026-06-15', '2026-06-15');
        $this->assertSame(LeaveStatus::Cancelled, $this->service()->cancel($leave, $this->admin)['leave']->status);
    }

    public function test_a_rejected_or_cancelled_leave_cannot_be_cancelled(): void
    {
        $leave = $this->service()->reject($this->request('2026-06-22', '2026-06-22'), $this->manager->user);

        $this->expectException(StaleLeaveDecisionException::class);

        $this->service()->cancel($leave, $this->admin);
    }

    // ── Deactivation ───────────────────────────────────────────────────

    public function test_deactivation_cancels_leave_after_the_last_day_and_shortens_leave_across_it(): void
    {
        $after = $this->service()->approve($this->request('2026-06-29', '2026-07-03'), $this->admin)['leave'];
        $spanning = $this->request('2026-06-10', '2026-06-19');                  // pending, Wed 10 – Fri 19
        $before = $this->service()->approve($this->request('2026-06-01', '2026-06-02'), $this->admin)['leave'];

        app(EmployeeLifecycle::class)->deactivate($this->employee, Carbon::parse('2026-06-12'), $this->admin);

        $this->assertSame(LeaveStatus::Cancelled, $after->fresh()->status);
        $this->assertSame($this->admin->id, $after->fresh()->cancelled_by);
        $this->assertSame(LeaveStatus::Pending, $spanning->fresh()->status);
        $this->assertSame('2026-06-12', $spanning->fresh()->end_date->format('Y-m-d'));
        $this->assertSame(LeaveStatus::Approved, $before->fresh()->status);
        $this->assertSame('2026-06-02', $before->fresh()->end_date->format('Y-m-d'));
    }

    public function test_a_leave_cut_down_to_no_working_days_is_cancelled_instead(): void
    {
        // Sat 13 – Tue 16; the last day is Sun 14, so only the weekend would be left.
        $leave = $this->service()->approve($this->request('2026-06-13', '2026-06-16'), $this->admin)['leave'];

        app(EmployeeLifecycle::class)->deactivate($this->employee, Carbon::parse('2026-06-14'), $this->admin);

        $this->assertSame(LeaveStatus::Cancelled, $leave->fresh()->status);
        $this->assertSame('2026-06-16', $leave->fresh()->end_date->format('Y-m-d'));
    }

    public function test_the_deactivate_modal_lists_affected_leave_before_writing(): void
    {
        $future = $this->service()->approve($this->request('2026-06-22', '2026-06-26'), $this->admin)['leave'];
        $past = $this->service()->approve($this->request('2026-06-08', '2026-06-12'), $this->admin)['leave'];

        $modal = Livewire::actingAs($this->admin)
            ->test(StatusModal::class)
            ->call('openDeactivate', $this->employee->id)
            ->call('confirm');

        // First click (last day today): the list, nothing written.
        $modal->assertSee('Leave after their last day will change')
            ->assertSee('Annual · 22–26 Jun (approved) — cancelled')
            ->assertSee('Deactivate and adjust 1 leave');
        $this->assertSame('active', $this->employee->fresh()->status);

        // The set changes before the second click: shown again, still nothing written.
        $pending = $this->request('2026-06-29', '2026-06-29');
        $modal->call('confirm')->assertSee('Deactivate and adjust 2 leaves');
        $this->assertSame('active', $this->employee->fresh()->status);

        // A new date starts over: the list is computed for it, not written.
        $modal->set('left_on', '2026-06-10')->call('confirm')
            ->assertSee('Annual · 8–12 Jun (approved) — shortened to end Wed 10 Jun')
            ->assertSee('Deactivate and adjust 3 leaves');
        $this->assertSame('active', $this->employee->fresh()->status);

        $modal->call('confirm')->assertHasNoErrors()->assertSet('showModal', false);
        $this->assertSame('inactive', $this->employee->fresh()->status);
        $this->assertSame('2026-06-10', $past->fresh()->end_date->format('Y-m-d'));
        $this->assertSame(LeaveStatus::Approved, $past->fresh()->status);
        $this->assertSame(LeaveStatus::Cancelled, $future->fresh()->status);
        $this->assertSame(LeaveStatus::Cancelled, $pending->fresh()->status);
    }

    public function test_with_no_affected_leave_deactivating_is_one_click(): void
    {
        Livewire::actingAs($this->admin)
            ->test(StatusModal::class)
            ->call('openDeactivate', $this->employee->id)
            ->call('confirm')
            ->assertSet('showModal', false);

        $this->assertSame('inactive', $this->employee->fresh()->status);
    }
}
