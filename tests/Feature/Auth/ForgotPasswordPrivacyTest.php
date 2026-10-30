<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6b, F09 — the forgot-password form never says whether an account
 * exists: every outcome gets the same message, a link is still only mailed to
 * an email on file, and the throttle a visitor can see trips the same way for
 * any username or email and says how long to wait.
 */
class ForgotPasswordPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public static function identifiers(): array
    {
        return [
            'an account with an email, by email' => ['kyaw@example.com'],
            'an account with an email, by username' => ['kyaw'],
            'an account with no email' => ['sreyneang'],
            'no such account' => ['nobody@example.com'],
        ];
    }

    #[DataProvider('identifiers')]
    public function test_every_outcome_gets_the_same_message(string $identifier): void
    {
        Notification::fake();
        User::factory()->create(['username' => 'kyaw', 'email' => 'kyaw@example.com']);
        User::factory()->create(['username' => 'sreyneang', 'email' => null]);

        $this->post('/forgot-password', ['email' => $identifier])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', PasswordResetLinkController::SENT);

        $this->get('/forgot-password')->assertSee(e(PasswordResetLinkController::SENT), false);
    }

    public function test_a_link_is_only_mailed_to_an_email_on_file(): void
    {
        Notification::fake();
        $kyaw = User::factory()->create(['username' => 'kyaw', 'email' => 'kyaw@example.com']);
        $sreyneang = User::factory()->create(['username' => 'sreyneang', 'email' => null]);

        $this->post('/forgot-password', ['email' => 'kyaw']);
        $this->post('/forgot-password', ['email' => 'sreyneang']);

        Notification::assertSentTo($kyaw, ResetPassword::class);
        Notification::assertNotSentTo($sreyneang, ResetPassword::class);
    }

    #[DataProvider('identifiers')]
    public function test_the_throttle_states_the_wait_whether_or_not_the_account_exists(string $identifier): void
    {
        // A frozen clock, so the wait reads a whole minute.
        $this->freezeTime();
        Notification::fake();
        User::factory()->create(['username' => 'kyaw', 'email' => 'kyaw@example.com']);
        User::factory()->create(['username' => 'sreyneang', 'email' => null]);

        foreach (range(1, 3) as $attempt) {
            $this->post('/forgot-password', ['email' => $identifier])->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => $identifier])
            ->assertSessionHasErrors(['email' => 'Too many attempts. Try again in 60 seconds.']);
    }

    public function test_the_field_says_username_or_email(): void
    {
        $this->get('/forgot-password')->assertSee('Username or email');

        $this->post('/forgot-password', ['email' => ''])
            ->assertSessionHasErrors(['email' => 'Enter your username or email.']);
    }
}
