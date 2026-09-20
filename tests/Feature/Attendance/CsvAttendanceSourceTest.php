<?php

namespace Tests\Feature\Attendance;

use App\Data\PunchRecord;
use App\Enums\PunchType;
use App\Exceptions\AttendanceImportException;
use App\Services\Attendance\CsvAttendanceSource;
use Carbon\Carbon;
use Tests\TestCase;

class CsvAttendanceSourceTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): CsvAttendanceSource
    {
        $path = tempnam(sys_get_temp_dir(), 'attendance-csv');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new CsvAttendanceSource($path);
    }

    /**
     * @return list<PunchRecord>
     */
    private function punches(CsvAttendanceSource $source, string $from = '2000-01-01', string $to = '2100-01-01'): array
    {
        return array_values(iterator_to_array($source->punches(Carbon::parse($from), Carbon::parse($to)), false));
    }

    public function test_a_missing_file_is_rejected_when_the_source_is_built(): void
    {
        $this->expectException(AttendanceImportException::class);
        $this->expectExceptionMessage('Attendance CSV not found: /no/such/file.csv');

        new CsvAttendanceSource('/no/such/file.csv');
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $this->expectException(AttendanceImportException::class);
        $this->expectExceptionMessage('Attendance CSV is empty');

        $this->punches($this->csv(''));
    }

    public function test_a_header_missing_a_configured_column_is_rejected_naming_the_column(): void
    {
        $this->expectException(AttendanceImportException::class);
        $this->expectExceptionMessage('Attendance CSV is missing configured column "state" for "punch_type".');

        $this->punches($this->csv("user_id,timestamp\n1001,2026-01-05 08:52:00\n"));
    }

    public function test_rows_are_parsed_with_the_configured_types(): void
    {
        $source = $this->csv("user_id,timestamp,state\n1001,2026-01-05 08:52:00,0\n1001,2026-01-05 17:03:00,1\n");

        $punches = $this->punches($source);

        $this->assertCount(2, $punches);
        $this->assertSame('1001', $punches[0]->deviceUserId);
        $this->assertSame('2026-01-05 08:52:00', $punches[0]->punchedAt->format('Y-m-d H:i:s'));
        $this->assertSame(PunchType::In, $punches[0]->punchType);
        $this->assertSame(PunchType::Out, $punches[1]->punchType);
        $this->assertSame([], $source->errors());
    }

    public function test_an_unmapped_or_blank_state_leaves_the_type_for_inference(): void
    {
        $punches = $this->punches($this->csv("user_id,timestamp,state\n1001,2026-01-05 08:00:00,7\n1001,2026-01-05 09:00:00,\n"));

        $this->assertCount(2, $punches);
        $this->assertNull($punches[0]->punchType);
        $this->assertNull($punches[1]->punchType);
    }

    public function test_a_file_without_a_punch_type_column_configured_still_imports(): void
    {
        config(['attendance.csv.columns' => ['device_user_id' => 'user_id', 'punched_at' => 'timestamp']]);

        $punches = $this->punches($this->csv("user_id,timestamp\n1001,2026-01-05 08:00:00\n"));

        $this->assertCount(1, $punches);
        $this->assertNull($punches[0]->punchType);
    }

    public function test_a_headerless_file_is_read_by_column_position(): void
    {
        config([
            'attendance.csv.has_header' => false,
            'attendance.csv.columns' => ['device_user_id' => 0, 'punched_at' => 1, 'punch_type' => 2],
        ]);

        $punches = $this->punches($this->csv("1001,2026-01-05 08:52:00,0\n1002,2026-01-05 17:03:00,1\n"));

        // The first line is data, not a header to skip.
        $this->assertCount(2, $punches);
        $this->assertSame('1001', $punches[0]->deviceUserId);
        $this->assertSame(PunchType::In, $punches[0]->punchType);
        $this->assertSame('1002', $punches[1]->deviceUserId);
        $this->assertSame(PunchType::Out, $punches[1]->punchType);
    }

    public function test_blank_lines_are_skipped_but_still_counted_in_error_line_numbers(): void
    {
        $source = $this->csv("user_id,timestamp,state\n1001,2026-01-05 08:52:00,0\n\n,2026-01-05 09:00:00,0\n");

        $punches = $this->punches($source);

        $this->assertCount(1, $punches);
        // Line 1 header, line 2 valid, line 3 blank, line 4 the bad row.
        $this->assertSame([['line' => 4, 'reason' => 'missing device_user_id']], $source->errors());
    }

    public function test_a_row_with_a_blank_device_user_id_is_skipped_and_reported(): void
    {
        $source = $this->csv("user_id,timestamp,state\n,2026-01-05 08:52:00,0\n");

        $this->assertSame([], $this->punches($source));
        $this->assertSame([['line' => 2, 'reason' => 'missing device_user_id']], $source->errors());
    }

    public function test_a_row_with_a_blank_timestamp_is_skipped_and_reported(): void
    {
        $source = $this->csv("user_id,timestamp,state\n1001,,0\n");

        $this->assertSame([], $this->punches($source));
        $this->assertSame([['line' => 2, 'reason' => 'missing punched_at']], $source->errors());
    }

    public function test_a_bad_row_does_not_stop_the_rest_of_the_file(): void
    {
        $source = $this->csv("user_id,timestamp,state\n1001,2026-01-05 08:00:00,0\n,2026-01-05 09:00:00,0\n1001,2026-01-05 17:00:00,1\n");

        $punches = $this->punches($source);

        $this->assertSame(['08:00:00', '17:00:00'], array_map(fn ($p) => $p->punchedAt->format('H:i:s'), $punches));
        $this->assertCount(1, $source->errors());
    }

    public function test_punches_outside_the_requested_range_are_skipped_without_an_error(): void
    {
        $source = $this->csv("user_id,timestamp,state\n1001,2026-01-04 08:00:00,0\n1001,2026-01-05 08:00:00,0\n1001,2026-01-06 08:00:00,0\n");

        $punches = $this->punches($source, '2026-01-05 00:00:00', '2026-01-05 23:59:59');

        $this->assertCount(1, $punches);
        $this->assertSame('2026-01-05 08:00:00', $punches[0]->punchedAt->format('Y-m-d H:i:s'));
        $this->assertSame([], $source->errors());
    }

}
