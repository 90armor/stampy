<?php

namespace App\Policies;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Services\Approval\ApprovalFlow;

/**
 * Who may do what with leave (CLAUDE.md, Phase 3, Authorization). The
 * services call it too (LeaveRequestService), so a caller can't skip it.
 */
class LeavePolicy
{
    /**
     * Anyone with something to see: admins and managers, and anyone with an
     * employee record (their own leave). Which leaves a list shows is
     * Leave::scopeVisibleTo(), through EmployeeScope.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']) || $user->employee !== null;
    }

    /** Mirrors EmployeePolicy::view: their own, their reports' (a manager's), anyone's (an admin's). */
    public function view(User $user, Leave $leave): bool
    {
        return $user->can('view', $leave->employee);
    }

    /**
     * The Time off page (Phase 3e): anyone with an employee record (their own
     * balances and requests), and anyone who can file for others — an admin
     * without an employee record still files there.
     */
    public function timeOff(User $user): bool
    {
        return $user->employee !== null || $this->fileForOthers($user);
    }

    /** Filing on someone else's behalf ("File for an employee") — admins only. */
    public function fileForOthers(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Correcting a balance with an adjustment (the employee profile's Leave
     * card) — admins only. Also how opening balances go in at go-live.
     */
    public function adjust(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * The Approvals page and its badge (Phase 3e): anyone who could be asked
     * to decide — an admin, or a manager with an employee record (step 1 is
     * for managers whose employee manages the requester, ApprovalFlow).
     */
    public function decideAny(User $user): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('manager') && $user->employee !== null);
    }

    /** For themself, or an admin for anyone — including employees with no login. */
    public function create(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin') || $user->employee?->is($employee) === true;
    }

    /** A pending request at a step this user may decide (ApprovalFlow::canDecide()). */
    public function approve(User $user, Leave $leave): bool
    {
        return $leave->status === LeaveStatus::Pending && app(ApprovalFlow::class)->canDecide($user, $leave);
    }

    /**
     * A pending or approved leave: by the requester (the employee it's for)
     * while it hasn't started yet, by an admin at any time.
     */
    public function cancel(User $user, Leave $leave): bool
    {
        if (! in_array($leave->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->employee?->is($leave->employee) === true && today()->lt($leave->start_date);
    }
}
