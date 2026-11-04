<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Closure;

/**
 * Sign-in takes a username or an employee code (LoginIdentifier, Phase 6g),
 * so one may never be another person's other: a username that is another
 * employee's code, or a code that is another user's username, would sign in
 * as whichever was found first. Case doesn't matter, as at sign-in (the
 * columns' collation). A user's username may be their own employee's code —
 * the employee form suggests exactly that.
 */
final class UsernameCodeCollision
{
    /**
     * A validation rule for a username, for the account linked to
     * $ownEmployeeId (null: none, or not created yet).
     */
    public static function forUsername(?int $ownEmployeeId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ownEmployeeId) {
            $employee = Employee::query()
                ->where('employee_code', trim((string) $value))
                ->when($ownEmployeeId !== null, fn ($query) => $query->whereKeyNot($ownEmployeeId))
                ->first();

            if ($employee !== null) {
                $fail("\"{$value}\" is {$employee->full_name}'s employee code, and people sign in with either. Choose a different username.");
            }
        };
    }

    /**
     * A validation rule for an employee code, for the employee whose login is
     * $ownUserId (null: none).
     */
    public static function forEmployeeCode(?int $ownUserId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ownUserId) {
            $user = User::query()
                ->where('username', trim((string) $value))
                ->when($ownUserId !== null, fn ($query) => $query->whereKeyNot($ownUserId))
                ->first();

            if ($user !== null) {
                $fail("\"{$value}\" is {$user->name}'s username, and people sign in with either. Choose a different employee code.");
            }
        };
    }
}
