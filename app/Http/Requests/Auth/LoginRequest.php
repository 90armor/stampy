<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
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
     * The field is still named/keyed 'email' (matches the view's input name
     * and the throttle key below) but no longer requires email FORMAT —
     * many employees have no email address (users.email is nullable) and
     * sign in with their username instead. See authenticate() for which
     * column it's actually matched against.
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

        $identifier = $this->string('email')->toString();
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$field => $identifier, 'password' => $this->string('password')], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // Kept out of the 'email'/'password' keys (reserved for real field
            // validation) so the login view can render this as a standalone
            // banner instead of a per-field message.
            throw ValidationException::withMessages([
                'auth' => __('Incorrect email/username or password. Please try again.'),
            ]);
        }

        $user = Auth::user();

        // Credentials matched, but a temporary password past its 48h window
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
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
