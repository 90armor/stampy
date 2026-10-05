<?php

namespace App\Services\Approval;

use App\Enums\LeaveStatus;
use App\Models\Leave;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What awaits a user's decision, in three groups the Approvals page shows
 * separately (3e):
 *
 * - stepOne: step-1 requests from the people they manage (manager or admin
 *   role, Employee::subordinateIds() — the same rule as ApprovalFlow).
 * - stepTwo (admins): requests waiting at step 2.
 * - overrides (admins): other step-1 requests they could decide in the
 *   manager's place — including any whose chain has nobody eligible left.
 *
 * Never the user's own requests. count() is the sidebar badge: a query each
 * time, not a stored number.
 */
class ApprovalInbox
{
    /**
     * @return array{stepOne: Collection<int, Leave>, stepTwo: Collection<int, Leave>, overrides: Collection<int, Leave>}
     */
    public static function for(User $user): array
    {
        $ownEmployeeId = $user->employee?->id;
        $pending = fn (int $step) => Leave::query()
            ->where('status', LeaveStatus::Pending->value)
            ->where('current_step', $step)
            ->when($ownEmployeeId !== null, fn ($query) => $query->where('employee_id', '!=', $ownEmployeeId))
            ->with(['employee', 'leaveType'])
            ->orderBy('start_date')
            ->orderBy('id');

        $managed = $user->hasAnyRole(['manager', 'admin']) && $user->employee !== null
            ? $user->employee->subordinateIds()
            : [];

        $stepOne = $managed === []
            ? collect()
            : $pending(ApprovalFlow::MANAGER_STEP)->whereIn('employee_id', $managed)->get();

        if (! $user->hasRole('admin')) {
            return ['stepOne' => $stepOne, 'stepTwo' => collect(), 'overrides' => collect()];
        }

        return [
            'stepOne' => $stepOne,
            'stepTwo' => $pending(ApprovalFlow::ADMIN_STEP)->get(),
            'overrides' => $pending(ApprovalFlow::MANAGER_STEP)->whereNotIn('id', $stepOne->pluck('id'))->get(),
        ];
    }

    public static function count(User $user): int
    {
        return collect(self::for($user))->sum(fn (Collection $group) => $group->count());
    }
}
