<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The one definition of which employees a user may see in lists and figures
 * (mirroring EmployeePolicy::view, which answers the same question for a
 * single record — a test keeps the two in step):
 *
 * - admin: everyone (null = no restriction, avoids a pointless whereIn);
 * - a user with no linked employee record: nobody. They have no position in
 *   the org tree, so this is an empty list, not "everyone", and callers show
 *   an explicit explanation rather than a bare "no results";
 * - manager: themself plus their transitive subordinates;
 * - anyone else: only their own record.
 *
 * Anything that asks "is this person one of my reports?" should come through
 * here or EmployeePolicy::view rather than defining "reports" again.
 */
final readonly class EmployeeScope
{
    /**
     * @param  int[]|null  $ids  null = no restriction
     */
    private function __construct(
        public ?array $ids,
        public bool $hasNoEmployeeRecord,
    ) {}

    /**
     * @param  string  $context  what is being viewed, for the log line when the user has no linked employee
     */
    public static function for(User $user, string $context): self
    {
        if ($user->hasRole('admin')) {
            return new self(null, false);
        }

        $employee = $user->employee;

        if ($employee === null) {
            Log::warning("{$context} viewed by a user with no linked employee record — showing an empty scope.", [
                'user_id' => $user->id,
            ]);

            return new self([], true);
        }

        if ($user->hasRole('manager')) {
            return new self([$employee->id, ...$employee->subordinateIds()], false);
        }

        return new self([$employee->id], false);
    }
}
