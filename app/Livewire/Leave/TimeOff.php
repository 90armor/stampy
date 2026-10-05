<?php

namespace App\Livewire\Leave;

use App\Enums\ApprovalOutcome;
use App\Enums\LeaveStatus;
use App\Exceptions\StaleLeaveDecisionException;
use App\Models\ApprovalStep;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\Balance;
use App\Services\Leave\EntitlementCalculator;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDayCounter;
use App\Services\Leave\LeaveRequestService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Time off (Phase 3e): the signed-in employee's leave balances and requests,
 * and the entry points to the request modal (Leave\RequestModal) — their own
 * request, and "File for an employee" for an admin. Every figure comes from
 * the leave services (LeaveBalance, LeaveDayCounter, EntitlementCalculator);
 * nothing here re-derives a rule.
 *
 * Requests decided since the user last opened the page carry a "New" marker:
 * users.time_off_seen_at is read once on opening, then moved to now. It's
 * the only notification there is.
 */
class TimeOff extends Component
{
    public int $year;

    /** @var list<int> requests decided since the last visit, worked out once on opening */
    public array $newIds = [];

    public ?string $notice = null;

    public ?string $warning = null;

    public function mount(): void
    {
        $this->authorize('timeOff', Leave::class);

        $this->year = today()->year;

        $user = auth()->user();

        if ($user->employee !== null) {
            $this->newIds = $this->decidedSince($user->employee, $user, $user->time_off_seen_at);
        }

        $user->forceFill(['time_off_seen_at' => now()])->save();
    }

    #[On('leave-saved')]
    public function leaveSaved(string $message = '', ?string $rebuildError = null): void
    {
        $this->notice = $message !== '' ? $message : null;
        $this->warning = $rebuildError;
    }

    public function cancel(Leave $leave, LeaveRequestService $service): void
    {
        $this->authorize('cancel', $leave);

        try {
            $result = $service->cancel($leave, auth()->user());
        } catch (StaleLeaveDecisionException $e) {
            $this->addError('requests', $e->getMessage());

            return;
        }

        $this->notice = "Cancelled your {$leave->leaveType->name} leave.";
        $this->warning = $result['rebuildError'];
    }

    public function render(EntitlementCalculator $calculator, LeaveBalance $balances, LeaveDayCounter $counter)
    {
        $this->authorize('timeOff', Leave::class);

        $user = auth()->user();
        $employee = $user->employee;
        $years = $employee !== null ? $this->years($employee) : [today()->year];

        if (! in_array($this->year, $years, true)) {
            $this->year = $years[0];
        }

        return view('livewire.leave.time-off', [
            'employee' => $employee,
            'years' => $years,
            'balanceRows' => $employee !== null ? $this->balanceRows($employee, $calculator, $balances) : [],
            'otherTypes' => LeaveType::query()->where('is_active', true)->whereNull('days_per_year')->with('deductsFrom')->orderBy('id')->get(),
            'requests' => $employee !== null ? $this->requests($employee, $counter) : collect(),
            'canFileForOthers' => $user->can('fileForOthers', Leave::class),
        ])->layout('layouts.app', ['header' => 'Time off']);
    }

    /**
     * The current year, and next year once it's granted.
     *
     * @return list<int>
     */
    private function years(Employee $employee): array
    {
        $next = today()->year + 1;
        $nextGranted = LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $next)->exists();

        return $nextGranted ? [today()->year, $next] : [today()->year];
    }

    /**
     * One row per active type with a yearly balance.
     *
     * @return list<array{type: LeaveType, balance: Balance, earnedSoFar: ?int}>
     */
    private function balanceRows(Employee $employee, EntitlementCalculator $calculator, LeaveBalance $balances): array
    {
        return LeaveType::query()
            ->where('is_active', true)
            ->whereNotNull('days_per_year')
            ->orderBy('id')
            ->get()
            ->map(fn (LeaveType $type) => [
                'type' => $type,
                'balance' => $balances->for($employee, $type, $this->year),
                'earnedSoFar' => $calculator->earnedSoFar($employee, $type, today()),
            ])
            ->all();
    }

    /**
     * @return Collection<int, array{leave: Leave, days: int}>
     */
    private function requests(Employee $employee, LeaveDayCounter $counter): Collection
    {
        return Leave::query()
            ->where('employee_id', $employee->id)
            ->with(['leaveType', 'approvalSteps.decidedBy'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (Leave $leave) use ($employee, $counter) {
                $leave->setRelation('employee', $employee);

                return ['leave' => $leave, 'days' => array_sum($counter->countLeave($leave))];
            });
    }

    /**
     * Requests that were decided — a step approved or rejected, or cancelled
     * by someone else — after $seenAt (all of them on a first visit is too
     * loud: none). The marker is for news the user didn't cause.
     *
     * @return list<int>
     */
    private function decidedSince(Employee $employee, User $user, ?CarbonInterface $seenAt): array
    {
        if ($seenAt === null) {
            return [];
        }

        return Leave::query()
            ->where('employee_id', $employee->id)
            ->with('approvalSteps')
            ->get()
            ->filter(fn (Leave $leave) => $leave->approvalSteps->contains(fn (ApprovalStep $step) => in_array($step->outcome, [ApprovalOutcome::Approved, ApprovalOutcome::Rejected], true)
                    && $step->decided_by !== $user->id
                    && $step->decided_at->gt($seenAt))
                || ($leave->status === LeaveStatus::Cancelled && $leave->cancelled_by !== $user->id && $leave->cancelled_at?->gt($seenAt)))
            ->pluck('id')
            ->values()
            ->all();
    }
}
