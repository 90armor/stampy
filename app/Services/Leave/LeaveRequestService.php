<?php

namespace App\Services\Leave;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveCounting;
use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Exceptions\AffectedLeavesChangedException;
use App\Exceptions\LeaveValidationException;
use App\Exceptions\StaleLeaveDecisionException;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Approval\ApprovalFlow;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Support\DisplayDate;
use App\Support\LeaveDays;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The leave request lifecycle (CLAUDE.md, Phase 3, Approval rules 8–15):
 * submit, approve, reject, cancel. Every mutation runs in a transaction that
 * first locks the employee's row (lockForUpdate), so the read-then-write
 * balance and overlap checks of two concurrent requests for the same employee
 * serialize; approval_steps' unique index is the backstop against a double
 * decision. Authorization goes through LeavePolicy here too — the service
 * doesn't trust its caller.
 *
 * Write first, then rebuild (the EmployeeScheduleAssigner pattern): once a
 * leave becomes approved, or an approved one is cancelled, its dates are
 * rebuilt after the transaction commits. A rebuild failure never undoes the
 * decision: it's returned as rebuildError, logged, and names the command that
 * heals it.
 */
class LeaveRequestService
{
    /** How far back an employee or manager may date a request; an admin may go back to join_date. */
    public const RETROACTIVE_DAYS = 30;

    public function __construct(
        private ApprovalFlow $flow,
        private LeaveDayCounter $counter,
        private LeaveBalance $balances,
        private EntitlementCalculator $calculator,
        private DailySummaryBuilder $builder,
        private EmployeeScheduleAssigner $assigner,
    ) {}

    /**
     * An admin filing for someone else is final on submit: approved, both
     * steps recorded as theirs. Otherwise the request starts at step 1 — or
     * records step 1 as skipped (ApprovalFlow::stepOneSkipReason()) and moves
     * on to step 2, which a sole admin's own request records as self_approved.
     *
     * @return array{leave: Leave, rebuildError: ?string}
     *
     * @throws LeaveValidationException
     */
    public function submit(
        Employee $employee,
        LeaveType $type,
        CarbonInterface $start,
        CarbonInterface $end,
        ?LeaveHalf $half,
        ?string $reason,
        User $actor,
    ): array {
        Gate::forUser($actor)->authorize('create', [Leave::class, $employee]);

        $start = Carbon::instance($start)->startOfDay();
        $end = Carbon::instance($end)->startOfDay();

        $leave = DB::transaction(function () use ($employee, $type, $start, $end, $half, $reason, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $type = $type->fresh();

            $this->validate($employee, $type, $start, $end, $half, $actor);

            $onBehalf = $actor->hasRole('admin') && ! $actor->is($employee->user);

            $leave = new Leave([
                'leave_type_id' => $type->id,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'half' => $half,
                'reason' => $reason,
                'status' => $onBehalf ? LeaveStatus::Approved : LeaveStatus::Pending,
                'current_step' => $onBehalf ? null : ApprovalFlow::MANAGER_STEP,
                'requested_by' => $actor->id,
            ]);
            $leave->employee()->associate($employee);
            $leave->save();

            if ($onBehalf) {
                $this->flow->record($leave, ApprovalFlow::MANAGER_STEP, ApprovalOutcome::Approved, $actor, 'Filed by an admin on the employee\'s behalf.');
                $this->flow->record($leave, ApprovalFlow::ADMIN_STEP, ApprovalOutcome::Approved, $actor, 'Filed by an admin on the employee\'s behalf.');

                return $leave;
            }

            $skipReason = $this->flow->stepOneSkipReason($leave);

            if ($skipReason !== null) {
                $this->flow->record($leave, ApprovalFlow::MANAGER_STEP, ApprovalOutcome::Skipped, null, $skipReason);
                $this->moveToStepTwo($leave);
            }

            return $leave;
        });

        return ['leave' => $leave, 'rebuildError' => $leave->status === LeaveStatus::Approved ? $this->rebuild($leave, 'approval') : null];
    }

    /**
     * What submit() would do, checked by the same rules and nothing written
     * — the request modal's review step (Phase 3e). Throws
     * LeaveValidationException exactly as submit() would. No row lock: it
     * writes nothing, and submit() checks everything again under the lock.
     *
     * @throws LeaveValidationException
     */
    public function preview(
        Employee $employee,
        LeaveType $type,
        CarbonInterface $start,
        CarbonInterface $end,
        ?LeaveHalf $half,
        User $actor,
    ): LeavePreview {
        Gate::forUser($actor)->authorize('create', [Leave::class, $employee]);

        $start = Carbon::instance($start)->startOfDay();
        $end = Carbon::instance($end)->startOfDay();
        $employee = $employee->fresh();
        $type = $type->fresh();

        $cost = $this->validate($employee, $type, $start, $end, $half, $actor);
        $balanceType = $type->deductsFrom ?? $type;

        $balances = [];

        if ($balanceType->hasBalance()) {
            foreach ($cost as $year => $days) {
                $before = $this->balances->for($employee, $balanceType, $year)->available();
                $balances[$year] = ['before' => $before, 'after' => $before - $days];
            }
        }

        return new LeavePreview(
            employee: $employee,
            type: $type,
            balanceType: $balanceType,
            start: CarbonImmutable::instance($start),
            end: CarbonImmutable::instance($end),
            half: $half?->value,
            cost: $cost,
            balances: $balances,
            notCharged: $this->notCharged($employee, $type, $start, $end),
            approvedOnSubmit: $actor->hasRole('admin') && ! $actor->is($employee->user),
        );
    }

    /**
     * The dates in $start..$end a workdays type doesn't charge, with why —
     * the same WorkdayCalendar rule LeaveDayCounter counts by. A
     * calendar-days type charges every date.
     *
     * @return list<array{date: CarbonImmutable, reason: string}>
     */
    private function notCharged(Employee $employee, LeaveType $type, Carbon $start, Carbon $end): array
    {
        if ($type->counts === LeaveCounting::CalendarDays) {
            return [];
        }

        $holidayNames = Holiday::query()
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->pluck('name', 'date')
            ->mapWithKeys(fn (string $name, string $date) => [Carbon::parse($date)->format('Y-m-d') => $name])
            ->all();

        $days = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (WorkdayCalendar::isWorkday($employee, $day, array_map(fn () => true, $holidayNames))) {
                continue;
            }

            $days[] = [
                'date' => CarbonImmutable::instance($day),
                'reason' => $holidayNames[$day->format('Y-m-d')] ?? ($day->isWeekend() ? $day->format('l') : 'Day off'),
            ];
        }

        return $days;
    }

    /**
     * Approves the step the request is waiting at. A manager's step-1 approval
     * moves it to step 2 (or, for a sole admin's own request, self_approved and
     * done); an admin's decision at step 1 completes both steps; step 2's
     * approval completes the request, which then rebuilds its days.
     * $expectedStep is the step the actor saw: if the request has moved on, the
     * decision fails cleanly (StaleLeaveDecisionException).
     *
     * @return array{leave: Leave, rebuildError: ?string}
     */
    public function approve(Leave $leave, User $actor, ?string $note = null, ?int $expectedStep = null): array
    {
        $leave = $this->decide($leave, $actor, $expectedStep, function (Leave $leave) use ($actor, $note) {
            if ($this->flow->decidesBothSteps($actor, $leave)) {
                $this->flow->record($leave, ApprovalFlow::MANAGER_STEP, ApprovalOutcome::Approved, $actor, $note);
                $this->flow->record($leave, ApprovalFlow::ADMIN_STEP, ApprovalOutcome::Approved, $actor, $note);
                $leave->update(['status' => LeaveStatus::Approved, 'current_step' => null]);

                return;
            }

            $this->flow->record($leave, $leave->current_step, ApprovalOutcome::Approved, $actor, $note);

            if ($leave->current_step === ApprovalFlow::MANAGER_STEP) {
                $this->moveToStepTwo($leave);

                return;
            }

            $leave->update(['status' => LeaveStatus::Approved, 'current_step' => null]);
        });

        return ['leave' => $leave, 'rebuildError' => $leave->status === LeaveStatus::Approved ? $this->rebuild($leave, 'approval') : null];
    }

    /**
     * Rejects at the step the request is waiting at, which ends it. Nothing to
     * rebuild: a pending leave never touched attendance.
     */
    public function reject(Leave $leave, User $actor, ?string $note = null, ?int $expectedStep = null): Leave
    {
        return $this->decide($leave, $actor, $expectedStep, function (Leave $leave) use ($actor, $note) {
            $this->flow->record($leave, $leave->current_step, ApprovalOutcome::Rejected, $actor, $note);
            $leave->update(['status' => LeaveStatus::Rejected, 'current_step' => null]);
        });
    }

    /**
     * Cancels a pending or approved leave (LeavePolicy::cancel: the requester
     * before it starts, an admin any time). Its steps stay as they are. A
     * cancelled approved leave rebuilds its days.
     *
     * @return array{leave: Leave, rebuildError: ?string}
     */
    public function cancel(Leave $leave, User $actor): array
    {
        $wasApproved = false;

        $leave = DB::transaction(function () use ($leave, $actor, &$wasApproved) {
            $leave = $this->lock($leave);

            if (! in_array($leave->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)) {
                throw StaleLeaveDecisionException::noLongerPending($leave->status->value);
            }

            Gate::forUser($actor)->authorize('cancel', $leave);

            $wasApproved = $leave->status === LeaveStatus::Approved;
            $this->markCancelled($leave, $actor);

            return $leave;
        });

        return ['leave' => $leave, 'rebuildError' => $wasApproved ? $this->rebuild($leave, 'cancellation') : null];
    }

    /**
     * What deactivating $employee with this last day does to their pending
     * and approved leaves after it — listed for the admin before they confirm
     * (Employees\StatusModal), then applied in the same write
     * (applyDeactivation()). A leave that starts after left_on is cancelled; one
     * that spans it is cut to end on left_on, or cancelled if that leaves it
     * costing nothing. Reactivating doesn't restore them.
     *
     * @return list<array{id: int, type: string, dates: string, status: string, action: string, end: ?string}>
     */
    public function deactivationEffects(Employee $employee, CarbonInterface $leftOn): array
    {
        $leftOn = Carbon::instance($leftOn)->startOfDay();

        return Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->whereDate('end_date', '>', $leftOn->format('Y-m-d'))
            ->with('leaveType')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(function (Leave $leave) use ($employee, $leftOn) {
                $keepsDays = $leave->start_date->lte($leftOn)
                    && array_sum($this->counter->count($employee, $leave->leaveType, $leave->start_date, $leftOn, $leave->isHalfDay())) > 0;

                return [
                    'id' => $leave->id,
                    'type' => $leave->leaveType->name,
                    'dates' => $leave->start_date->eq($leave->end_date) ? DisplayDate::compact($leave->start_date) : DisplayDate::range($leave->start_date, $leave->end_date),
                    'status' => $leave->status->value,
                    'action' => $keepsDays ? 'shorten' : 'cancel',
                    'end' => $keepsDays ? $leftOn->format('Y-m-d') : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Applies deactivationEffects() inside the caller's transaction (the
     * employee row already locked — EmployeeLifecycle::deactivate()). With
     * $expected (what the admin was shown), a different list throws
     * AffectedLeavesChangedException and nothing is written.
     *
     * @param  list<array<string, mixed>>|null  $expected
     */
    public function applyDeactivation(Employee $employee, CarbonInterface $leftOn, ?User $actor, ?array $expected = null): void
    {
        $effects = $this->deactivationEffects($employee, $leftOn);

        if ($expected !== null && self::effectsKey($effects) !== self::effectsKey($expected)) {
            throw new AffectedLeavesChangedException($effects);
        }

        foreach ($effects as $effect) {
            $leave = Leave::query()->lockForUpdate()->findOrFail($effect['id']);

            if ($effect['action'] === 'cancel') {
                $this->markCancelled($leave, $actor);
            } else {
                $leave->update(['end_date' => $effect['end']]);
            }
        }
    }

    /**
     * Two effect lists describe the same change.
     *
     * @param  list<array<string, mixed>>  $effects
     */
    public static function effectsKey(array $effects): string
    {
        return collect($effects)->map(fn (array $effect) => $effect['id'].':'.$effect['action'].':'.$effect['end'])->implode('|');
    }

    /**
     * The shared shape of approve() and reject(): lock, check the request is
     * still where the actor saw it, authorize, apply. A double decision that
     * slips past the check hits approval_steps' unique index, reported the
     * same way.
     */
    private function decide(Leave $leave, User $actor, ?int $expectedStep, callable $apply): Leave
    {
        return DB::transaction(function () use ($leave, $actor, $expectedStep, $apply) {
            $leave = $this->lock($leave);

            if ($leave->status !== LeaveStatus::Pending) {
                throw StaleLeaveDecisionException::noLongerPending($leave->status->value);
            }

            if ($expectedStep !== null && $leave->current_step !== $expectedStep) {
                throw StaleLeaveDecisionException::movedOn();
            }

            Gate::forUser($actor)->authorize('approve', $leave);

            try {
                $apply($leave);
            } catch (UniqueConstraintViolationException) {
                throw StaleLeaveDecisionException::movedOn();
            }

            return $leave;
        });
    }

    /** The employee row first, then the leave — always in that order. */
    private function lock(Leave $leave): Leave
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($leave->employee_id);
        $leave = Leave::query()->lockForUpdate()->findOrFail($leave->id);
        $leave->setRelation('employee', $employee);

        return $leave;
    }

    private function markCancelled(Leave $leave, ?User $actor): void
    {
        $leave->update([
            'status' => LeaveStatus::Cancelled,
            'current_step' => null,
            'cancelled_by' => $actor?->id,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Step 1 is done: wait for an admin, or — when the requester is the only
     * admin — record step 2 as self_approved and approve.
     */
    private function moveToStepTwo(Leave $leave): void
    {
        if ($this->flow->isSelfApprovedAtStepTwo($leave)) {
            $this->flow->record($leave, ApprovalFlow::ADMIN_STEP, ApprovalOutcome::SelfApproved, $this->flow->requester($leave), 'The requester is the only admin.');
            $leave->update(['status' => LeaveStatus::Approved, 'current_step' => null]);

            return;
        }

        $leave->update(['current_step' => ApprovalFlow::ADMIN_STEP]);
    }

    /**
     * Every rule a request must meet, in stages; the first stage with a
     * failure throws (LeaveValidationException), so a later check never runs
     * on input an earlier one rejected.
     *
     * @return array<int, int> the cost in tenths, by year
     */
    private function validate(Employee $employee, LeaveType $type, Carbon $start, Carbon $end, ?LeaveHalf $half, User $actor): array
    {
        $errors = [];
        $add = function (string $field, string $message) use (&$errors) {
            $errors[$field][] = $message;
        };
        $throwIfAny = function () use (&$errors) {
            if ($errors !== []) {
                throw new LeaveValidationException($errors);
            }
        };

        // 1. Type and dates.
        if (! $type->is_active) {
            $add('leave_type_id', "{$type->name} leave is no longer offered.");
        }

        if ($end->lt($start)) {
            $add('end_date', "The leave can't end before it starts.");
            $throwIfAny();
        }

        if ($start->lt($employee->join_date)) {
            $add('start_date', "Leave can't start before {$employee->full_name} joined (".DisplayDate::compact($employee->join_date).').');
        }

        if ($employee->left_on !== null && $end->gt($employee->left_on)) {
            $add('end_date', "Leave can't run past {$employee->full_name}'s last day (".DisplayDate::compact($employee->left_on).').');
        }

        $earliest = today()->subDays(self::RETROACTIVE_DAYS);

        if (! $actor->hasRole('admin') && $start->lt($earliest)) {
            $add('start_date', 'Leave can be requested at most '.self::RETROACTIVE_DAYS.' days back — from '.DisplayDate::compact($earliest).'.');
        }

        $nextYear = today()->year + 1;
        $nextYearGranted = LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $nextYear)->exists();
        $lastYear = $nextYearGranted ? $nextYear : today()->year;

        if ($end->year > $lastYear) {
            $add('end_date', $end->year === $nextYear
                ? "Leave in {$nextYear} can be requested once {$nextYear}'s leave has been granted."
                : 'Leave can be requested up to 31 Dec '.$lastYear.'.');
        }

        // 2. Eligibility: the type's own service requirement, or its target's.
        foreach (array_filter([$type, $type->deductsFrom]) as $serviceType) {
            if ($serviceType->min_service_months) {
                $eligibleOn = $this->calculator->eligibleOn($employee, $serviceType);

                if ($start->lt($eligibleOn)) {
                    $add('start_date', "{$serviceType->name} leave can be used from ".DisplayDate::compact($eligibleOn).'.');
                }
            }
        }

        // 3. Half days.
        if ($half !== null) {
            if (! $type->allows_half_day) {
                $add('half', "{$type->name} leave can't be taken as a half day.");
            } elseif (! $start->eq($end)) {
                $add('half', 'A half day covers a single date.');
            } elseif ($employee->scheduleOn($start)->break_start === null) {
                $add('half', "{$employee->full_name}'s schedule on ".DisplayDate::compact($start).' has no break time set, so the day can\'t be split into halves.');
            }
        }

        $throwIfAny();

        // 4. Cost.
        $cost = $this->counter->count($employee, $type, $start, $end, $half !== null);
        $total = array_sum($cost);
        $unit = $type->counts === LeaveCounting::CalendarDays ? 'calendar days' : 'working days';

        if ($total === 0) {
            $add('end_date', 'These dates cover no working days — every date is a day off or a holiday.');
        } elseif ($type->max_days_per_request !== null && $total > LeaveDays::fromDecimal($type->max_days_per_request)) {
            $add('end_date', "{$type->name} leave is at most ".LeaveDays::format(LeaveDays::fromDecimal($type->max_days_per_request))." {$unit} per request; these dates are ".LeaveDays::format($total).'.');
        }

        $throwIfAny();

        // 5. Overlap, at half-day granularity: an AM and a PM on one date may coexist.
        $existing = Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->whereDate('start_date', '<=', $end->format('Y-m-d'))
            ->whereDate('end_date', '>=', $start->format('Y-m-d'))
            ->with('leaveType')
            ->get();

        // "your" to the requester; the name when an admin files for someone else.
        $whose = $actor->employee?->is($employee) ? 'your' : "{$employee->full_name}'s";

        foreach ($existing as $other) {
            $otherHalves = $other->half !== null && $half !== null && $other->half !== $half;

            if (! $otherHalves) {
                $add('start_date', "These dates overlap {$whose} {$other->status->value} {$other->leaveType->name} leave ("
                    .($other->start_date->eq($other->end_date) ? DisplayDate::compact($other->start_date) : DisplayDate::range($other->start_date, $other->end_date))
                    .($other->half !== null ? ', '.$other->half->label() : '').').');
            }
        }

        $throwIfAny();

        // 6. Balance, per year of the cost, against the type that holds it.
        $balanceType = $type->deductsFrom ?? $type;

        if ($balanceType->hasBalance()) {
            foreach ($cost as $year => $days) {
                $available = $this->balances->for($employee, $balanceType, $year)->available();

                if ($days > $available) {
                    $add('leave', "Not enough {$balanceType->name} leave for {$year}: this needs ".LeaveDays::format($days)
                        .', '.LeaveDays::format(max(0, $available)).' available ('.LeaveDays::format($days - max(0, $available)).' short).');
                }
            }
        }

        $throwIfAny();

        return $cost;
    }

    /**
     * The days an approved (or no-longer-approved) leave touches, rebuilt:
     * max(start, join_date) to min(end, today, left_on). A leave entirely in
     * the future rebuilds nothing.
     */
    private function rebuild(Leave $leave, string $action): ?string
    {
        $employee = $leave->employee;
        $from = $leave->start_date->copy()->max($employee->join_date);
        $to = $leave->end_date->copy()->min(today());

        if ($employee->left_on !== null) {
            $to = $to->min($employee->left_on);
        }

        if ($from->gt($to)) {
            return null;
        }

        try {
            $this->builder->rebuildBetween($employee, $from, $to);

            return null;
        } catch (Throwable $e) {
            $message = $this->assigner->rebuildRecoveryMessage($from, "--employee={$employee->employee_code}", $to);

            Log::error("Leave #{$leave->id} {$action} for {$employee->employee_code}: rebuild failed partway ({$e->getMessage()}). {$message}");

            return $message;
        }
    }
}
