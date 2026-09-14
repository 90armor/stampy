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
}
