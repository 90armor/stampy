<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\SessionExpiry;
use App\Support\StrictDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): View
    {
        // Notices that arrive in the URL rather than flashed (EnsureStillEmployed,
        // SessionExpiry): a Livewire request follows the redirect before it
        // navigates, which would spend a flash. Only a real date is read.
        $endedOn = null;

        if (is_string($ended = $request->query('ended')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ended) === 1) {
            try {
                $endedOn = StrictDate::parse('Y-m-d', $ended);
            } catch (InvalidArgumentException) {
            }
        }

        return view('auth.login', [
            'endedMessage' => $endedOn === null ? null : User::employmentEndedMessage($endedOn),
            'expired' => $endedOn === null && $request->boolean('expired'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Returning to a page the session ran out on (or a link followed while
        // signed out): if that page turns out to be forbidden to whoever just
        // signed in, the first request lands on the dashboard instead of a 403.
        if ($request->session()->has('url.intended')) {
            $request->session()->flash(SessionExpiry::RETURNING, true);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
