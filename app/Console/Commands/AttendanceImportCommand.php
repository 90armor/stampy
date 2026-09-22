<?php

namespace App\Console\Commands;

use App\Enums\PunchSource;
use App\Exceptions\AttendanceImportException;
use App\Exceptions\NoScheduleAssignmentException;
use App\Models\Employee;
use App\Services\Attendance\CsvAttendanceSource;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\IngestionSummary;
use App\Services\Attendance\PunchIngestor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ValueError;

class AttendanceImportCommand extends Command
{
    protected $signature = 'attendance:import {file : Path to the CSV file}
        {--source=import : One of device, import, manual — stored on the resulting attendance_logs rows}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Import attendance punches from a CSV file';

    public function handle(PunchIngestor $ingestor, DailySummaryBuilder $builder): int
    {
        $path = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $logSource = PunchSource::from($this->option('source'));
        } catch (ValueError) {
            $valid = implode(', ', array_map(fn ($case) => $case->value, PunchSource::cases()));
            $this->error("Invalid --source \"{$this->option('source')}\". Expected one of: {$valid}.");

            return self::FAILURE;
        }

        try {
            $csv = new CsvAttendanceSource($path);

            $summary = $ingestor->ingest(
                $csv,
                Carbon::createFromTimestamp(0),
                Carbon::now()->addCentury(),
                $logSource,
                $dryRun,
            );
        } catch (AttendanceImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rebuildFailure = null;
        $rebuilt = null;

        if (! $dryRun && $summary->earliestImported !== null) {
            try {
                $rebuilt = $this->rebuild($builder, $summary);
            } catch (NoScheduleAssignmentException $e) {
                $rebuildFailure = $e->getMessage();
            }
        }

        $this->info($dryRun ? 'Dry run — nothing written.' : 'Import complete.');
        $this->line("Imported: {$summary->imported}");
        $this->line("Skipped (duplicate): {$summary->skippedDuplicate}");
        $this->line("Skipped (unknown device id): {$summary->skippedUnknown}");

        if ($summary->unknownDeviceIds !== []) {
            $this->line('Unknown device IDs: '.implode(', ', $summary->unknownDeviceIds));
        }

        if ($rebuilt !== null) {
            $this->line("Rebuilt daily attendance: {$rebuilt['from']} to {$rebuilt['to']} ({$rebuilt['employees']} employee(s), {$rebuilt['days']} day(s)).");
        } elseif (! $dryRun && $rebuildFailure === null) {
            $this->line('No new punches — nothing to rebuild.');
        }

        foreach ($csv->errors() as $error) {
            $this->warn("Line {$error['line']}: {$error['reason']}");
        }

        if ($rebuildFailure !== null) {
            $this->error('The punches were imported, but daily attendance could not be rebuilt: '.$rebuildFailure);
            $this->line('Run attendance:build-daily for the imported dates once that is fixed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Once, at the end: from the earliest to the latest newly imported punch,
     * for every active employee (so a person with no punches in the window is
     * marked absent rather than left "not calculated"). The window is widened
     * by a day each side and clamped to each employee's history by
     * DailySummaryBuilder::rebuildAround() — the same rule manual punches use.
     *
     * @return array{from: string, to: string, employees: int, days: int}
     */
    private function rebuild(DailySummaryBuilder $builder, IngestionSummary $summary): array
    {
        $employees = Employee::query()->where('status', 'active')->get();
        $days = 0;

        foreach ($employees as $employee) {
            $days += $builder->rebuildAround($employee, $summary->earliestImported, $summary->latestImported);
        }

        return [
            'from' => $summary->earliestImported->copy()->startOfDay()->subDay()->format('Y-m-d'),
            'to' => $summary->latestImported->copy()->startOfDay()->addDay()->min(today())->format('Y-m-d'),
            'employees' => $employees->count(),
            'days' => $days,
        ];
    }
}
