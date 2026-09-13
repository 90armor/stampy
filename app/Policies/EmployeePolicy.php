<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['admin', 'manager']);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasRole(['admin', 'manager']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin');
    }
}
