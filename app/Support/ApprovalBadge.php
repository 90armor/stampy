<?php

namespace App\Support;

use App\Models\Leave;
use App\Models\User;
use App\Services\Approval\ApprovalInbox;

/**
 * The pending-approvals count the sidebar's Approvals item and the topbar's
 * menu-button dot both show (Phase 3e) — computed once per request
 * (once(), keyed by the user), not once per partial that renders it. Zero
 * for anyone without the Approvals page's own ability (LeavePolicy::decideAny),
 * so a badge never points at a page its user can't open.
 */
final class ApprovalBadge
{
    public static function count(User $user): int
    {
        return once(fn () => $user->can('decideAny', Leave::class) ? ApprovalInbox::count($user) : 0);
    }
}
