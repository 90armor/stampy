<?php

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Livewire\Leave\Approvals;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalInbox;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3e — the Approvals page, its sidebar badge and topbar dot. The
 * clock is Mon 15 Jun 2026.
 */
class ApprovalsPageTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private Department $sales;

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
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $this->sales = Department::factory()->create(['name' => 'Sales']);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id, 'department_id' => $this->sales->id]);
    }

    private function request(Employee $employee, string $from, string $to, ?string $reason = null): Leave
    {
        return app(LeaveRequestService::class)->submit($employee, $this->annual, Carbon::parse($from), Carbon::parse($to), null, $reason, $employee->user ?? $this->admin)['leave'];
    }

    public function test_access_follows_the_decide_ability_and_so_do_the_sidebar_and_badge(): void
    {
        $this->request($this->employee, '2026-06-22', '2026-06-23');

        $this->actingAs($this->manager->user)->get(route('approvals.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk();
        $this->actingAs($this->employee->user)->get(route('approvals.index'))->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole('manager'))->get(route('approvals.index'))->assertForbidden();

        // The manager's sidebar badge and the menu button's dot label.
        $this->actingAs($this->manager->user)->get(route('dashboard'))
            ->assertSee(route('approvals.index'))
            ->assertSeeHtml('aria-label="1 waiting"')
            ->assertSee('Open sidebar — 1 approval waiting');
        $this->actingAs($this->employee->user)->get(route('dashboard'))
            ->assertDontSee(route('approvals.index'))
            ->assertDontSee('approval waiting');
    }

    public function test_the_groups_and_what_each_request_shows(): void
    {
        $leave = $this->request($this->employee, '2026-06-22', '2026-06-24', 'Family wedding');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertSee('Waiting for you')
            ->assertDontSee('Waiting for admin')
            ->assertSee('Annual · 22–24 Jun · 3 days')
            ->assertSee('“Family wedding”', false)
            // 18 granted, 3 reserved by this request.
            ->assertSee('Annual available after this request: 15');

        $this->service()->approve($leave, $this->manager->user, 'Fine by me');

        Livewire::actingAs($this->admin)->test(Approvals::class)
            ->assertSee('Waiting for admin')
            ->assertSee('Step 1: approved by Manager · Mon 15 Jun — “Fine by me”', false);
    }

    public function test_overrides_are_collapsed_until_opened(): void
    {
        $this->request($this->employee, '2026-06-22', '2026-06-22');

        Livewire::actingAs($this->admin)->test(Approvals::class)
            ->assertSee('You can override')
            ->assertSee('Show 1')
            ->assertDontSee('Annual · Mon 22 Jun')
            ->set('showOverrides', true)
            ->assertSee('Annual · Mon 22 Jun');
    }

    public function test_also_off_lists_department_colleagues_the_approver_can_see(): void
    {
        $colleague = $this->person('Colleague', 'employee', $this->manager);
        $this->request($colleague, '2026-06-23', '2026-06-23');
        // In Sales too, but not this manager's report: never named to them.
        $outsider = $this->person('Outsider', 'employee');
        $this->request($outsider, '2026-06-22', '2026-06-22');
        $this->request($this->employee, '2026-06-22', '2026-06-24');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertSee('1 other in Sales during these dates — Colleague (Annual, Tue 23 Jun, pending).')
            ->assertDontSee('Outsider');
    }

    public function test_reject_needs_a_note_and_approve_does_not(): void
    {
        $first = $this->request($this->employee, '2026-06-22', '2026-06-22');
        $second = $this->request($this->employee, '2026-06-23', '2026-06-23');

        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $first->id, 'reject')
            ->call('decide')
            ->assertHasErrors(['note' => 'required'])
            ->set('note', 'Stocktake that day')
            ->call('decide')
            ->assertHasNoErrors()
            ->assertSee("Rejected Employee's Annual leave.");
        $this->assertSame(LeaveStatus::Rejected, $first->fresh()->status);

        $page->call('openDecision', $second->id, 'approve')->call('decide')
            ->assertSee("Approved Employee's Annual leave — it now waits for an admin.");
        $this->assertSame(2, $second->fresh()->current_step);
    }

    public function test_an_admin_at_step_one_is_told_it_completes_both_steps(): void
    {
        $leave = $this->request($this->employee, '2026-06-22', '2026-06-22');

        Livewire::actingAs($this->admin)->test(Approvals::class)
            ->call('openDecision', $leave->id, 'approve')
            ->assertSee('approving completes both steps')
            ->call('decide');

        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
    }

    public function test_a_stale_decision_shows_the_services_message_and_refreshes(): void
    {
        $leave = $this->request($this->employee, '2026-06-22', '2026-06-22');

        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $leave->id, 'approve');

        // Meanwhile an admin decides it.
        $this->service()->approve($leave, $this->admin);

        $page->call('decide')
            ->assertSee('This request has already been approved.')
            ->assertSee('Nothing waiting for you');
    }

    public function test_the_count_is_what_waits_on_the_user_and_overrides_only_once_stuck(): void
    {
        // Mon 15 Jun: a request starting in two weeks, at step 1.
        $later = $this->request($this->employee, '2026-06-29', '2026-06-30');

        // The manager: their step 1. The admin: not yet — it isn't stuck.
        $this->assertSame(1, ApprovalInbox::count($this->manager->user));
        $this->assertSame(0, ApprovalInbox::count($this->admin));
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertDontSee('Pending approvals')
            ->assertDontSee('approval waiting');

        // Tue: one working day waited. Wed: two — stuck, so the admin's too.
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));
        $this->assertSame(0, ApprovalInbox::count($this->admin));
        $this->travelTo(Carbon::parse('2026-06-17 09:00:00'));
        $this->assertSame(1, ApprovalInbox::count($this->admin));
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertSee('Pending approvals')
            ->assertSee('Open sidebar — 1 approval waiting');

        // Step 2 always counts for an admin.
        $this->service()->approve($later, $this->manager->user);
        $this->assertSame(1, ApprovalInbox::count($this->admin));
        $this->assertSame(0, ApprovalInbox::count($this->manager->user));
    }

    public function test_stuck_means_two_working_days_waited_or_starting_within_two_days(): void
    {
        // Submitted Fri 19 Jun: Mon is one working day, Tue two.
        $this->travelTo(Carbon::parse('2026-06-19 16:00:00'));
        $leave = $this->request($this->employee, '2026-07-06', '2026-07-06');

        $this->assertFalse(ApprovalInbox::isStuck($leave, Carbon::parse('2026-06-22')));
        $this->assertTrue(ApprovalInbox::isStuck($leave, Carbon::parse('2026-06-23')));

        // Starting within two working days (from Friday: Mon or Tue), or
        // already under way: stuck at once.
        $this->assertTrue(ApprovalInbox::isStuck($this->request($this->employee, '2026-06-22', '2026-06-22')));
        $this->assertTrue(ApprovalInbox::isStuck($this->request($this->employee, '2026-06-23', '2026-06-23')));
        $this->assertFalse(ApprovalInbox::isStuck($this->request($this->employee, '2026-06-24', '2026-06-24')));
    }

    public function test_overrides_put_stuck_first_and_items_say_when_they_were_submitted_and_start(): void
    {
        // Mon: one starting 6 Jul. Wed: one starting 29 Jun, one tomorrow, one taken last week.
        $waiting = $this->request($this->employee, '2026-07-06', '2026-07-06', 'Waited since Monday');
        $this->travelTo(Carbon::parse('2026-06-17 09:00:00'));
        $this->request($this->employee, '2026-06-29', '2026-06-29', 'Just submitted');
        $this->request($this->employee, '2026-06-18', '2026-06-18', 'Tomorrow');
        $this->request($this->employee, '2026-06-10', '2026-06-10', 'Filed after the fact');

        Livewire::actingAs($this->admin)->test(Approvals::class)
            ->set('showOverrides', true)
            // Stuck ones first by start date (10, 18 Jun, 6 Jul), then the rest.
            ->assertSeeInOrder(['Filed after the fact', 'Tomorrow', 'Waited since Monday', 'Just submitted'])
            ->assertSee('Submitted Mon 15 Jun')
            ->assertSee('Submitted Wed 17 Jun · Starts tomorrow')
            ->assertSee('Submitted Wed 17 Jun · Already taken')
            ->assertSee('Stuck');

        // The manager's own group is by start date, with no Stuck marker.
        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertSeeInOrder(['Filed after the fact', 'Tomorrow', 'Just submitted', 'Waited since Monday'])
            ->assertDontSee('Stuck');
        $this->assertSame(4, ApprovalInbox::count($this->manager->user));
        $this->assertNotNull($waiting);
    }

    private function service(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }
}
