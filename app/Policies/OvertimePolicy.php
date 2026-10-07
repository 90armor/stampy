<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Who may do what with overtime requests (CLAUDE.md, Phase 4, rule 19) —
 * LeavePolicy's twin. The services call it too (OvertimeRequestService), so a
 * caller can't skip it.
 */
class OvertimePolicy
{
    /** For themself, or an admin for anyone — including employees with no login (rule 7). */
    public function create(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin') || $user->employee?->is($employee) === true;
    }
}
