<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2.7 — day close. An in-only day stays in_progress until its pairing
 * window (first in-punch + MAX_SHIFT_HOURS) closes, past the schedule's end
 * and past midnight; a day with no punches still closes as absent at the
 * schedule's end.
 */
class DayCloseTest extends TestCase
{
    use RefreshDatabase;

    // A Monday, a workday under the 08:00–17:00 schedule below.
    private const DAY = '2026-02-02';

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);
    }

    private function punch(Employee $employee, string $at, string $type): void
    {
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    private function buildAt(Employee $employee, string $now): DailyAttendance
    {
        $this->travelTo(Carbon::parse($now));

        return app(DailySummaryBuilder::class)->build($employee, Carbon::parse(self::DAY));
    }

    public function test_an_in_only_day_stays_in_progress_after_end_time_and_after_midnight(): void
    {
        $employee = Employee::factory()->create();
        $this->punch($employee, self::DAY.' 08:25:00', 'in');

        $evening = $this->buildAt($employee, self::DAY.' 19:00:00');
        $this->assertSame(AttendanceStatus::InProgress, $evening->status, 'past the schedule end, still at work');
        // Late from the in-punch stays as Phase 2.6 made it; early leave still needs both punches.
        $this->assertSame(25, $evening->late_minutes);
        $this->assertSame(0, $evening->early_leave_minutes);

        $afterMidnight = $this->buildAt($employee, '2026-02-03 01:00:00');
        $this->assertSame(AttendanceStatus::InProgress, $afterMidnight->status, 'past midnight, inside the 18h window');
    }

    public function test_an_in_only_day_becomes_incomplete_once_in_plus_eighteen_hours_passes(): void
    {
        $employee = Employee::factory()->create();
        $this->punch($employee, self::DAY.' 08:00:00', 'in');

        // The window closes at 02:00 the next day, inclusive — an out-punch at that instant would still pair.
        $this->assertSame(AttendanceStatus::InProgress, $this->buildAt($employee, '2026-02-03 02:00:00')->status);

        $closed = $this->buildAt($employee, '2026-02-03 02:00:01');
        $this->assertSame(AttendanceStatus::Incomplete, $closed->status);
        $this->assertSame(0, $closed->worked_minutes);
    }

    public function test_an_overnight_out_punch_pairs_into_present_with_no_incomplete_in_between(): void
    {
        $employee = Employee::factory()->create();
        $this->punch($employee, self::DAY.' 08:00:00', 'in');

        // Every rebuild through the evening and past midnight keeps the day open...
        foreach ([self::DAY.' 17:15:00', self::DAY.' 21:00:00', '2026-02-03 00:30:00'] as $now) {
            $this->assertSame(AttendanceStatus::InProgress, $this->buildAt($employee, $now)->status, "at {$now}");
        }

        // ...until the (+1) out-punch at 00:42 arrives and pairs.
        $this->punch($employee, '2026-02-03 00:42:00', 'out');
        $row = $this->buildAt($employee, '2026-02-03 00:45:00');

        $this->assertSame(AttendanceStatus::Present, $row->status);
        $this->assertSame('2026-02-03 00:42:00', $row->last_out->format('Y-m-d H:i:s'));
        $this->assertTrue($row->isOvernightOut());
        // 16h42m on the clock, less the 60-minute break.
        $this->assertSame(16 * 60 + 42 - 60, $row->worked_minutes);
    }

    public function test_a_day_with_no_punches_still_closes_as_absent_at_the_schedule_end(): void
    {
        $employee = Employee::factory()->create();

        $this->assertSame(AttendanceStatus::InProgress, $this->buildAt($employee, self::DAY.' 16:59:00')->status);
        $this->assertSame(AttendanceStatus::Absent, $this->buildAt($employee, self::DAY.' 17:00:00')->status);
    }

    public function test_an_out_only_day_keeps_its_rule_open_today_until_the_schedule_end(): void
    {
        $employee = Employee::factory()->create();
        $this->punch($employee, self::DAY.' 12:00:00', 'out');

        $this->assertSame(AttendanceStatus::InProgress, $this->buildAt($employee, self::DAY.' 16:00:00')->status);
        $this->assertSame(AttendanceStatus::Incomplete, $this->buildAt($employee, self::DAY.' 17:30:00')->status);
    }
}
