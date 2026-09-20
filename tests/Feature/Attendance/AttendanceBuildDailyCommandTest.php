<?php

namespace Tests\Feature\Attendance;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceBuildDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::factory()->create(['workdays' => [1, 2, 3, 4, 5], 'is_default' => true]);
        Employee::factory()->create();
    }

    public function test_date_cannot_be_combined_with_from_or_to(): void
    {
        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02', '--from' => '2026-02-01'])
            ->expectsOutputToContain('Use either --date or --from/--to, not both.')
            ->assertFailed();

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02', '--to' => '2026-02-03'])
            ->expectsOutputToContain('Use either --date or --from/--to, not both.')
            ->assertFailed();

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_from_and_to_must_be_given_together(): void
    {
        $this->artisan('attendance:build-daily', ['--from' => '2026-02-02'])
            ->expectsOutputToContain('--from and --to must be given together.')
            ->assertFailed();

        $this->artisan('attendance:build-daily', ['--to' => '2026-02-02'])
            ->expectsOutputToContain('--from and --to must be given together.')
            ->assertFailed();

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_from_must_not_be_after_to_but_may_equal_it(): void
    {
        $this->artisan('attendance:build-daily', ['--from' => '2026-02-03', '--to' => '2026-02-02'])
            ->expectsOutputToContain('--from must not be after --to.')
            ->assertFailed();

        $this->assertSame(0, DailyAttendance::count());

        $this->artisan('attendance:build-daily', ['--from' => '2026-02-02', '--to' => '2026-02-02'])->assertSuccessful();

        $this->assertSame(1, DailyAttendance::count());
    }

    public function test_a_malformed_date_names_the_option_and_the_expected_format(): void
    {
        // [options given, the offending option, its value]
        foreach ([
            [['--date' => 'not-a-date'], '--date', 'not-a-date'],
            [['--from' => '02/02/2026', '--to' => '2026-02-03'], '--from', '02/02/2026'],
            [['--from' => '2026-02-02', '--to' => 'tomorrow'], '--to', 'tomorrow'],
        ] as [$options, $option, $value]) {
            $this->artisan('attendance:build-daily', $options)
                ->expectsOutputToContain("Invalid {$option} value \"{$value}\" — expected Y-m-d.")
                ->assertFailed();
        }

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_an_employee_can_be_selected_by_id_or_by_code(): void
    {
        $other = Employee::factory()->create(['employee_code' => 'EMP-7777']);

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02', '--employee' => (string) $other->id])->assertSuccessful();
        $this->assertSame([$other->id], DailyAttendance::pluck('employee_id')->all());

        DailyAttendance::query()->delete();

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02', '--employee' => 'EMP-7777'])->assertSuccessful();
        $this->assertSame([$other->id], DailyAttendance::pluck('employee_id')->all());
    }

    public function test_an_unknown_or_inactive_employee_is_reported_as_no_active_employee(): void
    {
        $inactive = Employee::factory()->create(['status' => 'inactive', 'employee_code' => 'EMP-0001']);

        foreach ([(string) $inactive->id, 'EMP-0001', '999999', 'NOPE-1'] as $selector) {
            $this->artisan('attendance:build-daily', ['--date' => '2026-02-02', '--employee' => $selector])
                ->expectsOutputToContain("No active employee found matching \"{$selector}\".")
                ->assertFailed();
        }

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_inactive_employees_are_left_out_of_a_default_run(): void
    {
        Employee::factory()->create(['status' => 'inactive']);

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02'])
            ->expectsOutputToContain('(1 employee(s))')
            ->assertSuccessful();

        $this->assertSame(1, DailyAttendance::count());
    }

    public function test_days_before_an_employees_join_date_are_not_built(): void
    {
        DailyAttendance::query()->delete();
        Employee::query()->delete();
        $employee = Employee::factory()->create(['join_date' => '2026-02-05']);

        $this->artisan('attendance:build-daily', ['--from' => '2026-02-02', '--to' => '2026-02-06'])->assertSuccessful();

        $this->assertSame(
            ['2026-02-05', '2026-02-06'],
            DailyAttendance::where('employee_id', $employee->id)->orderBy('work_date')->get()->map(fn ($row) => $row->work_date->format('Y-m-d'))->all()
        );
    }

    public function test_the_summary_reports_created_then_updated_counts(): void
    {
        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02'])
            ->expectsOutputToContain('Built daily attendance for 2026-02-02 to 2026-02-02 (1 employee(s)).')
            ->expectsOutputToContain('Created: 1')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02'])
            ->expectsOutputToContain('Created: 0')
            ->expectsOutputToContain('Updated: 1')
            ->assertSuccessful();
    }

    public function test_a_future_date_is_rejected_with_a_clear_message_and_nothing_is_written(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));

        $this->artisan('attendance:build-daily', ['--date' => '2030-01-01'])
            ->expectsOutputToContain('--date 2030-01-01 is in the future (today is 2026-02-10)')
            ->assertFailed();

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_a_range_ending_in_the_future_is_rejected_not_clamped(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));

        $this->artisan('attendance:build-daily', ['--from' => '2026-02-01', '--to' => '2030-01-01'])
            ->expectsOutputToContain('--to 2030-01-01 is in the future')
            ->assertFailed();

        // Not even the valid part of the range was built.
        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_today_is_the_last_date_that_can_be_built(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-11'])
            ->expectsOutputToContain('--date 2026-02-11 is in the future')
            ->assertFailed();

        $this->assertSame(0, DailyAttendance::count());

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-10'])->assertSuccessful();

        $this->assertSame(1, DailyAttendance::count());
    }

    public function test_a_missing_default_schedule_is_reported_as_the_real_problem_not_a_stack_trace(): void
    {
        WorkSchedule::query()->delete();

        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02'])
            ->expectsOutputToContain('No default work schedule exists')
            ->assertFailed();

        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_a_past_date_is_still_built(): void
    {
        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02'])->assertSuccessful();

        $this->assertSame(1, DailyAttendance::count());
    }
}
