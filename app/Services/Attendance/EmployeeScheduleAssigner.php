<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * The one place an employee's schedule assignment is created or changed
 * outside of Employee::booted()'s creation-time default assignment — used
 * by the per-employee assignment action and the Schedules tab's bulk
 * reassignment. Every assignment change is followed by a rebuild from the
 * effective date to today (DailySummaryBuilder::rebuildFrom(), clamped the
 * same way rebuildAround() clamps its own window), since it can rewrite any
 * already-built day from that date forward.
 */
class EmployeeScheduleAssigner
{
    public function __construct(private DailySummaryBuilder $builder) {}

    /**
     * Upserts on (employee_id, effective_from): reassigning at a date that
     * already has a row for this employee replaces it rather than creating
     * a second, colliding one.
     *
     * @return int days rebuilt (0 for a future-dated assignment)
     */
    public function assign(Employee $employee, WorkSchedule $schedule, CarbonInterface $effectiveFrom): int
    {
        EmployeeWorkSchedule::updateOrCreate(
            ['employee_id' => $employee->id, 'effective_from' => $effectiveFrom->format('Y-m-d')],
            ['work_schedule_id' => $schedule->id, 'created_by' => Auth::id()],
        );

        // scheduleAssignments() caches on the Employee instance after its first
        // access (see Employee::scheduleAssignments()'s own doc comment) — if
        // this $employee was already used to resolve a schedule before this
        // write (bulkReassign()'s own filter step does exactly that, on the
        // very same instances it then calls assign() on), that cache is now
        // stale. Drop it so rebuildFrom()'s build() calls, just below, and
        // anything the caller does with $employee afterwards, see the change.
        $employee->unsetRelation('scheduleAssignments');

        return $this->builder->rebuildFrom($employee, $effectiveFrom);
    }

    /**
     * Moves every active employee currently on $from (per scheduleOn(today()),
     * the same resolution the builder itself uses) to $to, effective
     * $effectiveFrom — inactive employees are left alone, matching
     * attendance:build-daily's own active-only scope; their schedule no
     * longer affects anything that gets built.
     *
     * @return array{employees: int, days: int}
     */
    public function bulkReassign(WorkSchedule $from, WorkSchedule $to, CarbonInterface $effectiveFrom): array
    {
        $employees = Employee::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (Employee $employee) => $employee->scheduleOn(today())->id === $from->id);

        $days = 0;

        foreach ($employees as $employee) {
            $days += $this->assign($employee, $to, $effectiveFrom);
        }

        return ['employees' => $employees->count(), 'days' => $days];
    }
}
