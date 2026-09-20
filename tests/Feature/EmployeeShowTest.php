<?php

namespace Tests\Feature;

use App\Livewire\Employees\Show;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_an_admin_can_view_any_employees_profile(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Profile Target']);

        $this->actingAs($this->admin())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Profile Target');
    }

    public function test_a_manager_can_view_their_own_profile(): void
    {
        $self = Employee::factory()->create(['full_name' => 'Manager Themself']);

        $this->actingAs($this->managerUser($self))
            ->get(route('employees.show', $self))
            ->assertOk()
            ->assertSee('Manager Themself');
    }

    public function test_a_manager_can_view_a_report_at_any_depth(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id, 'full_name' => 'Deep Report']);

        $this->actingAs($this->managerUser($top))
            ->get(route('employees.show', $leaf))
            ->assertOk()
            ->assertSee('Deep Report');
    }

    public function test_a_manager_cannot_view_a_peer(): void
    {
        $top = Employee::factory()->create();
        $peer = Employee::factory()->create(['full_name' => 'A Peer']);

        $this->actingAs($this->managerUser($top))
            ->get(route('employees.show', $peer))
            ->assertForbidden();
    }

    public function test_a_manager_cannot_view_someone_in_another_branch(): void
    {
        $top = Employee::factory()->create();
        $otherManager = Employee::factory()->create();
        $otherBranch = Employee::factory()->create(['manager_id' => $otherManager->id]);

        $this->actingAs($this->managerUser($top))
            ->get(route('employees.show', $otherBranch))
            ->assertForbidden();
    }

    public function test_a_manager_with_no_linked_employee_record_is_denied(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create();

        $this->actingAs($manager)
            ->get(route('employees.show', $employee))
            ->assertForbidden();
    }

    public function test_an_employee_role_user_cannot_reach_the_page_even_for_their_own_record(): void
    {
        // Blocked by the route's role:admin|manager middleware, before the policy is asked.
        $own = Employee::factory()->create();
        $user = User::factory()->create()->assignRole('employee');
        $own->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('employees.show', $own))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $employee = Employee::factory()->create();

        $this->get(route('employees.show', $employee))->assertRedirect(route('login'));
    }

    public function test_the_component_enforces_the_policy_itself_when_driven_directly(): void
    {
        $top = Employee::factory()->create();
        $peer = Employee::factory()->create();
        $manager = $this->managerUser($top);

        Livewire::actingAs($manager)
            ->test(Show::class, ['employee' => $peer])
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test(Show::class, ['employee' => $top])
            ->assertOk();
    }
}
