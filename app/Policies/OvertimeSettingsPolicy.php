<?php

namespace App\Policies;

use App\Models\OvertimeSettings;
use App\Models\User;

/**
 * The overtime policy is company configuration (Phase 4d, Policies →
 * Overtime): admins only, like leave types.
 */
class OvertimeSettingsPolicy
{
    public function view(User $user, OvertimeSettings $settings): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, OvertimeSettings $settings): bool
    {
        return $user->hasRole('admin');
    }
}
