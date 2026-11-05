<?php

namespace App\Policies;

use App\Models\User;

/**
 * A login's own account details on the profile (Phase 6i).
 *
 * Email — and the name, for a login with no employee record — is changed
 * only by an admin, on their own account: everyone else asks HR, who change
 * it on the employee record. A linked login's name is HR data and comes from
 * that record, whoever is asking (Employees\FormModal keeps it in step).
 */
class UserPolicy
{
    public function updateAccount(User $actor, User $account): bool
    {
        return $actor->is($account) && $actor->hasRole('admin');
    }
}
