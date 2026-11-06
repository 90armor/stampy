<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Type it again" fails under Confirm, as "The passwords don't match.", on the
 * forced change and the reset link as on the profile (ProfileTest) — never
 * Laravel's "The password field confirmation does not match." under the new
 * password (Phase 6i follow-up, App\Support\PasswordConfirmation).
 */
class PasswordConfirmationMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forced_change_puts_a_mismatch_under_confirm(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->from(route('password.change'))->put(route('password.change.update'), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-different-password',
        ])
            ->assertRedirect(route('password.change'))
            ->assertSessionHasErrors(['password_confirmation' => "The passwords don't match."])
            ->assertSessionDoesntHaveErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_the_reset_link_puts_a_mismatch_under_confirm(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-brand-new-password',
                'password_confirmation' => '',
            ])
                ->assertSessionHasErrors(['password_confirmation' => "The passwords don't match."])
                ->assertSessionDoesntHaveErrors('password');

            return true;
        });
    }
}
