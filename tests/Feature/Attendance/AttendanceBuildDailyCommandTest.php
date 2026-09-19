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

    public function test_a_past_date_is_still_built(): void
    {
        $this->artisan('attendance:build-daily', ['--date' => '2026-02-02'])->assertSuccessful();

        $this->assertSame(1, DailyAttendance::count());
    }
}
