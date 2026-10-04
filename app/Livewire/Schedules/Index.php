<?php

namespace App\Livewire\Schedules;

use App\Exceptions\BulkReassignmentTooFarBackException;
use App\Exceptions\InvalidWorkScheduleException;
use App\Exceptions\WorkScheduleInUseException;
use App\Exceptions\WorkScheduleIsDefaultException;
use App\Exceptions\WorkScheduleLockedException;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?WorkSchedule $editing = null;

    public string $name = '';

    public string $start_time = '08:00';

    public string $end_time = '17:00';

    public int $grace_minutes = 0;

    public int $break_minutes = 0;

    /** 'HH:MM', or '' for none — the morning/afternoon boundary for half-day leave. */
    public string $break_start = '';

    /** @var int[] */
    public array $workdays = [1, 2, 3, 4, 5];

    public bool $is_default = false;

    public bool $showBulkModal = false;

    public ?int $bulk_from_id = null;

    public ?int $bulk_to_id = null;

    public string $bulk_effective_from = '';

    /** @var ?array{employees: int, days: int, rebuildError: ?string} */
    public ?array $bulkResult = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WorkSchedule::class);
    }

    public function create(): void
    {
        $this->authorize('create', WorkSchedule::class);

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(WorkSchedule $schedule): void
    {
        $this->authorize('update', $schedule);

        $this->editing = $schedule;
        $this->name = $schedule->name;
        $this->start_time = substr($schedule->start_time, 0, 5);
        $this->end_time = substr($schedule->end_time, 0, 5);
        $this->grace_minutes = $schedule->grace_minutes;
        $this->break_minutes = $schedule->break_minutes;
        $this->break_start = $schedule->break_start !== null ? substr($schedule->break_start, 0, 5) : '';
        $this->workdays = $schedule->workdays;
        $this->is_default = $schedule->is_default;
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing ?? WorkSchedule::class);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:65535'],
            'break_minutes' => ['required', 'integer', 'min:0'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'workdays' => ['required', 'array', 'min:1'],
            'workdays.*' => ['integer', 'between:1,7'],
        ]);

        $data = [
            'name' => $this->name,
            'start_time' => $this->start_time.':00',
            'end_time' => $this->end_time.':00',
            'grace_minutes' => $this->grace_minutes,
            'break_minutes' => $this->break_minutes,
            'break_start' => $this->break_start !== '' ? $this->break_start.':00' : null,
            'workdays' => array_values(array_map('intval', $this->workdays)),
            'is_default' => $this->is_default,
        ];

        try {
            if ($this->editing) {
                $this->editing->update($data);
            } else {
                WorkSchedule::create($data);
            }
        } catch (WorkScheduleLockedException|InvalidWorkScheduleException|WorkScheduleIsDefaultException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(WorkSchedule $schedule): void
    {
        $this->authorize('delete', $schedule);

        try {
            $schedule->delete();
        } catch (WorkScheduleInUseException|WorkScheduleIsDefaultException $e) {
            $this->addError('delete', $e->getMessage());
        }
    }

    public function setDefault(WorkSchedule $schedule): void
    {
        $this->authorize('update', $schedule);

        $schedule->update(['is_default' => true]);
    }

    public function openBulkReassign(): void
    {
        $this->authorize('create', WorkSchedule::class);

        $this->reset(['bulk_from_id', 'bulk_to_id', 'bulkResult']);
        $this->bulk_effective_from = today()->format('Y-m-d');
        $this->resetErrorBag();
        $this->showBulkModal = true;
    }

    /**
     * "Move everyone currently on schedule A to schedule B, effective date
     * D" — without this, changing company hours means editing every
     * employee by hand. See EmployeeScheduleAssigner::bulkReassign() for
     * exactly who counts as "currently on" A and which employees are moved.
     */
    public function bulkReassign(EmployeeScheduleAssigner $assigner): void
    {
        $this->authorize('create', WorkSchedule::class);

        $this->validate([
            'bulk_from_id' => ['required', 'exists:work_schedules,id', 'different:bulk_to_id'],
            'bulk_to_id' => ['required', 'exists:work_schedules,id'],
            'bulk_effective_from' => [
                'required',
                'date',
                'after_or_equal:'.today()->subDays(EmployeeScheduleAssigner::MAX_BULK_LOOKBACK_DAYS)->format('Y-m-d'),
            ],
        ], [
            'bulk_from_id.different' => 'Choose two different schedules.',
            'bulk_effective_from.after_or_equal' => 'Bulk reassignment can\'t be backdated more than '.EmployeeScheduleAssigner::MAX_BULK_LOOKBACK_DAYS.' days — for a correction further back, reassign the affected employees individually, or run attendance:build-daily by hand.',
        ], [
            'bulk_from_id' => 'schedule to move from',
            'bulk_to_id' => 'schedule to move to',
            'bulk_effective_from' => 'effective date',
        ]);

        $from = WorkSchedule::findOrFail($this->bulk_from_id);
        $to = WorkSchedule::findOrFail($this->bulk_to_id);

        // The Livewire rule above is the friendly, inline copy of this same
        // cap — this catch is defense in depth (e.g. a stale form re-posted
        // after the clock ticks past midnight), not the primary check.
        try {
            $this->bulkResult = $assigner->bulkReassign($from, $to, Carbon::parse($this->bulk_effective_from));
        } catch (BulkReassignmentTooFarBackException $e) {
            $this->addError('form', $e->getMessage());
        }
    }

    private function resetForm(): void
    {
        $this->reset(['editing', 'name', 'grace_minutes', 'break_minutes', 'break_start', 'is_default']);
        $this->start_time = '08:00';
        $this->end_time = '17:00';
        $this->workdays = [1, 2, 3, 4, 5];
        $this->resetErrorBag();
    }

    /**
     * Every active employee's CURRENT schedule (scheduleOn(today())),
     * tallied by schedule id — reuses the exact same resolution the builder
     * itself uses, rather than a separate SQL approximation that could
     * silently drift from it. Eager-loads each employee's assignments once,
     * so this is one query for the employees plus one for their
     * assignments, not one query per employee.
     *
     * @return array<int, int>
     */
    private function currentAssignmentCounts(): array
    {
        $today = today();
        $counts = [];

        Employee::query()
            ->where('status', 'active')
            ->with('scheduleAssignments.workSchedule')
            ->each(function (Employee $employee) use ($today, &$counts) {
                $scheduleId = $employee->scheduleOn($today)->id;
                $counts[$scheduleId] = ($counts[$scheduleId] ?? 0) + 1;
            });

        return $counts;
    }

    public function render(EmployeeScheduleAssigner $assigner)
    {
        $bulkFromSchedule = $this->bulk_from_id ? WorkSchedule::find($this->bulk_from_id) : null;

        return view('livewire.schedules.index', [
            'schedules' => WorkSchedule::query()->orderBy('name')->paginate(15, ['*'], 'schedulesPage'),
            // Unpaginated, for the bulk-reassign modal's two dropdowns — that
            // needs every schedule to choose from, not just the current page.
            'allSchedules' => WorkSchedule::query()->orderBy('name')->get(),
            'assignedCounts' => $this->currentAssignmentCounts(),
            'editingIsLocked' => $this->editing?->isReferenced() ?? false,
            // Locked schedules still take a first break start (WorkSchedule::LOCKED_FIELDS).
            'breakStartIsLocked' => $this->editing?->breakStartIsLocked() ?? false,
            // Who the bulk-reassign modal would actually move, shown once a
            // source schedule is picked and before the admin confirms — the
            // same selection bulkReassign() itself uses (employeesCurrentlyOn()),
            // so the preview can never disagree with what actually happens.
            'bulkFromEmployees' => $bulkFromSchedule ? $assigner->employeesCurrentlyOn($bulkFromSchedule) : null,
        ]);
    }
}
