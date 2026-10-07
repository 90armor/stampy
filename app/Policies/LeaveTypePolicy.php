<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;

/**
 * Leave types are company policy (Phase 3e, Policies → Leave types): admins
 * only, like the rest of the configuration.
 */
class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, LeaveType $type): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, LeaveType $type): bool
    {
        return $user->hasRole('admin');
    }
}
