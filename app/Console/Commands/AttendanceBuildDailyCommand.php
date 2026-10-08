<?php

namespace App\Console\Commands;

use App\Exceptions\NoScheduleAssignmentException;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Overtime\TimeOffInLieuReconciler;
use App\Support\StrictDate;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AttendanceBuildDailyCommand extends Command
{
    protected $signature = 'attendance:build-daily
        {--date= : Build a single date (Y-m-d)}
        {--from= : Start of a date range (Y-m-d) — requires --to}
        {--to= : End of a date range (Y-m-d) — requires --from}
        {--employee= : Limit to one employee, by id or employee_code}';

    protected $description = 'Recompute daily_attendances from attendance_logs. Defaults to yesterday and today.';

    public function handle(DailySummaryBuilder $builder, TimeOffInLieuReconciler $reconciler): int
    {
        [$from, $to] = $this->resolveRange();

        if ($from === null) {
            return self::FAILURE;
        }

        $employees = $this->resolveEmployees($from, $to);

        if ($employees === null) {
            return self::FAILURE;
        }

        // Time off in lieu is reconciled for everyone built here who has any
        // time-off overtime, changed or not (Phase 4b): idempotent, so this is
        // how the scheduled runs heal a reconciliation that failed earlier.
        $toilEmployees = $reconciler->employeesWithTimeOffOvertime($employees->pluck('id')->all());

        $created = 0;
        $updated = 0;
        $notEmployed = 0;
        $byStatus = [];
        $skipped = [];

        foreach ($employees as $employee) {
            // join_date is the only hire/start-date column on employees —
            // don't build days before someone was hired.
            $date = $employee->join_date->gt($from) ? $employee->join_date->copy() : $from->copy();

            try {
                // Leaves and overtime are loaded once per employee for the whole run, not per day (Phase 3d, 4b).
                $builder->buildDates($employee, $date, $to, reconcile: false, each: function (?DailyAttendance $row) use (&$notEmployed, &$created, &$updated, &$byStatus) {
                    // After left_on: the builder removed any row instead.
                    if ($row === null) {
                        $notEmployed++;

                        return;
                    }

                    $row->wasRecentlyCreated ? $created++ : $updated++;

                    $statusValue = $row->status->value;
                    $byStatus[$statusValue] = ($byStatus[$statusValue] ?? 0) + 1;
                });

                if (in_array($employee->id, $toilEmployees, true)) {
                    $builder->reconcileToil($employee);
                }
            } catch (NoScheduleAssignmentException) {
                // Caught per employee, not around the whole loop: one
                // employee with zero assignment rows (a data-integrity bug,
                // not a normal "nothing configured yet" case — see the
                // exception's own doc comment) shouldn't stop every other
                // employee in the run from being built. Move on and name
                // every affected employee at the end, not just the first.
                $skipped[] = "{$employee->employee_code} ({$employee->full_name})";

                continue;
            }
        }

        $this->info("Built daily attendance for {$from->format('Y-m-d')} to {$to->format('Y-m-d')} ({$employees->count()} employee(s)).");
        $this->line("Created: {$created}");
        $this->line("Updated: {$updated}");

        if ($notEmployed > 0) {
            $this->line("Not employed (no row kept): {$notEmployed}");
        }

        foreach ($byStatus as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        if (($unconfigured = $builder->reportToil()) > 0) {
            $this->warn("{$unconfigured} employee(s) have time-off overtime but no TOIL leave type is set (overtime_settings.toil_leave_type_id) — nothing was credited.");
        }

        if ($skipped !== []) {
            $this->error(count($skipped).' employee(s) have no work schedule assignment at all and were skipped — this is a data-integrity problem, not a missing default (see NoScheduleAssignmentException):');

            foreach ($skipped as $description) {
                $this->line("  {$description}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveRange(): array
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($date !== null) {
            if ($from !== null || $to !== null) {
                $this->error('Use either --date or --from/--to, not both.');

                return [null, null];
            }

            $parsed = $this->parseDate($date, '--date');

            return $parsed ? [$parsed, $parsed->copy()] : [null, null];
        }

        if ($from !== null || $to !== null) {
            if ($from === null || $to === null) {
                $this->error('--from and --to must be given together.');

                return [null, null];
            }

            $fromDate = $this->parseDate($from, '--from');
            $toDate = $this->parseDate($to, '--to');

            if ($fromDate === null || $toDate === null) {
                return [null, null];
            }

            if ($fromDate->gt($toDate)) {
                $this->error('--from must not be after --to.');

                return [null, null];
            }

            return [$fromDate, $toDate];
        }

        return [Carbon::yesterday(), Carbon::today()];
    }

    private function parseDate(string $value, string $option): ?Carbon
    {
        try {
            $parsed = StrictDate::parse('Y-m-d', $value)->startOfDay();
        } catch (InvalidArgumentException $e) {
            $this->error("Invalid {$option} value \"{$value}\" — {$e->getMessage()}.");

            return null;
        }

        if ($parsed->gt(Carbon::today())) {
            $this->error("{$option} {$value} is in the future (today is ".Carbon::today()->format('Y-m-d').') — attendance can only be built up to and including today.');

            return null;
        }

        return $parsed;
    }

    /**
     * @return ?Collection<int, Employee>
     */
    private function resolveEmployees(Carbon $from, Carbon $to): ?Collection
    {
        $employeeOption = $this->option('employee');

        // Everyone employed on at least one date in the range; per date the
        // builder asks isActiveOn() and removes a row after left_on. A named
        // employee is taken whatever their status — that's how a failed
        // deactivation rebuild is healed (EmployeeLifecycle).
        $query = $employeeOption === null
            ? Employee::query()->activeBetween($from, $to)
            : Employee::query()->where(function ($q) use ($employeeOption) {
                $q->where('id', $employeeOption)->orWhere('employee_code', $employeeOption);
            });

        $employees = $query->get();

        if ($employeeOption !== null && $employees->isEmpty()) {
            $this->error("No employee found matching \"{$employeeOption}\".");

            return null;
        }

        return $employees;
    }
}
