<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * What happens when a session runs out under someone's feet, and right
 * after they sign in again (Phase 6b, F17). Wired in bootstrap/app.php's
 * exception handling.
 *
 * The session is an idle timeout, so it expires on a tab left open — or
 * after a logout, a password change or a deactivation elsewhere. Then:
 *
 * - A Livewire request (or any other non-GET) arriving unauthenticated
 *   remembers the PAGE as the intended URL — for Livewire the page the
 *   component lives on (Livewire::originalUrl()), never /livewire/update —
 *   and goes to sign-in with "Your session expired". The notice is a query
 *   parameter: Livewire follows the redirect inside fetch() before
 *   navigating, which would spend a flash.
 * - A plain GET of a protected page keeps Laravel's own behaviour: the
 *   intended URL, and no notice — the visitor may never have signed in.
 * - After signing in, the intended page loads fresh; nothing interrupted is
 *   replayed. If that page is forbidden to whoever signed in (a shared
 *   office computer), the first request after sign-in goes to the dashboard
 *   instead of a 403 (AuthenticatedSessionController flashes the marker).
 */
class SessionExpiry
{
    /** Flashed at sign-in when there was an intended URL to return to. */
    public const RETURNING = 'signed_in_to_intended';

    public static function unauthenticated(Request $request): ?RedirectResponse
    {
        if ($request->is('api/*') || $request->isMethod('GET') || $request->isMethod('HEAD')) {
            return null;
        }

        $page = Livewire::isLivewireRequest() ? Livewire::originalUrl() : url()->previous();

        if (self::isOwnPage($page)) {
            $request->session()->put('url.intended', $page);
        }

        return redirect()->route('login', ['expired' => 1]);
    }

    public static function forbiddenAfterSignIn(Throwable $e, Request $request): ?RedirectResponse
    {
        if (! $e instanceof HttpExceptionInterface || $e->getStatusCode() !== 403) {
            return null;
        }

        if (! $request->isMethod('GET') || Livewire::isLivewireRequest() || ! $request->hasSession() || ! $request->session()->get(self::RETURNING)) {
            return null;
        }

        return redirect()->route('dashboard');
    }

    /** A page of this app — not the login page itself, not a Livewire endpoint. */
    private static function isOwnPage(?string $url): bool
    {
        if ($url === null || ! str_starts_with($url.'/', url('/').'/')) {
            return false;
        }

        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return ! in_array($path, ['/login', '/logout'], true) && ! str_starts_with($path, '/livewire');
    }
}
