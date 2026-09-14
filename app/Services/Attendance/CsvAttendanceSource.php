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
 * A bad individual row (missing fields, unparseable date) is recorded via
 * errors() and skipped rather than aborting the whole file. File-level
 * problems (missing file, missing configured column) throw
 * AttendanceImportException instead.
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

                $indexed = $hasHeader ? array_combine($header, $row) : $row;

                $deviceUserId = $indexed[$columns['device_user_id']] ?? null;
                $rawPunchedAt = $indexed[$columns['punched_at']] ?? null;
                $rawPunchType = isset($columns['punch_type']) ? ($indexed[$columns['punch_type']] ?? null) : null;

                if ($deviceUserId === null || $deviceUserId === '') {
                    $this->errors[] = ['line' => $line, 'reason' => 'missing device_user_id'];

                    continue;
                }

                if ($rawPunchedAt === null || $rawPunchedAt === '') {
                    $this->errors[] = ['line' => $line, 'reason' => 'missing punched_at'];

                    continue;
                }

                try {
                    $punchedAt = Carbon::createFromFormat($format, $rawPunchedAt);

                    if ($punchedAt === false) {
                        throw new \UnexpectedValueException;
                    }
                } catch (Throwable) {
                    $this->errors[] = ['line' => $line, 'reason' => "unparseable date: \"{$rawPunchedAt}\""];

                    continue;
                }

                $punchType = null;
                if ($rawPunchType !== null && $rawPunchType !== '' && isset($typeMap[$rawPunchType])) {
                    $punchType = PunchType::from($typeMap[$rawPunchType]);
                }

                if (! $punchedAt->between($from, $to)) {
                    continue;
                }

                yield new PunchRecord(
                    deviceUserId: (string) $deviceUserId,
                    punchedAt: $punchedAt,
                    punchType: $punchType,
                    raw: $indexed,
                );
            }
        } finally {
            fclose($handle);
        }
    }
}
