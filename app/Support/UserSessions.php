<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends a user's signed-in sessions: rotates their remember token, so a
 * "remember me" cookie stops working, and deletes their database sessions.
 * Used by deactivation (every session) and by a password change on the
 * profile (every session but the one that changed it, Phase 6i).
 */
class UserSessions
{
    public static function end(User $user, ?string $except = null): void
    {
        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }
}
