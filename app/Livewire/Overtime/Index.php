<?php

namespace App\Livewire\Overtime;

use App\Exceptions\StaleDecisionException;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Services\Leave\LeaveBalance;
use App\Services\Overtime\OvertimeRequestService;
use App\Services\Overtime\TimeOffInLieuReconciler;
use App\Support\DisplayDate;
use App\Support\OvertimeResult;
use App\Support\OvertimeSummary;
use App\Support\RequestDecisions;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Overtime (Phase 4d): the signed-in employee's credited overtime this month,
 * their requests with what each has credited, and the entry points to the
 * request modal (Overtime\RequestModal) — their own request, and "File for an
 * employee" for an admin. Every figure comes from the overtime services and
 * the builder's rows (OvertimeSummary, OvertimeResult, the reconciler);
 * nothing here re-derives a rule.
 *
 * Requests decided since the user last opened the page carry a "New" marker
 * (RequestDecisions — Time off's rule): users.overtime_seen_at is read once
 * on opening, then moved to now.
 */
class Index extends Component
{
    /** @var list<int> requests decided since the last visit, worked out once on opening */
    public array $newIds = [];

    public ?string $notice = null;

    public ?string $warning = null;

    public function mount(): void
    {
        $this->authorize('viewAny', OvertimeRequest::class);

        $user = auth()->user();

        if ($user->employee !== null) {
            $this->newIds = RequestDecisions::since($this->requestsOf($user->employee), $user, $user->overtime_seen_at)->pluck('id')->all();
        }

        $user->forceFill(['overtime_seen_at' => now()])->save();
    }

    #[On('overtime-saved')]
    public function overtimeSaved(string $message = '', ?string $rebuildError = null): void
    {
        $this->notice = $message !== '' ? $message : null;
        $this->warning = $rebuildError;
    }

    public function cancel(OvertimeRequest $request, OvertimeRequestService $service): void
    {
        $this->authorize('cancel', $request);

        try {
            $result = $service->cancel($request, auth()->user());
        } catch (StaleDecisionException $e) {
            $this->addError('requests', $e->getMessage());

            return;
        }

        $this->notice = 'Cancelled your overtime request for '.DisplayDate::compact($request->date).'.';
        $this->warning = $result['rebuildError'];
    }

    public function render(OvertimeRequestService $service, LeaveBalance $balances, TimeOffInLieuReconciler $reconciler)
    {
        $this->authorize('viewAny', OvertimeRequest::class);

        $user = auth()->user();
        $employee = $user->employee;
        $settings = OvertimeSettings::current();

        // Time off in lieu once they have any — a balance (an adjustment, ever)
        // or time saved toward a half day — never "0" for someone who has
        // never done overtime (LeaveType::shownFor()'s rule).
        $toilType = $settings->toilLeaveType;
        $saved = $employee !== null ? ($reconciler->remainderMinutes($employee) ?? 0) : 0;
        $toil = $employee !== null && $toilType !== null && ($saved > 0 || $toilType->adjustments()->where('employee_id', $employee->id)->exists())
            ? ['name' => $toilType->name, 'available' => $balances->for($employee, $toilType, today()->year)->available(), 'saved' => $saved]
            : null;

        return view('livewire.overtime.index', [
            'employee' => $employee,
            'month' => $employee !== null ? OvertimeSummary::forMonth($employee, today()) : null,
            'toil' => $toil,
            'requests' => $employee !== null ? $this->rows($employee, $service) : collect(),
            'canFileForOthers' => $user->can('fileForOthers', OvertimeRequest::class),
        ])->layout('layouts.app', ['header' => 'Overtime']);
    }

    /**
     * @return Collection<int, OvertimeRequest>
     */
    private function requestsOf(Employee $employee): Collection
    {
        return OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->with('approvalSteps.decidedBy')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->each(fn (OvertimeRequest $request) => $request->setRelation('employee', $employee));
    }

    /**
     * Each request with what it has credited, in words — the builder's row
     * for its date, loaded once for all of them.
     *
     * @return Collection<int, array{request: OvertimeRequest, result: ?string}>
     */
    private function rows(Employee $employee, OvertimeRequestService $service): Collection
    {
        $requests = $this->requestsOf($employee);
        $days = DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereIn('work_date', $requests->pluck('date')->map->format('Y-m-d')->unique()->values())
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'));

        return $requests->map(fn (OvertimeRequest $request) => [
            'request' => $request,
            'result' => OvertimeResult::line($request, $days->get($request->date->format('Y-m-d')), $request->status->value === 'approved' ? $service->approvedMinutes($request) : 0),
        ]);
    }
}
