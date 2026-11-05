<?php

namespace App\Livewire\Leave;

use App\Contracts\Approvable;
use App\Enums\ApprovalOutcome;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Exceptions\OvertimeValidationException;
use App\Exceptions\StaleDecisionException;
use App\Livewire\Attributes\QueryParam;
use App\Models\ApprovalStep;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Services\Approval\ApprovalFlow;
use App\Services\Approval\ApprovalInbox;
use App\Services\Leave\Balance;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\ApprovalBadge;
use App\Support\AttendanceTime;
use App\Support\DisplayDate;
use App\Support\Duration;
use App\Support\EmployeeScope;
use App\Support\LeaveDays;
use App\Support\OvertimeSummary;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Approvals (Phase 3e; overtime since 4d): what awaits this user's decision —
 * leave and overtime requests together, each labelled with its type — in
 * ApprovalInbox's three groups — waiting for you (step 1), waiting for admin (step 2,
 * admins) and you can override (step 1 elsewhere, admins, collapsed). Each
 * request shows what a decision needs: the reason, the requester's balance
 * after it, the step history, and who else in the department is off then
 * ("Also off", scoped through EmployeeScope so it never names anyone the
 * approver can't see).
 *
 * Approve takes an optional note; reject requires one — it's the
 * requester's only feedback. Decisions go through the request type's own
 * service (LeaveRequestService, OvertimeRequestService) with the step the
 * approver saw. A request that moved on while the page was open — cancelled,
 * decided by someone else, past the manager's step — gets a calm notice
 * saying so, checked before authorize() (staleReason(), Phase 6b), and the
 * list refreshes; the service's own stale check stays the last guard. For overtime the approver may change the
 * compensation, and sees what the day's punches would credit
 * (OvertimeRequestService::wouldCredit()); over a daily limit, a manager sees
 * the problem and approves anyway, an admin completing it gives an override
 * reason.
 */
class Approvals extends Component
{
    /** The type filter chips: all, leave or overtime (Phase 6j). */
    #[QueryParam(as: 'type', history: true)]
    public string $typeFilter = 'all';

    /**
     * Whether "You can override" lists the requests that aren't stuck: the
     * stuck ones are always listed, the rest behind "{n} more waiting for
     * managers" (Phase 6j).
     */
    public bool $showOverrides = false;

    public bool $showDecision = false;

    public ?int $decidingId = null;

    /** 'leave' or 'overtime'. */
    public string $decidingType = 'leave';

    /** Overtime: the compensation to approve it with (preselected). */
    public string $compensation = 'pay';

    /** Overtime: an admin's reason to complete it over a daily limit. */
    public string $override_reason = '';

    /** 'approve' or 'reject'. */
    public string $decision = 'approve';

    public ?int $expectedStep = null;

    public string $note = '';

    public ?string $notice = null;

    public ?string $problem = null;

    /**
     * Why the request someone tried to decide can't be decided any more —
     * cancelled, already decided, or moved on to the next step since the page
     * was loaded (Phase 6b). A calm notice, never the error overlay.
     */
    public ?string $stale = null;

    public function mount(): void
    {
        $this->authorize('decideAny', Leave::class);

        if (! in_array($this->typeFilter, self::TYPE_FILTERS, true)) {
            $this->typeFilter = 'all';
        }
    }

    public const TYPE_FILTERS = ['all', 'leave', 'overtime'];

    public function filterBy(string $type): void
    {
        $this->typeFilter = in_array($type, self::TYPE_FILTERS, true) ? $type : 'all';
    }

    /**
     * $step is the step the item was showing when it was clicked: a request
     * that has moved on since is stale, not forbidden.
     */
    public function openDecision(int $id, string $decision, string $type = 'leave', ?int $step = null): void
    {
        $request = $this->find($type, $id);

        if (($why = $this->staleReason($request, $step)) !== null) {
            $this->showStale($why);

            return;
        }

        $this->authorize('approve', $request);
        $this->stale = null;

        $this->decidingType = $type === 'overtime' ? 'overtime' : 'leave';
        $this->decidingId = $request->id;
        $this->decision = $decision === 'reject' ? 'reject' : 'approve';
        $this->expectedStep = $request->current_step;
        $this->note = '';
        $this->override_reason = '';
        $this->compensation = $request instanceof OvertimeRequest ? $request->compensation->value : 'pay';
        $this->resetErrorBag();
        $this->showDecision = true;
    }

    public function decide(LeaveRequestService $service, OvertimeRequestService $overtime): void
    {
        if ($this->decidingType === 'overtime') {
            $this->decideOvertime($overtime);

            return;
        }

        $leave = Leave::with(['employee', 'leaveType'])->findOrFail($this->decidingId);

        if (($why = $this->staleReason($leave, $this->expectedStep)) !== null) {
            $this->showStale($why);

            return;
        }

        $this->validate(
            ['note' => [$this->decision === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']],
            ['note.required' => 'Write a reason to reject this request.'],
        );

        $note = trim($this->note) !== '' ? trim($this->note) : null;

        try {
            if ($this->decision === 'reject') {
                $service->reject($leave, auth()->user(), $note, $this->expectedStep);
                $this->notice = "Rejected {$leave->employee->full_name}'s {$leave->leaveType->name} leave.";
                $this->stale = null;
                $this->problem = null;
            } else {
                $result = $service->approve($leave, auth()->user(), $note, $this->expectedStep);
                $this->stale = null;
                $this->notice = $result['leave']->status === LeaveStatus::Approved
                    ? "Approved {$leave->employee->full_name}'s {$leave->leaveType->name} leave."
                    : "Approved {$leave->employee->full_name}'s {$leave->leaveType->name} leave — it now waits for an admin.";
                $this->problem = $result['rebuildError'];
            }
        } catch (StaleDecisionException $e) {
            $this->notice = null;
            $this->problem = $e->getMessage();
        }

        $this->showDecision = false;
        $this->reset(['decidingId', 'note', 'expectedStep']);
    }

    private function decideOvertime(OvertimeRequestService $service): void
    {
        $request = OvertimeRequest::with('employee')->findOrFail($this->decidingId);

        if (($why = $this->staleReason($request, $this->expectedStep)) !== null) {
            $this->showStale($why);

            return;
        }

        $this->validate(
            ['note' => [$this->decision === 'reject' ? 'required' : 'nullable', 'string', 'max:1000'], 'compensation' => ['in:pay,time_off']],
            ['note.required' => 'Write a reason to reject this request.'],
        );

        $note = trim($this->note) !== '' ? trim($this->note) : null;
        $whose = "{$request->employee->full_name}'s overtime on ".DisplayDate::compact($request->date);

        try {
            if ($this->decision === 'reject') {
                $service->reject($request, auth()->user(), $note, $this->expectedStep);
                $this->notice = "Rejected {$whose}.";
                $this->stale = null;
                $this->problem = null;
            } else {
                $result = $service->approve($request, auth()->user(), $note, OvertimeCompensation::from($this->compensation), $this->expectedStep,
                    trim($this->override_reason) !== '' ? trim($this->override_reason) : null);
                $this->stale = null;
                $this->notice = $result['request']->status === OvertimeStatus::Approved
                    ? "Approved {$whose}."
                    : "Approved {$whose} — it now waits for an admin.";
                $this->problem = $result['rebuildError'];
            }
        } catch (OvertimeValidationException $e) {
            // The dialog stays open: a missing override reason, or time off without a TOIL type.
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        } catch (StaleDecisionException $e) {
            $this->notice = null;
            $this->problem = $e->getMessage();
        }

        $this->showDecision = false;
        $this->reset(['decidingId', 'note', 'expectedStep', 'override_reason']);
    }

    /**
     * Why this request can no longer be decided at $step, in plain words — or
     * null when it still can (or when the user may not even see it: then
     * authorize() answers, with the 403 a real authorization failure gets).
     * Checked before authorize(), which fails for any request no longer
     * pending and would otherwise put Livewire's error overlay on screen.
     */
    private function staleReason(Approvable $request, ?int $step): ?string
    {
        if (auth()->user()->cannot('view', $request)) {
            return null;
        }

        $request->loadMissing(['employee.user', 'cancelledBy', 'approvalSteps.decidedBy']);
        $decided = fn (array $outcomes) => $request->approvalSteps
            ->filter(fn (ApprovalStep $s) => in_array($s->outcome, $outcomes, true))
            ->sortBy('step')
            ->last();
        $by = fn (?ApprovalStep $s) => $s === null ? '.' : ' by '.($s->decidedBy?->name ?? 'an admin').' on '.DisplayDate::compact($s->decided_at).'.';
        // Which request, as its queue row names it: one person often has several waiting.
        $what = $this->staleSubject($request);
        $whose = $request->employee->full_name."'s {$what}";
        $canceller = $request->cancelledBy;

        return match ($request->status->value) {
            'cancelled' => match (true) {
                $canceller === null => "{$whose} was cancelled.",
                $canceller->is($request->employee->user) => "{$canceller->name} cancelled their {$what}.",
                default => "{$canceller->name} cancelled {$whose}.",
            },
            'approved' => "{$whose} was already approved".$by($decided([ApprovalOutcome::Approved, ApprovalOutcome::SelfApproved])),
            'rejected' => "{$whose} was already rejected".$by($decided([ApprovalOutcome::Rejected])),
            default => match (true) {
                $step === null || $request->current_step === $step => null,
                $request->current_step === ApprovalFlow::ADMIN_STEP => "{$whose} has already moved on to admin approval.",
                default => "{$whose} has changed since the page loaded.",
            },
        };
    }

    /** "leave request (Annual, Mon 3 Nov)", "overtime request (Thu 29 Oct, 5:00 PM – 7:00 PM)" — the queue rows' formats. */
    private function staleSubject(Approvable $request): string
    {
        if ($request instanceof OvertimeRequest) {
            return 'overtime request ('.$request->displayDateAndWindow().')';
        }

        $request->loadMissing('leaveType');

        return 'leave request ('.$request->leaveType->name.', '.str_replace(' · ', ', ', $request->displayDates()).')';
    }

    private function showStale(string $why): void
    {
        $this->stale = $why;
        $this->notice = null;
        $this->problem = null;
        $this->showDecision = false;
        $this->reset(['decidingId', 'note', 'expectedStep', 'override_reason']);
    }

    private function find(string $type, int $id): Approvable
    {
        return $type === 'overtime' ? OvertimeRequest::findOrFail($id) : Leave::findOrFail($id);
    }

    public function render(ApprovalFlow $flow, LeaveBalance $balances, LeaveDayCounter $counter, OvertimeRequestService $overtime)
    {
        $this->authorize('decideAny', Leave::class);

        $user = auth()->user();
        $isAdmin = $user->hasRole('admin');
        [$inbox, $status] = ApprovalInbox::withStatus($user);
        $waiting = ApprovalInbox::countOf($inbox, $status);
        // The sidebar badge and the menu-button dot are rendered with the page,
        // outside this component: every render — a decision, a stale removal —
        // sends them the count of the list it shows (Phase 6b), and on a full
        // page load they show it without counting again (Phase 6c). Always the
        // whole queue, whatever the type filter.
        ApprovalBadge::remember($user, $waiting);
        $this->dispatch('approval-badge', count: $waiting);

        $all = $inbox['stepOne']->concat($inbox['stepTwo'])->concat($inbox['overrides']);
        $typeCounts = [
            'all' => $all->count(),
            'leave' => $all->filter(fn (Approvable $item) => $item instanceof Leave)->count(),
            'overtime' => $all->filter(fn (Approvable $item) => $item instanceof OvertimeRequest)->count(),
        ];
        $shown = fn (Collection $items) => $items->filter(fn (Approvable $item) => match ($this->typeFilter) {
            'leave' => $item instanceof Leave,
            'overtime' => $item instanceof OvertimeRequest,
            default => true,
        })->values();
        $groups = array_map($shown, $inbox);

        $visible = EmployeeScope::for($user, 'Approvals')->ids;
        $facts = $this->facts($groups['stepOne']->concat($groups['stepTwo'])->concat($groups['overrides']), $balances, $counter, $overtime, $visible);
        // The override rows' step column names the requester's manager: one query, only when there are any.
        EloquentCollection::make($groups['overrides']->pluck('employee')->unique('id')->values()->all())->loadMissing('manager');
        // $section decides the step column: empty in "Waiting for you", who
        // approved step 1 in "Waiting for admin", whose step it is in "You can override".
        $describe = fn (Collection $items, string $section) => $items->map(function (Approvable $item) use ($facts, $status, $isAdmin, $section) {
            $itemStatus = $status[ApprovalInbox::key($item)] ?? ['stuck' => false, 'awayReason' => null, 'awayUntil' => null];
            $described = $item instanceof OvertimeRequest
                ? $this->describeOvertime($item, $facts, $itemStatus, $isAdmin)
                : $this->describe($item, $facts, $itemStatus);

            return [...$described, 'step' => $this->stepLabel($item, $section, $itemStatus)];
        })->all();

        $overrides = $describe($groups['overrides'], 'overrides');

        $deciding = $this->decidingId === null ? null : ($this->decidingType === 'overtime'
            ? OvertimeRequest::with('employee')->find($this->decidingId)
            : Leave::with(['employee', 'leaveType'])->find($this->decidingId));
        $decidesBoth = $deciding !== null && $flow->decidesBothSteps($user, $deciding);
        $limitProblems = $deciding instanceof OvertimeRequest && $this->decision === 'approve' ? $overtime->currentLimitProblems($deciding) : [];

        return view('livewire.leave.approvals', [
            'isAdmin' => $isAdmin,
            'typeCounts' => $typeCounts,
            'stepOne' => $describe($groups['stepOne'], 'stepOne'),
            'stepTwo' => $describe($groups['stepTwo'], 'stepTwo'),
            // Stuck first (ApprovalInbox's order): listed; the rest behind a toggle.
            'stuckOverrides' => array_values(array_filter($overrides, fn (array $item) => $item['stuck'])),
            'otherOverrides' => array_values(array_filter($overrides, fn (array $item) => ! $item['stuck'])),
            'deciding' => $deciding,
            'decidesBoth' => $decidesBoth,
            'limitProblems' => $limitProblems,
            // Completing it over a limit needs an admin's reason; a manager's step 1 doesn't.
            'needsOverride' => $limitProblems !== [] && $isAdmin && ($decidesBoth || $deciding?->current_step === ApprovalFlow::ADMIN_STEP),
            'timeOffOffered' => OvertimeSettings::current()->toil_leave_type_id !== null,
        ])->layout('layouts.app', ['header' => 'Approvals']);
    }

    /**
     * Everything the rows read, loaded once for every request on the page
     * rather than per row (Phase 6c): relations, each leave's cost, the
     * balances after it (LeaveBalance::forMany(), per balance type and year),
     * "Also off" (one query, each row's share filtered here exactly as its
     * own query did) and, for every overtime request, what its window counts
     * and its limit problems — and for a past one what its punches would
     * credit (OvertimeRequestService::creditsFor()).
     *
     * @param  Collection<int, Approvable>  $items
     * @param  int[]|null  $visible  EmployeeScope's ids for this approver (null = everyone)
     * @return array{costs: array<int, array<int, int>>, balances: array<string, Balance>, alsoOff: Collection<int, Leave>, credits: array<int, array<string, mixed>>}
     */
    private function facts(Collection $items, LeaveBalance $balances, LeaveDayCounter $counter, OvertimeRequestService $overtime, ?array $visible): array
    {
        $leaves = EloquentCollection::make($items->filter(fn (Approvable $item) => $item instanceof Leave)->values()->all())
            ->loadMissing(['employee.department', 'employee.scheduleAssignments', 'leaveType.deductsFrom', 'approvalSteps.decidedBy']);
        $overtimeRequests = EloquentCollection::make($items->filter(fn (Approvable $item) => $item instanceof OvertimeRequest)->values()->all())
            ->loadMissing(['employee.department', 'approvalSteps.decidedBy']);

        $holidays = LeaveDayCounter::holidaysFor($leaves);
        $costs = $leaves->mapWithKeys(fn (Leave $leave) => [$leave->id => $counter->countLeave($leave, $holidays)])->all();

        // Whose balance of which type, for which year: one forMany() per pair.
        $wanted = [];
        foreach ($leaves as $leave) {
            $balanceType = $leave->leaveType->deductsFrom ?? $leave->leaveType;

            foreach ($balanceType->hasBalance() ? array_keys($costs[$leave->id]) : [] as $year) {
                $wanted[$balanceType->id.'|'.$year] ??= ['type' => $balanceType, 'year' => $year, 'employees' => []];
                $wanted[$balanceType->id.'|'.$year]['employees'][$leave->employee->id] = $leave->employee;
            }
        }
        $found = [];
        foreach ($wanted as $key => $want) {
            foreach ($balances->forMany($want['employees'], $want['type'], $want['year']) as $employeeId => $balance) {
                $found[$key.'|'.$employeeId] = $balance;
            }
        }

        $alsoOff = $leaves->isEmpty() ? collect() : Leave::query()
            ->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->whereHas('employee', fn ($query) => $query->whereIn('department_id', $leaves->pluck('employee.department_id')->filter()->unique()))
            ->when($visible !== null, fn ($query) => $query->whereIn('employee_id', $visible))
            ->whereDate('start_date', '<=', $leaves->max('end_date')->format('Y-m-d'))
            ->whereDate('end_date', '>=', $leaves->min('start_date')->format('Y-m-d'))
            ->with(['employee', 'leaveType'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return ['costs' => $costs, 'balances' => $found, 'alsoOff' => $alsoOff, 'credits' => $overtime->creditsFor($overtimeRequests)];
    }

    /**
     * One overtime row: who, when, how long, its signals and step, and the
     * details behind "Show details" — for a claim or a planned date that has
     * passed, what the day's punches would credit if it were approved
     * (OvertimeRequestService::wouldCredit() — the builder's own calculation
     * — through creditsFor(), in facts()).
     *
     * @param  array<string, mixed>  $facts  facts()
     * @param  array{stuck: bool, awayReason: ?string, awayUntil: mixed}  $status
     * @return array<string, mixed>
     */
    private function describeOvertime(OvertimeRequest $request, array $facts, array $status, bool $isAdmin): array
    {
        $settings = OvertimeSettings::current();
        ['would' => $would, 'requested' => $requested, 'split' => $split, 'limitProblems' => $limitProblems] = $facts['credits'][$request->id];
        $splitMinutes = array_filter([
            'workday' => $split->workday, 'night' => $split->night, 'rest_day' => $split->restDay, 'holiday' => $split->holiday,
        ]);
        $pay = $request->compensation === OvertimeCompensation::Pay;
        // Rates belong to pay only (Phase 4d): time off is earned 1:1 whatever the category.
        $categories = collect($splitMinutes)->map(fn (int $minutes, string $key) => OvertimeSummary::CATEGORIES[$key][0]
            .' '.$settings->{OvertimeSummary::CATEGORIES[$key][1]}.'%'
            .(count($splitMinutes) > 1 ? ' '.Duration::format($minutes) : ''));

        $past = ! $request->date->isFuture();
        $worked = null;
        $workedHint = null;
        $punchSignal = null;

        if ($past) {
            $credit = $would['credit'] ?? null;
            $which = $pay && $credit !== null && $credit->total() > 0
                ? ' ('.collect(['workday' => $credit->workday, 'night' => $credit->night, 'rest_day' => $credit->restDay, 'holiday' => $credit->holiday])
                    ->filter()->keys()->map(fn ($key) => strtolower(OvertimeSummary::CATEGORIES[$key][0]))->implode(', ').')'
                : '';
            // Less than requested is written against the request, so it can't be read past.
            $short = $credit !== null && $credit->total() < $requested;
            $worked = match (true) {
                $would === null || ($would['in'] === null && $would['out'] === null) => 'No punches on record for this day yet: nothing would be credited until they\'re added.',
                $would['out'] === null => 'Punched in '.AttendanceTime::format($would['in']).'. No out-punch: nothing would be credited until it\'s added.',
                default => 'Punched '.AttendanceTime::format($would['in']).' – '.AttendanceTime::format($would['out'])
                    .($would['out']->isSameDay($request->date) ? '' : ' (+1)')
                    .' → '.match (true) {
                        ! $short => Duration::format($credit->total()).' would be credited'.$which,
                        $credit->total() > 0 => Duration::format($credit->total()).' of '.Duration::format($requested).' requested would be credited'.$which,
                        default => 'none of '.Duration::format($requested).' requested would be credited',
                    },
            };
            $punchSignal = match (true) {
                $would === null || ($would['in'] === null && $would['out'] === null) => 'No punches yet',
                $would['out'] === null => 'No out-punch yet',
                $short => 'Punches: '.Duration::format($credit->total()).' of '.Duration::format($requested),
                default => null,
            };
            if ($short && $would['in'] !== null && $would['out'] !== null) {
                $workedHint = 'Check the punches before approving — an out-punch may be missing.';
            }
        }

        $signals = [];
        $days = (int) today()->diffInDays($request->date->copy()->startOfDay(), false);

        if ($request->kind === OvertimeKind::Planned && $days === 0) {
            $signals[] = self::attention('calendar-days', 'Starts today');
        } elseif ($request->kind === OvertimeKind::Planned && $days === 1) {
            $signals[] = self::attention('calendar-days', 'Starts tomorrow');
        }
        if ($limitProblems !== []) {
            // A manager can't override a limit: the admin will have to.
            $signals[] = self::attention('clock', $isAdmin ? 'Over limit' : 'Override needed');
        }
        if ($status['stuck']) {
            $signals[] = self::attention('flag', 'Stuck');
        }
        if ($punchSignal !== null) {
            $signals[] = self::muted($punchSignal);
        }
        // Planned is the default and says nothing: only a claim, or a planned date that has passed.
        if ($request->kind === OvertimeKind::Claim) {
            $signals[] = self::muted('Claim');
        } elseif ($days < 0) {
            $signals[] = self::muted('Already worked');
        }

        $details = array_values(array_filter([
            $request->reason ? ['label' => 'Reason', 'lines' => ['“'.$request->reason.'”']] : null,
            ['label' => 'Requested', 'lines' => [
                $request->displayDateAndWindow().' · '.$request->kind->label().' · '.$request->compensation->label(),
                'Submitted '.DisplayDate::compact($request->created_at).($request->startsLabel() ? ' · '.$request->startsLabel() : ''),
            ]],
            $pay && count($splitMinutes) > 1 ? ['label' => 'Counts as', 'lines' => [$categories->implode(' · ')]] : null,
            $worked ? ['label' => 'Punches', 'lines' => array_values(array_filter([$worked, $workedHint]))] : null,
            $limitProblems !== [] ? ['label' => 'Daily limit', 'lines' => $limitProblems] : null,
            $request->limit_override_reason ? ['label' => 'Over the daily limit', 'lines' => ['“'.$request->limit_override_reason.'”']] : null,
            $status['awayReason'] ? ['label' => 'Approvers away', 'lines' => [$status['awayReason'].'.']] : null,
            $this->historyDetail($request),
        ]));

        return [
            'type' => 'overtime',
            'request' => $request,
            'stuck' => $status['stuck'],
            'name' => $request->employee->full_name,
            'department' => $request->employee->department?->name,
            'when' => DisplayDate::compact($request->date),
            'whenTime' => AttendanceTime::range($request->starts_at, $request->ends_at),
            'whenSub' => implode(' · ', array_filter([
                Duration::format($requested),
                $pay ? (count($splitMinutes) > 1 ? collect($splitMinutes)->keys()->map(fn ($key) => OvertimeSummary::CATEGORIES[$key][0].' '.$settings->{OvertimeSummary::CATEGORIES[$key][1]}.'%')->implode(' + ') : $categories->first()) : null,
                $request->compensation->label(),
            ])),
            'signals' => $signals,
            'reason' => $request->reason,
            'details' => $details,
        ];
    }

    /**
     * One leave row.
     *
     * @param  array<string, mixed>  $facts  facts()
     * @param  array{stuck: bool, awayReason: ?string, awayUntil: mixed}  $status
     * @return array<string, mixed>
     */
    private function describe(Leave $leave, array $facts, array $status): array
    {
        $employee = $leave->employee;
        $cost = $facts['costs'][$leave->id];
        $balanceType = $leave->leaveType->deductsFrom ?? $leave->leaveType;
        $deducted = $balanceType->isNot($leave->leaveType);

        // Pending leave is already reserved, so available() is the balance after this request.
        $after = ! $balanceType->hasBalance() ? [] : collect(array_keys($cost))
            ->map(fn (int $year) => ['year' => $year, 'tenths' => $facts['balances'][$balanceType->id.'|'.$year.'|'.$employee->id]->available()])
            ->all();
        $afterText = collect($after)->map(fn ($line) => (count($after) > 1 ? $line['year'].': ' : '').LeaveDays::format($line['tenths']))->implode(' · ');

        // Others in the same department off in these dates, from facts()'s one query.
        $start = $leave->start_date->format('Y-m-d');
        $end = $leave->end_date->format('Y-m-d');
        $alsoOff = $facts['alsoOff']
            ->filter(fn (Leave $other) => $other->employee_id !== $employee->id
                && $employee->department_id !== null
                && $other->employee->department_id === $employee->department_id
                && $other->start_date->format('Y-m-d') <= $end
                && $other->end_date->format('Y-m-d') >= $start)
            // One entry per person — the count is of people, not requests —
            // in the order of their first leave in these dates.
            ->groupBy('employee_id')
            ->map(fn (Collection $leaves) => $leaves->first()->employee->full_name.' ('
                .$leaves->map(fn (Leave $other) => "{$other->leaveType->name}, ".$other->displayDates().', '.$other->status->value)->implode('; ')
                .')')
            ->values();

        $signals = [];
        $days = (int) today()->diffInDays($leave->start_date->copy()->startOfDay(), false);

        if ($alsoOff->isNotEmpty()) {
            $signals[] = self::attention('users', $alsoOff->count().' '.($alsoOff->count() === 1 ? 'other' : 'others').' off');
        }
        $starts = match (true) {
            $leave->end_date->lt(today()) => null,
            $days < 0 => 'Started '.DisplayDate::compact($leave->start_date),
            $days === 0 => 'Starts today',
            $days === 1 => 'Starts tomorrow',
            default => null,
        };
        if ($starts !== null) {
            $signals[] = self::attention('calendar-days', $starts);
        }
        if (collect($after)->contains(fn ($line) => $line['tenths'] < 0)) {
            $signals[] = self::attention('scale', 'Balance below 0');
        }
        if ($status['stuck']) {
            $signals[] = self::attention('flag', 'Stuck');
        }
        if ($leave->end_date->lt(today())) {
            $signals[] = self::muted('Already taken');
        }
        if ($after !== []) {
            // "16 days left", "2026: 15 · 2027: 23 left"; a deducting type names what it draws on.
            $signals[] = self::muted(($deducted ? $balanceType->name.': ' : '')
                .(count($after) === 1 ? LeaveDays::label($after[0]['tenths']) : $afterText).' left');
        }

        $details = array_values(array_filter([
            $leave->reason ? ['label' => 'Reason', 'lines' => ['“'.$leave->reason.'”']] : null,
            ['label' => 'Requested', 'lines' => [
                $leave->leaveType->name.' · '.$leave->displayDates().' · '.LeaveDays::label(array_sum($cost)),
                'Submitted '.DisplayDate::compact($leave->created_at).($leave->startsLabel() ? ' · '.$leave->startsLabel() : ''),
            ]],
            ['label' => 'Balance', 'lines' => [$after === []
                ? $leave->leaveType->name.' has no balance to draw from.'
                : $balanceType->name.($deducted ? ' (deducted from)' : '').' available after this request: '
                    .collect($after)->map(fn ($line) => (count($after) > 1 ? $line['year'].': ' : '').LeaveDays::label($line['tenths']))->implode(' · ')]],
            $alsoOff->isNotEmpty() ? ['label' => 'Also off', 'lines' => [
                $alsoOff->count().' '.($alsoOff->count() === 1 ? 'other' : 'others').' in '.$employee->department?->name.' during these dates:',
                ...$alsoOff->all(),
            ]] : null,
            $status['awayReason'] ? ['label' => 'Approvers away', 'lines' => [$status['awayReason'].'.']] : null,
            $this->historyDetail($leave),
        ]));

        return [
            'type' => 'leave',
            'request' => $leave,
            'stuck' => $status['stuck'],
            'name' => $employee->full_name,
            'department' => $employee->department?->name,
            'when' => DisplayDate::weekdayRange($leave->start_date, $leave->end_date),
            'whenTime' => null,
            'whenSub' => ($leave->half !== null ? 'Half day '.$leave->half->label() : LeaveDays::label(array_sum($cost))).' · '.$leave->leaveType->name,
            'signals' => $signals,
            'reason' => $leave->reason,
            'details' => $details,
        ];
    }

    /** @return array{tone: string, icon: ?string, text: string} */
    private static function attention(string $icon, string $text): array
    {
        return ['tone' => 'attention', 'icon' => $icon, 'text' => $text];
    }

    /** @return array{tone: string, icon: ?string, text: string} */
    private static function muted(string $text): array
    {
        return ['tone' => 'muted', 'icon' => null, 'text' => $text];
    }

    /**
     * The step column (Phase 6j): nothing in "Waiting for you" (the section
     * says it); in "Waiting for admin" who approved step 1 ("Manager ✓ Aye Aye
     * Mon") or that it was skipped; in "You can override" whose step it is —
     * the requester's manager — and why it may be stalled ("Manager: Aye Aye
     * Mon · away until Fri 6 Nov"). A request with a skipped step 1 is never
     * an override: the skip moves it to the admin step.
     *
     * @param  array{stuck: bool, awayReason: ?string, awayUntil: mixed}  $status
     * @return array{done: bool, text: ?string, by: ?string}
     */
    private function stepLabel(Approvable $request, string $section, array $status): array
    {
        if ($section === 'stepOne') {
            return ['done' => false, 'text' => null, 'by' => null];
        }

        if ($section === 'overrides') {
            $manager = $request->employee->manager;

            return ['done' => false, 'by' => null, 'text' => ($manager !== null ? 'Manager: '.$manager->full_name : 'No manager')
                .($status['awayUntil'] !== null ? ' · away until '.DisplayDate::compact($status['awayUntil']) : '')];
        }

        $first = $request->approvalSteps->firstWhere('step', ApprovalFlow::MANAGER_STEP);

        return $first === null || $first->outcome === ApprovalOutcome::Skipped
            ? ['done' => false, 'text' => 'Manager step skipped', 'by' => null]
            : ['done' => true, 'text' => 'Manager', 'by' => $first->decidedBy?->name];
    }

    /** @return array{label: string, lines: list<string>}|null */
    private function historyDetail(Approvable $request): ?array
    {
        if ($request->approvalSteps->isEmpty()) {
            return null;
        }

        return ['label' => 'History', 'lines' => $request->approvalSteps->map(fn (ApprovalStep $step) => 'Step '.$step->step.': '.strtolower($step->outcome->label())
            .($step->decidedBy ? ' by '.$step->decidedBy->name : '').' · '.DisplayDate::compact($step->decided_at)
            .($step->note ? ' — “'.$step->note.'”' : ''))->values()->all()];
    }
}
