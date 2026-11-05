<?php

namespace Tests\Feature;

use App\Livewire\Profile\Show as ProfileShow;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The user's own profile (Phase 6i): read-only, every change in a modal; only
 * an admin changes their own email (and name, with no employee record) — the
 * server refuses everyone else, not only the page.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->schedule = WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
    }

    private function employeeUser(string $role = 'employee', array $employee = [], array $user = []): User
    {
        $login = User::factory()->create($user)->assignRole($role);
        Employee::factory()->create(['user_id' => $login->id, 'full_name' => $login->name] + $employee);

        return $login->refresh();
    }

    public function test_an_employees_profile_shows_who_they_are_their_schedule_account_and_fingerprint(): void
    {
        $this->travelTo('2026-11-05 10:00:00');

        $manager = Employee::factory()->create(['full_name' => 'Manager Person']);
        $user = $this->employeeUser('employee', [
            'employee_code' => 'EMP-1234',
            'manager_id' => $manager->id,
            'join_date' => '2024-08-01',
            'device_user_id' => '1234',
            'department_id' => Department::factory()->create(['name' => 'Engineering'])->id,
            'position_id' => Position::factory()->create(['name' => 'Developer'])->id,
        ], ['username' => 'kyaw', 'email' => null, 'password_changed_at' => null]);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSeeText('Developer · Engineering · EMP-1234')
            ->assertSeeText('Manager: Manager Person · Joined Thu 1 Aug 2024 (2 years 3 months)')
            ->assertSeeText('Mon–Fri · 8:00 AM – 5:00 PM · Break 12:00 PM')
            ->assertSeeText('kyaw')
            ->assertSeeText('Ask HR to change')
            ->assertSeeText('None')
            ->assertSeeText('Ask HR to add or change your email')
            ->assertSeeText('Device user ID 1234')
            ->assertSeeText("tell HR if your punches don't show up.")
            ->assertDontSee('effective-dated')
            ->assertDontSee('Default (current)')
            ->assertDontSee('Edit account')
            // A manager's name is plain text: an employee can't open their profile.
            ->assertDontSee(route('employees.show', $manager));
    }

    public function test_tenure_reads_in_months_and_years(): void
    {
        $this->travelTo('2026-11-05 10:00:00');

        $this->actingAs($this->employeeUser('employee', ['join_date' => '2026-06-01']))->get('/profile')->assertSeeText('(5 months)');
        $this->actingAs($this->employeeUser('employee', ['join_date' => '2024-11-05']))->get('/profile')->assertSeeText('(2 years)');
        $this->actingAs($this->employeeUser('employee', ['join_date' => '2026-10-20']))->get('/profile')->assertSeeText('(under a month)');
    }

    public function test_an_upcoming_schedule_change_is_one_line_under_todays(): void
    {
        $this->travelTo('2026-11-05 10:00:00');

        $user = $this->employeeUser();
        $later = WorkSchedule::factory()->create(['start_time' => '09:00:00', 'end_time' => '18:00:00', 'workdays' => [1, 3, 5, 6]]);
        EmployeeWorkSchedule::create(['employee_id' => $user->employee->id, 'work_schedule_id' => $later->id, 'effective_from' => '2026-12-01']);

        $this->actingAs($user)->get('/profile')
            ->assertSeeText('Mon–Fri · 8:00 AM – 5:00 PM · Break 12:00 PM')
            ->assertSeeText('From Tue 1 Dec: Mon, Wed, Fri, Sat · 9:00 AM – 6:00 PM · Break 1h 00m');
    }

    public function test_missing_schedule_and_device_id_say_what_to_do(): void
    {
        $user = $this->employeeUser('employee', ['device_user_id' => null]);
        EmployeeWorkSchedule::where('employee_id', $user->employee->id)->delete();

        $this->actingAs($user)->get('/profile')
            ->assertSeeText('No schedule assigned yet. Ask HR.')
            ->assertSeeText("Not set yet. HR adds it when you're enrolled on the device.");
    }

    public function test_the_password_row_says_when_it_was_changed(): void
    {
        $this->travelTo('2026-11-05 10:00:00');

        $this->actingAs($this->employeeUser('employee', [], ['password_changed_at' => '2026-11-02 10:00:00']))->get('/profile')
            ->assertSeeText('Changed 3 days ago');
        $this->actingAs($this->employeeUser('employee', [], ['password_changed_at' => null]))->get('/profile')
            ->assertDontSeeText('Changed ');
    }

    public function test_an_unlinked_admin_sees_the_account_and_no_employee_cards(): void
    {
        $admin = User::factory()->create(['name' => 'Root Admin'])->assignRole('admin');

        $this->actingAs($admin)->get('/profile')
            ->assertOk()
            ->assertSeeText('Root Admin')
            ->assertSeeText('Admin')
            ->assertSeeText('Not linked to an employee record.')
            ->assertSeeText('Account')
            ->assertDontSeeText('Work schedule')
            ->assertDontSeeText('Fingerprint')
            ->assertSee('Edit account');
    }

    public function test_employees_and_managers_cannot_change_their_email_or_name(): void
    {
        foreach (['employee', 'manager'] as $role) {
            $user = $this->employeeUser($role, ['full_name' => 'Real Name'], ['name' => 'Real Name', 'email' => "real-{$role}@example.com"]);

            Livewire::actingAs($user)->test(ProfileShow::class)->call('openAccount')->assertForbidden();

            // A crafted request that sets the fields and calls save directly.
            Livewire::actingAs($user)->test(ProfileShow::class)
                ->set('name', 'Tampered')
                ->set('email', 'tampered@example.com')
                ->call('saveAccount')
                ->assertForbidden();

            $user->refresh();
            $this->assertSame('Real Name', $user->name, $role);
            $this->assertSame("real-{$role}@example.com", $user->email, $role);
        }
    }

    public function test_the_old_profile_and_password_form_routes_are_gone(): void
    {
        $user = $this->employeeUser('employee', [], ['email' => 'real@example.com']);

        $this->actingAs($user)->patch('/profile', ['name' => 'Tampered', 'email' => 'tampered@example.com'])->assertStatus(405);
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'new-Password-1', 'password_confirmation' => 'new-Password-1'])->assertNotFound();

        $this->assertSame('real@example.com', $user->fresh()->email);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertFalse(Route::has('profile.update'));
        $this->assertFalse(Route::has('password.update'));
    }

    public function test_a_linked_admin_changes_their_email_but_not_their_name(): void
    {
        $admin = $this->employeeUser('admin', ['full_name' => 'Real Name'], ['name' => 'Real Name', 'email' => 'old@example.com']);

        Livewire::actingAs($admin)->test(ProfileShow::class)
            ->call('openAccount')
            ->assertSet('showAccountModal', true)
            ->assertSee('Edit on your employee record')
            ->assertSee(route('employees.show', $admin->employee))
            ->set('name', 'Tampered')
            ->set('email', ' New@Example.com ')
            ->call('saveAccount')
            ->assertHasNoErrors()
            ->assertSet('showAccountModal', false)
            ->assertSee('Account saved.');

        $admin->refresh();
        $this->assertSame('Real Name', $admin->name);
        $this->assertSame('new@example.com', $admin->email);
    }

    public function test_an_unlinked_admin_changes_their_name_and_can_clear_their_email(): void
    {
        $admin = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com'])->assignRole('admin');

        Livewire::actingAs($admin)->test(ProfileShow::class)
            ->call('openAccount')
            ->set('name', 'New Name')
            ->set('email', '')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $admin->refresh();
        $this->assertSame('New Name', $admin->name);
        $this->assertNull($admin->email);
    }

    public function test_the_admin_edit_refuses_a_taken_email_or_a_blank_name(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)->test(ProfileShow::class)
            ->call('openAccount')
            ->set('name', '')
            ->set('email', 'taken@example.com')
            ->call('saveAccount')
            ->assertHasErrors(['name', 'email'])
            ->assertSet('showAccountModal', true);
    }

    public function test_a_wrong_current_password_or_a_mismatch_is_refused(): void
    {
        $user = $this->employeeUser();

        Livewire::actingAs($user)->test(ProfileShow::class)
            ->call('openPassword')
            ->set('current_password', 'wrong')
            ->set('password', 'new-Password-1')
            ->set('password_confirmation', 'something-else')
            ->call('changePassword')
            ->assertHasErrors(['current_password', 'password'])
            ->assertSet('showPasswordModal', true);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_password_change_signs_out_every_other_session_and_keeps_this_one(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        config(['session.driver' => 'database']);

        $user = $this->employeeUser('employee', [], ['password_changed_at' => null]);
        $user->forceFill(['remember_token' => 'old-token'])->save();
        $other = $this->employeeUser();

        $component = Livewire::actingAs($user)->test(ProfileShow::class);
        $current = session()->getId();

        $row = fn (string $id, int $userId) => ['id' => $id, 'user_id' => $userId, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp];
        DB::table('sessions')->insert([$row($current, $user->id), $row('phone-session', $user->id), $row('laptop-session', $user->id), $row('someone-else', $other->id)]);

        $component->call('openPassword')
            ->set('current_password', 'password')
            ->set('password', 'new-Password-1')
            ->set('password_confirmation', 'new-Password-1')
            ->call('changePassword')
            ->assertHasNoErrors()
            ->assertSet('showPasswordModal', false)
            ->assertSee("Password changed. You've been signed out on other devices.")
            ->assertSeeHtml('data-focus-notice');

        $user->refresh();
        $this->assertTrue(Hash::check('new-Password-1', $user->password));
        $this->assertTrue($user->password_changed_at->equalTo(now()));
        $this->assertNotSame('old-token', $user->remember_token);
        $this->assertEqualsCanonicalizing([$current, 'someone-else'], DB::table('sessions')->pluck('id')->all());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_profile_page_offers_no_way_to_delete_the_account(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertDontSee('Delete Account')
            ->assertDontSee('confirm-user-deletion');

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertStatus(405);
        $this->assertNotNull($user->fresh());
        $this->assertFalse(Route::has('profile.destroy'));
    }
}
