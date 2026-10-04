<?php

namespace Tests\Feature;

use App\Livewire\Positions\Index;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PositionManagementTest extends TestCase
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

    public function test_admin_can_create_a_position(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Human Resources Manager')
            ->call('save');

        $this->assertDatabaseHas('positions', ['name' => 'Human Resources Manager']);
    }

    public function test_position_workspace_content_and_actions_are_accessible(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Position::factory()->create(['name' => 'Customer Success Lead']);

        $component = Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('Manage the organization-wide job titles assigned to employees.')
            ->assertSee('aria-label="Positions"', false)
            ->assertSee('aria-label="Edit Customer Success Lead position"', false)
            ->assertSee('aria-label="Delete Customer Success Lead position"', false)
            ->assertSee('role="tooltip"', false);

        $component->call('create')
            ->assertSee('wire:loading.attr="disabled"', false)
            ->assertSee('Saving&hellip;', false);
    }

    public function test_admin_can_edit_a_position(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $position = Position::factory()->create(['name' => 'Old Title']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $position->id)
            ->set('name', 'New Title')
            ->call('save');

        $this->assertSame('New Title', $position->fresh()->name);
    }

    public function test_position_name_must_be_unique(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Position::factory()->create(['name' => 'Software Engineer']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Software Engineer')
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

        // Reopening "Add Position" (without saving) must not still show
        // the previous submit's error.
        $component->call('create')->assertHasNoErrors();
    }

    public function test_editing_a_position_can_keep_its_own_name(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $position = Position::factory()->create(['name' => 'Software Engineer']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $position->id)
            ->set('name', 'Software Engineer')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_position_with_employees_cannot_be_deleted(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $position = Position::factory()->create();
        Employee::factory()->create(['position_id' => $position->id]);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('delete', $position->id)
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('positions', ['id' => $position->id]);
    }

    public function test_empty_position_can_be_deleted(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $position = Position::factory()->create();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('delete', $position->id);

        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
    }

    public function test_manager_cannot_access_positions(): void
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
        $existing = Position::factory()->create(['name' => 'Existing Position']);

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

        $this->assertDatabaseHas('positions', ['id' => $existing->id]);
        $this->assertDatabaseMissing('positions', ['name' => 'Direct Save']);
    }
}
