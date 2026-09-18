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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The dashboard now reads real daily_attendances/attendance_logs data via
 * App\Support\DashboardAttendance, replacing the old DemoAttendance
 * placeholder. These tests cover the one thing that placeholder never had to
 * get right: row-level scoping (a manager sees only their own team's
 * attendance figures, mirroring Attendance\Index/Show — see routes/web.php's
 * comment on why Total employees stays company-wide while these don't).
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

    public function test_manager_only_sees_their_own_teams_attendance_but_the_full_company_headcount(): void
    {
        $managerEmployee = Employee::factory()->create(['full_name' => 'Team Manager']);
        $manager = $this->managerUser($managerEmployee);

        $subordinate = Employee::factory()->create(['manager_id' => $managerEmployee->id, 'full_name' => 'My Report']);
        $this->attendanceRow($subordinate, AttendanceStatus::Present);

        // Someone outside the manager's team — must not leak into their figures.
        $outsider = Employee::factory()->create(['full_name' => 'Someone Elses Report']);
        $this->attendanceRow($outsider, AttendanceStatus::Absent);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        // Total employees stays company-wide (matches Employees\Index's own
        // unscoped precedent) — 3 total: manager + their report + the outsider.
        $response->assertViewHas('stats', fn ($stats) => $stats['total_employees'] === 3);

        $response->assertViewHas('attendance', function ($attendance) {
            $absentSegment = collect($attendance['today']['segments'])->firstWhere('key', 'absent');

            return $attendance['today']['present']['count'] === 1
                && ($absentSegment['count'] ?? 0) === 0;
        });
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
