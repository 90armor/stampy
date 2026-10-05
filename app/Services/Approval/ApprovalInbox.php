<?php

namespace App\Services\Approval;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Services\Attendance\LeaveDay;
use App\Support\DisplayDate;
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
     *   already started;
     * - or everyone who could decide step 1 is away (awayReason()) — the
     *   most common reason a request stalls.
     */
    public static function isStuck(Leave $leave, ?CarbonInterface $today = null): bool
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return $leave->start_date->copy()->startOfDay()->lte(self::workdaysAfter($leave, $today, 2))
            || self::workdaysAfter($leave, $leave->created_at->copy()->startOfDay(), 2)->lte($today)
            || self::awayReason($leave, $today) !== null;
    }

    /**
     * Why nobody can decide step 1 today, or null when someone can: every
     * eligible step-1 approver (ApprovalFlow::stepOneApprovers() — the
     * skip-level managers included) is on approved full-day leave today
     * (LeaveDay's rule, so an AM plus a PM leave counts). E.g. "Aye Aye Mon
     * (manager) is on leave until Tue 6 Oct". Null with no approvers at all:
     * that request is waiting on an admin anyway.
     */
    public static function awayReason(Leave $leave, ?CarbonInterface $today = null): ?string
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $approvers = app(ApprovalFlow::class)->stepOneApprovers($leave);

        if ($approvers->isEmpty()) {
            return null;
        }

        $leaves = Leave::query()
            ->whereIn('employee_id', $approvers->pluck('employee.id'))
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('end_date', '>=', $today)
            ->get()
            ->groupBy('employee_id');

        $away = [];

        foreach ($approvers as $approver) {
            $own = $leaves->get($approver->employee->id, collect());

            if (! LeaveDay::on($own, $today)->fullDay) {
                return null;
            }

            $role = $approver->hasRole('admin') ? 'admin' : 'manager';
            $until = self::awayUntil($approver->employee, $own, $today);
            // "Wai Yan Aung (manager) is on leave until Wed 7 Oct; Aye Aye Mon (manager) until Tue 6 Oct"
            $away[] = "{$approver->employee->full_name} ({$role}) ".($away === [] ? 'is on leave until ' : 'until ').DisplayDate::compact($until);
        }

        return implode('; ', $away);
    }

    /**
     * The last day of an absence that covers $today: following full-day
     * leaves extend it while they join up, with the days off and holidays
     * between them bridging (a Friday leave and a Monday one are one
     * absence). Capped at two months.
     *
     * @param  Collection<int, Leave>  $leaves  the employee's approved leaves ending today or later
     */
    private static function awayUntil(Employee $employee, Collection $leaves, CarbonInterface $today): CarbonInterface
    {
        $until = $today->copy();

        for ($day = $today->copy()->addDay(), $step = 0; $step < 62; $day = $day->copy()->addDay(), $step++) {
            if (LeaveDay::on($leaves, $day)->fullDay) {
                $until = $day->copy();
            } elseif (WorkdayCalendar::isWorkday($employee, $day)) {
                break;
            }
        }

        return $until;
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
