<?php

namespace Tests\Feature\Leave;

use App\Contracts\Approvable;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeCompensation;
use App\Livewire\Leave\Approvals;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
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
            ->assertSee('Kyaw Kyaw cancelled this request.')
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
            ->assertSee('Kyaw Kyaw cancelled this request.');
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
            ->assertSee('Already approved by Admin on Mon 15 Jun.');

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
            ->assertSee('Already rejected by Admin on Mon 15 Jun.');
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
            ->assertSee('Already moved on to admin approval.');

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
}
