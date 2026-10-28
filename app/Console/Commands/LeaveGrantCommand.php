<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Leave\LeaveGranter;
use Illuminate\Console\Command;

/**
 * Creates the missing leave grants for a year, as of today (LeaveGranter).
 * Scheduled daily at 00:05 (App\Console\LeaveSchedule): on 1 Jan it creates
 * the year's regular grants, on any other day the first-eligibility grants of
 * whoever reached their service requirement — and, because it looks for
 * "eligible and no row" rather than "today is the day", whatever a missed run
 * left behind. --year=<next year> grants early for everyone already eligible
 * who has no last day set.
 */
class LeaveGrantCommand extends Command
{
    protected $signature = 'leave:grant
        {--year= : The leave year to grant (defaults to the current year; a later year grants early)}';

    protected $description = 'Create the missing leave grants for a year, as of today.';

    public function handle(LeaveGranter $granter): int
    {
        $today = today();
        $year = $this->option('year') !== null ? (int) $this->option('year') : $today->year;

        if ($this->option('year') !== null && ! preg_match('/^\d{4}$/', (string) $this->option('year'))) {
            $this->error("Invalid --year \"{$this->option('year')}\".");

            return self::FAILURE;
        }

        if ($year < $today->year) {
            $this->error("--year {$year} is in the past — leave:grant only grants the current year ({$today->year}) or later.");

            return self::FAILURE;
        }

        $created = [];
        $employees = Employee::query()->activeOn($today)->orderBy('id')->get();

        foreach ($employees as $employee) {
            foreach ($granter->grant($employee, $year, $today) as $type => $count) {
                $created[$type] = ($created[$type] ?? 0) + $count;
            }
        }

        $this->info("Granted {$year} leave as of {$today->format('Y-m-d')} ({$employees->count()} active employee(s) checked).");

        if ($created === []) {
            $this->line('Nothing to grant — every eligible employee already has their grants.');
        }

        foreach ($created as $type => $count) {
            $this->line("  {$type}: {$count} created");
        }

        return self::SUCCESS;
    }
}
