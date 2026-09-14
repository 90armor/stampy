<?php

namespace App\Services\Attendance;

use App\Contracts\AttendanceSource;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves raw punches (identified by deviceUserId) to employees and writes
 * attendance_logs rows. This is the single write path into attendance_logs —
 * sources only produce PunchRecords, they never touch the database.
 */
class PunchIngestor
{
    public function ingest(
        AttendanceSource $source,
        CarbonInterface $from,
        CarbonInterface $to,
        PunchSource $logSource,
        bool $dryRun = false,
    ): IngestionSummary {
        $employeeIdsByDeviceId = Employee::query()
            ->whereNotNull('device_user_id')
            ->pluck('id', 'device_user_id');

        $resolved = [];
        $unknownDeviceIds = [];
        $skippedUnknown = 0;

        foreach ($source->punches($from, $to) as $record) {
            $employeeId = $employeeIdsByDeviceId[$record->deviceUserId] ?? null;

            if ($employeeId === null) {
                $skippedUnknown++;
                $unknownDeviceIds[$record->deviceUserId] = true;

                continue;
            }

            $resolved[] = [
                'employee_id' => $employeeId,
                'punched_at' => $record->punchedAt,
                'punch_type' => $record->punchType,
                'raw' => $record->raw,
            ];
        }

        if ($resolved === []) {
            return new IngestionSummary(0, 0, $skippedUnknown, array_keys($unknownDeviceIds));
        }

        $this->inferMissingPunchTypes($resolved);

        [$imported, $skippedDuplicate, $rows] = $this->deduplicateAndPrepare($resolved, $logSource);

        if (! $dryRun && $rows !== []) {
            foreach (array_chunk($rows, 500) as $chunk) {
                // The unique index on (employee_id, punched_at, source) is the
                // structural guarantee against duplicates — insertOrIgnore lets
                // that hold even if the pre-check above raced with another
                // import, rather than only being a best-effort PHP-side check.
                AttendanceLog::query()->insertOrIgnore($chunk);
            }
        }

        return new IngestionSummary($imported, $skippedDuplicate, $skippedUnknown, array_keys($unknownDeviceIds));
    }

    /**
     * For any resolved punch missing a type, infer it from its position in
     * that employee's full chronological punch list for the day (existing
     * DB rows across all sources, merged with the rest of this batch).
     *
     * A plain per-punch alternation breaks on a double-tap: 08:00, 08:00:08,
     * 17:00 would infer in, out, in. So punches within
     * config('attendance.duplicate_window_seconds') of the *previous* punch
     * in the timeline are chained into one group first; the group (not the
     * punch) is what alternates, so the example above becomes in, in, out.
     * Every punch is still stored — this only changes what type gets
     * assigned to the ones that arrived with none.
     *
     * @param  array<int, array{employee_id: int, punched_at: CarbonInterface, punch_type: ?PunchType, raw: array}>  $resolved
     */
    private function inferMissingPunchTypes(array &$resolved): void
    {
        $needsInference = array_filter($resolved, fn (array $r) => $r['punch_type'] === null);

        if ($needsInference === []) {
            return;
        }

        $windowSeconds = config('attendance.duplicate_window_seconds', 90);

        $employeeIds = array_unique(array_column($resolved, 'employee_id'));

        $punchTimes = array_column($resolved, 'punched_at');
        $from = Carbon::instance(min($punchTimes))->startOfDay();
        $to = Carbon::instance(max($punchTimes))->endOfDay();

        $existingByEmployeeDay = AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('punched_at', [$from, $to])
            ->get(['employee_id', 'punched_at'])
            ->groupBy(fn (AttendanceLog $log) => $log->employee_id.'|'.$log->punched_at->format('Y-m-d'));

        $newByEmployeeDay = [];
        foreach ($resolved as $index => $r) {
            $key = $r['employee_id'].'|'.$r['punched_at']->format('Y-m-d');
            $newByEmployeeDay[$key][] = $index;
        }

        foreach ($newByEmployeeDay as $key => $indices) {
            $timeline = ($existingByEmployeeDay[$key] ?? collect())
                ->map(fn (AttendanceLog $log) => ['index' => null, 'time' => $log->punched_at])
                ->values()
                ->all();

            foreach ($indices as $index) {
                $timeline[] = ['index' => $index, 'time' => $resolved[$index]['punched_at']];
            }

            usort($timeline, fn (array $a, array $b) => $a['time'] <=> $b['time']);

            $group = -1;
            $previousTime = null;

            foreach ($timeline as $entry) {
                if ($previousTime === null || $entry['time']->getTimestamp() - $previousTime->getTimestamp() > $windowSeconds) {
                    $group++;
                }

                $previousTime = $entry['time'];

                if ($entry['index'] === null || $resolved[$entry['index']]['punch_type'] !== null) {
                    continue;
                }

                $resolved[$entry['index']]['punch_type'] = $group % 2 === 0 ? PunchType::In : PunchType::Out;
            }
        }
    }

    /**
     * Idempotency check only — an exact match on (employee_id, punched_at,
     * source). attendance_logs is the raw, append-only record of what the
     * device reported and can never be recomputed, so nothing here collapses
     * punches that are merely close together (e.g. a double-tap) — that's a
     * business rule for the daily-summary calculation, not ingestion.
     *
     * @param  array<int, array{employee_id: int, punched_at: CarbonInterface, punch_type: ?PunchType, raw: array}>  $resolved
     * @return array{0: int, 1: int, 2: array<int, array<string, mixed>>}
     */
    private function deduplicateAndPrepare(array $resolved, PunchSource $logSource): array
    {
        $employeeIds = array_unique(array_column($resolved, 'employee_id'));
        $punchedAtStrings = array_unique(array_map(
            fn (CarbonInterface $time) => $time->format('Y-m-d H:i:s'),
            array_column($resolved, 'punched_at'),
        ));

        $existingKeys = AttendanceLog::query()
            ->where('source', $logSource->value)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('punched_at', $punchedAtStrings)
            ->get(['employee_id', 'punched_at'])
            ->map(fn (AttendanceLog $log) => $log->employee_id.'|'.$log->punched_at->format('Y-m-d H:i:s'))
            ->flip()
            ->all();

        $imported = 0;
        $skippedDuplicate = 0;
        $rows = [];
        $now = Carbon::now()->format('Y-m-d H:i:s');

        foreach ($resolved as $r) {
            $punchedAt = $r['punched_at']->format('Y-m-d H:i:s');
            $key = $r['employee_id'].'|'.$punchedAt;

            if (isset($existingKeys[$key])) {
                $skippedDuplicate++;

                continue;
            }

            // Also catches an exact duplicate appearing twice within this
            // same batch (e.g. a repeated row in an import file).
            $existingKeys[$key] = true;
            $imported++;

            $rows[] = [
                'employee_id' => $r['employee_id'],
                'punched_at' => $punchedAt,
                'punch_type' => $r['punch_type']->value,
                'source' => $logSource->value,
                'device_id' => null,
                'created_by' => null,
                'raw' => $r['raw'] === [] ? null : json_encode($r['raw']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [$imported, $skippedDuplicate, $rows];
    }
}
