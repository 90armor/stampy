<?php

namespace Tests\Feature;

use App\Livewire\Positions\Index;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PositionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
}
