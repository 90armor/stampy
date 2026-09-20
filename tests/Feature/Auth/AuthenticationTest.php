<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertStatus(200)
            ->assertSee('<title>Sign in – '.config('app.name', 'Laravel').'</title>', false)
            ->assertSee('Keep me signed in on this device')
            ->assertSee('Switch to dark mode');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_with_a_username(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('auth');
    }

    public function test_required_login_fields_render_accessible_error_states(): void
    {
        $this->from('/login')->post('/login', [
            'email' => '',
            'password' => '',
        ])->assertRedirect('/login');

        $this->get('/login')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="email-error"', false)
            ->assertSee('aria-describedby="password-error"', false);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
