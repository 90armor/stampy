<?php

namespace Tests\Feature;

use App\Livewire\Departments\Index;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
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

    public function test_admin_can_create_a_department(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Human Resources')
            ->call('save');

        $this->assertDatabaseHas('departments', ['name' => 'Human Resources']);
    }

    public function test_organization_workspace_and_department_actions_are_accessible(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create(['name' => 'Customer Success']);

        $this->actingAs($admin)
            ->get(route('organization.index'))
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Organization</h1>', false)
            ->assertDontSee('Departments, Positions, Holidays &amp; Schedules', false)
            ->assertSee('aria-label="Organization sections"', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('aria-labelledby="confirm-dialog-departments-title"', false)
            ->assertSee('aria-describedby="confirm-dialog-departments-description"', false)
            ->assertSee('@keydown.tab="trapTab($event)"', false);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('Define the teams employees belong to.')
            ->assertSee('aria-label="Edit Customer Success department"', false)
            ->assertSee('aria-label="Delete Customer Success department"', false)
            ->assertSee('role="tooltip"', false);
    }

    public function test_admin_can_edit_a_department(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $department->id)
            ->set('name', 'New Name')
            ->call('save');

        $this->assertSame('New Name', $department->fresh()->name);
    }

    public function test_department_name_must_be_unique(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Department::factory()->create(['name' => 'Engineering']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Engineering')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_reopening_the_create_modal_clears_a_previous_validation_error(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $component = Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);

        // Reopening "Add Department" (without saving) must not still show
        // the previous submit's error.
        $component->call('create')->assertHasNoErrors();
    }

    public function test_editing_a_department_can_keep_its_own_name(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create(['name' => 'Engineering']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $department->id)
            ->set('name', 'Engineering')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_department_with_employees_cannot_be_deleted(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();
        Employee::factory()->create(['department_id' => $department->id]);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('delete', $department->id)
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    public function test_empty_department_can_be_deleted(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('delete', $department->id);

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_manager_cannot_access_departments(): void
    {
        $manager = User::factory()->create()->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('organization.index'))
            ->assertForbidden();
    }

    /**
     * The component's mount() refuses anyone but an admin, so to drive an
     * action directly the page is opened as an admin and the acting user is
     * then swapped — the action must re-check on its own, not lean on mount().
     */
    private function pageOpenedByAnAdminThenDowngradedTo(string $role): Testable
    {
        $component = Livewire::actingAs(User::factory()->create()->assignRole('admin'))->test(Index::class);

        $this->actingAs(User::factory()->create()->assignRole($role));

        return $component;
    }

    public function test_a_non_admin_cannot_mount_the_component_directly(): void
    {
        foreach (['manager', 'employee'] as $role) {
            Livewire::actingAs(User::factory()->create()->assignRole($role))
                ->test(Index::class)
                ->assertForbidden();
        }
    }

    public function test_every_action_re_authorizes_when_driven_directly_by_a_non_admin(): void
    {
        $existing = Department::factory()->create(['name' => 'Existing Department']);

        foreach (['manager', 'employee'] as $role) {
            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('create')->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('edit', $existing->id)->assertForbidden();

            // save() without going through create()/edit() first.
            $this->pageOpenedByAnAdminThenDowngradedTo($role)
                ->set('name', 'Direct Save')
                ->call('save')
                ->assertForbidden();

            $this->pageOpenedByAnAdminThenDowngradedTo($role)->call('delete', $existing->id)->assertForbidden();
        }

        $this->assertDatabaseHas('departments', ['id' => $existing->id]);
        $this->assertDatabaseMissing('departments', ['name' => 'Direct Save']);
    }
}
