<?php

namespace Tests\Feature;

use App\Livewire\Employees\Show;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every employee is now assigned a schedule at creation, which needs a default to exist.
        WorkSchedule::factory()->create(['is_default' => true]);

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

    public function test_the_profile_shows_no_manager_as_a_dash(): void
    {
        $employee = Employee::factory()->create(['manager_id' => null]);

        $this->actingAs($this->admin())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSeeHtml('&mdash;');
    }

    public function test_an_admin_sees_the_manager_as_a_link(): void
    {
        $manager = Employee::factory()->create(['full_name' => 'Boss Person']);
        $employee = Employee::factory()->create(['manager_id' => $manager->id]);

        $this->actingAs($this->admin())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Boss Person')
            ->assertSeeHtml('href="'.route('employees.show', $manager).'"');
    }

    public function test_a_manager_viewing_their_own_profile_sees_their_own_manager_as_plain_text(): void
    {
        // isManagerOf() only ever walks manager_id DOWNWARD, so a manager who
        // can view a page at all (here, their own record — always allowed)
        // has no policy access to their own superior, one level UP. The
        // manager link must therefore fall back to plain text rather than a
        // link to a page this viewer would just be denied.
        $superior = Employee::factory()->create(['full_name' => 'Superior Person']);
        $self = Employee::factory()->create(['full_name' => 'Acting Manager', 'manager_id' => $superior->id]);

        $this->actingAs($this->managerUser($self))
            ->get(route('employees.show', $self))
            ->assertOk()
            ->assertSee('Superior Person')
            ->assertDontSeeHtml('href="'.route('employees.show', $superior).'"');
    }

    public function test_the_profile_shows_no_account_when_unlinked(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->actingAs($this->admin())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('No account');
    }

    public function test_the_profile_shows_the_username_and_password_state_for_a_linked_account(): void
    {
        $user = User::factory()->create(['username' => 'jane.doe', 'must_change_password' => true]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($this->admin())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Has an account')
            ->assertSee('jane.doe')
            ->assertSee('Change pending');
    }

    public function test_the_profile_shows_no_pending_password_change_once_its_cleared(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($this->admin())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Up to date')
            ->assertDontSee('Change pending');
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

    /**
     * A real request starts with an empty per-process cache; within one test
     * process it would otherwise still hold the previous "request's" answer.
     */
    private function forgetCachedSubordinates(): void
    {
        (new ReflectionProperty(Employee::class, 'subordinateIdsCache'))->setValue(null, []);
    }

    public function test_render_re_asserts_access_even_if_the_employee_property_is_set_directly(): void
    {
        $top = Employee::factory()->create();
        $peer = Employee::factory()->create();

        $this->actingAs($this->managerUser($top));

        $component = new Show;
        $component->employee = $peer;

        $this->expectException(AuthorizationException::class);

        $component->render();
    }

    public function test_render_still_serves_the_page_to_someone_who_may_view_it(): void
    {
        $top = Employee::factory()->create();
        $report = Employee::factory()->create(['manager_id' => $top->id]);

        $component = new Show;
        $component->employee = $report;

        $this->actingAs($this->managerUser($top));

        $this->assertNotNull($component->render());
    }

    public function test_a_page_opened_while_authorized_stops_serving_data_once_access_is_revoked(): void
    {
        $top = Employee::factory()->create();
        $report = Employee::factory()->create(['manager_id' => $top->id, 'full_name' => 'Revoked Report']);

        $component = Livewire::actingAs($this->managerUser($top))
            ->test(Show::class, ['employee' => $report])
            ->assertSee('Revoked Report');

        // The report is moved out of this manager's team while their page is still open, then an
        // edit elsewhere (the FormModal) fires the event this component re-renders on.
        $report->update(['manager_id' => null]);
        $this->forgetCachedSubordinates();

        $component->dispatch('employee-saved')->assertForbidden();
    }
}
