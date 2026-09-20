<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered globally on the 'web' middleware group (bootstrap/app.php),
 * not per-route — a per-route middleware list is exactly how this kind of
 * gate usually breaks: one forgotten route group and the block has a hole
 * in it. Running globally means every route is covered by construction, not
 * by remembering to add it everywhere.
 *
 * A guest ($request->user() is null) is unaffected — this only ever acts on
 * an authenticated user with must_change_password still set.
 */
class EnsureMustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs('password.change', 'password.change.update', 'logout')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
