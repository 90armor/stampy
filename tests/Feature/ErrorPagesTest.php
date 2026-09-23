<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laravel's own bundled 403/404/500 views (vendor/laravel/framework/.../
 * views/{code}.blade.php, extending errors::minimal) are a bare, unstyled
 * page with no navigation and the raw exception message — e.g. spatie/
 * laravel-permission's own internal wording for a role denial. An app view
 * at resources/views/errors/{code}.blade.php takes precedence over that
 * vendor one regardless of APP_DEBUG (Illuminate\Foundation\Exceptions\
 * Handler::getHttpExceptionView() just checks view()->exists('errors::{code}')
 * before ever consulting debug mode), so these three replace it with the
 * app's own layout, a plain message, and a way back.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    public function test_a_role_denied_403_shows_the_app_shell_and_a_plain_message(): void
    {
        $employeeUser = User::factory()->create()->assignRole('employee');
        Employee::factory()->create(['user_id' => $employeeUser->id]);

        $response = $this->actingAs($employeeUser)->get('/employees');

        $response->assertStatus(403);
        $response->assertSee("You don't have access to this page");
        $response->assertSee('Go to dashboard');
        // The app shell, not a bare page — the sidebar's own nav items.
        $response->assertSee('Dashboard');
        $response->assertSee('My attendance');
        // Never spatie/laravel-permission's raw internal wording.
        $response->assertDontSee('does not have the right roles');
    }

    public function test_a_404_for_an_authenticated_user_shows_the_app_shell(): void
    {
        $response = $this->actingAs($this->admin())->get('/employees/999999');

        $response->assertStatus(404);
        $response->assertSee('Page not found');
        $response->assertSee('Go to dashboard');
        $response->assertSee('Dashboard');
    }

    public function test_a_404_for_an_anonymous_visitor_does_not_crash_and_offers_login(): void
    {
        // No auth at all — layouts.app's sidebar/topbar call auth()->user()
        // directly with no null-guard, so this must render via the guest
        // layout instead, or it would turn an anonymous 404 into a 500.
        $response = $this->get('/this-route-does-not-exist-anywhere');

        $response->assertStatus(404);
        $response->assertSee('Page not found');
        $response->assertSee('Go to login');
    }

    public function test_the_500_view_renders_for_an_anonymous_viewer(): void
    {
        $anonymous = view('errors.500', ['errors' => new ViewErrorBag])->render();
        $this->assertStringContainsString('Something went wrong', $anonymous);
        $this->assertStringContainsString('Go to login', $anonymous);
    }

    public function test_the_500_view_renders_for_an_authenticated_viewer(): void
    {
        $this->actingAs($this->admin());

        $authed = view('errors.500', ['errors' => new ViewErrorBag])->render();
        $this->assertStringContainsString('Something went wrong', $authed);
        $this->assertStringContainsString('Go to dashboard', $authed);
    }
}
