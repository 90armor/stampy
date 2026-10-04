<?php

namespace App\Services\Attendance;

use App\Exceptions\BulkReassignmentTooFarBackException;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place an employee's schedule assignment is created or changed
 * outside of Employee::booted()'s creation-time default assignment — used
 * by the per-employee assignment action and the Schedules tab's bulk
 * reassignment. Every assignment change is followed by a rebuild from the
 * effective date to today (DailySummaryBuilder::rebuildFrom(), clamped the
 * same way rebuildAround() clamps its own window), since it can rewrite any
 * already-built day from that date forward.
 *
 * The assignment write and the rebuild are deliberately two separate steps,
 * in that order, everywhere in this class: an assignment row is always safe
 * to write (a single row, or — for bulkReassign() — every row for the move
 * in one transaction), while the rebuild can legitimately fail partway (a
 * deploy restart, a DB hiccup, any exception) without corrupting anything,
 * because daily_attendances is derived and always recomputable (CLAUDE.md).
 * A rebuild failure is reported, logged, and named with the exact command
 * to heal it — never allowed to leave an assignment half-written instead.
 */
class EmployeeScheduleAssigner
{
    /**
     * How far back a *bulk* move may be dated. Per-employee assignment has
     * no such cap (see assign() below) — the risk this exists for is
     * specifically the multiplication of employees × days a bulk move
     * rebuilds. Measured on the dev database: 200 employees 60 days back
     * took ~15s; 200 employees 365 days back took ~88s, past nginx's
     * default 60s fastcgi_read_timeout in this app's own docker-compose
     * stack (see BulkReassignmentTooFarBackException for the full
     * reasoning). 60 was chosen with margin: at that width, roughly 800
     * employees would be needed to reach the same risk. Public: Schedules\
     * Index reuses this to validate bulk_effective_from inline, before the
     * request ever reaches bulkReassign() below — the two checks must stay
     * in sync, so there's exactly one number, not two.
     */
    public const MAX_BULK_LOOKBACK_DAYS = 60;

    public function __construct(private DailySummaryBuilder $builder) {}

    /**
     * Upserts on (employee_id, effective_from): reassigning at a date that
     * already has a row for this employee replaces it rather than creating
     * a second, colliding one.
     *
     * @return array{days: int, rebuildError: ?string} days rebuilt (0 if the
     *                                                 assignment is future-dated, or if the rebuild itself failed —
     *                                                 see rebuildError). The assignment write above already
     *                                                 succeeded either way and is never rolled back for a rebuild
     *                                                 failure: it's a single row, and the affected range is at most
     *                                                 one employee's, cheap to heal by hand (the error message
     *                                                 below names the exact command).
     */
    public function assign(Employee $employee, WorkSchedule $schedule, CarbonInterface $effectiveFrom): array
    {
        EmployeeWorkSchedule::updateOrCreate(
            ['employee_id' => $employee->id, 'effective_from' => $effectiveFrom->format('Y-m-d')],
            ['work_schedule_id' => $schedule->id, 'created_by' => Auth::id()],
        );

        // scheduleAssignments() caches on the Employee instance after its first
        // access (see Employee::scheduleAssignments()'s own doc comment) — if
        // this $employee was already used to resolve a schedule before this
        // write, that cache is now stale. Drop it so rebuildFrom()'s build()
        // calls, just below, and anything the caller does with $employee
        // afterwards, see the change.
        $employee->unsetRelation('scheduleAssignments');

        try {
            return ['days' => $this->builder->rebuildFrom($employee, $effectiveFrom), 'rebuildError' => null];
        } catch (Throwable $e) {
            $message = $this->rebuildRecoveryMessage($effectiveFrom, "--employee={$employee->employee_code}");

            Log::error("Schedule assignment for {$employee->employee_code}: rebuild failed partway ({$e->getMessage()}). {$message}");

            return ['days' => 0, 'rebuildError' => $message];
        }
    }

    /**
     * Every active employee currently on $schedule (per scheduleOn(today())
     * — the same resolution the builder itself uses, not a separate SQL
     * approximation). The one definition of "who bulkReassign() would move"
     * — Schedules\Index's bulk-reassign modal calls this too, to preview
     * who's affected before the admin confirms, so the preview and the
     * actual move can never quietly disagree about who counts.
     *
     * @return Collection<int, Employee>
     */
    public function employeesCurrentlyOn(WorkSchedule $schedule): Collection
    {
        return Employee::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (Employee $employee) => $employee->scheduleOn(today())->id === $schedule->id)
            ->values();
    }

    /**
     * Moves every active employee currently on $from (per scheduleOn(today()),
     * the same resolution the builder itself uses) to $to, effective
     * $effectiveFrom — inactive employees are left alone: a bulk move is
     * about who is on the schedule now, and a former employee's days up to
     * their left_on keep the schedule they had.
     *
     * @return array{employees: int, days: int, rebuildError: ?string}
     *
     * @throws BulkReassignmentTooFarBackException
     */
    public function bulkReassign(WorkSchedule $from, WorkSchedule $to, CarbonInterface $effectiveFrom): array
    {
        if ($effectiveFrom->startOfDay()->lt(today()->subDays(self::MAX_BULK_LOOKBACK_DAYS))) {
            throw new BulkReassignmentTooFarBackException($effectiveFrom, self::MAX_BULK_LOOKBACK_DAYS);
        }

        $employees = $this->employeesCurrentlyOn($from);

        // Every assignment, written in one transaction, BEFORE any
        // rebuilding starts: the admin's intent either lands completely or
        // not at all. This is what makes the operation retry-safe. If this
        // only wrote SOME of the assignments and something interrupted it
        // there, a second attempt would select a different (smaller, wrong)
        // set of "currently on A" employees — the ones already moved
        // wouldn't match "on A" any more, so a retry could never reach
        // them. Writing every assignment up front means a retry is never
        // needed for THIS half: only the rebuild below can still fail, and
        // that's healed by re-running attendance:build-daily on the same
        // range, not by calling this again.
        DB::transaction(function () use ($employees, $to, $effectiveFrom) {
            foreach ($employees as $employee) {
                EmployeeWorkSchedule::updateOrCreate(
                    ['employee_id' => $employee->id, 'effective_from' => $effectiveFrom->format('Y-m-d')],
                    ['work_schedule_id' => $to->id, 'created_by' => Auth::id()],
                );

                $employee->unsetRelation('scheduleAssignments');
            }
        });

        $days = 0;
        $rebuildError = null;

        try {
            foreach ($employees as $employee) {
                $days += $this->builder->rebuildFrom($employee, $effectiveFrom);
            }
        } catch (Throwable $e) {
            $rebuildError = $this->rebuildRecoveryMessage($effectiveFrom);

            Log::error("Bulk schedule reassignment: rebuild failed partway, after all {$employees->count()} assignment(s) were already written ({$e->getMessage()}). {$rebuildError}");
        }

        return ['employees' => $employees->count(), 'days' => $days, 'rebuildError' => $rebuildError];
    }

    /**
     * Names exactly what to run to finish the job — with a 60-day bulk cap,
     * the stale window a failed rebuild can leave behind may reach 60 days,
     * well past the nightly scheduled rebuild's own 7-day healing window
     * (CLAUDE.md, "daily_attendances builds itself"), so this can't be left
     * to heal itself. Public: Employees\ScheduleAssignments::deleteAssignment()
     * reuses this for the same message after its own rebuildFrom() call,
     * rather than a second copy of the same wording drifting out of sync.
     */
    public function rebuildRecoveryMessage(CarbonInterface $effectiveFrom, string $employeeOption = ''): string
    {
        $from = $effectiveFrom->format('Y-m-d');
        $to = today()->format('Y-m-d');
        $employeeFlag = $employeeOption === '' ? '' : " {$employeeOption}";

        return "Not every affected day may have been rebuilt. Run: php artisan attendance:build-daily --from={$from} --to={$to}{$employeeFlag}";
    }
}
