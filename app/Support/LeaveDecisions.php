<?php

namespace App\Support;

use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The "New" state on an employee's leave requests (Phase 3e) — RequestDecisions'
 * rule, applied to leave. Read by Time off (which marks the decisions seen,
 * users.time_off_seen_at) and by the employee dashboard (which shows them
 * until Time off is opened).
 */
final class LeaveDecisions
{
    /**
     * @return Collection<int, Leave>
     */
    public static function since(Employee $employee, User $user, ?CarbonInterface $seenAt): Collection
    {
        if ($seenAt === null) {
            return collect();
        }

        return RequestDecisions::since(
            Leave::query()->where('employee_id', $employee->id)->with(['leaveType', 'approvalSteps.decidedBy'])->get(),
            $user,
            $seenAt,
        );
    }

    /** The decision's own note (approve or reject), the requester's feedback. */
    public static function note(Leave $leave): ?ApprovalStep
    {
        return RequestDecisions::note($leave);
    }
}
