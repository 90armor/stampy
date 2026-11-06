<?php

namespace Tests\Feature;

use App\Livewire\Employees\Show as EmployeeShow;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The employee profile's Login card (Phase 6i): an admin renames any linked
 * login, an admin's own included, under the employee form's rules (Phase 6g).
 * Sign-in finds the new name at once; nobody is signed out.
 */
class EmployeeUsernameChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);

        $this->admin = User::factory()->create(['username' => 'root'])->assignRole('admin');
    }

    private function linked(string $username, string $code, string $role = 'employee', array $employee = []): Employee
    {
        $user = User::factory()->create(['username' => $username])->assignRole($role);

        return Employee::factory()->create(['user_id' => $user->id, 'employee_code' => $code, 'full_name' => $user->name] + $employee);
    }

    public function test_the_new_username_signs_in_at_once_and_nobody_is_signed_out(): void
    {
        config(['session.driver' => 'database']);
        $employee = $this->linked('kyaw', 'EMP-0002');
        $user = $employee->user;

        // Kyaw is signed in somewhere, with "remember me".
        $this->post('/login', ['email' => 'kyaw', 'password' => 'password', 'remember' => 'on'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->post('/logout');
        // Logging out rotates the token; what matters is that the rename doesn't.
        $token = $user->fresh()->getRememberToken();
        DB::table('sessions')->insert(['id' => 'kyaw-phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        Livewire::actingAs($this->admin)->test(EmployeeShow::class, ['employee' => $employee])
            ->call('openUsername')
            ->assertSet('showUsernameModal', true)
            ->assertSet('username', 'kyaw')
            ->set('username', ' kyaw.naing ')
            ->call('saveUsername')
            ->assertHasNoErrors()
            ->assertSet('showUsernameModal', false)
            ->assertSee('signs in as “kyaw.naing” from now on.');

        $this->assertSame('kyaw.naing', $user->fresh()->username);
        $this->assertTrue(DB::table('sessions')->where('id', 'kyaw-phone')->exists(), 'other sessions stay signed in');
        $this->assertSame($token, $user->fresh()->getRememberToken(), '"remember me" keeps working');

        auth()->logout();
        $this->post('/login', ['email' => 'kyaw', 'password' => 'password'])->assertSessionHasErrors();
        $this->assertStringContainsString('Incorrect username, employee code or password.', collect(session('errors')->all())->implode(' '));
        $this->assertGuest();
        $this->post('/login', ['email' => 'KYAW.NAING', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_admin_renames_their_own_linked_login_and_stays_signed_in(): void
    {
        $employee = $this->linked('linked.admin', 'EMP-0100', 'admin');
        $admin = $employee->user;

        $this->actingAs($admin);
        Livewire::test(EmployeeShow::class, ['employee' => $employee])
            ->call('openUsername')
            ->set('username', 'office.admin')
            ->call('saveUsername')
            ->assertHasNoErrors();

        $this->assertSame('office.admin', $admin->fresh()->username);
        $this->get(route('employees.show', $employee))->assertOk();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_employee_forms_rules_and_messages(): void
    {
        $employee = $this->linked('kyaw', 'EMP-0002');
        $this->linked('taken.name', 'EMP-0003');
        Employee::factory()->create(['employee_code' => 'EMP-0031', 'full_name' => 'Aung Kyaw Moe']);

        $cases = [
            'TAKEN.NAME' => 'The username has already been taken.',
            'ROOT' => 'The username has already been taken.',
            'emp-0031' => '"emp-0031" is Aung Kyaw Moe\'s employee code, and people sign in with either. Choose a different username.',
            'EMP-0003' => '"EMP-0003" is '.Employee::where('employee_code', 'EMP-0003')->value('full_name').'\'s employee code, and people sign in with either. Choose a different username.',
            '  ' => 'The username field is required.',
        ];

        foreach ($cases as $username => $message) {
            $page = Livewire::actingAs($this->admin)->test(EmployeeShow::class, ['employee' => $employee])
                ->call('openUsername')
                ->set('username', $username)
                ->call('saveUsername')
                ->assertHasErrors('username')
                ->assertSet('showUsernameModal', true);

            $this->assertSame($message, $page->errors()->first('username'), "'{$username}'");
        }
        $this->assertSame('kyaw', $employee->user->fresh()->username);

        // Their own employee code is allowed, in any case — the form suggests exactly that.
        Livewire::actingAs($this->admin)->test(EmployeeShow::class, ['employee' => $employee])
            ->call('openUsername')
            ->set('username', 'emp-0002')
            ->call('saveUsername')
            ->assertHasNoErrors();
        $this->assertSame('emp-0002', $employee->user->fresh()->username);
    }

    public function test_only_an_admin_sees_and_uses_change_username(): void
    {
        $managerEmployee = $this->linked('manager', 'EMP-0010', 'manager');
        $employee = $this->linked('kyaw', 'EMP-0002', 'employee', ['manager_id' => $managerEmployee->id]);
        $manager = $managerEmployee->user;

        $this->actingAs($this->admin)->get(route('employees.show', $employee))->assertSee('aria-label="Change username"', false);
        $this->actingAs($manager)->get(route('employees.show', $employee))->assertOk()->assertDontSee('aria-label="Change username"', false);

        Livewire::actingAs($manager)->test(EmployeeShow::class, ['employee' => $employee])->call('openUsername')->assertForbidden();
        Livewire::actingAs($manager)->test(EmployeeShow::class, ['employee' => $employee])
            ->set('username', 'tampered')
            ->call('saveUsername')
            ->assertForbidden();

        $this->assertSame('kyaw', $employee->user->fresh()->username);
    }

    public function test_an_employee_without_a_login_has_nothing_to_rename(): void
    {
        $employee = Employee::factory()->create(['user_id' => null]);

        $this->actingAs($this->admin)->get(route('employees.show', $employee))->assertDontSee('aria-label="Change username"', false);
        Livewire::actingAs($this->admin)->test(EmployeeShow::class, ['employee' => $employee])
            ->call('openUsername')
            ->assertSet('showUsernameModal', false);
    }
}
