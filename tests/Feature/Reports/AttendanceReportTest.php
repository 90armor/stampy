<?php

namespace Tests\Feature\Reports;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Support\AttendanceReport;
use App\Support\AttendanceSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5b — the monthly attendance report. Its rows are AttendanceSummary's,
 * so every row must equal that employee's My attendance summary for the
 * month: the guard against a second definition. The fixed-date cases run at
 * Mon 15 Jun 2026 12:00 (May is closed, June open); the seeded comparison
 * runs at whatever instant the suite does.
 */
class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $engineering;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    /** The fixed-date world: Mon–Fri 08:00–17:00, an hour's break from noon, no grace. */
    private function fixedWorld(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0,
            'break_minutes' => 60, 'break_start' => '12:00:00', 'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->engineering = Department::factory()->create(['name' => 'Engineering']);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function punch(Employee $employee, string $at, string $type): void
    {
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    private function build(Employee $employee, string $from, string $to): void
    {
        app(DailySummaryBuilder::class)->rebuildBetween($employee, Carbon::parse($from), Carbon::parse($to));
    }

    private function rowFor(array $report, Employee $employee): ?array
    {
        foreach ($report['rows'] as $row) {
            if ($row['employee']->is($employee)) {
                return $row;
            }
        }

        return null;
    }

    private function assertEveryRowEqualsTheSummary(array $report, Carbon $month): void
    {
        foreach ($report['rows'] as $row) {
            // A fresh instance, so nothing the report loaded is reused.
            $employee = Employee::find($row['employee']->id);
            $this->assertSame(
                [...AttendanceSummary::forMonth($employee, $month), 'not_calculated' => AttendanceSummary::notCalculated($employee, $month)],
                $row['summary'],
                "{$row['employee']->employee_code}, {$month->format('Y-m')}",
            );
        }
    }

    public function test_every_row_of_the_seeded_months_equals_the_attendance_summary(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        foreach ([today()->startOfMonth()->subMonthNoOverflow(), today()->startOfMonth()] as $month) {
            $report = AttendanceReport::forMonth($month, $admin);

            $this->assertSame(
                Employee::query()->activeBetween($month, $month->copy()->endOfMonth())->count(),
                count($report['rows']),
            );
            $this->assertEveryRowEqualsTheSummary($report, $month);
        }

        // The seeded last month has real figures to compare, not just zeros.
        $last = AttendanceReport::forMonth(today()->startOfMonth()->subMonthNoOverflow(), $admin)['totals'];
        $this->assertGreaterThan(0, $last['workdays']);
        $this->assertGreaterThan(0, $last['present']);

        // A seeded manager's report is their team, row for row the same.
        $manager = User::where('username', 'aye.aye.mon')->orWhere('email', 'aye.aye.mon@example.com')->firstOrFail();
        $team = [$manager->employee->id, ...$manager->employee->subordinateIds()];
        $report = AttendanceReport::forMonth(today()->startOfMonth(), $manager);
        $this->assertNotEmpty($report['rows']);
        foreach ($report['rows'] as $row) {
            $this->assertContains($row['employee']->id, $team);
        }
        $this->assertEveryRowEqualsTheSummary($report, today()->startOfMonth());
    }

    public function test_a_closed_month_counts_status_timing_leave_and_worked_time(): void
    {
        $this->fixedWorld();
        $kyaw = Employee::factory()->create(['employee_code' => 'EMP-0002', 'full_name' => 'Kyaw Kyaw', 'department_id' => $this->engineering->id]);

        $this->punch($kyaw, '2026-05-04 08:00:00', 'in');
        $this->punch($kyaw, '2026-05-04 17:00:00', 'out');
        // 20 minutes late.
        $this->punch($kyaw, '2026-05-05 08:20:00', 'in');
        $this->punch($kyaw, '2026-05-05 17:00:00', 'out');
        // Left 30 minutes early.
        $this->punch($kyaw, '2026-05-06 08:00:00', 'in');
        $this->punch($kyaw, '2026-05-06 16:30:00', 'out');
        // In late, never out: incomplete, late 45.
        $this->punch($kyaw, '2026-05-07 08:45:00', 'in');
        // A full day of leave, and a morning of leave then the afternoon worked.
        Leave::factory()->for($kyaw)->between('2026-05-08', '2026-05-08')->approved()->create();
        Leave::factory()->for($kyaw)->between('2026-05-11', '2026-05-11')->halfDay('am')->approved()->create();
        $this->punch($kyaw, '2026-05-11 13:00:00', 'in');
        $this->punch($kyaw, '2026-05-11 17:00:00', 'out');
        $this->build($kyaw, '2026-05-01', '2026-05-31');

        $report = AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $this->admin);

        $this->assertFalse($report['open']);
        $this->assertTrue($report['calculated']);
        $this->assertSame([
            'workdays' => 21,
            // The half-day Present day is one present day and half a day of leave.
            'leave_tenths' => 15,
            'leave' => 1,
            'present' => 4,
            'days_off_worked' => 0,
            'late' => 1,
            'absent' => 15,
            'incomplete' => 1,
            'incomplete_late' => 1,
            'late_minutes' => 65,
            'early_leave_days' => 1,
            'early_leave_minutes' => 30,
            // 8h + 7h 40m + 7h 30m + 4h (the afternoon misses the break).
            'total_worked_minutes' => 480 + 460 + 450 + 240,
            'not_calculated' => 0,
        ], $this->rowFor($report, $kyaw)['summary']);
        $this->assertSame(2, AttendanceReport::lateDays($this->rowFor($report, $kyaw)['summary']));
        $this->assertEveryRowEqualsTheSummary($report, Carbon::parse('2026-05-01'));
    }

    public function test_the_totals_add_up_the_rows(): void
    {
        $this->fixedWorld();
        $a = Employee::factory()->create(['employee_code' => 'EMP-0001']);
        $b = Employee::factory()->create(['employee_code' => 'EMP-0002']);
        $this->punch($a, '2026-05-05 08:10:00', 'in');
        $this->punch($a, '2026-05-05 17:00:00', 'out');
        $this->punch($b, '2026-05-05 08:30:00', 'in');
        $this->punch($b, '2026-05-05 16:00:00', 'out');
        $this->build($a, '2026-05-01', '2026-05-31');
        $this->build($b, '2026-05-01', '2026-05-15');

        $report = AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $this->admin);

        $this->assertSame(['EMP-0001', 'EMP-0002'], array_map(fn ($row) => $row['employee']->employee_code, $report['rows']));
        foreach (AttendanceReport::FIGURES as $figure) {
            $this->assertSame(array_sum(array_map(fn ($row) => $row['summary'][$figure], $report['rows'])), $report['totals'][$figure], $figure);
        }
        $this->assertSame(40, $report['totals']['late_minutes']);
        // B's second half of May was never built.
        $this->assertSame(10, $report['totals']['not_calculated']);
    }

    public function test_a_joiner_and_leaver_in_the_same_month_counts_only_their_employed_days(): void
    {
        $this->fixedWorld();
        $brief = Employee::factory()->inactive('2026-05-20')->create(['join_date' => '2026-05-11', 'employee_code' => 'EMP-0007']);
        // Built Mon 11 – Fri 15 May (absent, no punches); 18–20 May never built.
        $this->build($brief, '2026-05-11', '2026-05-15');

        $row = $this->rowFor(AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $this->admin), $brief);

        $this->assertSame('2026-05-11', $row['joined']->format('Y-m-d'));
        $this->assertSame('2026-05-20', $row['left']->format('Y-m-d'));
        $this->assertSame(5, $row['summary']['workdays']);
        $this->assertSame(5, $row['summary']['absent']);
        // Mon 18 – Wed 20 May: inside the employment period, not before or after it.
        $this->assertSame(3, $row['summary']['not_calculated']);

        // Not in June at all: they left in May.
        $this->assertNull($this->rowFor(AttendanceReport::forMonth(Carbon::parse('2026-06-01'), $this->admin), $brief));
        // And no Joined/Left line in a month they were simply employed through.
        $steady = Employee::factory()->create();
        $row = $this->rowFor(AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $this->admin), $steady);
        $this->assertNull($row['joined']);
        $this->assertNull($row['left']);
    }

    public function test_today_in_progress_counts_as_the_summary_counts_it_and_the_month_is_open(): void
    {
        $this->fixedWorld();
        $kyaw = Employee::factory()->create();
        Holiday::factory()->create(['date' => '2026-06-10', 'name' => 'Company holiday']);
        // Built 1–9 June (absent); 10 June is a holiday; 11 and 12 June never
        // built; today In progress, 30 minutes late.
        $this->build($kyaw, '2026-06-01', '2026-06-09');
        $this->punch($kyaw, '2026-06-15 08:30:00', 'in');
        $this->build($kyaw, '2026-06-15', '2026-06-15');
        $this->assertSame(AttendanceStatus::InProgress, DailyAttendance::where('work_date', '2026-06-15')->sole()->status);

        $report = AttendanceReport::forMonth(Carbon::parse('2026-06-01'), $this->admin);
        $summary = $this->rowFor($report, $kyaw)['summary'];

        $this->assertTrue($report['open']);
        $this->assertSame(7, $summary['workdays']);
        $this->assertSame(0, $summary['present']);
        // The late arrival waits for the day to close, as in My attendance.
        $this->assertSame(0, $summary['late_minutes']);
        // 11 and 12 June — never the holiday, never today until it closes,
        // never a day after today.
        $this->assertSame(2, $summary['not_calculated']);
        $this->assertEveryRowEqualsTheSummary($report, Carbon::parse('2026-06-01'));
    }

    public function test_a_manager_sees_todays_team_for_the_whole_month(): void
    {
        $this->fixedWorld();
        $oldUser = User::factory()->create()->assignRole('manager');
        $old = Employee::factory()->create(['user_id' => $oldUser->id, 'employee_code' => 'EMP-0010']);
        $newUser = User::factory()->create()->assignRole('manager');
        $new = Employee::factory()->create(['user_id' => $newUser->id, 'employee_code' => 'EMP-0020']);
        $moved = Employee::factory()->create(['manager_id' => $old->id, 'employee_code' => 'EMP-0030']);

        $this->punch($moved, '2026-05-04 08:00:00', 'in');
        $this->punch($moved, '2026-05-04 17:00:00', 'out');
        $this->build($moved, '2026-05-01', '2026-05-31');
        // Moved to the other manager after the month.
        $moved->update(['manager_id' => $new->id]);

        $codes = fn (User $user) => array_map(fn ($row) => $row['employee']->employee_code, AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $user)['rows']);

        $this->assertSame(['EMP-0010'], $codes($oldUser));
        $this->assertSame(['EMP-0020', 'EMP-0030'], $codes($newUser));
        $this->assertSame(1, $this->rowFor(AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $newUser), $moved)['summary']['present']);
    }

    public function test_the_department_filter_never_widens_the_scope(): void
    {
        $this->fixedWorld();
        $operations = Department::factory()->create(['name' => 'Operations']);
        $sales = Department::factory()->create(['name' => 'Sales']);
        $managerUser = User::factory()->create()->assignRole('manager');
        $manager = Employee::factory()->create(['user_id' => $managerUser->id, 'department_id' => $this->engineering->id, 'employee_code' => 'EMP-0001']);
        Employee::factory()->create(['manager_id' => $manager->id, 'department_id' => $this->engineering->id, 'employee_code' => 'EMP-0002']);
        // In the team, in Sales, but gone before May.
        Employee::factory()->inactive('2026-03-31')->create(['manager_id' => $manager->id, 'department_id' => $sales->id, 'employee_code' => 'EMP-0003']);
        // Not in the team at all.
        Employee::factory()->create(['department_id' => $operations->id, 'employee_code' => 'EMP-0004']);

        $may = Carbon::parse('2026-05-01');
        $report = AttendanceReport::forMonth($may, $managerUser);
        // Options are the team's departments, not the company's.
        $this->assertSame([$this->engineering->id => 'Engineering', $sales->id => 'Sales'], $report['departments']);

        // A department outside the scope is ignored, never widening it.
        $outside = AttendanceReport::forMonth($may, $managerUser, $operations->id);
        $this->assertNull($outside['department']);
        $this->assertSame(['EMP-0001', 'EMP-0002'], array_map(fn ($row) => $row['employee']->employee_code, $outside['rows']));

        // One the scope has nobody in this month: no rows, not an error.
        $empty = AttendanceReport::forMonth($may, $managerUser, $sales->id);
        $this->assertSame($sales->id, $empty['department']);
        $this->assertSame([], $empty['rows']);

        $this->assertSame(['EMP-0004'], array_map(fn ($row) => $row['employee']->employee_code, AttendanceReport::forMonth($may, $this->admin, $operations->id)['rows']));
    }

    public function test_a_month_before_anyone_joined_or_before_go_live_has_nothing_to_show(): void
    {
        $this->fixedWorld();
        // No login: reports are about employees, not users.
        $noLogin = Employee::factory()->create(['user_id' => null]);

        // The factory's join date is 1 Jan 2020.
        $this->assertSame([], AttendanceReport::forMonth(Carbon::parse('2019-12-01'), $this->admin)['rows']);

        // Employed, but nothing was ever built: rows exist, nothing calculated.
        $april = AttendanceReport::forMonth(Carbon::parse('2026-04-01'), $this->admin);
        $this->assertNotNull($this->rowFor($april, $noLogin));
        $this->assertFalse($april['calculated']);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_employees(): void
    {
        $this->fixedWorld();

        $count = function (int $employees): int {
            DailyAttendance::query()->delete();
            Leave::query()->delete();
            Employee::query()->delete();

            Employee::factory()->count($employees)->create(['department_id' => $this->engineering->id])->each(function (Employee $employee) {
                DailyAttendance::factory()->for($employee)->create(['work_date' => '2026-05-04', 'late_minutes' => 5]);
                DailyAttendance::factory()->for($employee)->create(['work_date' => '2026-05-05', 'status' => AttendanceStatus::Incomplete]);
                $leave = Leave::factory()->for($employee)->between('2026-05-06', '2026-05-06')->approved()->create();
                DailyAttendance::factory()->for($employee)->create(['work_date' => '2026-05-06', 'status' => AttendanceStatus::Leave, 'leave_id' => $leave->id]);
            });

            DB::flushQueryLog();
            DB::enableQueryLog();
            $report = AttendanceReport::forMonth(Carbon::parse('2026-05-01'), $this->admin);
            DB::disableQueryLog();
            $this->assertCount($employees, $report['rows']);
            $this->assertSame($employees * 10, $report['totals']['leave_tenths']);

            return count(DB::getQueryLog());
        };

        // The viewer's roles are looked up once and then cached on the user:
        // not a cost of the report.
        $this->admin->load('roles');

        // Departments, employees (with department and schedules: three),
        // the month's rows, their leave, holidays.
        $this->assertSame(8, $count(10));
        $this->assertSame(8, $count(50));
    }
}
