<?php

namespace Tests\Feature\Auth;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6b, F16 and F17 — a session that ran out mid-use (SessionExpiry):
 * a Livewire action arriving signed out remembers the page, not
 * /livewire/update, and the login page says the session expired; a plain
 * GET while signed out keeps Laravel's intended URL with no notice; signing
 * in returns to the page, or to the dashboard when that page is forbidden to
 * whoever signed in; nothing interrupted is replayed. Plus the 419/503
 * views and the signed-in 404 in the app shell. The clock is Mon 15 Jun 2026.
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        // Before the employee, so they're granted it on creation.
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $user = User::factory()->create(['username' => 'kyaw', 'password' => 'password'])->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $user->id, 'full_name' => 'Kyaw Kyaw']);
    }

    /** The first component snapshot on a page, as the browser would send it back. */
    private function snapshotOn(string $url): string
    {
        $html = $this->actingAs($this->employee->user)->get($url)->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
        $this->assertNotEmpty($match, "No Livewire component on {$url}");

        return html_entity_decode($match[1]);
    }

    /** A Livewire call from a tab whose session has gone. */
    private function livewireCallSignedOut(string $snapshot, string $method, array $params = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]],
        ]);
    }

    public function test_a_livewire_action_after_the_session_ends_remembers_the_page_and_says_it_expired(): void
    {
        $snapshot = $this->snapshotOn('/time-off');

        $this->livewireCallSignedOut($snapshot, '$refresh')
            ->assertRedirect(route('login', ['expired' => 1]));

        $this->assertSame(url('/time-off'), session('url.intended'));
        $this->assertGuest();

        $this->get(route('login', ['expired' => 1]))->assertSee('Your session expired. Sign in to continue.');
    }

    public function test_signing_in_returns_to_the_page_and_replays_nothing(): void
    {
        $leave = app(LeaveRequestService::class)->submit($this->employee, $this->annual, Carbon::parse('2026-06-22'), Carbon::parse('2026-06-22'), null, null, $this->employee->user)['leave'];
        $snapshot = $this->snapshotOn('/time-off');

        // The tab tried to cancel the leave after the session had gone.
        $this->livewireCallSignedOut($snapshot, 'cancel', [$leave->id])->assertRedirect(route('login', ['expired' => 1]));
        $this->assertSame(LeaveStatus::Pending, $leave->fresh()->status);

        $this->post('/login', ['email' => 'kyaw', 'password' => 'password'])->assertRedirect(url('/time-off'));
        $this->get('/time-off')->assertOk();

        // The page loads fresh; the cancel isn't replayed.
        $this->assertSame(LeaveStatus::Pending, $leave->fresh()->status);
        $this->assertSame(1, Leave::count());
    }

    public function test_a_plain_get_while_signed_out_keeps_the_intended_url_without_a_notice(): void
    {
        $this->get('/time-off')->assertRedirect(route('login'));
        $this->assertSame(url('/time-off'), session('url.intended'));

        $this->get(route('login'))->assertDontSee('Your session expired');
    }

    public function test_an_intended_page_forbidden_to_whoever_signed_in_lands_on_the_dashboard(): void
    {
        // Someone opened /employees (admin-only for them, as it happens) and walked away.
        $this->get('/employees')->assertRedirect(route('login'));

        $this->post('/login', ['email' => 'kyaw', 'password' => 'password'])->assertRedirect(url('/employees'));
        $this->get('/employees')->assertRedirect(route('dashboard'));

        // Only the first request after sign-in: later, a forbidden page is a 403 as usual.
        $this->get('/employees')->assertForbidden();
    }

    public function test_an_ordinary_403_is_unchanged(): void
    {
        $this->actingAs($this->employee->user)->get('/employees')->assertForbidden()->assertSee("You don't have access to this page");
    }

    public function test_a_signed_in_404_uses_the_app_shell_and_a_guest_404_the_guest_shell(): void
    {
        $this->actingAs($this->employee->user)->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to dashboard')
            ->assertSee('Time off');

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to login');
    }

    public function test_the_fallback_keeps_404_for_an_unknown_url_and_405_for_a_wrong_method(): void
    {
        $this->actingAs($this->employee->user)->post('/no-such-page')->assertNotFound();
        $this->actingAs($this->employee->user)->delete('/time-off')->assertStatus(405)->assertHeader('Allow');
    }

    public function test_a_model_404_still_uses_the_app_shell(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->get('/employees/99999')->assertNotFound()->assertSee('Go to dashboard');
    }

    public function test_the_419_and_503_pages_say_what_to_do(): void
    {
        Route::middleware('web')->get('/_test/expired', fn () => abort(419));
        Route::middleware('web')->get('/_test/maintenance', fn () => abort(503));

        $this->get('/_test/expired')->assertStatus(419)
            ->assertSee('This page expired')
            ->assertSee('Reload and try again.')
            ->assertSee('Reload');

        $this->get('/_test/maintenance')->assertStatus(503)
            ->assertSee('Stampy is down for maintenance')
            ->assertSee('Reload');
    }
}
