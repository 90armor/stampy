<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->hasRole('admin');
    }
}
