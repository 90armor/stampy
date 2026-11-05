<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * What a sign-in or forgot-password identifier names (Phase 6g, F15): a
 * username, the employee code of an employee with a login, or an email —
 * looked up in that order, trimmed. Case doesn't matter: the three columns'
 * collation (utf8mb4_unicode_ci) compares case-insensitively.
 *
 * It must name one user. If it would name two different ones — one's
 * username is another's employee code, say — it names nobody, and the
 * collision is logged: signing in as whichever came first would be a guess.
 * Validation keeps new usernames and codes from colliding
 * (UsernameCodeCollision); this is the guard for data that already does.
 */
final class LoginIdentifier
{
    public static function resolve(string $identifier, string $context): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $matches = collect([
            'username' => User::query()->where('username', $identifier)->first(),
            'employee code' => Employee::query()->where('employee_code', $identifier)->whereNotNull('user_id')->first()?->user,
            'email' => User::query()->where('email', $identifier)->first(),
        ])->filter();

        if ($matches->unique('id')->count() > 1) {
            Log::warning("{$context}: the identifier \"{$identifier}\" names more than one user (".$matches->map(fn (User $user, string $as) => "{$as}: user #{$user->id}")->implode(', ').'), so it signs nobody in. Change the username or the employee code so they differ.');

            return null;
        }

        return $matches->first();
    }
}
