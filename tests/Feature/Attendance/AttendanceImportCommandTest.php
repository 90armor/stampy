<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = base_path('tests/Fixtures/zkteco-sample.csv');

        foreach (['1001', '1002', '1003', '1004', '1005'] as $deviceUserId) {
            Employee::factory()->create(['device_user_id' => $deviceUserId]);
        }
    }

    public function test_it_imports_a_clean_file_and_reports_a_summary(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->assertSuccessful()
            ->expectsOutputToContain('Imported: 18')
            ->expectsOutputToContain('Skipped (duplicate): 1')
            ->expectsOutputToContain('Skipped (unknown device id): 1');

        $this->assertSame(18, AttendanceLog::count());
    }

    public function test_rerunning_the_same_import_adds_nothing(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture])->assertSuccessful();
        $this->assertSame(18, AttendanceLog::count());

        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->assertSuccessful()
            ->expectsOutputToContain('Imported: 0');

        $this->assertSame(18, AttendanceLog::count());
    }

    public function test_unknown_device_ids_are_skipped_and_reported(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->assertSuccessful()
            ->expectsOutputToContain('Unknown device IDs: 9999');
    }

    public function test_a_malformed_row_is_skipped_and_the_rest_still_import(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->assertSuccessful()
            ->expectsOutputToContain('unparseable date');

        $this->assertSame(18, AttendanceLog::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Imported: 18');

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_missing_file_reports_a_clear_error_not_a_stack_trace(): void
    {
        $this->artisan('attendance:import', ['file' => base_path('tests/Fixtures/does-not-exist.csv')])
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }

    private function tempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attendance-csv');
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_one_malformed_row_does_not_stop_the_good_rows_and_the_summary_names_the_bad_lines(): void
    {
        $path = $this->tempCsv(implode("\n", [
            'user_id,timestamp,state',
            '1001,2026-01-05 08:00:00,0',   // 2  good
            '1002',                          // 3  truncated
            '1002,2026-01-05 08:10:00,0,',   // 4  trailing comma
            '1003,2026-02-30 08:00:00,0',    // 5  impossible date
            '1004,2026-01-05 09:00:00,0',    // 6  good
            '1005,2026-01-05 17:00:00,1',    // 7  good
        ])."\n");

        $this->artisan('attendance:import', ['file' => $path])
            ->expectsOutputToContain('Imported: 3')
            ->expectsOutputToContain('Line 3: wrong number of fields (expected 3, found 1)')
            ->expectsOutputToContain('Line 4: wrong number of fields (expected 3, found 4)')
            ->expectsOutputToContain('Line 5: unparseable date: "2026-02-30 08:00:00"')
            ->assertSuccessful();

        $this->assertSame(3, AttendanceLog::count());
        $this->assertSame(['1001', '1004', '1005'], AttendanceLog::with('employee')->orderBy('id')->get()->pluck('employee.device_user_id')->all());

        unlink($path);
    }

    public function test_an_unexpected_error_in_a_row_is_a_clear_failure_with_the_line_number(): void
    {
        config(['attendance.csv.punch_type_map' => ['0' => 'bogus']]);
        $path = $this->tempCsv("user_id,timestamp,state\n1001,2026-01-05 08:00:00,0\n");

        $this->artisan('attendance:import', ['file' => $path])
            ->expectsOutputToContain('line 2')
            ->assertFailed();

        $this->assertSame(0, AttendanceLog::count());

        unlink($path);
    }

}
