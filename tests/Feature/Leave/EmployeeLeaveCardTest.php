<?php

namespace Tests\Feature\Leave;

use App\Livewire\Employees\LeaveCard;
use App\Livewire\Leave\RequestModal;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3e — the employee profile's Leave card: balances, requests with
 * their step history and adjustments for an admin; read only for the
 * employee's manager; "File leave" opens the request modal with the
 * employee locked. The clock is Mon 15 Jun 2026.
 */
class EmployeeLeaveCardTest extends TestCase
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
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'seniority_bonus' => false, 'min_service_months' => null]);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
        $this->manager = Employee::factory()->create(['full_name' => 'Manager', 'user_id' => User::factory()->create(['name' => 'Manager'])->assignRole('manager')->id]);
        $this->employee = Employee::factory()->create(['full_name' => 'Employee', 'manager_id' => $this->manager->id, 'user_id' => User::factory()->create(['name' => 'Employee'])->assignRole('employee')->id]);
    }

    public function test_an_admin_sees_balances_requests_with_their_history_and_the_admin_actions(): void
    {
        $leave = app(LeaveRequestService::class)->submit($this->employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-23'), null, 'Family visit', $this->employee->user)['leave'];
        app(LeaveRequestService::class)->approve($leave, $this->manager->user, 'Fine by me');

        $this->actingAs($this->admin)->get(route('employees.show', $this->employee))->assertOk()->assertSee('Balances, requests and adjustments.');

        Livewire::actingAs($this->admin)->test(LeaveCard::class, ['employee' => $this->employee])
            ->assertSee('Annual')
            ->assertSeeText('Annual · 22–23 Jun · 2 days')
            ->assertSee('Waiting for admin')
            ->assertSee('“Family visit”', false)
            ->assertSee('Step 1: approved by Manager · Mon 15 Jun — “Fine by me”', false)
            ->assertSeeHtml("file-leave', { employeeId: {$this->employee->id}, lock: true })")
            ->assertSee('Add adjustment');
    }

    public function test_the_employees_manager_sees_it_read_only(): void
    {
        Livewire::actingAs($this->manager->user)->test(LeaveCard::class, ['employee' => $this->employee])
            ->assertSee('Balances, requests and adjustments.')
            ->assertDontSee('File leave')
            ->assertDontSee('Add adjustment')
            ->call('openAdjustment')
            ->assertForbidden();

        // Someone the manager doesn't manage: no card at all.
        $other = Employee::factory()->create();
        Livewire::actingAs($this->manager->user)->test(LeaveCard::class, ['employee' => $other])->assertForbidden();
    }

    public function test_an_adjustment_needs_a_note_is_never_zero_and_changes_the_balance(): void
    {
        $card = Livewire::actingAs($this->admin)->test(LeaveCard::class, ['employee' => $this->employee])
            ->call('openAdjustment')
            ->assertSee('fix a mistake with a reversing entry')
            ->assertSee('Opening balances')
            ->set('adj_leave_type_id', (string) $this->annual->id)
            ->set('adj_days', '0')
            ->call('saveAdjustment')
            ->assertHasErrors(['adj_days', 'adj_note' => 'required']);

        $card->set('adj_days', '-1.5')->set('adj_note', 'Opening balance: taken before go-live')->call('saveAdjustment')
            ->assertHasNoErrors()
            ->assertSee('Adjusted Annual 2026 by −1.5 days.')
            ->assertSee('“Opening balance: taken before go-live”', false);

        $card->call('openAdjustment')->set('adj_leave_type_id', (string) $this->annual->id)->set('adj_days', '+2')->set('adj_note', 'Correction')->call('saveAdjustment')
            ->assertHasNoErrors();

        $this->assertSame(['-1.5', '2.0'], LeaveAdjustment::orderBy('id')->pluck('days')->all());
        $this->assertSame(185, app(LeaveBalance::class)->for($this->employee, $this->annual, 2026)->available());
    }

    public function test_file_leave_opens_the_request_modal_with_the_employee_locked(): void
    {
        Livewire::actingAs($this->admin)->test(RequestModal::class)
            ->call('openForOthers', $this->employee->id, true)
            ->assertSet('employeeId', $this->employee->id)
            ->assertSet('employeeLocked', true)
            ->assertSee('Employee')
            ->call('clearEmployee')
            ->assertForbidden();

        // A filing from the modal lands on the card as a notice.
        Livewire::actingAs($this->admin)->test(LeaveCard::class, ['employee' => $this->employee])
            ->dispatch('leave-saved', message: 'Filed Annual leave for Employee — approved.')
            ->assertSee('Filed Annual leave for Employee — approved.');
    }

    public function test_a_leaver_shows_what_was_earned_to_their_last_day(): void
    {
        $this->employee->update(['status' => 'inactive', 'left_on' => '2026-06-10']);

        Livewire::actingAs($this->admin)->test(LeaveCard::class, ['employee' => $this->employee->fresh()])
            ->assertSee('Earned to last day:');
    }
}
