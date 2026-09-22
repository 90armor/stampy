<?php

namespace Tests\Feature;

use App\Livewire\Employees\Index;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeDirectoryScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * A manager with a direct report, a skip-level report, and people outside the team.
     *
     * @return array{manager: User, team: list<Employee>, outsiders: list<Employee>}
     */
    private function org(): array
    {
        $top = Employee::factory()->create(['full_name' => 'Team Manager']);
        $direct = Employee::factory()->create(['full_name' => 'Direct Report', 'manager_id' => $top->id]);
        $skip = Employee::factory()->create(['full_name' => 'Skip Level Report', 'manager_id' => $direct->id, 'status' => 'inactive']);

        $peer = Employee::factory()->create(['full_name' => 'Outside Peer']);
        $otherManager = Employee::factory()->create(['full_name' => 'Other Manager']);
        $otherBranch = Employee::factory()->create(['full_name' => 'Other Branch Person', 'manager_id' => $otherManager->id]);

        return [
            'manager' => $this->managerUser($top),
            'team' => [$top, $direct, $skip],
            'outsiders' => [$peer, $otherManager, $otherBranch],
        ];
    }

    public function test_a_manager_sees_only_themself_and_their_transitive_reports(): void
    {
        ['manager' => $manager] = $this->org();

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertSee('Team Manager')
            ->assertSee('Direct Report')
            ->assertSee('Skip Level Report')
            ->assertDontSee('Outside Peer')
            ->assertDontSee('Other Manager')
            ->assertDontSee('Other Branch Person');
    }

    public function test_a_managers_stats_describe_their_team_not_the_company(): void
    {
        ['manager' => $manager] = $this->org();

        // Team of 3 (2 active, 1 inactive) inside a company of 6.
        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertViewHas('stats', fn (array $stats) => $stats === ['total_employees' => 3, 'active_employees' => 2, 'inactive_employees' => 1]);
    }

    public function test_an_admin_still_sees_everyone_and_company_wide_stats(): void
    {
        $this->org();
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('Team Manager')
            ->assertSee('Outside Peer')
            ->assertSee('Other Branch Person')
            ->assertViewHas('stats', fn (array $stats) => $stats['total_employees'] === 6 && $stats['inactive_employees'] === 1);
    }

    public function test_every_employee_the_directory_lists_is_one_the_viewer_may_open(): void
    {
        ['manager' => $manager] = $this->org();

        // The directory's rows link to the detail page, which is authorised by the policy.
        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertViewHas('employees', fn ($page) => $page->count() === 3 && $page->every(fn (Employee $employee) => $manager->can('view', $employee)));
    }

    public function test_search_and_filters_cannot_reach_outside_the_team(): void
    {
        ['manager' => $manager, 'outsiders' => [$peer]] = $this->org();

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->set('search', 'Outside')
            ->assertDontSee('Outside Peer')
            ->assertSee('No employees found');

        // A department that only outsiders belong to isn't offered, and filtering by it yields nothing.
        $outsiderOnly = Department::factory()->create(['name' => 'Outsiders Only']);
        $peer->update(['department_id' => $outsiderOnly->id]);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertViewHas('departments', fn ($departments) => ! $departments->contains('name', 'Outsiders Only'))
            ->set('departmentFilter', (string) $outsiderOnly->id)
            ->assertDontSee('Outside Peer');
    }

    public function test_a_manager_with_no_linked_employee_gets_the_same_explicit_empty_state_as_the_attendance_list(): void
    {
        $this->org();
        $manager = User::factory()->create()->assignRole('manager');

        Log::spy();

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertSee("Your account isn't linked to an employee record")
            ->assertSee("The employee directory can't be scoped to you")
            ->assertDontSee('Team Manager')
            ->assertDontSee('Outside Peer')
            ->assertDontSee('Total employees');

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }
}
