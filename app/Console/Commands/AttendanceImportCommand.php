<?php

namespace App\Console\Commands;

use App\Enums\PunchSource;
use App\Exceptions\AttendanceImportException;
use App\Services\Attendance\CsvAttendanceSource;
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

    public function handle(PunchIngestor $ingestor): int
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

        $this->info($dryRun ? 'Dry run — nothing written.' : 'Import complete.');
        $this->line("Imported: {$summary->imported}");
        $this->line("Skipped (duplicate): {$summary->skippedDuplicate}");
        $this->line("Skipped (unknown device id): {$summary->skippedUnknown}");

        if ($summary->unknownDeviceIds !== []) {
            $this->line('Unknown device IDs: '.implode(', ', $summary->unknownDeviceIds));
        }

        foreach ($csv->errors() as $error) {
            $this->warn("Line {$error['line']}: {$error['reason']}");
        }

        return self::SUCCESS;
    }
}
