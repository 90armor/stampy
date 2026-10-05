<?php

namespace App\Livewire\Employees;

use App\Exceptions\HalfDayLeaveNeedsBreakException;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Support\DisplayDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

/**
 * The employee profile's schedule-assignment history: list, assign a new
 * one (effective-dated), delete a row. Mounted directly with the employee
 * (unlike Employees\FormModal, this is only ever used from one profile page
 * at a time, not shared across a list) — but still its own Livewire
 * component with its own signed snapshot and its own update requests, which
 * never route through the parent's (Employees\Show) render(). So it follows
 * the full Authorization convention with no exception: authorize in
 * mount(), again in every mutating action, and again in render() — see
 * render()'s own comment for why that last one matters even though mount()
 * already checked once.
 */
class ScheduleAssignments extends Component
{
    public Employee $employee;

    public bool $showModal = false;

    public string $work_schedule_id = '';

    public string $effective_from = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);

        $this->employee = $employee;
    }

    public function create(): void
    {
        $this->authorize('update', $this->employee);

        $this->reset(['work_schedule_id', 'effective_from']);
        $this->effective_from = today()->format('Y-m-d');
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function assign(EmployeeScheduleAssigner $assigner): void
    {
        $this->authorize('update', $this->employee);

        $this->validate([
            'work_schedule_id' => ['required', 'exists:work_schedules,id'],
            'effective_from' => [
                'required',
                'date',
                'after_or_equal:'.$this->employee->join_date->format('Y-m-d'),
            ],
        ], [
            'effective_from.after_or_equal' => 'The effective date can\'t be before this employee\'s join date ('.DisplayDate::compact($this->employee->join_date).').',
        ], [
            'work_schedule_id' => 'schedule',
            'effective_from' => 'effective date',
        ]);

        $schedule = WorkSchedule::findOrFail($this->work_schedule_id);

        try {
            $result = $assigner->assign($this->employee, $schedule, Carbon::parse($this->effective_from));
        } catch (HalfDayLeaveNeedsBreakException $e) {
            $this->addError('work_schedule_id', $e->getMessage());

            return;
        }

        $this->showModal = false;
        $this->reset(['work_schedule_id', 'effective_from']);

        // The assignment itself always succeeded by this point (assign()
        // never rolls it back for a rebuild failure — see its own doc
        // comment) — so the modal still closes. A rebuild failure is
        // reported as a standing warning on the page, not a blocking error,
        // since there's nothing left for the admin to retry here.
        if ($result['rebuildError'] !== null) {
            $this->addError('rebuild', $result['rebuildError']);
        }

        // Attendance for this employee may have just changed (a backdated
        // assignment rebuilds through today) — let any sibling component
        // showing it (the calendar, if this is ever embedded alongside one)
        // know to refresh.
        $this->dispatch('employee-saved');
    }

    public function deleteAssignment(EmployeeWorkSchedule $assignment, DailySummaryBuilder $builder, EmployeeScheduleAssigner $assigner): void
    {
        $this->authorize('update', $this->employee);

        abort_unless($assignment->employee_id === $this->employee->id, 404);

        if ($this->employee->scheduleAssignments()->count() <= 1) {
            $this->addError('delete', 'This is the only schedule this employee has ever been assigned — every employee must have at least one.');

            return;
        }

        $effectiveFrom = $assignment->effective_from;

        // The dates it governed fall back to the neighbouring assignment's
        // schedule; refuse if that strands a half-day leave without a break.
        $remaining = $this->employee->scheduleAssignments->reject(fn (EmployeeWorkSchedule $row) => $row->is($assignment))->values();
        $stranded = $assigner->halfDaysWithoutBreak($this->employee, $remaining);

        if ($stranded !== []) {
            $fallback = $this->employee->replicate()->setRelation('scheduleAssignments', $remaining)->scheduleOn($effectiveFrom);
            $this->addError('delete', (new HalfDayLeaveNeedsBreakException($fallback->name, $stranded))->getMessage());

            return;
        }

        $assignment->delete();

        // The deleted row's dates may have been the ones in force for part
        // of the employee's history — rebuild from its effective_from
        // through today so any day that resolved through it picks up
        // whichever assignment now applies instead. $employee's own cached
        // scheduleAssignments (if this request already resolved one, e.g.
        // via render()) is now stale too — see EmployeeScheduleAssigner::
        // assign()'s identical comment.
        $this->employee->unsetRelation('scheduleAssignments');

        // The delete itself already succeeded by this point and is never
        // rolled back for a rebuild failure — same reasoning as
        // EmployeeScheduleAssigner::assign(), which this mirrors: a single
        // employee's rebuild is cheap to heal by hand, so a failure here is
        // reported, not treated as if the deletion itself failed.
        try {
            $builder->rebuildFrom($this->employee, $effectiveFrom);
        } catch (Throwable $e) {
            $message = $assigner->rebuildRecoveryMessage($effectiveFrom, "--employee={$this->employee->employee_code}");

            Log::error("Schedule assignment deletion for {$this->employee->employee_code}: rebuild failed partway ({$e->getMessage()}). {$message}");

            $this->addError('rebuild', $message);
        }

        $this->dispatch('employee-saved');
    }

    public function render()
    {
        // Every request ends here — the same reasoning as Employees\Show
        // and Attendance\Show (CLAUDE.md, Authorization convention): this
        // component holds $employee as a typed property and has a
        // read-only action (opening the modal) beyond mount(), so a page
        // opened while authorised must stop serving data once access is
        // revoked, not just refuse the mutating actions above.
        $this->authorize('view', $this->employee);

        return view('livewire.employees.schedule-assignments', [
            'assignments' => $this->employee->scheduleAssignments,
            'schedules' => WorkSchedule::query()->orderBy('name')->get(),
        ]);
    }
}
