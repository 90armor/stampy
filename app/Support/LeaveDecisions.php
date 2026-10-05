<?php

namespace App\Support;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveStatus;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The "New" state on an employee's own requests (Phase 3e) — with no email,
 * it's how they find out a request was decided. One definition, read by Time
 * off (which marks the decisions seen, users.time_off_seen_at) and by the
 * employee dashboard (which shows them until Time off is opened).
 */
final class LeaveDecisions
{
    /**
     * Requests decided after $seenAt — a step approved or rejected, or the
     * request cancelled, by someone other than $user. One the user cancelled
     * themself isn't news, whatever was decided before: their own last word
     * supersedes it. None at all before the first visit to Time off ($seenAt
     * null): everything would be "new", which says nothing.
     *
     * @return Collection<int, Leave>
     */
    public static function since(Employee $employee, User $user, ?CarbonInterface $seenAt): Collection
    {
        if ($seenAt === null) {
            return collect();
        }

        return Leave::query()
            ->where('employee_id', $employee->id)
            ->with(['leaveType', 'approvalSteps.decidedBy'])
            ->orderByDesc('updated_at')
            ->get()
            ->reject(fn (Leave $leave) => $leave->status === LeaveStatus::Cancelled && $leave->cancelled_by === $user->id)
            ->filter(fn (Leave $leave) => $leave->approvalSteps->contains(fn (ApprovalStep $step) => in_array($step->outcome, [ApprovalOutcome::Approved, ApprovalOutcome::Rejected], true)
                    && $step->decided_by !== $user->id
                    && $step->decided_at->gt($seenAt))
                || ($leave->status === LeaveStatus::Cancelled && $leave->cancelled_by !== $user->id && $leave->cancelled_at?->gt($seenAt)))
            ->values();
    }

    /**
     * The decision's own note (approve or reject), the requester's feedback.
     */
    public static function note(Leave $leave): ?ApprovalStep
    {
        return $leave->approvalSteps
            ->filter(fn (ApprovalStep $step) => $step->note !== null && in_array($step->outcome, [ApprovalOutcome::Approved, ApprovalOutcome::Rejected], true))
            ->last();
    }
}
