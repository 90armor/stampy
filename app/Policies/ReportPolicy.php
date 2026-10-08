<?php

namespace App\Policies;

use App\Models\User;

/**
 * The reports (Phase 4d: the monthly overtime report, the first). Not tied to
 * a model, so its abilities are registered as gates (AppServiceProvider);
 * the sidebar's Reports item and the page itself both check the same one.
 */
class ReportPolicy
{
    /** The monthly overtime report, for payroll — admins. */
    public function overtime(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
