<?php

namespace Tests\Feature;

use App\Livewire\Employees\FormModal;
use App\Livewire\Employees\Index;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    public function test_admin_can_create_employee_without_a_user_account(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();
        $position = Position::factory()->create();

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('create')
            ->set('full_name', 'Jane Doe')
            ->set('employee_code', 'EMP-9001')
            ->set('department_id', $department->id)
            ->set('position_id', $position->id)
            ->set('join_date', '2026-01-01')
            ->call('save')
            ->assertSet('showModal', false);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP-9001',
            'user_id' => null,
        ]);
    }

    public function test_admin_can_create_employee_with_a_linked_user_account(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();
        $position = Position::factory()->create();

        $component = Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('create')
            ->set('full_name', 'John Smith')
            ->set('employee_code', 'EMP-9002')
            ->set('department_id', $department->id)
            ->set('position_id', $position->id)
            ->set('join_date', '2026-01-01')
            ->set('create_user', true)
            ->set('email', 'john.smith@example.com')
            ->set('role', 'employee')
            ->call('save');

        $component->assertSet('generatedPassword', fn ($password) => ! empty($password));

        $this->assertDatabaseHas('users', ['email' => 'john.smith@example.com']);

        $employee = Employee::where('employee_code', 'EMP-9002')->first();
        $this->assertNotNull($employee->user_id);
        $this->assertTrue($employee->user->hasRole('employee'));
    }

    public function test_employee_code_must_be_unique(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();
        $position = Position::factory()->create();
        Employee::factory()->create(['employee_code' => 'EMP-DUP', 'department_id' => $department->id]);

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('create')
            ->set('full_name', 'Duplicate Code')
            ->set('employee_code', 'EMP-DUP')
            ->set('department_id', $department->id)
            ->set('position_id', $position->id)
            ->set('join_date', '2026-01-01')
            ->call('save')
            ->assertHasErrors(['employee_code']);
    }

    public function test_admin_can_deactivate_an_employee(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('deactivate', $employee->id);

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertSame('inactive', $employee->fresh()->status);
    }

    public function test_search_filters_the_employee_list(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Employee::factory()->create(['full_name' => 'Alice Wonder']);
        Employee::factory()->create(['full_name' => 'Bob Builder']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('search', 'Alice')
            ->assertSee('Alice Wonder')
            ->assertDontSee('Bob Builder');
    }

    public function test_employee_without_a_role_cannot_view_the_employee_list(): void
    {
        $employee = User::factory()->create()->assignRole('employee');

        $this->actingAs($employee)
            ->get(route('employees.index'))
            ->assertForbidden();
    }
}
