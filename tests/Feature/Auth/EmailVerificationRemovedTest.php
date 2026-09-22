<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Email verification was removed: accounts are provisioned by an admin, many have no
 * email at all, and User never implemented MustVerifyEmail, so nothing enforced it.
 * These pin that it stays gone, and that nothing has quietly started gating on it.
 */
class EmailVerificationRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_verification_routes_no_longer_exist(): void
    {
        foreach (['verification.notice', 'verification.verify', 'verification.send'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $user = User::factory()->create();

        $this->actingAs($user)->get('/verify-email')->assertNotFound();
        $this->actingAs($user)->post('/email/verification-notification')->assertNotFound();
    }

    public function test_no_route_requires_a_verified_email(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertNotContains('verified', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_a_user_without_a_verified_email_can_use_the_app_and_edit_their_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->actingAs($user)->patch('/profile', ['name' => 'Renamed', 'email' => 'new@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }
}
