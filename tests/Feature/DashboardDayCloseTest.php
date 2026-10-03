<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\DashboardAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2.7 — the dashboard follows the builder's statuses, and every trend
 * day carries a value, a marker or both (docs/ATTENDANCE_UI.md).
 */
class DashboardDayCloseTest extends TestCase
{
    use RefreshDatabase;

    // Friday 2 October 2026; the 08:00–17:00 schedule makes it a workday.
    private const TODAY = '2026-10-02';

    private WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = WorkSchedule::factory()->create(['is_default' => true]);
        Role::firstOrCreate(['name' => 'admin']);
    }

    private function row(Employee $employee, AttendanceStatus $status, array $overrides = []): DailyAttendance
    {
        return DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => self::TODAY,
            'work_schedule_id' => $this->schedule->id,
            'status' => $status,
            'first_in' => null,
            'last_out' => null,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function trendDay(string $date): array
    {
        return collect(DashboardAttendance::weeklyTrend(null))->firstWhere('date', $date);
    }

    public function test_today_with_no_rows_is_pending_everywhere_and_nobody_is_absent(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 09:00:00'));
        Employee::factory()->count(3)->create();

        $live = DashboardAttendance::liveToday(null);
        $this->assertSame(['notIn' => 3, 'notInDue' => 3, 'notInAbsent' => 0], array_intersect_key($live, array_flip(['notIn', 'notInDue', 'notInAbsent'])));
        $this->assertSame(['value' => null, 'marker' => 'Today', 'pending' => true], array_intersect_key($this->trendDay(self::TODAY), array_flip(['value', 'marker', 'pending'])));
        $this->assertSame(0, DashboardAttendance::needsAttentionTotal(null));
    }

    public function test_today_with_in_progress_rows_only_shows_checked_in_so_far(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00'));
        $department = Department::factory()->create();
        [$in, $late, $due] = Employee::factory()->count(3)->create(['department_id' => $department->id])->all();
        $this->row($in, AttendanceStatus::InProgress, ['first_in' => self::TODAY.' 07:55:00']);
        $this->row($late, AttendanceStatus::InProgress, ['first_in' => self::TODAY.' 08:40:00', 'late_minutes' => 40]);
        $this->row($due, AttendanceStatus::InProgress);

        $cells = collect(DashboardAttendance::liveTodayCells(DashboardAttendance::liveToday(null)))->keyBy('label');
        $this->assertSame(['2', '1 late'], [$cells['At work']['value'], $cells['At work']['subtext']]);
        $this->assertSame(['1', '1 due'], [$cells['Not in']['value'], $cells['Not in']['subtext']]);

        $today = $this->trendDay(self::TODAY);
        $this->assertSame(['value' => 66.7, 'marker' => 'Today', 'pending' => true], array_intersect_key($today, array_flip(['value', 'marker', 'pending'])));

        $departments = DashboardAttendance::departmentAttendance(Department::withCount('employees')->get(), null);
        $this->assertSame(['employees' => 3, 'checkedIn' => 2, 'pending' => true], array_intersect_key($departments[0], array_flip(['employees', 'checkedIn', 'pending'])));

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('dashboard'))
            ->assertSee('Checked in')
            ->assertDontSee('Attended');
    }

    public function test_after_end_time_absent_rows_show_as_absent_and_in_only_rows_as_past_end_time(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 20:40:00'));
        $overtime = Employee::factory()->count(2)->create();
        $missing = Employee::factory()->count(35)->create();
        foreach ($overtime as $employee) {
            // In-only rows stay open past the schedule end (Phase 2.7).
            $this->row($employee, AttendanceStatus::InProgress, ['first_in' => self::TODAY.' 07:58:00']);
        }
        foreach ($missing as $employee) {
            $this->row($employee, AttendanceStatus::Absent);
        }

        $cells = collect(DashboardAttendance::liveTodayCells(DashboardAttendance::liveToday(null)))->keyBy('label');
        $this->assertSame(['2', '2 past end time'], [$cells['At work']['value'], $cells['At work']['subtext']]);
        $this->assertSame(['35', '35 absent'], [$cells['Not in']['value'], $cells['Not in']['subtext']]);

        // Needs attention follows the builder: Absent is Absent; the header has the real total, the list is capped.
        $list = DashboardAttendance::needsAttention(null);
        $this->assertCount(8, $list);
        $this->assertSame(['absent'], collect($list)->pluck('kind')->unique()->values()->all());
        $this->assertSame(35, DashboardAttendance::needsAttentionTotal(null));

        // Still pending while those in-only rows are open: a provisional bar of who checked in, not 0%.
        $this->assertSame(['value' => 5.4, 'marker' => 'Today', 'pending' => true], array_intersect_key($this->trendDay(self::TODAY), array_flip(['value', 'marker', 'pending'])));

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('dashboard'))
            ->assertSeeInOrder(['At work', '2', '2 past end time', 'Not in', '35', '35 absent'])
            ->assertSee('35 today');
    }

    public function test_an_out_only_day_counts_as_left_and_not_in_sub_lines_sum_to_their_cell(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 20:40:00'));
        [$present, $outOnly, $atWork, $absent1, $absent2, $off] = Employee::factory()->count(6)->create()->all();
        $this->row($present, AttendanceStatus::Present, ['first_in' => self::TODAY.' 07:55:00', 'last_out' => self::TODAY.' 17:05:00']);
        // An out-punch with no in-punch: incomplete, but they did leave — Left, not "Not in".
        $this->row($outOnly, AttendanceStatus::Incomplete, ['last_out' => self::TODAY.' 17:02:00']);
        $this->row($atWork, AttendanceStatus::InProgress, ['first_in' => self::TODAY.' 08:30:00', 'late_minutes' => 30]);
        $this->row($absent1, AttendanceStatus::Absent);
        $this->row($absent2, AttendanceStatus::Absent);
        $this->row($off, AttendanceStatus::Off);

        $live = DashboardAttendance::liveToday(null);
        $this->assertSame(['atWork' => 1, 'left' => 2, 'notIn' => 3], array_intersect_key($live, array_flip(['atWork', 'left', 'notIn'])));
        $this->assertSame($live['total'], $live['atWork'] + $live['left'] + $live['notIn']);
        // Every state sub-line sums to its cell: 2 absent + 1 off = Not in 3.
        $this->assertSame($live['notIn'], $live['notInDue'] + $live['notInAbsent'] + $live['notInOff'] + $live['notInHoliday'] + $live['notInLeave']);

        $cells = collect(DashboardAttendance::liveTodayCells($live))->keyBy('label');
        $this->assertSame(['3', '2 absent · 1 off'], [$cells['Not in']['value'], $cells['Not in']['subtext']]);
        $this->assertSame(['1', '1 late · 1 past end time'], [$cells['At work']['value'], $cells['At work']['subtext']]);
        $this->assertSame('2', $cells['Left']['value']);
    }

    public function test_yesterday_still_inside_a_pairing_window_at_one_in_the_morning_is_pending(): void
    {
        $employees = Employee::factory()->count(2)->create();
        // Thursday: one present, one in at 09:30 with no out yet — open until 03:30 Friday.
        $this->row($employees[0], AttendanceStatus::Present, ['work_date' => '2026-10-01', 'first_in' => '2026-10-01 08:00:00', 'last_out' => '2026-10-01 17:00:00']);
        $this->row($employees[1], AttendanceStatus::InProgress, ['work_date' => '2026-10-01', 'first_in' => '2026-10-01 09:30:00']);

        $this->travelTo(Carbon::parse(self::TODAY.' 01:00:00'));
        $this->assertSame(['value' => 100.0, 'marker' => 'Pending', 'pending' => true], array_intersect_key($this->trendDay('2026-10-01'), array_flip(['value', 'marker', 'pending'])));

        // Once the window has closed and the builder hasn't run, the day is stale: not calculated, never 0% or absent.
        $this->travelTo(Carbon::parse(self::TODAY.' 04:00:00'));
        $this->assertSame(['value' => null, 'marker' => 'Not calculated', 'pending' => false], array_intersect_key($this->trendDay('2026-10-01'), array_flip(['value', 'marker', 'pending'])));
    }

    public function test_a_past_day_with_no_rows_is_not_calculated(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 12:00:00'));
        Employee::factory()->create();

        $this->assertSame(['value' => null, 'marker' => 'Not calculated', 'pending' => false], array_intersect_key($this->trendDay('2026-09-30'), array_flip(['value', 'marker', 'pending'])));
    }

    public function test_the_attendance_rate_counts_incomplete_days_and_marks_a_closed_zero(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 12:00:00'));
        $department = Department::factory()->create();
        $employees = Employee::factory()->count(4)->create(['department_id' => $department->id]);

        // Tuesday: 1 present + 2 incomplete + 1 absent → 3 of 4 attended.
        foreach ([AttendanceStatus::Present, AttendanceStatus::Incomplete, AttendanceStatus::Incomplete, AttendanceStatus::Absent] as $i => $status) {
            $this->row($employees[$i], $status, ['work_date' => '2026-09-29']);
        }
        // Wednesday: everyone absent → a closed workday with 0 attended gets a "0%" marker, not an empty slot.
        foreach ($employees as $employee) {
            $this->row($employee, AttendanceStatus::Absent, ['work_date' => '2026-09-30']);
        }
        // Today, closed (no open rows): 2 present + 1 incomplete → the Department card says "Attended 3 / 4".
        foreach ([AttendanceStatus::Present, AttendanceStatus::Present, AttendanceStatus::Incomplete, AttendanceStatus::Absent] as $i => $status) {
            $this->row($employees[$i], $status);
        }

        $this->assertSame(75.0, $this->trendDay('2026-09-29')['value']);
        $this->assertSame(['value' => null, 'marker' => '0%'], array_intersect_key($this->trendDay('2026-09-30'), array_flip(['value', 'marker'])));

        $departments = DashboardAttendance::departmentAttendance(Department::withCount('employees')->get(), null);
        $this->assertSame(['employees' => 4, 'attended' => 3, 'pending' => false], array_intersect_key($departments[0], array_flip(['employees', 'attended', 'pending'])));

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('dashboard'))
            ->assertSeeInOrder(['Attended', '3', '/ 4']);
    }
}
