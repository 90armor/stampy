<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Who may do what with leave (CLAUDE.md, Phase 3, Authorization). The
 * services call it too (LeaveRequestService), so a caller can't skip it.
 */
class LeavePolicy
{
    /** For themself, or an admin for anyone — including employees with no login. */
    public function create(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin') || $user->employee?->is($employee) === true;
    }
}
