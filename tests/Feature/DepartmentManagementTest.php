<?php

namespace Tests\Feature;

use App\Livewire\Departments\Index;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
}
