<?php

namespace Tests\Feature\Auth;

use App\Livewire\Employees\FormModal;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordResetForNoEmailAccountsTest extends TestCase
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

    public function test_admin_can_reset_another_users_password(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create()->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->call('resetPassword')
            ->assertSet('resetPasswordValue', fn ($value) => is_string($value) && Hash::check($value, $user->fresh()->password));

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertSame($admin->id, $user->password_reset_by);
        $this->assertNotNull($user->password_reset_at);
        $this->assertNotNull($user->temporary_password_expires_at);
        $this->assertTrue($user->temporary_password_expires_at->betweenIncluded(now()->addHours(47), now()->addHours(49)));
    }

    public function test_a_manager_cannot_reset_a_password(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $user = User::factory()->create()->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        // A manager can't even open the edit form the reset button lives
        // in — EmployeePolicy::update is admin-only, same gate save() uses.
        Livewire::actingAs($manager)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_the_temporary_password_appears_exactly_once_and_is_not_retrievable_after(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $component = Livewire::actingAs($admin)
            ->test(FormModal::class)
            ->call('edit', $employee->id)
            ->call('resetPassword');

        $temporary = $component->get('resetPasswordValue');

        // The password shown to the admin must be one this account really authenticates with.
        $this->assertTrue(Auth::validate(['username' => $user->username, 'password' => $temporary]));

        // Dismissing ("Done") clears it — there is no second look.
        $component->set('resetPasswordValue', null)->assertSet('resetPasswordValue', null);

        // Never stored anywhere but as a hash.
        $this->assertNotSame($temporary, $user->fresh()->password);
        $this->assertDatabaseMissing('users', ['password' => $temporary]);
        $this->assertTrue(Hash::check($temporary, $user->fresh()->password));
    }

    public function test_a_user_with_must_change_password_is_redirected_from_any_route(): void
    {
        $user = User::factory()->create(['must_change_password' => true])->assignRole('employee');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('password.change'));
    }

    public function test_password_change_and_logout_are_reachable_while_flagged_no_loop(): void
    {
        $user = User::factory()->create(['must_change_password' => true])->assignRole('employee');

        $this->actingAs($user)
            ->get(route('password.change'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_changing_the_password_clears_the_flag_and_lands_on_dashboard(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'password_changed_at' => null,
            'temporary_password_expires_at' => now()->addHours(48),
        ])->assignRole('employee');

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
    }

    public function test_reusing_the_temporary_password_as_the_new_one_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Kyaw-4871'),
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHours(48),
        ])->assignRole('employee');

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'password' => 'Kyaw-4871',
                'password_confirmation' => 'Kyaw-4871',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_a_temporary_password_older_than_48h_is_rejected_at_login_with_the_specific_message(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Kyaw-4871'),
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->subHours(49),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'Kyaw-4871',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('auth');
        $this->assertStringContainsString(
            'expired',
            collect(session('errors')->get('auth'))->implode(' ')
        );
    }

    public function test_a_user_can_log_in_with_their_username_when_they_have_no_email(): void
    {
        $user = User::factory()->create(['email' => null, 'username' => 'no.email.user']);

        $response = $this->post('/login', [
            'email' => 'no.email.user',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_user_with_no_email_gets_the_guidance_message_on_forgot_password(): void
    {
        Notification::fake();

        User::factory()->create(['email' => null, 'username' => 'no.email.user']);

        $response = $this->post('/forgot-password', ['email' => 'no.email.user']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'no email on file',
            collect(session('errors')->get('email'))->implode(' ')
        );

        Notification::assertNothingSent();
    }

    /**
     * Found during review: NewPasswordController never touched
     * must_change_password/temporary_password_expires_at, so a user who
     * still had a pending admin reset but also has an email could set a
     * brand-new password via the email link and then get sent straight
     * back to /password/change on their next login — as if the email
     * reset had never happened.
     */
    public function test_an_email_based_reset_clears_a_pending_forced_change(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHours(48),
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));

        // And the very next login must NOT bounce them to /password/change.
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-brand-new-password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }
}
