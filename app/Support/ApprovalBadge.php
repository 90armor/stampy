<?php

namespace App\Support;

use App\Models\Leave;
use App\Models\User;
use App\Services\Approval\ApprovalInbox;

/**
 * The pending-approvals count the sidebar's Approvals item and the topbar's
 * menu-button dot and the dashboard's Pending approvals card show (Phase 3e)
 * — computed once per request, not once per place that shows it. Kept on
 * the request itself rather than once(), whose cache outlives a request in
 * a long-lived process (a test making several requests). Zero
 * for anyone without the Approvals page's own ability (LeavePolicy::decideAny),
 * so a badge never points at a page its user can't open.
 */
final class ApprovalBadge
{
    public static function count(User $user): int
    {
        $attributes = request()->attributes;
        $key = 'approval-badge.'.$user->getKey();

        if (! $attributes->has($key)) {
            $attributes->set($key, $user->can('decideAny', Leave::class) ? ApprovalInbox::count($user) : 0);
        }

        return $attributes->get($key);
    }
}
