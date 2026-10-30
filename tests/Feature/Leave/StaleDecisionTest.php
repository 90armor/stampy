<?php

namespace Tests\Feature\Leave;

use App\Contracts\Approvable;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Livewire\Leave\Approvals;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalInbox;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6b, F02 — deciding a request that moved on while the Approvals page
 * was open (cancelled, decided by someone else, or past the manager's step)
 * shows a calm notice, closes the dialog and refreshes the list — for leave
 * and overtime alike — instead of Livewire's error overlay. Someone who may
 * not decide it at all still gets a 403. The clock is Mon 15 Jun 2026 12:00.
 */
class StaleDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $senior;

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

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);

        $this->senior = $this->person('Htet Htet Oo', 'manager');
        $this->manager = $this->person('Aye Aye Mon', 'manager', $this->senior);
        $this->employee = $this->person('Kyaw Kyaw', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, string $role, ?Employee $manager = null): Employee
    {
        $user = User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user->id, 'manager_id' => $manager?->id]);
    }

    /** A pending request at step 1, filed by the employee: leave, or planned overtime two days ahead. */
    private function pending(string $type): Approvable
    {
        return $type === 'leave'
            ? app(LeaveRequestService::class)->submit($this->employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $this->employee->user)['leave']
            : app(OvertimeRequestService::class)->submit($this->employee, Carbon::parse('2026-06-17'), Carbon::parse('2026-06-17 17:00'), Carbon::parse('2026-06-17 19:00'), OvertimeCompensation::Pay, null, $this->employee->user)['request'];
    }

    /** The request as its queue row names it: one person often has several waiting. */
    private function subject(string $type): string
    {
        return $type === 'leave' ? 'leave request (Annual, Mon 22 Jun)' : 'overtime request (Wed 17 Jun, 5:00 PM – 7:00 PM)';
    }

    private function service(Approvable $request): LeaveRequestService|OvertimeRequestService
    {
        return app($request instanceof Leave ? LeaveRequestService::class : OvertimeRequestService::class);
    }

    public static function types(): array
    {
        return ['leave' => ['leave'], 'overtime' => ['overtime']];
    }

    #[DataProvider('types')]
    public function test_cancelled_while_the_dialog_was_open(string $type): void
    {
        $request = $this->pending($type);

        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', $type, 1)
            ->assertSet('showDecision', true);

        $this->service($request)->cancel($request, $this->employee->user);

        $page->call('decide')
            ->assertSet('showDecision', false)
            ->assertSee('Kyaw Kyaw cancelled their '.$this->subject($type).'.')
            ->assertSee('Nothing waiting for you')
            ->assertDontSee('You don')
            // The item may have been far down the list: the notice scrolls into view.
            ->assertSeeHtml('x-init="$el.scrollIntoView({ block: \'center\' })"');

        $this->assertSame('cancelled', $request->fresh()->status->value);
    }

    #[DataProvider('types')]
    public function test_cancelled_before_the_dialog_opened(string $type): void
    {
        $request = $this->pending($type);
        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class);

        $this->service($request)->cancel($request, $this->employee->user);

        $page->call('openDecision', $request->id, 'reject', $type, 1)
            ->assertSet('showDecision', false)
            ->assertSee('Kyaw Kyaw cancelled their '.$this->subject($type).'.');
    }

    #[DataProvider('types')]
    public function test_approved_by_another_approver(string $type): void
    {
        $request = $this->pending($type);
        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'reject', $type, 1);

        // An admin acting at step 1 completes both steps.
        $this->service($request)->approve($request, $this->admin);

        $page->set('note', 'Not this week.')
            ->call('decide')
            ->assertSet('showDecision', false)
            ->assertSee("Kyaw Kyaw's ".$this->subject($type).' was already approved by Admin on Mon 15 Jun.');

        $this->assertSame('approved', $request->fresh()->status->value);
    }

    #[DataProvider('types')]
    public function test_rejected_by_another_approver(string $type): void
    {
        $request = $this->pending($type);
        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', $type, 1);

        $this->service($request)->reject($request, $this->admin, 'Too busy.');

        $page->call('decide')
            ->assertSee("Kyaw Kyaw's ".$this->subject($type).' was already rejected by Admin on Mon 15 Jun.');
    }

    #[DataProvider('types')]
    public function test_the_manager_step_already_done_by_a_skip_level_manager(string $type): void
    {
        $request = $this->pending($type);
        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class);

        // The manager's own manager approves step 1 meanwhile.
        $this->service($request)->approve($request, $this->senior->user);
        $this->assertSame(2, $request->fresh()->current_step);

        $page->call('openDecision', $request->id, 'approve', $type, 1)
            ->assertSet('showDecision', false)
            ->assertSee("Kyaw Kyaw's ".$this->subject($type).' has already moved on to admin approval.');

        $this->assertSame(2, $request->fresh()->current_step);
    }

    #[DataProvider('types')]
    public function test_someone_who_may_not_decide_it_still_gets_a_403(string $type): void
    {
        $request = $this->pending($type);
        $stranger = $this->person('Zaw Zaw Htet', 'manager');

        Livewire::actingAs($stranger->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', $type, 1)
            ->assertForbidden();

        // Not even a cancelled request's story leaks to them.
        $this->service($request)->cancel($request, $this->employee->user);

        Livewire::actingAs($stranger->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', $type, 1)
            ->assertForbidden();
    }

    public function test_a_request_still_pending_at_its_step_opens_as_before(): void
    {
        $leave = $this->pending('leave');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $leave->id, 'approve', 'leave', 1)
            ->assertSet('showDecision', true)
            ->call('decide')
            ->assertSee('it now waits for an admin');

        $this->assertSame(LeaveStatus::Pending, $leave->fresh()->status);
        $this->assertSame(2, $leave->fresh()->current_step);
    }

    #[DataProvider('types')]
    public function test_an_admin_who_cancelled_it_is_named_with_whose_request_it_was(string $type): void
    {
        $request = $this->pending($type);
        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class);

        $this->service($request)->cancel($request, $this->admin);

        $page->call('openDecision', $request->id, 'approve', $type, 1)
            ->assertSee("Admin cancelled Kyaw Kyaw's ".$this->subject($type).'.');
    }

    public function test_the_notice_goes_on_the_next_approve_or_reject(): void
    {
        $leave = $this->pending('leave');
        $overtime = $this->pending('overtime');
        $page = Livewire::actingAs($this->manager->user)->test(Approvals::class);

        app(LeaveRequestService::class)->cancel($leave, $this->employee->user);

        $page->call('openDecision', $leave->id, 'approve', 'leave', 1)
            ->assertSee('Kyaw Kyaw cancelled their leave request')
            // The next decision opened: no old notice above it.
            ->call('openDecision', $overtime->id, 'reject', 'overtime', 1)
            ->assertSet('stale', null)
            ->assertDontSee('Kyaw Kyaw cancelled their leave request')
            ->set('note', 'Not this week.')
            ->call('decide')
            ->assertSet('stale', null)
            ->assertSee("Rejected Kyaw Kyaw's overtime on Wed 17 Jun.");
    }

    /**
     * The sidebar badge and the menu-button dot are rendered with the page;
     * every render of Approvals sends them the count of the list it shows, so
     * after a stale removal or a decision they agree with it — for a manager
     * (Waiting for you) and an admin (Waiting for admin; no overrides here,
     * whose non-stuck items the badge leaves out by design).
     */
    public function test_the_badge_follows_the_queue_after_a_stale_removal_and_a_decision(): void
    {
        // The manager: two requests at step 1.
        $leave = $this->pending('leave');
        $overtime = $this->pending('overtime');
        $manager = $this->manager->user;
        $page = Livewire::actingAs($manager)->test(Approvals::class);
        $this->assertBadgeIsTheQueue($page, $manager, 2);

        app(LeaveRequestService::class)->cancel($leave, $this->employee->user);
        $page->call('openDecision', $leave->id, 'approve', 'leave', 1);
        $this->assertBadgeIsTheQueue($page, $manager, 1);

        $page->call('openDecision', $overtime->id, 'approve', 'overtime', 1)->call('decide');
        $this->assertBadgeIsTheQueue($page, $manager, 0);

        // The admin: two requests at step 2.
        $leave = $this->pending('leave');
        app(LeaveRequestService::class)->approve($leave, $manager);
        $this->assertSame(2, $overtime->fresh()->current_step);
        $page = Livewire::actingAs($this->admin)->test(Approvals::class);
        $this->assertBadgeIsTheQueue($page, $this->admin, 2);

        app(LeaveRequestService::class)->cancel($leave, $this->employee->user);
        $page->call('openDecision', $leave->id, 'approve', 'leave', 2);
        $this->assertBadgeIsTheQueue($page, $this->admin, 1);

        $page->call('openDecision', $overtime->id, 'approve', 'overtime', 2)->call('decide');
        $this->assertBadgeIsTheQueue($page, $this->admin, 0);
        $this->assertSame(OvertimeStatus::Approved, $overtime->fresh()->status);
    }

    public function test_the_sidebar_badge_and_the_menu_dot_listen_for_the_refresh(): void
    {
        $this->pending('leave');

        $this->actingAs($this->manager->user)->get(route('approvals.index'))
            ->assertSeeHtml('x-on:approval-badge.window="count = $event.detail.count"')
            ->assertSeeHtml('x-on:approval-badge.window="waiting = $event.detail.count"')
            ->assertSee('Open sidebar — 1 approval waiting');
    }

    private function assertBadgeIsTheQueue($page, User $user, int $expected): void
    {
        $queue = count($page->viewData('stepOne')) + count($page->viewData('stepTwo'));

        $this->assertSame($expected, $queue);
        $this->assertSame([], $page->viewData('overrides'));
        $page->assertDispatched('approval-badge', count: $expected);
        // What the badge shows on the next page load.
        $this->assertSame($expected, ApprovalInbox::count($user));
    }
}
