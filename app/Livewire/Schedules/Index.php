<?php

namespace App\Livewire\Schedules;

use App\Exceptions\InvalidWorkScheduleException;
use App\Exceptions\WorkScheduleInUseException;
use App\Exceptions\WorkScheduleIsDefaultException;
use App\Exceptions\WorkScheduleLockedException;
use App\Models\Employee;
use App\Models\WorkSchedule;
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

    /** @var int[] */
    public array $workdays = [1, 2, 3, 4, 5];

    public bool $is_default = false;

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
            'grace_minutes' => ['required', 'integer', 'min:0'],
            'break_minutes' => ['required', 'integer', 'min:0'],
            'workdays' => ['required', 'array', 'min:1'],
            'workdays.*' => ['integer', 'between:1,7'],
        ]);

        $data = [
            'name' => $this->name,
            'start_time' => $this->start_time.':00',
            'end_time' => $this->end_time.':00',
            'grace_minutes' => $this->grace_minutes,
            'break_minutes' => $this->break_minutes,
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

    private function resetForm(): void
    {
        $this->reset(['editing', 'name', 'grace_minutes', 'break_minutes', 'is_default']);
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

    public function render()
    {
        return view('livewire.schedules.index', [
            'schedules' => WorkSchedule::query()->orderBy('name')->paginate(15, ['*'], 'schedulesPage'),
            'assignedCounts' => $this->currentAssignmentCounts(),
            'editingIsLocked' => $this->editing?->isReferenced() ?? false,
        ]);
    }
}
