<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkSchedule;

class WorkSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, WorkSchedule $schedule): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, WorkSchedule $schedule): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, WorkSchedule $schedule): bool
    {
        return $user->hasRole('admin');
    }
}
