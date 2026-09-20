<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Plain controller, not a Livewire component — deliberately, so this page's
 * own form submission never round-trips through /livewire/update. The
 * global EnsureMustChangePassword middleware exempts this route by name,
 * but Livewire's update endpoint is one shared route regardless of which
 * component is posting to it; exempting that broadly would let ANY
 * Livewire action bypass the block, and exempting it narrowly (inspecting
 * the payload for this one component) is exactly the kind of fragile
 * plumbing a plain controller avoids by construction.
 */
class ForcePasswordChangeController extends Controller
{
    public function create(): View
    {
        return view('auth.force-password-change');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'password' => [
                'required',
                'confirmed',
                Password::defaults(),
                function (string $attribute, $value, Closure $fail) use ($user) {
                    if (Hash::check($value, $user->password)) {
                        $fail('Your new password must be different from the temporary one.');
                    }
                },
            ],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'temporary_password_expires_at' => null,
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
