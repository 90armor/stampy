<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\RefusingStreamWrapper;
use Tests\TestCase;

class AttendanceImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = base_path('tests/Fixtures/zkteco-sample.csv');

        // An import now rebuilds the days it touched, which needs a schedule to measure against.
        WorkSchedule::factory()->create(['is_default' => true]);

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

    /**
     * Raw hardware facts are never dropped: a punch after the employee's last
     * day is stored, counted in a warning line, and builds no row.
     */
    public function test_a_punch_outside_the_employment_period_is_kept_warned_about_and_builds_no_row(): void
    {
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));
        $leaver = Employee::factory()->inactive('2026-04-10')->create(['device_user_id' => '2001']);

        $path = $this->tempCsv("user_id,timestamp,state\n"
            ."2001,2026-04-09 08:00:00,0\n"
            ."2001,2026-04-09 17:00:00,1\n"
            ."2001,2026-04-13 08:00:00,0\n"
            ."2001,2026-04-13 17:00:00,1\n");

        $this->artisan('attendance:import', ['file' => $path])
            ->assertSuccessful()
            ->expectsOutputToContain('Imported: 4')
            ->expectsOutputToContain('Outside employment: 2 punch(es)');

        $this->assertSame(4, AttendanceLog::where('employee_id', $leaver->id)->count());
        $this->assertSame('present', DailyAttendance::where('employee_id', $leaver->id)->whereDate('work_date', '2026-04-09')->first()->status->value);
        $this->assertFalse(DailyAttendance::where('employee_id', $leaver->id)->whereDate('work_date', '>', '2026-04-10')->exists());
    }

    public function test_no_outside_employment_line_when_every_punch_is_inside_it(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('Outside employment');
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

    public function test_a_file_that_cannot_be_opened_fails_with_the_clean_message_not_a_stack_trace(): void
    {
        RefusingStreamWrapper::register();

        try {
            $this->artisan('attendance:import', ['file' => 'refuse://file.csv'])
                ->expectsOutputToContain('Unable to open attendance CSV: refuse://file.csv')
                ->assertFailed();
        } finally {
            RefusingStreamWrapper::unregister();
        }
    }

    /**
     * @return list<string> the Y-m-d dates that have a daily_attendances row for one employee
     */
    private function rebuiltDates(string $deviceUserId): array
    {
        return DailyAttendance::where('employee_id', Employee::where('device_user_id', $deviceUserId)->value('id'))
            ->orderBy('work_date')->get()
            ->map(fn ($row) => $row->work_date->format('Y-m-d'))->all();
    }

    public function test_an_import_rebuilds_the_touched_days_plus_the_day_before_and_after(): void
    {
        // The fixture punches on 2026-01-05 and 2026-01-06, so the window is 01-04 through 01-07.
        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->expectsOutputToContain('Rebuilt daily attendance: 2026-01-04 to 2026-01-07 (5 employee(s), 20 day(s)).')
            ->assertSuccessful();

        $this->assertSame(['2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07'], $this->rebuiltDates('1001'));
        $this->assertSame(20, DailyAttendance::count());
    }

    public function test_the_rebuild_runs_once_at_the_end_not_per_row(): void
    {
        $counter = new class extends DailySummaryBuilder
        {
            public int $builds = 0;

            public function build(Employee $employee, CarbonInterface $date, ?Collection $leaves = null): ?DailyAttendance
            {
                $this->builds++;

                return parent::build($employee, $date, $leaves);
            }
        };
        $this->app->instance(DailySummaryBuilder::class, $counter);

        $this->artisan('attendance:import', ['file' => $this->fixture])->assertSuccessful();

        // 5 employees x 4 days, once — not 18 imported rows each triggering their own rebuild.
        $this->assertSame(20, $counter->builds);
    }

    public function test_a_dry_run_rebuilds_nothing(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture, '--dry-run' => true])
            ->doesntExpectOutputToContain('Rebuilt')
            ->assertSuccessful();

        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_an_import_that_adds_nothing_rebuilds_nothing(): void
    {
        $this->artisan('attendance:import', ['file' => $this->fixture])->assertSuccessful();

        DailyAttendance::query()->delete();

        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->expectsOutputToContain('Imported: 0')
            ->expectsOutputToContain('No new punches — nothing to rebuild.')
            ->assertSuccessful();

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_the_widened_window_never_reaches_tomorrow(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $path = $this->tempCsv("user_id,timestamp,state\n1001,2026-02-10 08:00:00,0\n");

        $this->artisan('attendance:import', ['file' => $path])
            ->expectsOutputToContain('Rebuilt daily attendance: 2026-02-09 to 2026-02-10')
            ->assertSuccessful();

        $this->assertSame(['2026-02-09', '2026-02-10'], $this->rebuiltDates('1001'));

        unlink($path);
    }

    public function test_a_missing_schedule_assignment_keeps_the_punches_and_says_how_to_recover(): void
    {
        // The scenario this once was — no default work schedule exists — can no
        // longer happen at rebuild time: every employee is assigned one at
        // creation (see Employee::booted()), and a schedule referenced by any
        // assignment can't be deleted (restrictOnDelete). What CAN still fail
        // is the data-integrity case NoScheduleAssignmentException guards
        // against: an employee whose assignment rows were removed some other
        // way, simulated here directly.
        EmployeeWorkSchedule::query()->delete();

        // Only one substring check per actual output line: Mockery's mock
        // here only credits the first matching expectation against a given
        // doWrite call, so two checks that both match the SAME line (e.g.
        // the whole error() line) would leave the second one unsatisfied.
        $this->artisan('attendance:import', ['file' => $this->fixture])
            ->expectsOutputToContain('has no work schedule assignment at all')
            ->expectsOutputToContain('Run attendance:build-daily for the imported dates')
            ->assertFailed();

        $this->assertSame(18, AttendanceLog::count());
        $this->assertSame(0, DailyAttendance::count());
    }
}
