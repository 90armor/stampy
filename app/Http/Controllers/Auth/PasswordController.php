<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        // Same bookkeeping as the other two paths that result in a genuinely
        // new, self-chosen password (ForcePasswordChangeController,
        // NewPasswordController) — this route is normally unreachable while
        // must_change_password is set (EnsureMustChangePassword blocks it),
        // but clearing it here too keeps all three paths consistent rather
        // than relying on that being the only thing preventing a stale flag.
        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'temporary_password_expires_at' => null,
        ]);

        return back()->with('status', 'password-updated');
    }
}
