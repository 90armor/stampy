<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The dashboard now reads real daily_attendances/attendance_logs data via
 * App\Support\DashboardAttendance, replacing the old DemoAttendance
 * placeholder. These tests cover the one thing that placeholder never had to
 * get right: row-level scoping (a manager sees only their own team's
 * attendance AND headcount figures, via the same EmployeeScope the employee
 * directory uses — mirroring Attendance\Index/Show and Employees\Index).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    private function attendanceRow(Employee $employee, AttendanceStatus $status, array $overrides = []): DailyAttendance
    {
        return DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => $status,
            ...$overrides,
        ]);
    }

    public function test_admin_sees_the_company_wide_employee_total(): void
    {
        Employee::factory()->count(3)->create();

        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_employees'] === 3);
    }

    public function test_attendance_logs_has_an_index_for_the_admin_recent_activity_query(): void
    {
        // recentActivity() for an admin is `WHERE voided_at IS NULL ORDER BY punched_at DESC LIMIT n`
        // with no employee filter; without this index that is a full table scan plus filesort.
        $this->assertTrue(Schema::hasIndex('attendance_logs', ['voided_at', 'punched_at']));
    }

    public function test_new_this_month_counts_only_joiners_from_the_current_month_and_year(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));

        Employee::factory()->create(['join_date' => '2026-03-01']); // first day of this month
        Employee::factory()->create(['join_date' => '2026-03-15']); // today
        Employee::factory()->create(['join_date' => '2026-02-28']); // last day of last month
        Employee::factory()->create(['join_date' => '2026-04-01']); // first day of next month
        Employee::factory()->create(['join_date' => '2025-03-10']); // same month, a year ago

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn ($stats) => $stats['new_this_month'] === 2);
    }

    public function test_admin_sees_every_employees_attendance_today(): void
    {
        $admin = $this->admin();

        $present = Employee::factory()->create(['full_name' => 'Present Person']);
        $this->attendanceRow($present, AttendanceStatus::Present);

        $absent = Employee::factory()->create(['full_name' => 'Absent Person']);
        $this->attendanceRow($absent, AttendanceStatus::Absent);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) {
            return $attendance['today']['present']['count'] === 1
                && collect($attendance['today']['segments'])->firstWhere('key', 'absent')['count'] === 1;
        });
    }

    public function test_manager_only_sees_their_own_teams_attendance_and_headcount(): void
    {
        $managerEmployee = Employee::factory()->create(['full_name' => 'Team Manager']);
        $manager = $this->managerUser($managerEmployee);

        $subordinate = Employee::factory()->create(['manager_id' => $managerEmployee->id, 'full_name' => 'My Report']);
        $this->attendanceRow($subordinate, AttendanceStatus::Present);

        // Someone outside the manager's team — must not leak into their figures.
        $outsider = Employee::factory()->create(['full_name' => 'Someone Elses Report']);
        $this->attendanceRow($outsider, AttendanceStatus::Absent);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        // Total employees is scoped like the employee directory's own stats —
        // 2, not 3: manager + their report, not the outsider.
        $response->assertViewHas('stats', fn ($stats) => $stats['total_employees'] === 2);

        $response->assertViewHas('attendance', function ($attendance) {
            $absentSegment = collect($attendance['today']['segments'])->firstWhere('key', 'absent');

            return $attendance['today']['present']['count'] === 1
                && ($absentSegment['count'] ?? 0) === 0;
        });
    }

    public function test_a_managers_new_this_month_only_counts_their_own_team(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00'));

        $managerEmployee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $manager = $this->managerUser($managerEmployee);

        Employee::factory()->create(['manager_id' => $managerEmployee->id, 'join_date' => '2026-03-10']); // in scope, this month
        Employee::factory()->create(['join_date' => '2026-03-12']); // outsider, must not count

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertViewHas('stats', fn ($stats) => $stats['new_this_month'] === 1);
    }

    public function test_a_manager_with_no_linked_employee_sees_an_empty_scope_not_an_error(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        Employee::factory()->create();
        $this->attendanceRow(Employee::factory()->create(), AttendanceStatus::Present);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('attendance', fn ($attendance) => $attendance['today']['present']['count'] === 0
            && $attendance['needsAttention'] === []
        );
        $response->assertViewHas('stats', fn ($stats) => $stats['total_employees'] === 0
            && $stats['new_this_month'] === 0
        );
    }

    public function test_needs_attention_lists_absent_incomplete_and_late_with_the_right_badge(): void
    {
        $admin = $this->admin();

        $absent = Employee::factory()->create(['full_name' => 'Absent Ann']);
        $this->attendanceRow($absent, AttendanceStatus::Absent);

        $incomplete = Employee::factory()->create(['full_name' => 'Incomplete Ian']);
        $this->attendanceRow($incomplete, AttendanceStatus::Incomplete);

        $late = Employee::factory()->create(['full_name' => 'Late Larry']);
        $this->attendanceRow($late, AttendanceStatus::Present, ['late_minutes' => 12]);

        $onTime = Employee::factory()->create(['full_name' => 'On Time Otto']);
        $this->attendanceRow($onTime, AttendanceStatus::Present);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) {
            $byName = collect($attendance['needsAttention'])->keyBy('name');

            return $byName->has('Absent Ann') && $byName['Absent Ann']['badge'] === 'red'
                && $byName->has('Incomplete Ian') && $byName['Incomplete Ian']['badge'] === 'violet'
                && $byName->has('Late Larry') && $byName['Late Larry']['badge'] === 'amber'
                && ! $byName->has('On Time Otto');
        });
    }

    public function test_weekly_trend_is_seven_days_ending_today_as_the_present_share_of_active_employees(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 12:00:00')); // a Wednesday: the window is Thu Mar 5 through Wed Mar 11

        [$a, $b, $c] = Employee::factory()->count(3)->create()->all();
        Employee::factory()->create(['status' => 'inactive']); // never part of the denominator

        // Mar 5, the first day of the window: all three present.
        foreach ([$a, $b, $c] as $employee) {
            $this->attendanceRow($employee, AttendanceStatus::Present, ['work_date' => '2026-03-05']);
        }

        // Mar 10: two present — a late arrival is still present.
        $this->attendanceRow($a, AttendanceStatus::Present, ['work_date' => '2026-03-10']);
        $this->attendanceRow($b, AttendanceStatus::Present, ['work_date' => '2026-03-10', 'late_minutes' => 20]);

        // Mar 11, today: one present; incomplete and absent don't count.
        $this->attendanceRow($a, AttendanceStatus::Present);
        $this->attendanceRow($b, AttendanceStatus::Incomplete);
        $this->attendanceRow($c, AttendanceStatus::Absent);

        // Mar 4 is one day before the window opens, so it appears nowhere.
        $this->attendanceRow($a, AttendanceStatus::Present, ['work_date' => '2026-03-04']);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => $attendance['trend'] === [
                ['label' => 'Thu', 'value' => 100.0],
                ['label' => 'Fri', 'value' => 0.0],
                ['label' => 'Sat', 'value' => 0.0],
                ['label' => 'Sun', 'value' => 0.0],
                ['label' => 'Mon', 'value' => 0.0],
                ['label' => 'Tue', 'value' => 66.7],
                ['label' => 'Wed', 'value' => 33.3],
            ]);
    }

    public function test_weekly_trend_is_empty_when_there_are_no_active_employees(): void
    {
        Employee::factory()->create(['status' => 'inactive']);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => $attendance['trend'] === []);
    }

    public function test_a_managers_weekly_trend_only_counts_their_own_team(): void
    {
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);
        $report = Employee::factory()->create(['manager_id' => $managerEmployee->id]);
        $outsider = Employee::factory()->create();

        $this->attendanceRow($report, AttendanceStatus::Present);
        $this->attendanceRow($outsider, AttendanceStatus::Present);
        $this->attendanceRow($managerEmployee, AttendanceStatus::Absent);

        // Team of two, one present today = 50.0. Company-wide it would be 2 of 3 = 66.7.
        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => end($attendance['trend'])['value'] === 50.0);
    }

    public function test_a_managers_needs_attention_only_lists_their_own_team(): void
    {
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);

        $mine = Employee::factory()->create(['manager_id' => $managerEmployee->id, 'full_name' => 'My Absent Report']);
        $this->attendanceRow($mine, AttendanceStatus::Absent);

        $outsider = Employee::factory()->create(['full_name' => 'Elsewhere Absent']);
        $this->attendanceRow($outsider, AttendanceStatus::Absent);
        $lateOutsider = Employee::factory()->create(['full_name' => 'Elsewhere Late']);
        $this->attendanceRow($lateOutsider, AttendanceStatus::Present, ['late_minutes' => 9]);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => collect($attendance['needsAttention'])->pluck('name')->all() === ['My Absent Report']);

        // The same data for an admin includes everyone, so the scoping above is doing the filtering.
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => collect($attendance['needsAttention'])->pluck('name')->sort()->values()->all() === ['Elsewhere Absent', 'Elsewhere Late', 'My Absent Report']);
    }

    public function test_a_managers_recent_activity_only_shows_their_own_teams_punches(): void
    {
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);

        $mine = Employee::factory()->create(['manager_id' => $managerEmployee->id, 'full_name' => 'My Punching Report']);
        $outsider = Employee::factory()->create(['full_name' => 'Elsewhere Puncher']);

        foreach ([$mine, $outsider] as $employee) {
            AttendanceLog::factory()->create([
                'employee_id' => $employee->id,
                'punch_type' => PunchType::In,
                'punched_at' => today()->setTime(8, 30),
            ]);
        }

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => collect($attendance['recent'])->pluck('name')->all() === ['My Punching Report']);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => collect($attendance['recent'])->pluck('name')->sort()->values()->all() === ['Elsewhere Puncher', 'My Punching Report']);
    }

    public function test_department_attendance_omits_departments_with_no_one_in_the_managers_scope(): void
    {
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);

        $ownDepartment = $managerEmployee->department;
        $otherDepartment = Department::factory()->create();
        Employee::factory()->create(['department_id' => $otherDepartment->id]);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) use ($ownDepartment, $otherDepartment) {
            $names = collect($attendance['departments'])->pluck('name');

            return $names->contains($ownDepartment->name) && ! $names->contains($otherDepartment->name);
        });
    }

    public function test_recent_activity_shows_a_late_check_in_with_its_minutes(): void
    {
        $admin = $this->admin();

        $employee = Employee::factory()->create(['full_name' => 'Punchy Person']);
        $this->attendanceRow($employee, AttendanceStatus::Present, ['late_minutes' => 8]);

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punch_type' => PunchType::In,
            'punched_at' => today()->setTime(9, 8),
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) {
            $activity = collect($attendance['recent'])->firstWhere('name', 'Punchy Person');

            return $activity !== null && $activity['tone'] === 'late' && str_contains($activity['action'], '8m late');
        });
    }

    public function test_employee_role_does_not_see_attendance_stats_on_the_dashboard(): void
    {
        $employee = User::factory()->create()->assignRole('employee');

        $response = $this->actingAs($employee)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats === null);
    }
}
