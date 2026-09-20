<?php

namespace Tests\Feature;

use App\Livewire\Employees\FormModal;
use App\Livewire\Employees\Index;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
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

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
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

    public function test_manager_cannot_create_an_employee_via_the_form_modal(): void
    {
        $manager = User::factory()->create()->assignRole('manager');

        // A denied authorize() call renders as a 403 response, which ends the
        // Livewire round-trip for that instance — assert the denial directly
        // rather than chaining further set()/call() onto it.
        Livewire::actingAs($manager)
            ->test(FormModal::class)
            ->call('create')
            ->assertForbidden();
    }

    public function test_manager_cannot_edit_an_employee_via_the_form_modal(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create(['full_name' => 'Original Name']);

        Livewire::actingAs($manager)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->assertForbidden();

        $this->assertSame('Original Name', $employee->fresh()->full_name);
    }

    public function test_manager_cannot_call_save_directly_on_the_form_modal(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $department = Department::factory()->create();
        $position = Position::factory()->create();

        // Calls save() without going through create()/edit() first, to prove
        // save() enforces authorization on its own rather than relying on the
        // caller having already been through a gated entry point.
        Livewire::actingAs($manager)
            ->test(FormModal::class)
            ->set('full_name', 'Direct Save')
            ->set('employee_code', 'EMP-9200')
            ->set('department_id', $department->id)
            ->set('position_id', $position->id)
            ->set('join_date', '2026-01-01')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-9200']);
    }

    public function test_the_deactivate_control_is_shown_to_an_admin_and_not_to_a_manager(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $manager = User::factory()->create()->assignRole('manager');
        Employee::factory()->create(['status' => 'active']);

        $this->actingAs($admin)->get(route('employees.index'))->assertOk()->assertSeeHtml('title="Deactivate"');
        $this->actingAs($manager)->get(route('employees.index'))->assertOk()->assertDontSeeHtml('title="Deactivate"');
    }

    public function test_manager_cannot_deactivate_an_employee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create(['status' => 'active']);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->call('deactivate', $employee->id)
            ->assertForbidden();

        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_manager_cannot_reactivate_an_employee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->call('reactivate', $employee->id)
            ->assertForbidden();

        $this->assertSame('inactive', $employee->fresh()->status);
    }

    /**
     * A create form filled in with valid values, ready for one field to be broken.
     */
    private function validCreateForm(User $admin, array $overrides = []): Testable
    {
        $values = array_merge([
            'full_name' => 'Valid Person',
            'employee_code' => 'EMP-9500',
            'department_id' => Department::factory()->create()->id,
            'position_id' => Position::factory()->create()->id,
            'join_date' => '2026-01-01',
        ], $overrides);

        $component = Livewire::actingAs($admin)->test(FormModal::class)->call('create');

        foreach ($values as $field => $value) {
            $component->set($field, $value);
        }

        return $component;
    }

    public function test_admin_can_reactivate_an_inactive_employee(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('reactivate', $employee->id);

        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_device_user_id_must_be_unique(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Employee::factory()->create(['device_user_id' => '7001']);

        $this->validCreateForm($admin, ['device_user_id' => '7001'])
            ->call('save')
            ->assertHasErrors(['device_user_id']);

        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-9500']);
    }

    public function test_an_employee_can_keep_their_own_device_user_id_when_edited(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create(['device_user_id' => '7002', 'full_name' => 'Before Edit']);

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('full_name', 'After Edit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('After Edit', $employee->fresh()->full_name);
        $this->assertSame('7002', $employee->fresh()->device_user_id);
    }

    public function test_several_employees_may_have_no_device_user_id(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        // A blank id is stored as NULL, not '' — two empty strings would collide on the unique index.
        foreach (['EMP-9500', 'EMP-9502'] as $code) {
            $this->validCreateForm($admin, ['employee_code' => $code, 'device_user_id' => ''])
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, Employee::whereIn('employee_code', ['EMP-9500', 'EMP-9502'])->whereNull('device_user_id')->count());
    }

    public function test_the_required_fields_are_validated_and_nothing_is_created_without_them(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $before = Employee::count();

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('create')
            ->call('save')
            ->assertHasErrors(['full_name', 'employee_code', 'department_id', 'position_id', 'join_date']);

        $this->assertSame($before, Employee::count());
    }

    public function test_a_department_or_position_that_does_not_exist_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->validCreateForm($admin, ['department_id' => 999999])
            ->call('save')
            ->assertHasErrors(['department_id']);

        $this->validCreateForm($admin, ['employee_code' => 'EMP-9501', 'position_id' => 999999])
            ->call('save')
            ->assertHasErrors(['position_id']);

        $this->assertSame(0, Employee::whereIn('employee_code', ['EMP-9500', 'EMP-9501'])->count());
    }

    public function test_a_malformed_join_date_or_status_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->validCreateForm($admin, ['join_date' => 'not-a-date'])
            ->call('save')
            ->assertHasErrors(['join_date']);

        $this->validCreateForm($admin, ['employee_code' => 'EMP-9501', 'status' => 'banana'])
            ->call('save')
            ->assertHasErrors(['status']);
    }

    public function test_a_login_requires_a_valid_email_and_creates_nothing_when_it_is_missing_or_malformed(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $usersBefore = User::count();

        foreach (['not-an-email', ''] as $i => $email) {
            $this->validCreateForm($admin, ['employee_code' => 'EMP-950'.$i])
                ->set('create_user', true)
                ->set('email', $email)
                ->call('save')
                ->assertHasErrors(['email']);
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, Employee::where('employee_code', 'like', 'EMP-950%')->count());
    }

    public function test_a_login_role_must_be_one_of_the_real_roles(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $usersBefore = User::count();

        $this->validCreateForm($admin)
            ->set('create_user', true)
            ->set('email', 'new.person@example.com')
            ->set('role', 'superuser')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-9500']);
    }

    public function test_a_login_email_or_username_already_in_use_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $taken = User::factory()->create(['email' => 'taken@example.com', 'username' => 'taken.user']);

        $this->validCreateForm($admin)
            ->set('create_user', true)
            ->set('email', $taken->email)
            ->set('username', 'someone.new')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->validCreateForm($admin, ['employee_code' => 'EMP-9501'])
            ->set('create_user', true)
            ->set('email', 'fresh@example.com')
            ->set('username', $taken->username)
            ->call('save')
            ->assertHasErrors(['username']);

        $this->assertSame(0, Employee::whereIn('employee_code', ['EMP-9500', 'EMP-9501'])->count());
    }
}
