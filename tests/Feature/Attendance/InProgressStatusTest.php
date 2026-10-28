<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Today" and "has end_time passed" are both evaluated via bare now()/
 * Carbon::parse() calls in DailySummaryBuilder, which resolve against PHP's
 * default timezone — set from config('app.timezone') at Laravel's bootstrap,
 * currently UTC. Carbon::setTestNow() freezes that same default-timezone
 * clock, so freezing at a UTC instant here is exactly the app's own
 * timezone, not a stand-in for it.
 */
class InProgressStatusTest extends TestCase
{
    use RefreshDatabase;

    // A Monday — a scheduled workday under the schedule below.
    private const TODAY = '2026-02-02';

    protected function tearDown(): void
    {
        // Carbon::setTestNow() is global/static state — clearing it here,
        // even if a frozen-time test fails partway through, keeps that
        // freeze from leaking into every test that runs after it.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function schedule(): WorkSchedule
    {
        return WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);
    }

    private function employee(): Employee
    {
        // Assigned automatically at creation, to whatever's default — see
        // Employee::booted(). Marking this schedule the default (above) is
        // what puts a new employee on it, now that there's no per-employee
        // work_schedule_id to set directly.
        $this->schedule();

        return Employee::factory()->create();
    }

    public function test_today_before_end_time_with_no_punches_is_in_progress(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00'));

        $employee = $this->employee();

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));

        $this->assertSame(AttendanceStatus::InProgress, $row->status);
    }

    public function test_today_after_end_time_with_no_punches_is_absent(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 18:00:00'));

        $employee = $this->employee();

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));

        $this->assertSame(AttendanceStatus::Absent, $row->status);
    }

    public function test_a_day_stops_being_in_progress_at_exactly_end_time(): void
    {
        $employee = $this->employee();

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 16:59:59'));
        $before = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));
        $this->assertSame(AttendanceStatus::InProgress, $before->status);

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 17:00:00'));
        $at = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));
        $this->assertSame(AttendanceStatus::Absent, $at->status);
    }

    public function test_today_in_punch_only_before_end_time_is_in_progress(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00'));

        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::TODAY.' 08:00:00',
            'punch_type' => 'in',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));

        $this->assertSame(AttendanceStatus::InProgress, $row->status);
    }

    public function test_today_in_punch_only_after_end_time_stays_in_progress(): void
    {
        // Phase 2.7: an in-only day stays open until its pairing window
        // closes, not at the schedule's end (see DayCloseTest).
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 18:00:00'));

        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::TODAY.' 08:00:00',
            'punch_type' => 'in',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));

        $this->assertSame(AttendanceStatus::InProgress, $row->status);
    }

    public function test_a_past_date_with_no_punches_is_absent_never_in_progress(): void
    {
        // "Now" is today (Monday); the date being built is the previous
        // Friday — a past WORKDAY, not just any past date (a past Sunday
        // would correctly be Off, which wouldn't test this rule at all).
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00'));

        $employee = $this->employee();

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY)->subDays(3));

        $this->assertSame(AttendanceStatus::Absent, $row->status);
    }

    public function test_a_day_built_in_progress_downgrades_to_absent_on_rebuild_after_end_time(): void
    {
        $employee = $this->employee();
        $builder = app(DailySummaryBuilder::class);

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 09:00:00'));
        $morning = $builder->build($employee, Carbon::parse(self::TODAY));
        $this->assertSame(AttendanceStatus::InProgress, $morning->status);

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 18:00:00'));
        $evening = $builder->build($employee, Carbon::parse(self::TODAY));

        // Same row, rebuilt — not a new one.
        $this->assertSame($morning->id, $evening->id);
        $this->assertSame(AttendanceStatus::Absent, $evening->status);
    }

    public function test_an_in_progress_day_reports_zero_worked_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00'));

        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::TODAY.' 08:00:00',
            'punch_type' => 'in',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));

        $this->assertSame(0, $row->worked_minutes);
    }

    public function test_a_newly_created_employee_uses_the_default_schedules_end_time(): void
    {
        // No explicit assignment made — proves the builder resolves a fresh
        // employee's automatic default-schedule assignment (Employee::booted())
        // correctly via scheduleOn(), not just an employee this file's own
        // employee() helper has deliberately pinned to a specific schedule.
        WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);

        $employee = Employee::factory()->create();

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00'));
        $before = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));
        $this->assertSame(AttendanceStatus::InProgress, $before->status);

        Carbon::setTestNow(Carbon::parse(self::TODAY.' 18:00:00'));
        $after = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::TODAY));
        $this->assertSame(AttendanceStatus::Absent, $after->status);
    }
}
