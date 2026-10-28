<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\OvertimeReport;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The overtime category rule at whatever "now" is — deliberately unpinned
 * (OvertimeCalculatorTest pins fixed dates), so the pinned-instant check runs
 * it on a weekend: yesterday is a Sunday at Mon 2 Feb 00:00:30 and a Saturday
 * at Sun 8 Feb 12:00, and the instant itself is a Saturday at Sat 7 Feb
 * 23:59:30 and a Sunday at Sun 8 Feb 12:00. The weekly rest day (Sunday) is
 * rest_day; a Saturday — a day off, not the rest day — and a weekday evening
 * are workday. Through the service, the builder and the report.
 */
class OvertimeCategoryAtAnyInstantTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->employee = Employee::factory()->create();
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    /** The category every minute of a window off normal hours lands in on this date. */
    private static function expectedCategory(CarbonInterface $date): string
    {
        return $date->isoWeekday() === 7 ? 'rest_day' : 'workday';
    }

    /** An admin's paid claim for $from–$to on $date, with punches around it, built. */
    private function claim(CarbonInterface $date, string $in, string $from, string $to, string $out): DailyAttendance
    {
        $day = $date->format('Y-m-d');
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$day} {$in}", 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$day} {$out}", 'punch_type' => 'out']);

        app(OvertimeRequestService::class)->submit(
            $this->employee, $date, Carbon::parse("{$day} {$from}"), Carbon::parse("{$day} {$to}"), OvertimeCompensation::Pay, null, $this->admin,
        );

        return DailyAttendance::query()->where('employee_id', $this->employee->id)->whereDate('work_date', $day)->sole();
    }

    private function assertCredited(DailyAttendance $row, string $category, int $minutes): void
    {
        $credited = [
            'workday' => $row->overtime_workday_minutes, 'night' => $row->overtime_night_minutes,
            'rest_day' => $row->overtime_rest_day_minutes, 'holiday' => $row->overtime_holiday_minutes,
        ];

        $this->assertSame([$category => $minutes], array_filter($credited), $row->work_date->format('D Y-m-d').' at '.now()->format('D Y-m-d H:i:s'));
    }

    public function test_yesterday_evening_lands_in_its_weekday_category(): void
    {
        $yesterday = today()->subDay();

        $row = $this->claim($yesterday, '08:00:00', '17:00', '19:00', '19:05:00');

        $this->assertCredited($row, self::expectedCategory($yesterday), 120);

        $report = OvertimeReport::forMonth($yesterday);
        $this->assertSame(120, $report['rows'][0]['pay'][self::expectedCategory($yesterday)]);
    }

    public function test_a_weekend_morning_today_counts_whole_with_no_normal_hours(): void
    {
        // Only on a Saturday or Sunday once the window has passed: a weekday
        // morning is normal working hours, and a later window isn't over yet.
        if (! today()->isWeekend() || now()->lt(today()->setTime(10, 0))) {
            $this->assertTrue(true);

            return;
        }

        $row = $this->claim(today(), '07:55:00', '08:00', '10:00', '10:05:00');

        $this->assertCredited($row, self::expectedCategory(today()), 120);
    }
}
