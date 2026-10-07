<?php

namespace App\Livewire\Leave;

use App\Contracts\Approvable;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Exceptions\OvertimeValidationException;
use App\Exceptions\StaleDecisionException;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Services\Approval\ApprovalFlow;
use App\Services\Approval\ApprovalInbox;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\AttendanceTime;
use App\Support\DisplayDate;
use App\Support\Duration;
use App\Support\EmployeeScope;
use App\Support\LeaveDays;
use App\Support\OvertimeSummary;
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
 * approver saw, so a request that moved on fails with the service's own
 * message, and the list refreshes. For overtime the approver may change the
 * compensation, and sees what the day's punches would credit
 * (OvertimeRequestService::wouldCredit()); over a daily limit, a manager sees
 * the problem and approves anyway, an admin completing it gives an override
 * reason.
 */
class Approvals extends Component
{
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

    public function mount(): void
    {
        $this->authorize('decideAny', Leave::class);
    }

    public function openDecision(int $id, string $decision, string $type = 'leave'): void
    {
        $request = $this->find($type, $id);
        $this->authorize('approve', $request);

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

        $this->validate(
            ['note' => [$this->decision === 'reject' ? 'required' : 'nullable', 'string', 'max:1000']],
            ['note.required' => 'Write a reason to reject this request.'],
        );

        $note = trim($this->note) !== '' ? trim($this->note) : null;

        try {
            if ($this->decision === 'reject') {
                $service->reject($leave, auth()->user(), $note, $this->expectedStep);
                $this->notice = "Rejected {$leave->employee->full_name}'s {$leave->leaveType->name} leave.";
                $this->problem = null;
            } else {
                $result = $service->approve($leave, auth()->user(), $note, $this->expectedStep);
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
                $this->problem = null;
            } else {
                $result = $service->approve($request, auth()->user(), $note, OvertimeCompensation::from($this->compensation), $this->expectedStep,
                    trim($this->override_reason) !== '' ? trim($this->override_reason) : null);
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

    private function find(string $type, int $id): Approvable
    {
        return $type === 'overtime' ? OvertimeRequest::findOrFail($id) : Leave::findOrFail($id);
    }

    public function render(ApprovalFlow $flow, LeaveBalance $balances, LeaveDayCounter $counter, OvertimeRequestService $overtime)
    {
        $this->authorize('decideAny', Leave::class);

        $user = auth()->user();
        $inbox = ApprovalInbox::for($user);
        $visible = EmployeeScope::for($user, 'Approvals')->ids;
        $describeOne = fn (Approvable $item) => $item instanceof OvertimeRequest
            ? $this->describeOvertime($item, $overtime)
            : $this->describe($item, $balances, $counter, $visible);
        $describe = fn (Collection $items) => $items->map($describeOne)->all();
        // Only the override group says "Stuck": it's why an admin steps in there.
        $describeOverrides = fn (Collection $items) => $items->map(fn (Approvable $item) => [
            ...$describeOne($item),
            'stuck' => ApprovalInbox::isStuck($item),
            'awayReason' => ApprovalInbox::awayReason($item),
        ])->all();

        $deciding = $this->decidingId === null ? null : ($this->decidingType === 'overtime'
            ? OvertimeRequest::with('employee')->find($this->decidingId)
            : Leave::with(['employee', 'leaveType'])->find($this->decidingId));
        $decidesBoth = $deciding !== null && $flow->decidesBothSteps($user, $deciding);
        $limitProblems = $deciding instanceof OvertimeRequest && $this->decision === 'approve' ? $overtime->currentLimitProblems($deciding) : [];

        return view('livewire.leave.approvals', [
            'isAdmin' => $user->hasRole('admin'),
            'stepOne' => $describe($inbox['stepOne']),
            'stepTwo' => $describe($inbox['stepTwo']),
            'overrides' => $describeOverrides($inbox['overrides']),
            'deciding' => $deciding,
            'decidesBoth' => $decidesBoth,
            'limitProblems' => $limitProblems,
            // Completing it over a limit needs an admin's reason; a manager's step 1 doesn't.
            'needsOverride' => $limitProblems !== [] && $user->hasRole('admin') && ($decidesBoth || $deciding?->current_step === ApprovalFlow::ADMIN_STEP),
            'timeOffOffered' => OvertimeSettings::current()->toil_leave_type_id !== null,
        ])->layout('layouts.app', ['header' => 'Approvals']);
    }

    /**
     * What one overtime request card shows: the request, and for a claim or a
     * planned date that has passed, what the day's punches would credit if it
     * were approved (OvertimeRequestService::wouldCredit() — the builder's own
     * calculation).
     *
     * @return array<string, mixed>
     */
    private function describeOvertime(OvertimeRequest $request, OvertimeRequestService $service): array
    {
        $request->loadMissing(['employee.department', 'approvalSteps.decidedBy']);

        $worked = null;

        if (! $request->date->isFuture()) {
            $would = $service->wouldCredit($request);
            $credit = $would['credit'] ?? null;
            $categories = $credit === null ? [] : array_filter([
                'workday' => $credit->workday, 'night' => $credit->night, 'rest_day' => $credit->restDay, 'holiday' => $credit->holiday,
            ]);
            // Which categories, for pay only: time off is earned 1:1 whatever the category.
            $which = $request->compensation === OvertimeCompensation::Pay && $categories !== []
                ? ' ('.collect($categories)->keys()->map(fn ($key) => strtolower(OvertimeSummary::CATEGORIES[$key][0]))->implode(', ').')'
                : '';
            $worked = match (true) {
                $would === null || ($would['in'] === null && $would['out'] === null) => 'No punches on record for this day yet: nothing would be credited until they\'re added.',
                $would['out'] === null => 'Punched in '.AttendanceTime::format($would['in']).'. No out-punch: nothing would be credited until it\'s added.',
                default => 'Punched '.AttendanceTime::format($would['in']).' – '.AttendanceTime::format($would['out'])
                    .($would['out']->isSameDay($request->date) ? '' : ' (+1)')
                    .' → '.($credit->total() > 0 ? Duration::format($credit->total()).' would be credited'.$which : 'nothing would be credited'),
            };
        }

        return [
            'type' => 'overtime',
            'request' => $request,
            'submitted' => 'Submitted '.DisplayDate::compact($request->created_at),
            'when' => $request->startsLabel(),
            'stuck' => false,
            'awayReason' => null,
            'department' => $request->employee->department?->name,
            'worked' => $worked,
        ];
    }

    /**
     * What one request card shows.
     *
     * @param  int[]|null  $visible  EmployeeScope's ids for this approver (null = everyone)
     * @return array<string, mixed>
     */
    private function describe(Leave $leave, LeaveBalance $balances, LeaveDayCounter $counter, ?array $visible): array
    {
        $leave->loadMissing(['employee.department', 'leaveType.deductsFrom', 'approvalSteps.decidedBy']);
        $employee = $leave->employee;
        $cost = $counter->countLeave($leave);
        $balanceType = $leave->leaveType->deductsFrom ?? $leave->leaveType;

        // Pending leave is already reserved, so available() is the balance after this request.
        $after = ! $balanceType->hasBalance() ? [] : collect(array_keys($cost))
            ->map(fn (int $year) => ['year' => $year, 'available' => LeaveDays::format($balances->for($employee, $balanceType, $year)->available())])
            ->all();

        $alsoOff = Leave::query()
            ->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value])
            ->where('employee_id', '!=', $employee->id)
            ->whereHas('employee', fn ($query) => $query->where('department_id', $employee->department_id))
            ->when($visible !== null, fn ($query) => $query->whereIn('employee_id', $visible))
            ->whereDate('start_date', '<=', $leave->end_date->format('Y-m-d'))
            ->whereDate('end_date', '>=', $leave->start_date->format('Y-m-d'))
            ->with(['employee', 'leaveType'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            // One entry per person — the count is of people, not requests —
            // in the order of their first leave in these dates.
            ->groupBy('employee_id')
            ->map(fn (Collection $leaves) => $leaves->first()->employee->full_name.' ('
                .$leaves->map(fn (Leave $other) => "{$other->leaveType->name}, ".$other->displayDates().', '.$other->status->value)->implode('; ')
                .')')
            ->values();

        return [
            'type' => 'leave',
            'request' => $leave,
            'leave' => $leave,
            'submitted' => 'Submitted '.DisplayDate::compact($leave->created_at),
            'when' => $leave->startsLabel(),
            'stuck' => false,
            'awayReason' => null,
            'dates' => $leave->displayDates(),
            'days' => LeaveDays::label(array_sum($cost)),
            'balanceType' => $balanceType->name,
            'deducted' => $balanceType->isNot($leave->leaveType),
            'after' => $after,
            'department' => $employee->department?->name,
            'alsoOff' => $alsoOff->all(),
        ];
    }
}
