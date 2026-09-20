<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
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
     * The field accepts a username too (like the login form) — an employee
     * with no email has nothing valid to type in an email-only field, and
     * they're likely to try their username here since that's what they log
     * in with. Breeze's own behavior (validate as email, mail a link) is
     * otherwise untouched for anyone who does have an email.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string'],
        ]);

        $identifier = $request->string('email')->toString();

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        // Found by username (or by an email that happens to be null-safe —
        // it can't be, since $identifier is never null) but has no email on
        // file: Password::sendResetLink() has nothing to mail. Say so
        // directly instead of leaving them on a form that can never work.
        if ($user !== null && $user->email === null) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => __('This account has no email on file. Ask an admin to reset your password instead.'),
            ]);
        }

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink([
            'email' => $user?->email ?? $identifier,
        ]);

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
