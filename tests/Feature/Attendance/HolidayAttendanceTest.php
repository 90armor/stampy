<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Builder-level holiday rules only — see HolidayManagementTest for the
 * admin CRUD screen and the rebuild-on-change behaviour.
 */
class HolidayAttendanceTest extends TestCase
{
    use RefreshDatabase;

    // A Monday — a scheduled workday under the schedule below.
    private const WORKDAY = '2026-02-02';

    // The Sunday immediately before it — not a scheduled workday.
    private const WEEKEND = '2026-02-01';

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

    public function test_a_workday_holiday_with_no_punches_is_holiday(): void
    {
        Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);
        $employee = $this->employee();

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WORKDAY));

        $this->assertSame(AttendanceStatus::Holiday, $row->status);
    }

    public function test_a_workday_holiday_with_punches_is_present_with_no_timing_exception(): void
    {
        Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);
        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 08:25:00', // would be late on an ordinary day
            'punch_type' => 'in',
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 16:00:00', // would be an early leave
            'punch_type' => 'out',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WORKDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(0, $row->late_minutes);
        $this->assertSame(0, $row->early_leave_minutes);
        $this->assertFalse($row->hasTimingException());
        // displayVariant() is status-only, so a worked holiday is 'present';
        // the zeroed timing fields also mean it carries no amber annotation.
        $this->assertSame('present', $row->displayVariant());
    }

    public function test_a_weekend_holiday_with_no_punches_is_off_but_the_name_still_renders(): void
    {
        Holiday::factory()->create(['date' => self::WEEKEND, 'name' => 'Ad-hoc day off']);
        $employee = $this->employee();

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WEEKEND));

        // Status is unaffected — a weekend was already non-working before
        // holidays existed. The calendar shows the holiday name as a
        // separate layer read directly from `holidays`, not from this row
        // (see HolidayCalendarDisplayTest) — that's a display concern, not
        // a status one.
        $this->assertSame(AttendanceStatus::Off, $row->status);
    }

    public function test_a_weekend_holiday_someone_still_worked_is_present_not_off(): void
    {
        // A holiday landing on a weekend doesn't retroactively make working
        // it invisible — this already worked for an ordinary (non-holiday)
        // weekend before holidays existed, and must keep working.
        Holiday::factory()->create(['date' => self::WEEKEND, 'name' => 'Ad-hoc day off']);
        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WEEKEND.' 09:00:00',
            'punch_type' => 'in',
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WEEKEND.' 13:00:00',
            'punch_type' => 'out',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WEEKEND));

        $this->assertSame(AttendanceStatus::Present, $row->status);
    }

    public function test_a_one_sided_punch_on_a_holiday_is_still_incomplete(): void
    {
        // A missing punch is a device-defect fact independent of whether
        // the day was a holiday — holiday only suppresses Absent for a
        // completely unpunched day, not Incomplete for a partly-punched one.
        Holiday::factory()->create(['date' => self::WORKDAY, 'name' => 'Test Holiday']);
        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 08:00:00',
            'punch_type' => 'in',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WORKDAY));

        $this->assertSame(AttendanceStatus::Incomplete, $row->status);
    }

    public function test_a_non_holiday_workday_is_unaffected(): void
    {
        // No Holiday row at all for this date — sanity check that the
        // holiday lookup doesn't change behaviour when it finds nothing.
        $employee = $this->employee();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 08:25:00',
            'punch_type' => 'in',
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => self::WORKDAY.' 17:00:00',
            'punch_type' => 'out',
        ]);

        $row = app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::WORKDAY));

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame(25, $row->late_minutes);
    }
}
