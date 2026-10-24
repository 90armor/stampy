<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;

/**
 * The Policies page (Phase 5a). Not a model, so its ability is a gate
 * (`policies.view`, AppServiceProvider), like ReportPolicy's: true when the
 * user may see any tab, each tab by the very ability its component checks in
 * mount(). The sidebar item, the route and the page all use it, so the page
 * never opens on a tab whose component would refuse, and the item never
 * depends on one tab's model. Every tab is admin-only today.
 */
class PoliciesPolicy
{
    /** Tab key => label, in display order: working time first. */
    public const TABS = [
        'schedules' => 'Schedules',
        'holidays' => 'Holidays',
        'leave-types' => 'Leave types',
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
            fn (string $tab) => $this->canSee($user, $tab),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function canSee(User $user, string $tab): bool
    {
        return match ($tab) {
            'schedules' => $user->can('viewAny', WorkSchedule::class),
            'holidays' => $user->can('viewAny', Holiday::class),
            'leave-types' => $user->can('viewAny', LeaveType::class),
            'overtime' => $user->can('view', OvertimeSettings::current()),
        };
    }
}
