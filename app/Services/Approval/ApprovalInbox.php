<?php

namespace App\Services\Approval;

use App\Contracts\Approvable;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\OvertimeRequest;
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
 * Every group holds leave and overtime requests together (Phase 4c), sorted
 * by the date each is about (Approvable::approvalDate()), soonest first.
 * Never the user's own requests. Nothing here branches on the type: the
 * dates come through Approvable, and each item is decided through its own
 * type's service and policy.
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
     * @return array{stepOne: Collection<int, Approvable>, stepTwo: Collection<int, Approvable>, overrides: Collection<int, Approvable>}
     */
    public static function for(User $user): array
    {
        $ownEmployeeId = $user->employee?->id;
        $managed = $user->hasAnyRole(['manager', 'admin']) && $user->employee !== null
            ? $user->employee->subordinateIds()
            : [];

        // Both types' pending requests at $step (only $employeeIds', when given), soonest first.
        $pending = function (int $step, ?array $employeeIds = null) use ($ownEmployeeId): Collection {
            $query = fn ($query) => $query
                ->where('current_step', $step)
                ->when($ownEmployeeId !== null, fn ($query) => $query->where('employee_id', '!=', $ownEmployeeId))
                ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds));

            return Leave::query()->where('status', LeaveStatus::Pending->value)->tap($query)->with(['employee', 'leaveType'])->get()
                ->concat(OvertimeRequest::query()->where('status', OvertimeStatus::Pending->value)->tap($query)->with('employee')->get())
                ->sortBy([
                    fn (Approvable $a, Approvable $b) => $a->approvalDate() <=> $b->approvalDate(),
                    fn (Approvable $a, Approvable $b) => $a->created_at <=> $b->created_at,
                ])
                ->values();
        };

        $stepOne = $managed === [] ? collect() : $pending(ApprovalFlow::MANAGER_STEP, $managed);

        if (! $user->hasRole('admin')) {
            return ['stepOne' => $stepOne, 'stepTwo' => collect(), 'overrides' => collect()];
        }

        return [
            'stepOne' => $stepOne,
            'stepTwo' => $pending(ApprovalFlow::ADMIN_STEP),
            // sortBy is stable, so the date order holds within each half.
            'overrides' => $pending(ApprovalFlow::MANAGER_STEP)
                ->reject(fn (Approvable $item) => $stepOne->contains(fn (Approvable $mine) => $mine->is($item)))
                ->sortBy(fn (Approvable $item) => self::isStuck($item) ? 0 : 1)
                ->values(),
        ];
    }

    public static function count(User $user): int
    {
        $inbox = self::for($user);

        return $inbox['stepOne']->count()
            + $inbox['stepTwo']->count()
            + $inbox['overrides']->filter(fn (Approvable $item) => self::isStuck($item))->count();
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
     *   already started (Approvable::approvalStartsOn(): an overtime claim has
     *   no start left, so only the other two tests apply to it);
     * - or everyone who could decide step 1 is away (awayReason()) and none
     *   of them is back before the day the start rule above would make it
     *   stuck (stuckDeadline()) — the most common reason a request stalls;
     *   an absence that ends in time doesn't count.
     */
    public static function isStuck(Approvable $item, ?CarbonInterface $today = null): bool
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $deadline = self::stuckDeadline($item);

        if ($deadline !== null && $deadline->lte($today)) {
            return true;
        }

        if (self::workdaysAfter($item, $item->created_at->copy()->startOfDay(), 2)->lte($today)) {
            return true;
        }

        // Away only blocks the request if nobody who can decide it is back
        // before the deadline: a manager back two weeks before it starts
        // decides it then, and an admin's override would pre-empt them. With
        // no start (a claim), being away blocks it until they're back at all.
        $away = self::away($item, $today);

        return $away !== null && ($deadline === null || $away['backOn']->gte($deadline));
    }

    /**
     * Who is away, when every eligible step-1 approver (ApprovalFlow::
     * stepOneApprovers() — the skip-level managers included) is on approved
     * full-day leave today (LeaveDay's rule, so an AM plus a PM leave
     * counts) — e.g. "Aye Aye Mon (manager) is on leave until Tue 6 Oct" —
     * whether or not that makes the request stuck (isStuck()). Null when
     * someone can decide today, and with no approvers at all: that request
     * is waiting on an admin anyway.
     */
    public static function awayReason(Approvable $item, ?CarbonInterface $today = null): ?string
    {
        return self::away($item, ($today ?? today())->copy()->startOfDay())['reason'] ?? null;
    }

    /**
     * The step-1 approvers' absence covering $today: the line to show, and
     * backOn — the first working day any of them is back.
     *
     * @return array{reason: string, backOn: CarbonInterface}|null
     */
    private static function away(Approvable $item, CarbonInterface $today): ?array
    {
        $approvers = app(ApprovalFlow::class)->stepOneApprovers($item);

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
        $backOn = null;

        foreach ($approvers as $approver) {
            $own = $leaves->get($approver->employee->id, collect());

            if (! LeaveDay::on($own, $today)->fullDay) {
                return null;
            }

            $role = $approver->hasRole('admin') ? 'admin' : 'manager';
            [$until, $back] = self::absence($approver->employee, $own, $today);
            $backOn = $backOn === null || $back->lt($backOn) ? $back : $backOn;
            // "Wai Yan Aung (manager) is on leave until Wed 7 Oct; Aye Aye Mon (manager) until Tue 6 Oct"
            $away[] = "{$approver->employee->full_name} ({$role}) ".($away === [] ? 'is on leave until ' : 'until ').DisplayDate::compact($until);
        }

        return ['reason' => implode('; ', $away), 'backOn' => $backOn];
    }

    /**
     * An absence covering $today: its last day, and the first working day
     * after it — when they're back. Following full-day leaves extend it
     * while they join up, with the days off and holidays between them
     * bridging (a Friday leave and a Monday one are one absence). Capped at
     * two months.
     *
     * @param  Collection<int, Leave>  $leaves  the employee's approved leaves ending today or later
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private static function absence(Employee $employee, Collection $leaves, CarbonInterface $today): array
    {
        $until = $today->copy();

        for ($day = $today->copy()->addDay(), $step = 0; $step < 62; $day = $day->copy()->addDay(), $step++) {
            if (LeaveDay::on($leaves, $day)->fullDay) {
                $until = $day->copy();
            } elseif (WorkdayCalendar::isWorkday($employee, $day)) {
                return [$until, $day];
            }
        }

        return [$until, $until->copy()->addDay()];
    }

    /**
     * The day a request becomes stuck by its start: the second of the
     * requester's working days before it — from then on it starts within two
     * working days (on a Friday, a Tuesday start). A request already under
     * way is past it. Capped at a month. Null with no start (a claim).
     */
    private static function stuckDeadline(Approvable $item): ?CarbonInterface
    {
        $start = $item->approvalStartsOn();

        if ($start === null) {
            return null;
        }

        $day = $start->copy()->startOfDay();

        for ($found = 0, $step = 0; $found < 2 && $step < 31; $step++) {
            $day = $day->subDay();
            $found += WorkdayCalendar::isWorkday($item->approvalSubject(), $day) ? 1 : 0;
        }

        return $day;
    }

    /**
     * The $count-th working day of the requester after $from. Capped at a
     * month, so a schedule with no working days can't loop.
     */
    private static function workdaysAfter(Approvable $item, CarbonInterface $from, int $count): CarbonInterface
    {
        $day = $from->copy();

        for ($found = 0, $step = 0; $found < $count && $step < 31; $step++) {
            $day = $day->addDay();
            $found += WorkdayCalendar::isWorkday($item->approvalSubject(), $day) ? 1 : 0;
        }

        return $day;
    }
}
