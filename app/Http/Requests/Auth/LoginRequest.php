<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\LoginIdentifier;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private ?User $resolved = null;

    private bool $hasResolved = false;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The field is still named/keyed 'email' (the view's input name) but
     * takes any identifier LoginIdentifier resolves — a username, an
     * employee code or an email (Phase 6g, F15): many employees have no email
     * address (users.email is nullable).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->resolvedUser();

        // By id once resolved, so the identifier the user typed doesn't
        // matter past this point (its events and password rehash are Laravel's).
        if ($user === null || ! Auth::attempt(['id' => $user->id, 'password' => $this->string('password')], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // Kept out of the 'email'/'password' keys (reserved for real field
            // validation) so the login view can render this as a standalone
            // banner instead of a per-field message.
            throw ValidationException::withMessages([
                'auth' => __('Incorrect username, employee code or password.'),
            ]);
        }

        $user = Auth::user();

        // Credentials matched, but a temporary password past its window (TemporaryPassword::LIFETIME_HOURS)
        // must not grant a session at all — not just get caught later by
        // the must-change-password redirect. Undo the attempt and give the
        // specific reason rather than the generic message above, which
        // would wrongly read as "you mistyped something".
        if ($user->hasExpiredTemporaryPassword()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'auth' => __('This temporary password has expired. Please ask an admin for a new one.'),
            ]);
        }

        // A leaver (Phase 6b): checked only once the password is known to be
        // right, so the message can't tell anyone that an account exists.
        if (($leftOn = $user->employmentEndedOn()) !== null) {
            Auth::logout();

            throw ValidationException::withMessages([
                'auth' => User::employmentEndedMessage($leftOn),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'auth' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request: the resolved user
     * when there is one (Phase 6g, F15), so switching between their username,
     * employee code and email shares one count; else what was typed.
     */
    public function throttleKey(): string
    {
        $user = $this->resolvedUser();

        return $user !== null
            ? 'user:'.$user->id.'|'.$this->ip()
            : Str::transliterate(Str::lower(trim($this->string('email')))).'|'.$this->ip();
    }

    /** Looked up once per request: the throttle key and the attempt share it. */
    private function resolvedUser(): ?User
    {
        if (! $this->hasResolved) {
            $this->resolved = LoginIdentifier::resolve($this->string('email')->toString(), 'Sign-in');
            $this->hasResolved = true;
        }

        return $this->resolved;
    }
}
