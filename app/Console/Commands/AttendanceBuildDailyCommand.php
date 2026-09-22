<?php

namespace App\Console\Commands;

use App\Exceptions\NoScheduleAssignmentException;
use App\Models\Employee;
use App\Services\Attendance\DailySummaryBuilder;
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

    public function handle(DailySummaryBuilder $builder): int
    {
        [$from, $to] = $this->resolveRange();

        if ($from === null) {
            return self::FAILURE;
        }

        $employees = $this->resolveEmployees();

        if ($employees === null) {
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $byStatus = [];
        $skipped = [];

        foreach ($employees as $employee) {
            // join_date is the only hire/start-date column on employees —
            // don't build days before someone was hired.
            $date = $employee->join_date->gt($from) ? $employee->join_date->copy() : $from->copy();

            try {
                while ($date->lte($to)) {
                    $row = $builder->build($employee, $date);

                    $row->wasRecentlyCreated ? $created++ : $updated++;

                    $statusValue = $row->status->value;
                    $byStatus[$statusValue] = ($byStatus[$statusValue] ?? 0) + 1;

                    $date = $date->copy()->addDay();
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

        foreach ($byStatus as $status => $count) {
            $this->line("  {$status}: {$count}");
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
    private function resolveEmployees(): ?Collection
    {
        $query = Employee::query()->where('status', 'active');

        $employeeOption = $this->option('employee');

        if ($employeeOption !== null) {
            $query->where(function ($q) use ($employeeOption) {
                $q->where('id', $employeeOption)->orWhere('employee_code', $employeeOption);
            });
        }

        $employees = $query->get();

        if ($employeeOption !== null && $employees->isEmpty()) {
            $this->error("No active employee found matching \"{$employeeOption}\".");

            return null;
        }

        return $employees;
    }
}
