<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\LoginIdentifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /** The one answer to every request (Phase 6b): it never says whether an account exists. */
    public const SENT = 'If this account has an email on file, a reset link is on its way. No email? Ask an admin to reset your password.';

    /** Requests per identifier typed, per address, per minute. */
    private const ATTEMPTS = 3;

    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * The field takes whatever the login form does — a username, an employee
     * code or an email, resolved the same way (LoginIdentifier, Phase 6g) —
     * since an employee with no email will type what they sign in with.
     *
     * Every outcome — no such account, an account with no email, a link sent,
     * the broker's own per-account throttle — gets the same message (SENT), so
     * the form can't be used to find out which usernames exist (audit F09).
     * Behind it nothing changed: a link is only mailed to an email on file.
     * The throttle the user can see is this form's own, per identifier typed
     * and address, so it trips the same way whether the account exists or not,
     * and says how long to wait.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'string']],
            ['email.required' => 'Enter your username or employee code.'],
            ['email' => 'username or employee code'],
        );

        $identifier = trim($request->string('email')->toString());
        $key = 'password-reset-link:'.Str::transliterate(Str::lower($identifier)).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withInput($request->only('email'))->withErrors([
                'email' => "Too many attempts. Try again in {$seconds} ".Str::plural('second', $seconds).'.',
            ]);
        }

        RateLimiter::hit($key, 60);

        $user = LoginIdentifier::resolve($identifier, 'Password reset request');

        if ($user?->email !== null) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', self::SENT);
    }
}
