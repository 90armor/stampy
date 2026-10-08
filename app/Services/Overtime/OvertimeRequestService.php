<?php

namespace App\Services\Overtime;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Exceptions\OvertimeValidationException;
use App\Exceptions\StaleDecisionException;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalFlow;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Services\Attendance\ExpectedWindow;
use App\Services\Attendance\LeaveDay;
use App\Services\Attendance\OvertimeCalculator;
use App\Services\Attendance\OvertimeCredit;
use App\Support\AttendanceTime;
use App\Support\DisplayDate;
use App\Support\Duration;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The overtime request lifecycle (CLAUDE.md, Phase 4, rules 1, 4–7, 12,
 * 17–19) — LeaveRequestService's twin, on the same mechanisms: every
 * mutation locks the employee's row first, so two submits for the same
 * employee serialize their read-then-write checks; refusals are an
 * OvertimeValidationException keyed by field; authorization goes through
 * OvertimePolicy here too; the steps go through ApprovalFlow; and write
 * first, then rebuild — a rebuild failure never undoes the decision, it's
 * returned as rebuildError, logged, and names the command that heals it.
 */
class OvertimeRequestService
{
    /**
     * How far ahead a planned request may be dated (Claude's choice, Phase
     * 4c): far enough to plan a month, near enough that the schedule and the
     * holidays it will be measured against are known.
     */
    public const PLANNED_DAYS_AHEAD = 31;

    public function __construct(
        private ApprovalFlow $flow,
        private OvertimeCalculator $calculator,
        private DailySummaryBuilder $builder,
        private EmployeeScheduleAssigner $assigner,
    ) {}

    /**
     * Files a request. Its kind is decided here: planned when it starts after
     * now, otherwise a claim. An admin filing for someone else is final on
     * submit — approved, both steps theirs — and a past or today's date is
     * rebuilt (and time off in lieu reconciled) at once. Otherwise it starts
     * at step 1, or records the skip and moves on, exactly as leave does.
     *
     * @return array{request: OvertimeRequest, rebuildError: ?string}
     *
     * @throws OvertimeValidationException
     */
    public function submit(
        Employee $employee,
        CarbonInterface $date,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        OvertimeCompensation $compensation,
        ?string $reason,
        User $actor,
        ?string $overrideReason = null,
    ): array {
        Gate::forUser($actor)->authorize('create', [OvertimeRequest::class, $employee]);

        $date = Carbon::instance($date)->startOfDay();
        $startsAt = Carbon::instance($startsAt);
        $endsAt = Carbon::instance($endsAt);

        $request = DB::transaction(function () use ($employee, $date, $startsAt, $endsAt, $compensation, $reason, $actor, $overrideReason) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            $override = $this->validate($employee, $date, $startsAt, $endsAt, $compensation, $actor, $overrideReason)['override'];
            $onBehalf = $actor->hasRole('admin') && ! $actor->is($employee->user);

            $request = new OvertimeRequest([
                'date' => $date->format('Y-m-d'),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'kind' => $startsAt->gt(now()) ? OvertimeKind::Planned : OvertimeKind::Claim,
                'compensation' => $compensation,
                'reason' => $reason,
                'status' => $onBehalf ? OvertimeStatus::Approved : OvertimeStatus::Pending,
                'current_step' => $onBehalf ? null : ApprovalFlow::MANAGER_STEP,
                'requested_by' => $actor->id,
                'limit_override_reason' => $override,
            ]);
            $request->employee()->associate($employee);
            $request->save();

            if ($onBehalf) {
                $this->flow->record($request, ApprovalFlow::MANAGER_STEP, ApprovalOutcome::Approved, $actor, 'Filed by an admin on the employee\'s behalf.');
                $this->flow->record($request, ApprovalFlow::ADMIN_STEP, ApprovalOutcome::Approved, $actor, 'Filed by an admin on the employee\'s behalf.');

                return $request;
            }

            $skipReason = $this->flow->stepOneSkipReason($request);

            if ($skipReason !== null) {
                $this->flow->record($request, ApprovalFlow::MANAGER_STEP, ApprovalOutcome::Skipped, null, $skipReason);
                $this->moveToStepTwo($request);
            }

            return $request;
        });

        return ['request' => $request, 'rebuildError' => $request->status === OvertimeStatus::Approved ? $this->rebuild($request, 'approval') : null];
    }

    /**
     * What submit() would file, checked by the same rules and nothing written
     * — the request modal's review (Phase 4d). Throws exactly as submit()
     * would, except that an admin over a daily limit without an override
     * reason gets the problems back (limitProblems) to give one. No row lock:
     * it writes nothing, and submit() checks everything again under the lock.
     *
     * @throws OvertimeValidationException
     */
    public function preview(
        Employee $employee,
        CarbonInterface $date,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        OvertimeCompensation $compensation,
        User $actor,
        ?string $overrideReason = null,
    ): OvertimePreview {
        Gate::forUser($actor)->authorize('create', [OvertimeRequest::class, $employee]);

        $employee = $employee->fresh();
        $date = Carbon::instance($date)->startOfDay();
        $startsAt = Carbon::instance($startsAt);
        $endsAt = Carbon::instance($endsAt);

        $checked = $this->validate($employee, $date, $startsAt, $endsAt, $compensation, $actor, $overrideReason, deferAdminLimits: true);
        $day = $checked['day'];
        $window = new OvertimeRequest(['date' => $date, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
        $window->setRelation('employee', $employee);
        $settings = OvertimeSettings::current();
        $immutable = fn (int $timestamp) => CarbonImmutable::createFromTimestamp($timestamp, $date->getTimezone());

        $credit = $this->calculator->calculate($window, $startsAt, $endsAt, $day['schedule'], $date, $day['isScheduledWorkday'], $day['isHoliday'], LeaveDay::none(), $settings);
        $counted = array_map(fn (array $span) => [$immutable($span[0]), $immutable($span[1])],
            $this->calculator->countedSpans($window, $startsAt, $endsAt, $day['schedule'], $date, $day['isScheduledWorkday'], $day['isHoliday']));
        $normalFrom = max($startsAt->getTimestamp(), $day['window']->start->getTimestamp());
        $normalTo = min($endsAt->getTimestamp(), $day['window']->end->getTimestamp());
        $isClaim = ! $startsAt->gt(now());

        $row = $isClaim ? $employee->dailyAttendances()->whereDate('work_date', $date->format('Y-m-d'))->first() : null;
        $punchCredit = $row !== null ? $this->creditFromPunches($window, $row->first_in, $row->last_out, $day) : null;
        $toil = null;

        if ($compensation === OvertimeCompensation::TimeOff) {
            $adds = intdiv($credit->total() * $settings->toil_ratio_percent, 100);
            $saved = app(TimeOffInLieuReconciler::class)->remainderMinutes($employee) ?? 0;
            $toil = ['adds' => $adds, 'saved' => $saved, 'completes' => intdiv($saved + $adds, $settings->toil_block_minutes)];
        }

        $onBehalf = $actor->hasRole('admin') && ! $actor->is($employee->user);

        return new OvertimePreview(
            employee: $employee,
            date: CarbonImmutable::instance($date),
            startsAt: CarbonImmutable::instance($startsAt),
            endsAt: CarbonImmutable::instance($endsAt),
            kind: $isClaim ? OvertimeKind::Claim : OvertimeKind::Planned,
            compensation: $compensation,
            credit: $credit,
            counted: $counted,
            normal: $day['hasHours'] && $normalTo > $normalFrom ? [$immutable($normalFrom), $immutable($normalTo)] : null,
            limitProblems: $checked['override'] === null ? $checked['limitProblems'] : [],
            overrideReason: $checked['override'],
            punches: $row !== null ? ['in' => $row->first_in?->toImmutable(), 'out' => $row->last_out?->toImmutable()] : null,
            punchCredit: $punchCredit,
            toil: $toil,
            approvedOnSubmit: $onBehalf,
            reviewers: $onBehalf ? '' : $this->reviewers($employee, ! $isClaim),
        );
    }

    /**
     * The minutes an approved window can credit — all of it worked
     * (OvertimeCalculator on the window itself): "1h 20m credited of 2h
     * approved".
     */
    public function approvedMinutes(OvertimeRequest $request): int
    {
        return $this->creditableMinutes($this->day($request->employee, $request->date->copy()), $request->starts_at, $request->ends_at);
    }

    /**
     * What the day's punches would credit if the request were approved — for
     * an approver deciding a claim or a past planned request (Phase 4d):
     * "Punched 8:02 AM – 7:05 PM → 2h would be credited (workday)". Null when
     * the day has no row yet. The builder's own calculation, on its paired
     * punches.
     *
     * @return array{in: ?Carbon, out: ?Carbon, credit: OvertimeCredit}|null
     */
    public function wouldCredit(OvertimeRequest $request): ?array
    {
        $row = $request->employee->dailyAttendances()->whereDate('work_date', $request->date->format('Y-m-d'))->first();

        if ($row === null) {
            return null;
        }

        return [
            'in' => $row->first_in,
            'out' => $row->last_out,
            'credit' => $this->creditFromPunches($request, $row->first_in, $row->last_out, $this->day($request->employee, $request->date->copy()))
                ?? new OvertimeCredit($request->id),
        ];
    }

    /**
     * What a window would credit with the day's real punches — the builder's
     * own calculation (approved leave included) — or null without both.
     *
     * @param  array<string, mixed>  $day  day()
     */
    private function creditFromPunches(OvertimeRequest $window, ?CarbonInterface $firstIn, ?CarbonInterface $lastOut, array $day): ?OvertimeCredit
    {
        if ($firstIn === null || $lastOut === null) {
            return null;
        }

        $employee = $window->employee ?? Employee::find($window->employee_id);
        $leaveDay = $employee !== null ? LeaveDay::on($this->leavesOn($employee, $day['date']->copy(), [LeaveStatus::Approved]), $day['date']) : LeaveDay::none();

        return $this->calculator->calculate($window, $firstIn, $lastOut, $day['schedule'], $day['date'], $day['isScheduledWorkday'], $day['isHoliday'], $leaveDay, OvertimeSettings::current());
    }

    /**
     * The limit problems a request has now — what the decision completing it
     * would re-check (approve()). For the approve dialog: a manager sees them
     * as a notice, an admin at the final step is asked for an override reason.
     *
     * @return list<string>
     */
    public function currentLimitProblems(OvertimeRequest $request): array
    {
        $day = $this->day($request->employee, $request->date->copy());

        return $this->limitProblems($day, $this->creditableMinutes($day, $request->starts_at, $request->ends_at));
    }

    /**
     * Who reviews a new request of $employee's, in words — and, for a planned
     * one, that it can be cancelled until it starts (a claim has started, so
     * only an admin can cancel it).
     */
    private function reviewers(Employee $employee, bool $planned): string
    {
        $probe = new OvertimeRequest;
        $probe->setRelation('employee', $employee);
        $skip = $this->flow->stepOneSkipReason($probe);

        if ($skip !== null) {
            return "It goes straight to an admin: {$skip}";
        }

        $names = $this->flow->stepOneApprovers($probe)->map(fn (User $user) => $user->employee?->full_name ?? $user->name)->implode(' or ');

        return "{$names} reviews it first, then an admin.".($planned ? ' You can cancel it until it starts.' : '');
    }

    /**
     * Approves the step the request is waiting at, as leave does: a manager's
     * step 1 moves it to step 2 (or self_approved for a sole admin's own); an
     * admin at step 1 completes both; step 2 completes it. $expectedStep is
     * the step the actor saw (StaleDecisionException if it moved on).
     *
     * - $compensation: the approver may change pay ↔ time off (rule 4); the
     *   step's note says so ("Compensation changed: pay → time off"), the
     *   actor's note after it. Approving a time_off request needs a TOIL type.
     * - The decision that completes the request re-checks the daily limits
     *   (settings or leave may have changed since it was filed): over a
     *   limit, it needs an admin's override reason — $overrideReason, or the
     *   one already on the request. A manager's step 1 isn't re-checked; the
     *   admin sees the problem at step 2. (A sole admin's own request,
     *   self-approved after step 1, was checked when they filed it.)
     * - Once approved, a past or today's date is rebuilt and time off in lieu
     *   reconciled; a future one is picked up by the regular builds on the day.
     *
     * @return array{request: OvertimeRequest, rebuildError: ?string}
     *
     * @throws OvertimeValidationException|StaleDecisionException
     */
    public function approve(
        OvertimeRequest $request,
        User $actor,
        ?string $note = null,
        ?OvertimeCompensation $compensation = null,
        ?int $expectedStep = null,
        ?string $overrideReason = null,
    ): array {
        $request = $this->decide($request, $actor, $expectedStep, function (OvertimeRequest $request) use ($actor, $note, $compensation, $overrideReason) {
            $errors = new ValidationErrors;
            $compensation ??= $request->compensation;
            $final = $this->flow->decidesBothSteps($actor, $request) || $request->current_step === ApprovalFlow::ADMIN_STEP;

            $this->checkCompensation($errors, $compensation);
            $override = null;

            if ($final) {
                $day = $this->day($request->employee, $request->date->copy());
                $creditable = $this->creditableMinutes($day, $request->starts_at, $request->ends_at);
                $override = $this->checkLimits($errors, $day, $creditable, $actor, $overrideReason ?? $request->limit_override_reason);
            }

            $errors->throwIfAny();

            if ($compensation !== $request->compensation) {
                $changed = 'Compensation changed: '.strtolower($request->compensation->label()).' → '.strtolower($compensation->label());
                $note = filled($note) ? "{$changed}. {$note}" : $changed;
                $request->update(['compensation' => $compensation]);
            }

            if ($override !== null) {
                $request->update(['limit_override_reason' => $override]);
            }

            if ($this->flow->decidesBothSteps($actor, $request)) {
                $this->flow->record($request, ApprovalFlow::MANAGER_STEP, ApprovalOutcome::Approved, $actor, $note);
                $this->flow->record($request, ApprovalFlow::ADMIN_STEP, ApprovalOutcome::Approved, $actor, $note);
                $request->update(['status' => OvertimeStatus::Approved, 'current_step' => null]);

                return;
            }

            $this->flow->record($request, $request->current_step, ApprovalOutcome::Approved, $actor, $note);

            if ($request->current_step === ApprovalFlow::MANAGER_STEP) {
                $this->moveToStepTwo($request);

                return;
            }

            $request->update(['status' => OvertimeStatus::Approved, 'current_step' => null]);
        });

        return ['request' => $request, 'rebuildError' => $request->status === OvertimeStatus::Approved ? $this->rebuild($request, 'approval') : null];
    }

    /**
     * Rejects at the step the request is waiting at, which ends it. A note is
     * required — the employee is owed the reason. Nothing to rebuild: a
     * pending request never touched attendance.
     *
     * @throws OvertimeValidationException|StaleDecisionException
     */
    public function reject(OvertimeRequest $request, User $actor, ?string $note, ?int $expectedStep = null): OvertimeRequest
    {
        if (trim((string) $note) === '') {
            throw new OvertimeValidationException(['note' => ['Say why the overtime is rejected.']]);
        }

        return $this->decide($request, $actor, $expectedStep, function (OvertimeRequest $request) use ($actor, $note) {
            $this->flow->record($request, $request->current_step, ApprovalOutcome::Rejected, $actor, trim($note));
            $request->update(['status' => OvertimeStatus::Rejected, 'current_step' => null]);
        });
    }

    /**
     * Cancels a pending or approved request (OvertimePolicy::cancel: the
     * requester before it starts, an admin any time). Its steps stay as they
     * are. A cancelled approved request rebuilds its date if it's past or
     * today, which takes its time off in lieu back.
     *
     * There is no edit: an approved window that turns out wrong (they stayed
     * until 21:00, not 20:00) is corrected by an admin cancelling it and
     * filing the right one, which is final on submit — the history shows
     * both, and a cancelled request doesn't hold its date.
     *
     * @return array{request: OvertimeRequest, rebuildError: ?string}
     */
    public function cancel(OvertimeRequest $request, User $actor): array
    {
        $wasApproved = false;

        $request = DB::transaction(function () use ($request, $actor, &$wasApproved) {
            $request = $this->lock($request);

            if (! in_array($request->status, [OvertimeStatus::Pending, OvertimeStatus::Approved], true)) {
                throw StaleDecisionException::noLongerPending($request->status->value);
            }

            Gate::forUser($actor)->authorize('cancel', $request);

            $wasApproved = $request->status === OvertimeStatus::Approved;
            $this->markCancelled($request, $actor);

            return $request;
        });

        return ['request' => $request, 'rebuildError' => $wasApproved ? $this->rebuild($request, 'cancellation') : null];
    }

    /**
     * The employee's pending and approved requests dated after $leftOn — all
     * cancelled by a deactivation (the work can't happen), listed for the
     * admin first (Employees\StatusModal, through EmployeeLifecycle) in the
     * shape LeaveRequestService::deactivationEffects() uses, kind 'overtime'.
     *
     * @return list<array{kind: string, id: int, type: string, dates: string, status: string, action: string, end: null}>
     */
    public function deactivationEffects(Employee $employee, CarbonInterface $leftOn): array
    {
        return OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [OvertimeStatus::Pending->value, OvertimeStatus::Approved->value])
            ->whereDate('date', '>', $leftOn->format('Y-m-d'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (OvertimeRequest $request) => [
                'kind' => 'overtime',
                'id' => $request->id,
                'type' => 'Overtime',
                'dates' => DisplayDate::compact($request->date).', '.AttendanceTime::format($request->starts_at).' – '.AttendanceTime::format($request->ends_at),
                'status' => $request->status->value,
                'action' => 'cancel',
                'end' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * Cancels them, inside the caller's transaction (the employee row
     * already locked — EmployeeLifecycle::deactivate()). The rebuild that
     * follows the deactivation removes their days, which takes any time off
     * in lieu they credited back: the work never happened.
     */
    public function applyDeactivation(Employee $employee, CarbonInterface $leftOn, ?User $actor): void
    {
        foreach ($this->deactivationEffects($employee, $leftOn) as $effect) {
            $this->markCancelled(OvertimeRequest::query()->lockForUpdate()->findOrFail($effect['id']), $actor);
        }
    }

    /**
     * The shared shape of approve() and reject(), as for leave: lock, check
     * the request is still where the actor saw it, authorize, apply. A double
     * decision that slips past the check hits approval_steps' unique index,
     * reported the same way.
     */
    private function decide(OvertimeRequest $request, User $actor, ?int $expectedStep, callable $apply): OvertimeRequest
    {
        return DB::transaction(function () use ($request, $actor, $expectedStep, $apply) {
            $request = $this->lock($request);

            if ($request->status !== OvertimeStatus::Pending) {
                throw StaleDecisionException::noLongerPending($request->status->value);
            }

            if ($expectedStep !== null && $request->current_step !== $expectedStep) {
                throw StaleDecisionException::movedOn();
            }

            Gate::forUser($actor)->authorize('approve', $request);

            try {
                $apply($request);
            } catch (UniqueConstraintViolationException) {
                throw StaleDecisionException::movedOn();
            }

            return $request;
        });
    }

    /** The employee row first, then the request — always in that order. */
    private function lock(OvertimeRequest $request): OvertimeRequest
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($request->employee_id);
        $request = OvertimeRequest::query()->lockForUpdate()->findOrFail($request->id);
        $request->setRelation('employee', $employee);

        return $request;
    }

    private function markCancelled(OvertimeRequest $request, ?User $actor): void
    {
        $request->update([
            'status' => OvertimeStatus::Cancelled,
            'current_step' => null,
            'cancelled_by' => $actor?->id,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Every rule a new request must meet, in stages; the first stage with a
     * failure throws (OvertimeValidationException).
     *
     * Returns the override reason to store (only when a limit is exceeded and
     * an admin gave one), the day, the creditable minutes and the limit
     * problems (for a preview's review).
     *
     * @return array{override: ?string, day: array<string, mixed>, creditable: int, limitProblems: list<string>}
     *
     * @throws OvertimeValidationException
     */
    private function validate(
        Employee $employee,
        Carbon $date,
        Carbon $startsAt,
        Carbon $endsAt,
        OvertimeCompensation $compensation,
        User $actor,
        ?string $overrideReason,
        bool $deferAdminLimits = false,
    ): array {
        $errors = new ValidationErrors;

        // 1. Employment.
        if (! $employee->isActiveOn($date)) {
            $errors->add('date', $employee->left_on !== null && $date->gt($employee->left_on)
                ? "Overtime can't be after {$employee->full_name}'s last day (".DisplayDate::compact($employee->left_on).').'
                : "Overtime can't be before {$employee->full_name} joined (".DisplayDate::compact($employee->join_date).').');
        }

        $errors->throwIfAny();

        // 2. The window's shape, and how far back or ahead it may be.
        if ($endsAt->lte($startsAt)) {
            $errors->add('ends_at', 'The overtime must end after it starts.');
        } elseif ($endsAt->gt($startsAt->copy()->addHours(OvertimeRequest::MAX_WINDOW_HOURS))) {
            $errors->add('ends_at', 'An overtime window can\'t be longer than '.OvertimeRequest::MAX_WINDOW_HOURS.' hours.');
        }

        if (! $startsAt->isSameDay($date)) {
            $errors->add('starts_at', 'The overtime must start on its date.');
        }

        $settings = OvertimeSettings::current();

        if ($startsAt->gt(now())) {
            $latest = today()->addDays(self::PLANNED_DAYS_AHEAD);

            if ($date->gt($latest)) {
                $errors->add('date', 'Overtime can be planned at most '.self::PLANNED_DAYS_AHEAD.' days ahead — up to '.DisplayDate::compact($latest).'.');
            }
        } elseif (! $actor->hasRole('admin')) {
            $earliest = today()->subDays($settings->claim_window_days);

            if ($date->lt($earliest)) {
                $errors->add('date', "Overtime can be claimed at most {$settings->claim_window_days} days back — from ".DisplayDate::compact($earliest).'.');
            }
        }

        $errors->throwIfAny();

        // 3. Something in the window must count as overtime — the builder's own
        // definition (OvertimeCalculator), as if all of it were worked.
        $day = $this->day($employee, $date);
        $creditable = $this->creditableMinutes($day, $startsAt, $endsAt);

        if ($creditable === 0) {
            $errors->add('starts_at', $day['hasHours']
                ? 'This is within normal working hours ('.AttendanceTime::format($day['window']->start).' – '.AttendanceTime::format($day['window']->end).').'
                : 'None of this window counts as overtime — it\'s all break time.');
        }

        $errors->throwIfAny();

        // 4. Full-day leave, approved or pending: a pending one may yet be
        // approved, and the builder would then credit nothing.
        $leave = $this->fullDayLeave($employee, $date);

        if ($leave !== null) {
            $whose = $actor->employee?->is($employee) ? 'your' : "{$employee->full_name}'s";
            $errors->add('date', DisplayDate::compact($date)." is covered by {$whose} {$leave->status->value} {$leave->leaveType->name} leave ("
                .($leave->start_date->eq($leave->end_date) ? DisplayDate::compact($leave->start_date) : DisplayDate::range($leave->start_date, $leave->end_date)).').');
        }

        // 5. One active request per date.
        $active = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date->format('Y-m-d'))
            ->whereIn('status', [OvertimeStatus::Pending->value, OvertimeStatus::Approved->value])
            ->first();

        if ($active !== null) {
            $errors->add('date', 'There is already '.($active->status === OvertimeStatus::Pending ? 'a pending' : 'an approved').' overtime request on '.DisplayDate::compact($date)
                .' ('.AttendanceTime::format($active->starts_at).' – '.AttendanceTime::format($active->ends_at).'). Cancel it first to file a different one.');
        }

        $errors->throwIfAny();

        // 6. The daily limits.
        $override = $this->checkLimits($errors, $day, $creditable, $actor, $overrideReason, $deferAdminLimits);

        // 7. Time off needs somewhere to credit it.
        $this->checkCompensation($errors, $compensation);

        $errors->throwIfAny();

        return ['override' => $override, 'day' => $day, 'creditable' => $creditable, 'limitProblems' => $this->limitProblems($day, $creditable)];
    }

    /**
     * The daily-limit problems with a window's creditable minutes, in words
     * (empty when it's within them) — rule 6, see checkLimits().
     *
     * @param  array<string, mixed>  $day  day()
     * @return list<string>
     */
    private function limitProblems(array $day, int $creditable): array
    {
        $settings = OvertimeSettings::current();
        $problems = [];

        if ($day['hasHours'] && $creditable > $settings->max_overtime_minutes_per_day) {
            $problems[] = 'This is '.Duration::format($creditable).' of overtime; the limit is '.Duration::format($settings->max_overtime_minutes_per_day).' a day.';
        }

        $total = $day['scheduledMinutes'] + $creditable;

        if ($total > $settings->max_work_minutes_per_day) {
            $problems[] = ($day['scheduledMinutes'] > 0
                ? 'With '.Duration::format($day['scheduledMinutes']).' of scheduled work this makes '.Duration::format($total)
                : 'This is '.Duration::format($total).' of work').'; the limit is '.Duration::format($settings->max_work_minutes_per_day).' a day.';
        }

        return $problems;
    }

    /**
     * The limits (rule 6) on a window's creditable minutes: on a day with
     * scheduled hours, at most max_overtime_minutes_per_day of overtime, and
     * the scheduled minutes (less the half on leave) plus the overtime at
     * most max_work_minutes_per_day. On a day with none — a non-workday or a
     * holiday — only the total applies (Claude's decision, open to the
     * owner's veto: the 2-hour cap is about extending a normal day, and would
     * forbid an ordinary 8-hour Saturday). Over a limit, only an admin's
     * override reason lets it through.
     *
     * $deferForAdmin (a preview): an admin over a limit without a reason
     * isn't refused yet — the review shows the problems and asks for one.
     *
     * @param  array<string, mixed>  $day  day()
     * @return ?string the override reason to store, if one was needed
     */
    private function checkLimits(ValidationErrors $errors, array $day, int $creditable, User $actor, ?string $overrideReason, bool $deferForAdmin = false): ?string
    {
        $problems = $this->limitProblems($day, $creditable);

        if ($problems === []) {
            return null;
        }

        $reason = trim((string) $overrideReason);

        if ($actor->hasRole('admin') && ($reason !== '' || $deferForAdmin)) {
            return $reason !== '' ? $reason : null;
        }

        foreach ($problems as $problem) {
            $errors->add('overtime', $problem);
        }

        if ($actor->hasRole('admin')) {
            $errors->add('limit_override_reason', 'To go over the limit, give an override reason.');
        }

        return null;
    }

    private function checkCompensation(ValidationErrors $errors, OvertimeCompensation $compensation): void
    {
        if ($compensation === OvertimeCompensation::TimeOff && OvertimeSettings::current()->toil_leave_type_id === null) {
            $errors->add('compensation', 'Overtime can\'t be taken as time off until a time-off-in-lieu leave type is set in Policies → Overtime.');
        }
    }

    /**
     * What a date is for an employee: their schedule and its full window,
     * whether it has scheduled hours (a workday that isn't a holiday), and
     * the scheduled minutes — the window less the break, or the half worked
     * on an approved half-day leave.
     *
     * @return array{schedule: WorkSchedule, date: Carbon, window: ExpectedWindow, isScheduledWorkday: bool, isHoliday: bool, hasHours: bool, scheduledMinutes: int}
     */
    private function day(Employee $employee, Carbon $date): array
    {
        $schedule = $employee->scheduleOn($date);
        $isScheduledWorkday = WorkdayCalendar::isScheduledWorkday($schedule, $date);
        $isHoliday = WorkdayCalendar::isHoliday($date);
        $hasHours = $isScheduledWorkday && ! $isHoliday;
        $window = ExpectedWindow::for($schedule, $date);
        $scheduledMinutes = 0;

        if ($hasHours) {
            $leaveDay = LeaveDay::on($this->leavesOn($employee, $date, [LeaveStatus::Approved]), $date);
            $worked = $leaveDay->isHalfDay() && $schedule->break_start !== null ? ExpectedWindow::for($schedule, $date, $leaveDay->half) : $window;
            $seconds = $worked->end->getTimestamp() - $worked->start->getTimestamp();
            $scheduledMinutes = $leaveDay->isHalfDay()
                ? intdiv($seconds - $worked->breakOverlapSeconds($worked->start, $worked->end), 60)
                : intdiv($seconds, 60) - $schedule->break_minutes;
        }

        return compact('schedule', 'date', 'window', 'isScheduledWorkday', 'isHoliday', 'hasHours', 'scheduledMinutes');
    }

    /**
     * The window's overtime minutes if all of it were worked — OvertimeCalculator
     * with the window as the punches, so "creditable" has one definition.
     *
     * @param  array<string, mixed>  $day  day()
     */
    private function creditableMinutes(array $day, Carbon $startsAt, Carbon $endsAt): int
    {
        $window = new OvertimeRequest(['date' => $day['date'], 'starts_at' => $startsAt, 'ends_at' => $endsAt]);

        return $this->calculator->calculate(
            $window, $startsAt, $endsAt, $day['schedule'], $day['date'],
            $day['isScheduledWorkday'], $day['isHoliday'], LeaveDay::none(), OvertimeSettings::current(),
        )->total();
    }

    /** An approved or pending leave making $date a full day off (an AM and a PM one together count), if any. */
    private function fullDayLeave(Employee $employee, Carbon $date): ?Leave
    {
        $leaves = $this->leavesOn($employee, $date, [LeaveStatus::Approved, LeaveStatus::Pending]);

        if (! LeaveDay::on($leaves, $date)->fullDay) {
            return null;
        }

        return $leaves->first(fn (Leave $leave) => $leave->half === null) ?? $leaves->first();
    }

    /**
     * @param  list<LeaveStatus>  $statuses
     * @return Collection<int, Leave>
     */
    private function leavesOn(Employee $employee, Carbon $date, array $statuses)
    {
        return Leave::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', array_map(fn (LeaveStatus $status) => $status->value, $statuses))
            ->whereDate('start_date', '<=', $date->format('Y-m-d'))
            ->whereDate('end_date', '>=', $date->format('Y-m-d'))
            ->with('leaveType')
            ->orderBy('id')
            ->get();
    }

    /**
     * Step 1 is done: wait for an admin, or — when the requester is the only
     * admin — record step 2 as self_approved and approve.
     */
    private function moveToStepTwo(OvertimeRequest $request): void
    {
        if ($this->flow->isSelfApprovedAtStepTwo($request)) {
            $this->flow->record($request, ApprovalFlow::ADMIN_STEP, ApprovalOutcome::SelfApproved, $this->flow->requester($request), 'The requester is the only admin.');
            $request->update(['status' => OvertimeStatus::Approved, 'current_step' => null]);

            return;
        }

        $request->update(['current_step' => ApprovalFlow::ADMIN_STEP]);
    }

    /**
     * The request's work date rebuilt (DailySummaryBuilder::rebuildOvertimeDate()
     * — nothing for a future date), then time off in lieu reconciled for a
     * time_off request. The rebuild reconciles by itself when the credited
     * minutes changed; the explicit call also covers a decision that moved
     * no minute (a request that credits nothing yet). A failure never undoes
     * the decision: it's logged and returned.
     */
    private function rebuild(OvertimeRequest $request, string $action): ?string
    {
        $employee = $request->employee;

        if ($request->date->gt(today())) {
            return null;
        }

        try {
            $this->builder->rebuildOvertimeDate($request);

            if ($request->compensation === OvertimeCompensation::TimeOff) {
                $this->builder->reconcileToil($employee, $request);
                $this->builder->reportToil();
            }

            return null;
        } catch (Throwable $e) {
            $message = $this->assigner->rebuildRecoveryMessage($request->date, "--employee={$employee->employee_code}", $request->date);

            Log::error("Overtime request #{$request->id} {$action} for {$employee->employee_code}: rebuild failed partway ({$e->getMessage()}). {$message}");

            return $message;
        }
    }
}
