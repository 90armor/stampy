<?php

namespace App\Services\Leave;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveCounting;
use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Exceptions\LeaveValidationException;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Approval\ApprovalFlow;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Support\DisplayDate;
use App\Support\LeaveDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

        foreach ($existing as $other) {
            $otherHalves = $other->half !== null && $half !== null && $other->half !== $half;

            if (! $otherHalves) {
                $add('start_date', "These dates overlap {$employee->full_name}'s {$other->status->value} {$other->leaveType->name} leave ("
                    .($other->start_date->eq($other->end_date) ? DisplayDate::compact($other->start_date) : DisplayDate::range($other->start_date, $other->end_date))
                    .($other->half !== null ? ', '.$other->half->label() : '').').');
            }
        }

        $throwIfAny();

        // 6. Balance, per year of the cost, against the type that holds it.
        $balanceType = $type->deductsFrom ?? $type;

        if ($balanceType->days_per_year !== null) {
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
