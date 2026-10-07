<?php

namespace App\Policies;

use App\Enums\OvertimeStatus;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalFlow;

/**
 * Who may do what with overtime requests (CLAUDE.md, Phase 4, rule 19) —
 * LeavePolicy's twin. The services call it too (OvertimeRequestService), so a
 * caller can't skip it. In a list that mixes leave and overtime (ApprovalInbox)
 * each item is acted on through its own type's policy.
 */
class OvertimePolicy
{
    /**
     * The Overtime page and its sidebar item (Phase 4d): anyone with an
     * employee record (their own requests), and an admin, who files for
     * others there — the Time off precedent (LeavePolicy::timeOff). A manager
     * without an employee record has an empty EmployeeScope, so nothing to
     * see. Which requests a list shows is OvertimeRequest::scopeVisibleTo().
     */
    public function viewAny(User $user): bool
    {
        return $user->employee !== null || $this->fileForOthers($user);
    }

    /** Filing on someone else's behalf ("File for an employee") — admins only. */
    public function fileForOthers(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /** Mirrors EmployeePolicy::view: their own, their reports' (a manager's), anyone's (an admin's). */
    public function view(User $user, OvertimeRequest $request): bool
    {
        return $user->can('view', $request->employee);
    }

    /** For themself, or an admin for anyone — including employees with no login (rule 7). */
    public function create(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin') || $user->employee?->is($employee) === true;
    }

    /** A pending request at a step this user may decide (ApprovalFlow::canDecide()). */
    public function approve(User $user, OvertimeRequest $request): bool
    {
        return $request->status === OvertimeStatus::Pending && app(ApprovalFlow::class)->canDecide($user, $request);
    }

    /**
     * A pending or approved request: by the requester (the employee it's for)
     * before its window starts, by an admin at any time.
     */
    public function cancel(User $user, OvertimeRequest $request): bool
    {
        if (! in_array($request->status, [OvertimeStatus::Pending, OvertimeStatus::Approved], true)) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->employee?->is($request->employee) === true && now()->lt($request->starts_at);
    }
}
