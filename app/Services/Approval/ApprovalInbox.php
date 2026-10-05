<?php

namespace App\Services\Approval;

use App\Enums\LeaveStatus;
use App\Models\Leave;
use App\Models\User;
use App\Support\WorkdayCalendar;
use Carbon\CarbonInterface;
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
 *   Stuck ones (isStuck()) first, then by start date.
 *
 * Every group is sorted by start date, soonest first. Never the user's own
 * requests.
 *
 * count() is what waits on this user — the sidebar badge, the menu-button
 * dot and the dashboard's Pending approvals card: step 1 for their team, and
 * for an admin step 2 plus only the overrides that are stuck. Counting every
 * override would put every pending request in the company on an admin's
 * badge, and a badge that's always on is one nobody reads. A query each
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
            // sortBy is stable, so the start-date order holds within each half.
            'overrides' => $pending(ApprovalFlow::MANAGER_STEP)->whereNotIn('id', $stepOne->pluck('id'))->get()
                ->sortBy(fn (Leave $leave) => self::isStuck($leave) ? 0 : 1)
                ->values(),
        ];
    }

    public static function count(User $user): int
    {
        $inbox = self::for($user);

        return $inbox['stepOne']->count()
            + $inbox['stepTwo']->count()
            + $inbox['overrides']->filter(fn (Leave $leave) => self::isStuck($leave))->count();
    }

    /**
     * A request waiting at step 1 that an admin should step in on. Both
     * tests count the requester's working days (WorkdayCalendar), so a
     * weekend or a holiday doesn't hide the urgency:
     *
     * - it has waited two working days: two have begun since the day it was
     *   submitted — one submitted on a Monday is stuck from Wednesday, one on
     *   a Friday from Tuesday;
     * - or it starts within two working days — on or before the second
     *   working day after today (on a Friday: Monday or Tuesday) — or has
     *   already started.
     */
    public static function isStuck(Leave $leave, ?CarbonInterface $today = null): bool
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return $leave->start_date->copy()->startOfDay()->lte(self::workdaysAfter($leave, $today, 2))
            || self::workdaysAfter($leave, $leave->created_at->copy()->startOfDay(), 2)->lte($today);
    }

    /**
     * The $count-th working day of the requester after $from. Capped at a
     * month, so a schedule with no working days can't loop.
     */
    private static function workdaysAfter(Leave $leave, CarbonInterface $from, int $count): CarbonInterface
    {
        $day = $from->copy();

        for ($found = 0, $step = 0; $found < $count && $step < 31; $step++) {
            $day = $day->addDay();
            $found += WorkdayCalendar::isWorkday($leave->employee, $day) ? 1 : 0;
        }

        return $day;
    }
}
