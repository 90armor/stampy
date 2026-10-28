<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A leaver has no access from the day after their last day (Phase 6b;
 * User::employmentEndedOn()). Sign-in already refuses them (LoginRequest);
 * this covers what sign-in can't see: a session opened before they were
 * deactivated, a "remember me" cookie, and a last day set in the future
 * that has since passed at midnight. Global on the web group, like
 * EnsureMustChangePassword, so no route can forget it — including
 * Livewire's update requests.
 *
 * The message travels as a query parameter, not flashed: a Livewire request
 * follows the redirect inside fetch() and only then navigates to it, so a
 * flash would be spent on the fetch and never seen. The login page only
 * reads a well-formed date from it.
 */
class EnsureStillEmployed
{
    public function handle(Request $request, Closure $next): Response
    {
        $leftOn = $request->user()?->employmentEndedOn();

        if ($leftOn === null) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login', ['ended' => $leftOn->format('Y-m-d')]);
    }
}
