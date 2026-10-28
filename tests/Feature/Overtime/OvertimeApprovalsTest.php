<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Livewire\Leave\Approvals;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalInbox;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\ApprovalBadge;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4c — the approvals inbox holds leave and overtime together
 * (ApprovalInbox through Approvable), and OvertimePolicy's abilities per role.
 * The clock is Mon 15 Jun 2026 12:00; the schedule is Mon–Fri.
 */
class OvertimeApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id]);
    }

    private function overtime(string $date, string $from = '17:00', string $to = '19:00'): OvertimeRequest
    {
        return app(OvertimeRequestService::class)->submit(
            $this->employee, Carbon::parse($date), Carbon::parse("{$date} {$from}"), Carbon::parse("{$date} {$to}"), OvertimeCompensation::Pay, null, $this->employee->user,
        )['request'];
    }

    private function leave(string $from, string $to): Leave
    {
        return app(LeaveRequestService::class)->submit($this->employee, $this->annual, Carbon::parse($from), Carbon::parse($to), null, null, $this->employee->user)['leave'];
    }

    /** @return list<string> "leave:<id>" / "overtime:<id>" */
    private function keys($items): array
    {
        return $items->map(fn ($item) => ($item instanceof Leave ? 'leave:' : 'overtime:').$item->id)->all();
    }

    // ── The inbox ───────────────────────────────────────────────────────

    public function test_both_types_wait_together_in_date_order_and_both_count(): void
    {
        $late = $this->leave('2026-06-24', '2026-06-25');
        $soon = $this->overtime('2026-06-16');
        $between = $this->leave('2026-06-18', '2026-06-18');

        $inbox = ApprovalInbox::for($this->manager->user);
        $this->assertSame(["overtime:{$soon->id}", "leave:{$between->id}", "leave:{$late->id}"], $this->keys($inbox['stepOne']));
        $this->assertSame(3, ApprovalInbox::count($this->manager->user));

        app(OvertimeRequestService::class)->approve($soon, $this->manager->user);
        app(LeaveRequestService::class)->approve($between, $this->manager->user);

        $admin = ApprovalInbox::for($this->admin);
        $this->assertSame(["overtime:{$soon->id}", "leave:{$between->id}"], $this->keys($admin['stepTwo']));
        $this->assertSame(["leave:{$late->id}"], $this->keys($admin['overrides']));
        $this->assertSame(2, ApprovalInbox::count($this->admin));
        $this->assertSame(1, ApprovalInbox::count($this->manager->user));
    }

    public function test_a_planned_request_is_stuck_by_its_start_and_a_claim_only_by_waiting(): void
    {
        // Wed 17 Jun is within two working days of Mon 15 Jun: stuck at once.
        $planned = $this->overtime('2026-06-17');
        $this->assertTrue(ApprovalInbox::isStuck($planned));
        $this->assertFalse(ApprovalInbox::isStuck($this->overtime('2026-06-24')));

        // A claim has no start left to wait for: not stuck until it has waited two working days.
        $claim = $this->overtime('2026-06-12');
        $this->assertFalse(ApprovalInbox::isStuck($claim));
        $this->assertTrue(ApprovalInbox::isStuck($claim, Carbon::parse('2026-06-17')));
        // An admin's count has only the stuck override: the planned request.
        $this->assertSame(1, ApprovalInbox::count($this->admin));
    }

    public function test_the_approvals_page_still_shows_the_leave_and_the_badge_counts_both(): void
    {
        $leave = $this->leave('2026-06-24', '2026-06-25');
        $this->overtime('2026-06-26');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertOk()
            ->assertSee($leave->leaveType->name);

        $this->assertSame(2, ApprovalBadge::count($this->manager->user));
    }

    // ── OvertimePolicy ──────────────────────────────────────────────────

    public function test_who_may_view_create_approve_and_cancel(): void
    {
        $request = $this->overtime('2026-06-16');
        $colleague = $this->person('Colleague', 'employee', $this->manager);
        $otherManager = $this->person('Other manager', 'manager');

        $can = fn (User $user, string $ability, $subject) => $user->can($ability, $subject);

        // view: own, their manager's, an admin's — nobody else's.
        $this->assertTrue($can($this->employee->user, 'view', $request));
        $this->assertTrue($can($this->manager->user, 'view', $request));
        $this->assertTrue($can($this->admin, 'view', $request));
        $this->assertFalse($can($colleague->user, 'view', $request));
        $this->assertFalse($can($otherManager->user, 'view', $request));

        // viewAny: anyone with something to see.
        $this->assertTrue($can($this->employee->user, 'viewAny', OvertimeRequest::class));
        $this->assertFalse($can(User::factory()->create(), 'viewAny', OvertimeRequest::class));

        // create: for themself, or an admin for anyone.
        $this->assertTrue($can($this->employee->user, 'create', [OvertimeRequest::class, $this->employee]));
        $this->assertTrue($can($this->admin, 'create', [OvertimeRequest::class, $this->employee]));
        $this->assertFalse($can($this->manager->user, 'create', [OvertimeRequest::class, $this->employee]));
        $this->assertFalse($can($colleague->user, 'create', [OvertimeRequest::class, $this->employee]));

        // approve: their manager at step 1, an admin; never the requester or another manager.
        $this->assertTrue($can($this->manager->user, 'approve', $request));
        $this->assertTrue($can($this->admin, 'approve', $request));
        $this->assertFalse($can($this->employee->user, 'approve', $request));
        $this->assertFalse($can($otherManager->user, 'approve', $request));

        // cancel: the requester before it starts, an admin any time; not the manager.
        $this->assertTrue($can($this->employee->user, 'cancel', $request));
        $this->assertTrue($can($this->admin, 'cancel', $request));
        $this->assertFalse($can($this->manager->user, 'cancel', $request));

        $this->travelTo(Carbon::parse('2026-06-16 17:00:00'));
        $this->assertFalse($can($this->employee->user, 'cancel', $request->fresh()));
        $this->assertTrue($can($this->admin, 'cancel', $request->fresh()));
    }

    public function test_lists_are_scoped_like_leave(): void
    {
        $mine = $this->overtime('2026-06-16');
        $outsider = $this->person('Outsider', 'employee');
        OvertimeRequest::factory()->for($outsider)->create();

        $this->assertSame([$mine->id], OvertimeRequest::visibleTo($this->manager->user)->pluck('id')->all());
        $this->assertSame([$mine->id], OvertimeRequest::visibleTo($this->employee->user)->pluck('id')->all());
        $this->assertSame(2, OvertimeRequest::visibleTo($this->admin)->count());
    }
}
