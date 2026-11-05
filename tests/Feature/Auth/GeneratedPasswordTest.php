<?php

namespace Tests\Feature\Auth;

use App\Livewire\Employees\FormModal;
use App\Livewire\Employees\Show;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\TemporaryPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A password generated with a new login is a temporary password (Phase 6h,
 * N1): the same seven-day expiry and forced change as a reset, and an expired
 * one is offered for reset on the profile's Login card.
 */
class GeneratedPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::factory()->create(['is_default' => true]);

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function createWithLogin(User $admin): string
    {
        return Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('create')
            ->set('full_name', 'Sophea Chan')
            ->set('employee_code', 'EMP-9101')
            ->set('department_id', Department::factory()->create()->id)
            ->set('position_id', Position::factory()->create()->id)
            ->set('join_date', '2026-01-01')
            ->set('create_user', true)
            ->set('username', 'sophea.chan')
            ->set('email', 'sophea.chan@example.com')
            ->set('role', 'employee')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('It expires in 7 days and must be changed at first sign-in.')
            ->get('generatedPassword');
    }

    private function assertTemporary(User $user, string $password): void
    {
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertMatchesRegularExpression('/^[A-Za-z]+-\d{4}$/', $password);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->temporary_password_expires_at->betweenIncluded(now()->addDays(7)->subHour(), now()->addDays(7)->addHour()));
        // Not a reset: nobody reset anything.
        $this->assertNull($user->password_reset_by);
        $this->assertNull($user->password_reset_at);
    }

    public function test_a_new_employees_generated_password_is_temporary(): void
    {
        $password = $this->createWithLogin($this->admin());

        $this->assertTemporary(User::where('username', 'sophea.chan')->sole(), $password);
    }

    public function test_a_login_added_to_an_existing_employee_gets_a_temporary_password(): void
    {
        $employee = Employee::factory()->create(['employee_code' => 'EMP-9102']);

        $password = Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->set('create_user', true)
            ->set('email', 'added@example.com')
            ->set('role', 'employee')
            ->call('save')
            ->assertHasNoErrors()
            ->get('generatedPassword');

        $this->assertTemporary($employee->fresh()->user, $password);
    }

    public function test_the_first_sign_in_forces_a_new_password(): void
    {
        $password = $this->createWithLogin($this->admin());
        auth()->logout();

        $this->post('/login', ['email' => 'sophea.chan', 'password' => $password])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('You signed in with a temporary password.')
            ->assertDontSee('An admin reset your password.');

        $this->put(route('password.change.update'), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('username', 'sophea.chan')->sole();
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->get(route('dashboard'))->assertOk();
    }

    /** Seven days, so an account made on a Friday, or before a long holiday, lasts to the first workday. */
    public function test_a_generated_password_made_on_a_friday_still_signs_in_after_a_long_weekend(): void
    {
        $this->travelTo('2026-04-10 16:30:00'); // a Friday, before Khmer New Year (14–16 Apr)
        $password = $this->createWithLogin($this->admin());
        auth()->logout();

        $this->travelTo('2026-04-17 08:00:00'); // the next workday, 6 days 15½ hours later

        $this->post('/login', ['email' => 'sophea.chan', 'password' => $password])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_an_expired_generated_password_is_refused_with_the_existing_message(): void
    {
        $password = $this->createWithLogin($this->admin());
        auth()->logout();

        $this->travel(TemporaryPassword::LIFETIME_HOURS + 1)->hours();

        $this->post('/login', ['email' => 'sophea.chan', 'password' => $password])
            ->assertSessionHasErrors(['auth' => 'This temporary password has expired. Please ask an admin for a new one.']);
        $this->assertGuest();
    }

    public function test_the_login_card_offers_a_reset_once_the_temporary_password_has_expired(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->create()->assignRole('manager');
        $managerEmployee = Employee::factory()->create(['user_id' => $manager->id]);
        $user = User::factory()->create([
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHours(TemporaryPassword::LIFETIME_HOURS),
        ])->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id, 'manager_id' => $managerEmployee->id]);

        // Still valid: a change pending, nothing to reset.
        Livewire::actingAs($admin)->test(Show::class, ['employee' => $employee])
            ->assertSee('Change pending')
            ->assertDontSee('Temporary password expired');

        $this->travel(TemporaryPassword::LIFETIME_HOURS + 1)->hours();

        Livewire::actingAs($admin)->test(Show::class, ['employee' => $employee])
            ->assertSeeHtml('Temporary password expired —')
            ->assertSeeHtml("method: 'resetPasswordFor'")
            ->assertSeeHtml('>reset it</button>')
            ->assertDontSee('Change pending');

        // A manager sees the state but has no reset.
        Livewire::actingAs($manager)->test(Show::class, ['employee' => $employee])
            ->assertSee('Temporary password expired — an admin can reset it')
            ->assertDontSeeHtml('resetPasswordFor');
    }

    public function test_reset_it_resets_the_password_and_done_closes_the_modal(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subHour(),
        ])->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $component = Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('resetPasswordFor', $employee->id)
            ->assertSet('showModal', true)
            ->assertSet('resetOnly', true)
            ->assertDispatched('employee-saved')
            ->assertSee('Password reset');

        $temporary = $component->get('resetPasswordValue');
        $user->refresh();
        $this->assertTrue(Hash::check($temporary, $user->password));
        $this->assertFalse($user->hasExpiredTemporaryPassword());
        $this->assertSame($admin->id, $user->password_reset_by);

        $component->call('closeReset')
            ->assertSet('showModal', false)
            ->assertSet('resetPasswordValue', null);
    }

    public function test_done_after_a_reset_from_the_edit_form_returns_to_the_form(): void
    {
        $user = User::factory()->create()->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($this->admin())
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->call('resetPassword')
            ->call('closeReset')
            ->assertSet('showModal', true)
            ->assertSet('resetPasswordValue', null)
            ->assertSet('editing.id', $employee->id);
    }

    public function test_a_manager_cannot_reset_from_the_login_card(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $managerEmployee = Employee::factory()->create(['user_id' => $manager->id]);
        $user = User::factory()->create(['password' => Hash::make('Kyaw-1234')])->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id, 'manager_id' => $managerEmployee->id]);

        Livewire::actingAs($manager)
            ->test(FormModal::class)
            ->call('resetPasswordFor', $employee->id)
            ->assertForbidden();

        $this->assertTrue(Hash::check('Kyaw-1234', $user->fresh()->password));
    }

    public function test_a_reset_still_says_an_admin_reset_it(): void
    {
        $user = User::factory()->create(['must_change_password' => true, 'password_reset_at' => now()])->assignRole('employee');

        $this->actingAs($user)->get(route('password.change'))
            ->assertSee('An admin reset your password.')
            ->assertDontSee('You signed in with a temporary password.');
    }
}
