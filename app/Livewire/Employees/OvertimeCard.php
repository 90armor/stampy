<?php

namespace App\Livewire\Employees;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\OvertimeRequest;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\DisplayDate;
use App\Support\OvertimeResult;
use App\Support\OvertimeSummary;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The employee profile's Overtime card (Phase 4d) — the Leave card's twin:
 * this month's and last month's credited overtime (by category for pay,
 * the plain total for time off), every request with its step history and
 * what it credited, and the time off in lieu posted for it. Whoever may open
 * the profile sees it (EmployeePolicy::view — a manager reads); an admin can
 * "File overtime" with the employee preselected and locked.
 *
 * Its own component with its own snapshot, so it authorizes in mount() and
 * render() like every nested card (CLAUDE.md, Authorization).
 */
class OvertimeCard extends Component
{
    public Employee $employee;

    public ?string $notice = null;

    public ?string $warning = null;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);
        $this->employee = $employee;
    }

    #[On('overtime-saved')]
    public function overtimeSaved(string $message = '', ?string $rebuildError = null): void
    {
        $this->notice = $message !== '' ? $message : null;
        $this->warning = $rebuildError;
    }

    public function render(OvertimeRequestService $service)
    {
        $this->authorize('view', $this->employee);

        $requests = OvertimeRequest::query()
            ->where('employee_id', $this->employee->id)
            ->with(['approvalSteps.decidedBy', 'cancelledBy'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->each(fn (OvertimeRequest $request) => $request->setRelation('employee', $this->employee));
        $days = DailyAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereIn('work_date', $requests->pluck('date')->map->format('Y-m-d')->unique()->values())
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'));
        $user = auth()->user();

        return view('livewire.employees.overtime-card', [
            'months' => [
                ['label' => DisplayDate::month(today()), 'summary' => OvertimeSummary::forMonth($this->employee, today())],
                ['label' => DisplayDate::month(today()->subMonthNoOverflow()), 'summary' => OvertimeSummary::forMonth($this->employee, today()->subMonthNoOverflow())],
            ],
            'requests' => $requests->map(fn (OvertimeRequest $request) => [
                'request' => $request,
                'result' => OvertimeResult::line($request, $days->get($request->date->format('Y-m-d')), $request->status->value === 'approved' ? $service->approvedMinutes($request) : 0),
            ]),
            // What time off in lieu settlement posted — system-authored, linked to the request that set it off.
            'posted' => LeaveAdjustment::query()
                ->where('employee_id', $this->employee->id)
                ->whereNotNull('overtime_request_id')
                ->with(['leaveType', 'overtimeRequest'])
                ->latest('id')
                ->get(),
            'canFile' => $user->can('create', [OvertimeRequest::class, $this->employee]) && $user->can('fileForOthers', OvertimeRequest::class),
        ]);
    }
}
