<?php

namespace App\Services\Attendance;

use App\Contracts\AttendanceSource;
use App\Data\PunchRecord;
use App\Enums\PunchType;
use App\Exceptions\AttendanceImportException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Reads punches from a CSV export. Column names, the punch-type value map,
 * and the datetime format all come from config/attendance.php since the
 * real device's format is unknown and will need tuning once one arrives.
 *
 * A bad individual row (wrong field count, missing fields, unparseable date)
 * is recorded via errors() and skipped rather than aborting the whole file.
 * File-level problems (missing file, missing configured column) and any error
 * the reader didn't anticipate throw AttendanceImportException instead — the
 * latter with the line number.
 */
class CsvAttendanceSource implements AttendanceSource
{
    /**
     * @var array<int, array{line: int, reason: string}>
     */
    private array $errors = [];

    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path)) {
            throw new AttendanceImportException("Attendance CSV not found: {$this->path}");
        }
    }

    /**
     * @return array<int, array{line: int, reason: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function punches(CarbonInterface $from, CarbonInterface $to): iterable
    {
        $config = config('attendance.csv');
        $columns = $config['columns'];
        $typeMap = $config['punch_type_map'] ?? [];
        $format = $config['datetime_format'];
        $hasHeader = $config['has_header'] ?? true;

        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw new AttendanceImportException("Unable to open attendance CSV: {$this->path}");
        }

        try {
            $header = null;
            $line = 0;

            if ($hasHeader) {
                $header = fgetcsv($handle);
                $line++;

                if ($header === false) {
                    throw new AttendanceImportException("Attendance CSV is empty: {$this->path}");
                }

                foreach ($columns as $field => $columnName) {
                    if (! in_array($columnName, $header, true)) {
                        throw new AttendanceImportException(
                            "Attendance CSV is missing configured column \"{$columnName}\" for \"{$field}\"."
                        );
                    }
                }
            }

            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                if ($row === [null]) {
                    continue;
                }

                try {
                    $record = $this->parseRow($row, $header, $line, $columns, $typeMap, $format);
                } catch (AttendanceImportException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new AttendanceImportException("Attendance CSV line {$line}: unexpected error — {$e->getMessage()}", 0, $e);
                }

                if ($record !== null && $record->punchedAt->between($from, $to)) {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, ?string>  $row
     * @param  ?array<int, string>  $header
     * @param  array<string, string|int>  $columns
     * @param  array<string, string>  $typeMap
     */
    private function parseRow(array $row, ?array $header, int $line, array $columns, array $typeMap, string $format): ?PunchRecord
    {
        if ($header !== null && count($row) !== count($header)) {
            $this->errors[] = ['line' => $line, 'reason' => 'wrong number of fields (expected '.count($header).', found '.count($row).')'];

            return null;
        }

        $indexed = $header !== null ? array_combine($header, $row) : $row;

        $deviceUserId = $indexed[$columns['device_user_id']] ?? null;
        $rawPunchedAt = $indexed[$columns['punched_at']] ?? null;
        $rawPunchType = isset($columns['punch_type']) ? ($indexed[$columns['punch_type']] ?? null) : null;

        if ($deviceUserId === null || $deviceUserId === '') {
            $this->errors[] = ['line' => $line, 'reason' => 'missing device_user_id'];

            return null;
        }

        if ($rawPunchedAt === null || $rawPunchedAt === '') {
            $this->errors[] = ['line' => $line, 'reason' => 'missing punched_at'];

            return null;
        }

        try {
            $punchedAt = Carbon::createFromFormat($format, $rawPunchedAt);

            if ($punchedAt === false) {
                throw new \UnexpectedValueException;
            }
        } catch (Throwable) {
            $this->errors[] = ['line' => $line, 'reason' => "unparseable date: \"{$rawPunchedAt}\""];

            return null;
        }

        $punchType = null;
        if ($rawPunchType !== null && $rawPunchType !== '' && isset($typeMap[$rawPunchType])) {
            $punchType = PunchType::from($typeMap[$rawPunchType]);
        }

        return new PunchRecord(
            deviceUserId: (string) $deviceUserId,
            punchedAt: $punchedAt,
            punchType: $punchType,
            raw: $indexed,
        );
    }
}
