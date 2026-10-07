<?php

namespace App\Livewire\Leave;

use App\Enums\LeaveStatus;
use App\Exceptions\StaleLeaveDecisionException;
use App\Models\Leave;
use App\Services\Approval\ApprovalFlow;
use App\Services\Approval\ApprovalInbox;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use App\Services\Leave\LeaveRequestService;
use App\Support\DisplayDate;
use App\Support\EmployeeScope;
use App\Support\LeaveDays;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Approvals (Phase 3e): what awaits this user's decision, in ApprovalInbox's
 * three groups — waiting for you (step 1), waiting for admin (step 2,
 * admins) and you can override (step 1 elsewhere, admins, collapsed). Each
 * request shows what a decision needs: the reason, the requester's balance
 * after it, the step history, and who else in the department is off then
 * ("Also off", scoped through EmployeeScope so it never names anyone the
 * approver can't see).
 *
 * Approve takes an optional note; reject requires one — it's the
 * requester's only feedback. Decisions go through LeaveRequestService with
 * the step the approver saw, so a request that moved on fails with the
 * service's own message, and the list refreshes.
 */
class Approvals extends Component
{
    public bool $showOverrides = false;

    public bool $showDecision = false;

    public ?int $decidingId = null;

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

    public function openDecision(int $leaveId, string $decision): void
    {
        $leave = Leave::findOrFail($leaveId);
        $this->authorize('approve', $leave);

        $this->decidingId = $leave->id;
        $this->decision = $decision === 'reject' ? 'reject' : 'approve';
        $this->expectedStep = $leave->current_step;
        $this->note = '';
        $this->resetErrorBag();
        $this->showDecision = true;
    }

    public function decide(LeaveRequestService $service): void
    {
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
        } catch (StaleLeaveDecisionException $e) {
            $this->notice = null;
            $this->problem = $e->getMessage();
        }

        $this->showDecision = false;
        $this->reset(['decidingId', 'note', 'expectedStep']);
    }

    public function render(ApprovalFlow $flow, LeaveBalance $balances, LeaveDayCounter $counter)
    {
        $this->authorize('decideAny', Leave::class);

        $user = auth()->user();
        $inbox = ApprovalInbox::for($user);
        $visible = EmployeeScope::for($user, 'Approvals')->ids;
        $describe = fn (Collection $leaves) => $leaves->map(fn (Leave $leave) => $this->describe($leave, $balances, $counter, $visible))->all();
        // Only the override group says "Stuck": it's why an admin steps in there.
        $describeOverrides = fn (Collection $leaves) => $leaves->map(fn (Leave $leave) => [
            ...$this->describe($leave, $balances, $counter, $visible),
            'stuck' => ApprovalInbox::isStuck($leave),
            'awayReason' => ApprovalInbox::awayReason($leave),
        ])->all();

        $deciding = $this->decidingId !== null ? Leave::with(['employee', 'leaveType'])->find($this->decidingId) : null;

        return view('livewire.leave.approvals', [
            'isAdmin' => $user->hasRole('admin'),
            'stepOne' => $describe($inbox['stepOne']),
            'stepTwo' => $describe($inbox['stepTwo']),
            'overrides' => $describeOverrides($inbox['overrides']),
            'deciding' => $deciding,
            'decidesBoth' => $deciding !== null && $flow->decidesBothSteps($user, $deciding),
        ])->layout('layouts.app', ['header' => 'Approvals']);
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
        $after = $balanceType->days_per_year === null ? [] : collect(array_keys($cost))
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
