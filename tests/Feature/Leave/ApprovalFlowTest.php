<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Exceptions\InvalidLeaveTransitionException;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalFlow;
use App\Services\Approval\ApprovalInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3c — who decides each step (CLAUDE.md, Phase 3, Approval rule 8),
 * what each user's inbox holds, and the leave status transitions.
 */
class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::factory()->create(['is_default' => true]);

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->flow = app(ApprovalFlow::class);
    }

    /**
     * An employee, optionally with a login holding $roles, reporting to $manager.
     *
     * @param  list<string>|null  $roles  null = no login
     */
    private function person(string $name, ?array $roles = ['employee'], ?Employee $manager = null): Employee
    {
        $user = $roles === null ? null : User::factory()->create(['name' => $name])->assignRole($roles);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id]);
    }

    private function pendingLeave(Employee $employee, int $step = 1): Leave
    {
        return Leave::factory()->for($employee)->pending($step)->create();
    }

    /** @return list<string> */
    private function names($users): array
    {
        return $users->map(fn (User $user) => $user->name)->sort()->values()->all();
    }

    // ── Step 1 ─────────────────────────────────────────────────────────

    public function test_a_direct_manager_decides_step_one(): void
    {
        $manager = $this->person('Manager', ['manager']);
        $employee = $this->person('Employee', ['employee'], $manager);
        $leave = $this->pendingLeave($employee);

        $this->assertSame(['Manager'], $this->names($this->flow->stepOneApprovers($leave)));
        $this->assertNull($this->flow->stepOneSkipReason($leave));
        $this->assertTrue($this->flow->canDecide($manager->user, $leave));
        $this->assertFalse($this->flow->decidesBothSteps($manager->user, $leave));
        $this->assertFalse($this->flow->canDecide($employee->user, $leave));
    }

    public function test_a_skip_level_manager_can_decide_step_one_too(): void
    {
        $top = $this->person('Top', ['manager']);
        $middle = $this->person('Middle', ['manager'], $top);
        $employee = $this->person('Employee', ['employee'], $middle);

        $this->assertSame(['Middle', 'Top'], $this->names($this->flow->stepOneApprovers($this->pendingLeave($employee))));
    }

    public function test_no_manager_means_step_one_is_skipped_with_a_reason(): void
    {
        $leave = $this->pendingLeave($this->person('Loner'));

        $this->assertSame([], $this->names($this->flow->stepOneApprovers($leave)));
        $this->assertSame('No manager is assigned to Loner.', $this->flow->stepOneSkipReason($leave));
    }

    public function test_a_chain_with_no_logins_skips_step_one(): void
    {
        $top = $this->person('Top', null);
        $middle = $this->person('Middle', null, $top);
        $leave = $this->pendingLeave($this->person('Employee', ['employee'], $middle));

        $this->assertSame('No one who manages Employee has a login.', $this->flow->stepOneSkipReason($leave));
    }

    public function test_an_employee_role_manager_is_not_an_approver_and_the_skip_names_them(): void
    {
        $lead = $this->person('Team Lead', ['employee']);
        $employee = $this->person('Employee', ['employee'], $lead);
        $leave = $this->pendingLeave($employee);

        $this->assertSame([], $this->names($this->flow->stepOneApprovers($leave)));
        $this->assertSame("Team Lead manages Employee but doesn't have the manager role.", $this->flow->stepOneSkipReason($leave));
        $this->assertFalse($this->flow->canDecide($lead->user, $leave));
    }

    public function test_a_requester_who_is_a_manager_is_approved_by_their_own_manager(): void
    {
        $director = $this->person('Director', ['manager']);
        $manager = $this->person('Manager', ['manager'], $director);
        $this->person('Report', ['employee'], $manager);
        $leave = $this->pendingLeave($manager);

        $this->assertSame(['Director'], $this->names($this->flow->stepOneApprovers($leave)));
        $this->assertFalse($this->flow->canDecide($manager->user, $leave));
    }

    public function test_eligibility_is_evaluated_at_decision_time(): void
    {
        $manager = $this->person('Manager', ['manager']);
        $other = $this->person('Other Manager', ['manager']);
        $employee = $this->person('Employee', ['employee'], $manager);
        $leave = $this->pendingLeave($employee);
        $this->assertTrue($this->flow->canDecide($manager->user, $leave));

        // Moved to another team while pending.
        $employee->update(['manager_id' => $other->id]);
        $leave->refresh();
        $this->assertFalse($this->flow->canDecide($manager->user, $leave));
        $this->assertTrue($this->flow->canDecide($other->user, $leave));

        // The new manager loses the role: nobody is eligible, the request stays at step 1 for an admin.
        $other->user->removeRole('manager');
        $this->assertFalse($this->flow->canDecide($other->user->fresh(), $leave));
        $this->assertSame(1, $leave->fresh()->current_step);

        $admin = $this->person('Admin', ['admin'])->user;
        $this->assertTrue($this->flow->canDecide($admin, $leave));
        $this->assertTrue(ApprovalInbox::for($admin)['overrides']->contains($leave));
    }

    // ── Step 2 and admins ──────────────────────────────────────────────

    public function test_an_admin_requester_needs_another_admin_at_step_two(): void
    {
        $requester = $this->person('Admin One', ['admin']);
        $other = $this->person('Admin Two', ['admin']);
        $leave = $this->pendingLeave($requester, 2);

        $this->assertSame(['Admin Two'], $this->names($this->flow->stepTwoApprovers($leave)));
        $this->assertFalse($this->flow->isSelfApprovedAtStepTwo($leave));
        $this->assertFalse($this->flow->canDecide($requester->user, $leave));
        $this->assertTrue($this->flow->canDecide($other->user, $leave));
    }

    public function test_the_sole_admins_step_two_is_self_approved(): void
    {
        $leave = $this->pendingLeave($this->person('Only Admin', ['admin']), 2);

        $this->assertSame([], $this->names($this->flow->stepTwoApprovers($leave)));
        $this->assertTrue($this->flow->isSelfApprovedAtStepTwo($leave));
        $this->assertFalse($this->flow->canDecide($leave->employee->user, $leave));
    }

    public function test_a_manager_who_is_also_an_admin_decides_both_steps_at_once(): void
    {
        $boss = $this->person('Boss', ['manager', 'admin']);
        $leave = $this->pendingLeave($this->person('Employee', ['employee'], $boss));

        $this->assertTrue($this->flow->canDecide($boss->user, $leave));
        $this->assertTrue($this->flow->decidesBothSteps($boss->user, $leave));
    }

    public function test_an_admin_can_override_step_one_and_their_decision_covers_both_steps(): void
    {
        $manager = $this->person('Manager', ['manager']);
        $leave = $this->pendingLeave($this->person('Employee', ['employee'], $manager));
        $admin = $this->person('Admin', ['admin'])->user;

        $this->assertNotContains('Admin', $this->names($this->flow->stepOneApprovers($leave)));
        $this->assertTrue($this->flow->canDecide($admin, $leave));
        $this->assertTrue($this->flow->decidesBothSteps($admin, $leave));
        // Not at step 2: there the admin decides only step 2.
        $this->assertFalse($this->flow->decidesBothSteps($admin, $this->pendingLeave($this->person('Someone', ['employee']), 2)));
    }

    public function test_a_manager_cannot_decide_step_two(): void
    {
        $manager = $this->person('Manager', ['manager']);
        $leave = $this->pendingLeave($this->person('Employee', ['employee'], $manager), 2);

        $this->assertFalse($this->flow->canDecide($manager->user, $leave));
    }

    // ── Inbox ──────────────────────────────────────────────────────────

    public function test_each_roles_inbox(): void
    {
        $admin = $this->person('Admin', ['admin']);
        $manager = $this->person('Manager', ['manager'], $admin);
        $report = $this->person('Report', ['employee'], $manager);
        $outsider = $this->person('Outsider', ['employee']);

        $reportStep1 = $this->pendingLeave($report);
        $reportStep2 = $this->pendingLeave($report, 2);
        $outsiderStep1 = $this->pendingLeave($outsider);
        $managerOwn = $this->pendingLeave($manager);
        $adminOwn = $this->pendingLeave($admin, 2);
        Leave::factory()->for($report)->approved()->create();

        $ids = fn (array $inbox) => array_map(fn ($group) => $group->pluck('id')->sort()->values()->all(), $inbox);

        $this->assertSame(
            ['stepOne' => [$reportStep1->id], 'stepTwo' => [], 'overrides' => []],
            $ids(ApprovalInbox::for($manager->user)),
        );
        $this->assertSame(1, ApprovalInbox::count($manager->user));

        // The admin manages Manager (and, transitively, Report).
        $this->assertSame(
            ['stepOne' => [$reportStep1->id, $managerOwn->id], 'stepTwo' => [$reportStep2->id], 'overrides' => [$outsiderStep1->id]],
            $ids(ApprovalInbox::for($admin->user)),
        );
        $this->assertSame(4, ApprovalInbox::count($admin->user));
        $this->assertNotContains($adminOwn->id, collect(ApprovalInbox::for($admin->user))->flatten()->pluck('id'));

        $this->assertSame(0, ApprovalInbox::count($report->user));
    }

    // ── Status transitions ─────────────────────────────────────────────

    public function test_allowed_status_transitions(): void
    {
        $employee = $this->person('Employee');

        foreach ([LeaveStatus::Approved, LeaveStatus::Rejected, LeaveStatus::Cancelled] as $to) {
            $leave = $this->pendingLeave($employee);
            $leave->update(['status' => $to, 'current_step' => null]);
            $this->assertSame($to, $leave->fresh()->status);
        }

        $approved = Leave::factory()->for($employee)->approved()->create();
        $approved->update(['status' => LeaveStatus::Cancelled]);
        $this->assertSame(LeaveStatus::Cancelled, $approved->fresh()->status);
    }

    public function test_every_other_transition_throws(): void
    {
        $employee = $this->person('Employee');
        $cases = [
            [Leave::factory()->for($employee)->approved()->create(), ['status' => LeaveStatus::Pending, 'current_step' => 1]],
            [Leave::factory()->for($employee)->approved()->create(), ['status' => LeaveStatus::Rejected]],
            [Leave::factory()->for($employee)->rejected()->create(), ['status' => LeaveStatus::Approved]],
            [Leave::factory()->for($employee)->rejected()->create(), ['status' => LeaveStatus::Cancelled]],
            [Leave::factory()->for($employee)->cancelled()->create(), ['status' => LeaveStatus::Approved]],
            [Leave::factory()->for($employee)->cancelled()->create(), ['status' => LeaveStatus::Pending, 'current_step' => 1]],
        ];

        foreach ($cases as [$leave, $change]) {
            try {
                $leave->update($change);
                $this->fail("{$leave->status->value} → {$change['status']->value} must throw.");
            } catch (InvalidLeaveTransitionException $e) {
                $this->assertStringContainsString("can't become", $e->getMessage());
            }
        }
    }

    public function test_current_step_is_set_exactly_while_pending(): void
    {
        $employee = $this->person('Employee');

        try {
            $this->pendingLeave($employee)->update(['status' => LeaveStatus::Approved]);
            $this->fail('A closed request must not keep a current step.');
        } catch (InvalidLeaveTransitionException) {
        }

        $this->expectException(InvalidLeaveTransitionException::class);

        Leave::factory()->for($employee)->create(['status' => LeaveStatus::Pending, 'current_step' => null]);
    }
}
