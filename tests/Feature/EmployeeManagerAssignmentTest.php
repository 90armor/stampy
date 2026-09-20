<?php

namespace Tests\Feature;

use App\Livewire\Employees\FormModal;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeManagerAssignmentTest extends TestCase
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

    public function test_a_valid_manager_assignment_saves(): void
    {
        $employee = Employee::factory()->create();
        $manager = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('manager_id', $manager->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($manager->id, $employee->fresh()->manager_id);
    }

    public function test_an_employee_cannot_be_their_own_manager(): void
    {
        $employee = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('manager_id', $employee->id)
            ->call('save')
            ->assertHasErrors(['manager_id']);

        $this->assertNull($employee->fresh()->manager_id);
    }

    public function test_assigning_a_direct_report_as_manager_is_rejected(): void
    {
        $top = Employee::factory()->create();
        $directReport = Employee::factory()->create(['manager_id' => $top->id]);

        Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $top->id)
            ->set('manager_id', $directReport->id)
            ->call('save')
            ->assertHasErrors(['manager_id']);

        $this->assertNull($top->fresh()->manager_id);
    }

    public function test_assigning_a_report_of_a_report_as_manager_is_rejected(): void
    {
        $top = Employee::factory()->create();
        $mid = Employee::factory()->create(['manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['manager_id' => $mid->id]);

        Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $top->id)
            ->set('manager_id', $leaf->id)
            ->call('save')
            ->assertHasErrors(['manager_id']);

        $this->assertNull($top->fresh()->manager_id);
    }

    public function test_clearing_the_manager_to_null_works(): void
    {
        $manager = Employee::factory()->create();
        $employee = Employee::factory()->create(['manager_id' => $manager->id]);

        Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('manager_id', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($employee->fresh()->manager_id);
    }
}
