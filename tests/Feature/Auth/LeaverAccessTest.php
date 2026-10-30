<?php

namespace Tests\Feature\Auth;

use App\Exceptions\SelfDeactivationException;
use App\Livewire\Employees\StatusModal;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\EmployeeLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6b, F01 — a leaver has no access from the day after their last day
 * (User::employmentEndedOn()): sign-in refuses them once the password is
 * right, EnsureStillEmployed ends any session they still have (a remember
 * cookie included), deactivating with a past last day signs them out
 * everywhere, reactivating restores access, and an admin can't deactivate
 * their own record. The clock is Wed 21 Oct 2026 10:00.
 */
class LeaverAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ENDED = 'Your employment ended on Fri 16 Oct. Contact HR if you need access.';

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
    }

    private function employee(?string $leftOn = null, string $username = 'visal'): Employee
    {
        $user = User::factory()->create(['username' => $username, 'password' => 'password'])->assignRole('employee');

        return Employee::factory()->create(array_filter([
            'user_id' => $user->id,
            'join_date' => '2024-02-01',
            'status' => $leftOn === null ? 'active' : 'inactive',
            'left_on' => $leftOn,
        ]));
    }

    public function test_a_leaver_is_refused_at_sign_in_without_a_session(): void
    {
        $this->employee('2026-10-16');

        $this->post('/login', ['email' => 'visal', 'password' => 'password'])
            ->assertSessionHasErrors(['auth' => self::ENDED]);

        $this->assertGuest();
    }

    public function test_a_wrong_password_never_reveals_that_the_account_has_left(): void
    {
        $this->employee('2026-10-16');

        $this->post('/login', ['email' => 'visal', 'password' => 'wrong'])
            ->assertSessionHasErrors(['auth' => 'Incorrect email/username or password. Please try again.']);

        $this->assertGuest();
    }

    public function test_the_last_day_itself_still_signs_in(): void
    {
        $this->employee('2026-10-21');

        $this->post('/login', ['email' => 'visal', 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_users_without_an_employee_record_are_unaffected(): void
    {
        User::factory()->create(['username' => 'boss', 'password' => 'password'])->assignRole('admin');

        $this->post('/login', ['email' => 'boss', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_an_open_session_ends_on_the_next_request_with_the_message(): void
    {
        $employee = $this->employee('2026-10-21');

        $this->actingAs($employee->user)->get('/dashboard')->assertOk();

        // Midnight passes: the last day is now behind them.
        $this->travelTo(Carbon::parse('2026-10-22 00:00:30'));

        $this->get('/dashboard')->assertRedirect('/login?ended=2026-10-21');
        $this->assertGuest();

        $this->get('/login?ended=2026-10-21')->assertSee('Your employment ended on Wed 21 Oct. Contact HR if you need access.');
    }

    public function test_a_remember_me_cookie_is_cut_off_too(): void
    {
        $this->employee('2026-10-21');

        $response = $this->post('/login', ['email' => 'visal', 'password' => 'password', 'remember' => 'on']);
        $recaller = collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertNotNull($recaller);

        // A new day and a fresh browser session: only the cookie is left.
        $this->travelTo(Carbon::parse('2026-10-22 08:00:00'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
            ->get('/dashboard')
            ->assertRedirect('/login?ended=2026-10-21');
        $this->assertGuest();
    }

    public function test_a_login_page_link_with_a_malformed_date_shows_nothing(): void
    {
        $this->get('/login?ended=2026-02-30')->assertOk()->assertDontSee('Your employment ended');
        $this->get('/login?ended=<script>')->assertOk()->assertDontSee('Your employment ended');
    }

    public function test_deactivating_with_a_past_last_day_signs_them_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        $employee = $this->employee();
        $user = $employee->user;
        $user->forceFill(['remember_token' => 'old-token'])->save();
        foreach (['a', 'b'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $admin = User::factory()->create()->assignRole('admin');

        app(EmployeeLifecycle::class)->deactivate($employee, Carbon::parse('2026-10-16'), $admin);

        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_deactivating_with_today_as_the_last_day_keeps_todays_sessions(): void
    {
        config(['session.driver' => 'database']);
        $employee = $this->employee();
        $user = $employee->user;
        $user->forceFill(['remember_token' => 'old-token'])->save();
        DB::table('sessions')->insert(['id' => 'a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        app(EmployeeLifecycle::class)->deactivate($employee, today(), User::factory()->create()->assignRole('admin'));

        $this->assertSame('old-token', $user->fresh()->remember_token);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_reactivating_restores_access_with_the_same_password(): void
    {
        $employee = $this->employee();
        app(EmployeeLifecycle::class)->deactivate($employee, Carbon::parse('2026-10-16'), User::factory()->create()->assignRole('admin'));

        $this->post('/login', ['email' => 'visal', 'password' => 'password'])->assertSessionHasErrors('auth');

        app(EmployeeLifecycle::class)->reactivate($employee->fresh());

        $this->post('/login', ['email' => 'visal', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_an_admin_cannot_deactivate_their_own_record_in_the_service(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $own = Employee::factory()->create(['user_id' => $admin->id]);

        $this->expectException(SelfDeactivationException::class);

        try {
            app(EmployeeLifecycle::class)->deactivate($own, today(), $admin);
        } finally {
            $this->assertSame('active', $own->fresh()->status);
        }
    }

    public function test_an_admin_cannot_deactivate_their_own_record_in_the_modal_and_is_told_why(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $own = Employee::factory()->create(['user_id' => $admin->id]);

        Livewire::actingAs($admin)->test(StatusModal::class)
            ->call('openDeactivate', $own->id)
            ->assertSee(SelfDeactivationException::MESSAGE)
            ->call('confirm')
            ->assertHasErrors(['left_on']);

        $this->assertSame('active', $own->fresh()->status);
    }

    public function test_the_profile_and_directory_disable_deactivate_on_your_own_record_only(): void
    {
        $admin = User::factory()->create(['name' => 'Sophea'])->assignRole('admin');
        $own = Employee::factory()->create(['user_id' => $admin->id, 'full_name' => 'Sophea Chan']);
        $other = Employee::factory()->create(['full_name' => 'Dara Pich']);

        $this->actingAs($admin)->get(route('employees.show', $own))
            ->assertSee(SelfDeactivationException::MESSAGE)
            ->assertSee('aria-describedby="deactivate-self-hint"', false)
            ->assertDontSee("\$dispatch('deactivate-employee', { id: {$own->id} })", false);

        $this->actingAs($admin)->get(route('employees.show', $other))
            ->assertDontSee(SelfDeactivationException::MESSAGE)
            ->assertSee("\$dispatch('deactivate-employee', { id: {$other->id} })", false);

        $this->actingAs($admin)->get(route('employees.index'))
            ->assertSee('Deactivate Sophea Chan: '.e(SelfDeactivationException::MESSAGE), false)
            ->assertDontSee("\$dispatch('deactivate-employee', { id: {$own->id} })", false)
            ->assertSee("\$dispatch('deactivate-employee', { id: {$other->id} })", false);
    }
}
