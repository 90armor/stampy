<?php

namespace Tests\Feature;

use App\Livewire\Employees\FormModal;
use App\Livewire\Employees\Index;
use App\Livewire\Employees\StatusModal;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
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

        // Every employee is now assigned a schedule at creation, which needs a default to exist.
        WorkSchedule::factory()->create(['is_default' => true]);

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

    public function test_employee_form_validation_messages_are_human_readable_not_raw_field_names(): void
    {
        // Laravel's default attribute-name fallback would otherwise read
        // "The department id field is required." etc. — the raw column
        // name with its "_id" left dangling.
        $admin = User::factory()->create()->assignRole('admin');

        $component = Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('create')
            ->call('save');

        $component->assertHasErrors(['department_id', 'position_id']);

        $messages = $component->errors()->all();
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'The department field is required')));
        $this->assertTrue(collect($messages)->contains(fn ($m) => str_contains($m, 'The position field is required')));

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('department id', $message);
            $this->assertStringNotContainsString('position id', $message);
        }
    }

    public function test_admin_can_deactivate_an_employee(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create();

        Livewire::actingAs($admin)
            ->test(StatusModal::class)
            ->call('openDeactivate', $employee->id)
            ->call('confirm');

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertSame('inactive', $employee->fresh()->status);
        $this->assertSame(today()->format('Y-m-d'), $employee->fresh()->left_on->format('Y-m-d'));
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

    public function test_reset_filters_restores_the_default_directory_state(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $department = Department::factory()->create();
        Employee::factory()->create(['full_name' => 'Reset Target', 'department_id' => $department->id, 'status' => 'inactive']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('search', 'Missing')
            ->set('departmentFilter', (string) $department->id)
            ->set('statusFilter', 'active')
            ->assertSee('No employees found')
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('departmentFilter', '')
            ->assertSet('statusFilter', '')
            ->assertSee('Reset Target');
    }

    public function test_employee_link_and_row_actions_have_explicit_accessible_targets(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create(['full_name' => 'Accessible Person']);

        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('employees.show', $employee).'"')
            ->assertSeeHtml('aria-label="Edit Accessible Person"')
            ->assertSeeHtml('aria-label="Deactivate Accessible Person"')
            ->assertSee('Edit employee')
            ->assertSee('Deactivate employee');
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
        $managerEmployee = Employee::factory()->create(['user_id' => $manager->id]);
        Employee::factory()->create(['full_name' => 'A Report', 'manager_id' => $managerEmployee->id, 'status' => 'active']);

        $this->actingAs($admin)->get(route('employees.index'))->assertOk()->assertSeeHtml('aria-label="Deactivate A Report"');

        // The manager's directory has rows (their team), so the absence is about the control, not an empty page.
        $this->actingAs($manager)->get(route('employees.index'))->assertOk()->assertSee('A Report')->assertDontSeeHtml('aria-label="Deactivate A Report"');
    }

    public function test_manager_cannot_deactivate_an_employee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create(['status' => 'active']);

        Livewire::actingAs($manager)
            ->test(StatusModal::class)
            ->call('openDeactivate', $employee->id)
            ->assertForbidden();

        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_manager_cannot_reactivate_an_employee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = Employee::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($manager)
            ->test(StatusModal::class)
            ->call('openReactivate', $employee->id)
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
            ->test(StatusModal::class)
            ->call('openReactivate', $employee->id)
            ->call('confirm');

        $this->assertSame('active', $employee->fresh()->status);
        $this->assertNull($employee->fresh()->left_on);
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

    public function test_editing_an_employees_full_name_updates_their_linked_users_name(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $user = User::factory()->create(['name' => 'Before Edit']);
        $employee = Employee::factory()->create(['user_id' => $user->id, 'full_name' => 'Before Edit']);

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('full_name', 'After Edit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('After Edit', $employee->fresh()->full_name);
        $this->assertSame('After Edit', $user->fresh()->name);
    }

    public function test_editing_an_employee_with_no_linked_user_does_not_error(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create(['user_id' => null, 'full_name' => 'Before Edit']);

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('full_name', 'After Edit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('After Edit', $employee->fresh()->full_name);
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

    public function test_a_malformed_join_date_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->validCreateForm($admin, ['join_date' => 'not-a-date'])
            ->call('save')
            ->assertHasErrors(['join_date']);
    }

    /**
     * Status isn't a form field: create always makes an active employee, and
     * deactivating or reactivating happens only through StatusModal.
     */
    public function test_the_form_has_no_status_and_create_makes_an_active_employee(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->validCreateForm($admin)
            ->assertDontSeeHtml('id="emp_status"')
            ->call('save')
            ->assertHasNoErrors();

        $employee = Employee::where('employee_code', 'EMP-9500')->firstOrFail();
        $this->assertSame('active', $employee->status);
        $this->assertNull($employee->left_on);
    }

    public function test_editing_an_inactive_employee_keeps_them_inactive_and_their_join_date_on_or_before_their_last_day(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->inactive('2024-06-30')->create(['join_date' => '2024-01-01']);

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('join_date', '2024-07-01')
            ->call('save')
            ->assertHasErrors(['join_date'])
            ->set('join_date', '2024-06-30')
            ->set('full_name', 'Renamed Leaver')
            ->call('save')
            ->assertHasNoErrors();

        $employee->refresh();
        $this->assertSame('Renamed Leaver', $employee->full_name);
        $this->assertSame('inactive', $employee->status);
        $this->assertSame('2024-06-30', $employee->left_on->format('Y-m-d'));
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

    public function test_employee_pages_carry_no_eyebrow_labels(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create();

        $this->actingAs($admin)->get(route('employees.index'))
            ->assertDontSee('People directory')
            ->assertDontSee('tracking-widest', false);

        $this->actingAs($admin)->get(route('employees.show', $employee))
            ->assertDontSee('tracking-widest', false)
            ->assertSee('<h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Details</h2>', false);
    }

    public function test_the_directory_column_order_ends_with_pinned_status_and_actions(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Employee::factory()->create();

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        preg_match_all('/<th[^>]*>\s*(.*?)\s*<\/th>/s', $html, $matches);
        $headers = array_map(fn ($cell) => trim(strip_tags($cell)), $matches[1]);
        // Owner decision (docs/ATTENDANCE_UI.md): Status and Actions are the
        // trailing columns, pinned to the right edge below xl.
        $this->assertSame(['Employee', 'Department', 'Position', 'Start date', 'Status', 'Actions'], $headers);
        $this->assertStringContainsString('<th class="table-pin table-pin-start px-6 py-3">Status</th>', $html);
        $this->assertStringContainsString('<th class="table-pin table-pin-end px-6 py-3 text-right">Actions</th>', $html);
        $this->assertStringContainsString('x-data="pinnedColumns"', $html);
        // The three-column filter grid only starts at xl, so it can't overflow
        // the card at 1024px.
        $this->assertStringContainsString('xl:grid-cols-[minmax(18rem,1fr)_14rem_11rem]', $html);
        $this->assertStringNotContainsString('lg:grid-cols-[minmax(18rem,1fr)_14rem_11rem]', $html);
    }

    public function test_the_stat_strip_is_one_row_of_three_at_every_width_with_card_padding(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        // Three cells, one row at every width — no wrapped multi-row grid
        // that could orphan a cell.
        $this->assertStringContainsString('grid grid-cols-3 divide-x divide-slate-divider', $html);
        $this->assertSame(3, substr_count($html, 'min-w-0 px-6 py-4 lg:flex'));
    }
}
