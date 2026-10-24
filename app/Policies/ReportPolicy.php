<?php

namespace App\Policies;

use App\Models\User;

/**
 * The reports (Phase 4d: the monthly overtime report, the first; Phase 5b:
 * one Reports page with a tab per report). Not tied to a model, so its
 * abilities are registered as gates (AppServiceProvider). The page's own
 * ability, `reports.view`, is true when the user may see any tab, each tab by
 * the very ability its component checks — the same shape as PoliciesPolicy —
 * so the sidebar item, the route and the page can't drift from the tabs.
 */
class ReportPolicy
{
    /**
     * Tab key => label, in display order. Leave joins in 5b.2; its ability
     * already exists below.
     */
    public const TABS = [
        'attendance' => 'Attendance',
        'overtime' => 'Overtime',
    ];

    public function view(User $user): bool
    {
        return $this->tabs($user) !== [];
    }

    /**
     * The tabs this user may see, in display order.
     *
     * @return array<string, string>
     */
    public function tabs(User $user): array
    {
        return array_filter(
            self::TABS,
            fn (string $tab) => $user->can('reports.'.$tab),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The monthly attendance report — an admin for everyone, a manager for
     * their team (EmployeeScope). A manager with no employee record has no
     * team to report on, so no report.
     */
    public function attendance(User $user): bool
    {
        return $this->teamReport($user);
    }

    /** The yearly leave report (5b.2) — the same people as attendance. */
    public function leave(User $user): bool
    {
        return $this->teamReport($user);
    }

    /** The monthly overtime report, for payroll — admins. */
    public function overtime(User $user): bool
    {
        return $user->hasRole('admin');
    }

    private function teamReport(User $user): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('manager') && $user->employee !== null);
    }
}
