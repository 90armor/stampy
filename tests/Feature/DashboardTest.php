<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
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

        // Every employee is now assigned a schedule at creation, which needs a default to exist.
        WorkSchedule::factory()->create(['is_default' => true]);

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

    public function test_application_shell_mobile_navigation_has_dialog_and_keyboard_support(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('role="dialog" aria-modal="true" aria-label="Navigation"', false)
            ->assertSee('@keydown.escape.window="if (sidebarOpen) closeSidebar()"', false)
            ->assertSee('@keydown.tab="trapSidebarTab($event)"', false)
            ->assertSee('x-ref="sidebarClose"', false)
            ->assertSee('Close navigation')
            ->assertSee('aria-haspopup="true"', false)
            ->assertSee(':aria-expanded="open.toString()"', false)
            ->assertSee('@keydown.escape.stop.prevent="close(true)"', false);
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
        $this->attendanceRow($late, AttendanceStatus::Present, ['late_minutes' => 80]);

        $onTime = Employee::factory()->create(['full_name' => 'On Time Otto']);
        $this->attendanceRow($onTime, AttendanceStatus::Present);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) {
            $byName = collect($attendance['needsAttention'])->keyBy('name');

            return $byName->has('Absent Ann') && $byName['Absent Ann']['badge'] === 'red'
                && $byName->has('Incomplete Ian') && $byName['Incomplete Ian']['badge'] === 'violet'
                // Late is timing, not a status: no badge, just the duration.
                && $byName->has('Late Larry') && $byName['Late Larry']['badge'] === null
                && $byName['Late Larry']['detail'] === '1h 20m late'
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
                ['label' => 'Thu', 'date' => '2026-03-05', 'value' => 100.0, 'marker' => null, 'pending' => false],
                ['label' => 'Fri', 'date' => '2026-03-06', 'value' => 0.0, 'marker' => null, 'pending' => false],
                ['label' => 'Sat', 'date' => '2026-03-07', 'value' => 0.0, 'marker' => null, 'pending' => false],
                ['label' => 'Sun', 'date' => '2026-03-08', 'value' => 0.0, 'marker' => null, 'pending' => false],
                ['label' => 'Mon', 'date' => '2026-03-09', 'value' => 0.0, 'marker' => null, 'pending' => false],
                ['label' => 'Tue', 'date' => '2026-03-10', 'value' => 66.7, 'marker' => null, 'pending' => false],
                ['label' => 'Wed', 'date' => '2026-03-11', 'value' => 33.3, 'marker' => null, 'pending' => false],
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

    /**
     * Quick Actions is gated per-item by the exact ability its destination
     * enforces (EmployeePolicy::create/viewAny, DepartmentPolicy::viewAny),
     * not a role list — this pins what each role actually sees, which is
     * the test that would have caught the bug this replaced: the card used
     * to render for anyone with $stats set (admin or manager), hardcoding
     * all four actions regardless of whether the viewer could open them.
     */
    public function test_admin_sees_every_quick_action(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Quick actions')
            ->assertSee('Add employee')
            ->assertSee('Add department')
            ->assertSee('View employees')
            ->assertSee('Organization settings');
    }

    public function test_manager_sees_no_quick_actions_card_at_all(): void
    {
        // create() on both Employee and Department is admin-only, so
        // filtering by ability leaves a manager with exactly one action —
        // "View employees" — which duplicates the sidebar's own "Employees"
        // link one-for-one. The whole card is skipped rather than shown
        // with that single redundant link.
        $managerEmployee = Employee::factory()->create();
        $manager = $this->managerUser($managerEmployee);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Quick actions')
            ->assertDontSee('Add employee')
            ->assertDontSee('Add department')
            ->assertDontSee('Organization settings');
    }

    public function test_employee_role_sees_no_quick_actions_card_either(): void
    {
        $employee = User::factory()->create()->assignRole('employee');

        $this->actingAs($employee)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Quick actions');
    }

    public function test_the_dashboard_uses_the_shared_stat_strip_with_headcount(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create();
        $this->attendanceRow($employee, AttendanceStatus::Present, ['late_minutes' => 5]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertSee('Today, '.today()->format('D, j M'))
            ->assertSee('1 late')
            ->assertSee('added this month')
            ->assertSee('text-xl font-semibold leading-7 tabular-nums', false)
            // The nested tinted tiles and the separate Employee summary card are gone.
            ->assertDontSee('rounded-xl bg-slate-50 p-4', false)
            ->assertDontSee('Employee summary');
    }

    public function test_an_off_day_in_the_trend_is_a_marker_not_a_zero_percent_bar(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 12:00:00'));
        $a = Employee::factory()->create();
        $b = Employee::factory()->create();

        foreach ([$a, $b] as $employee) {
            $this->attendanceRow($employee, AttendanceStatus::Off, ['work_date' => '2026-03-07']);
            $this->attendanceRow($employee, AttendanceStatus::Holiday, ['work_date' => '2026-03-09']);
        }
        $this->attendanceRow($a, AttendanceStatus::Present, ['work_date' => '2026-03-10']);
        $this->attendanceRow($b, AttendanceStatus::Absent, ['work_date' => '2026-03-10']);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', function ($attendance) {
                $byDate = collect($attendance['trend'])->keyBy('date');

                return $byDate['2026-03-07']['value'] === null && $byDate['2026-03-07']['marker'] === 'Off'
                    && $byDate['2026-03-09']['value'] === null && $byDate['2026-03-09']['marker'] === 'Holiday'
                    && $byDate['2026-03-10']['value'] === 50.0 && $byDate['2026-03-10']['marker'] === null;
            })
            // Time scope lives in the card header's meta slot as real dates.
            ->assertSee('5–11 Mar')
            ->assertSee('Sat Off', false);
    }

    public function test_dashboard_cards_use_title_and_meta_headers_without_eyebrows(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 12:00:00'));
        $late = Employee::factory()->create(['full_name' => 'Late Larry']);
        $this->attendanceRow($late, AttendanceStatus::Present, ['late_minutes' => 80]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertSee('Needs attention')
            ->assertSee('1 today')
            ->assertSee('Wed, 11 Mar')
            ->assertSee('1h 20m late')
            ->assertDontSee('tracking-widest', false)
            ->assertDontSee('Action required')
            ->assertDontSee('Last seven days')
            // No "Late" badge and no amber avatar on the late row.
            ->assertDontSee('bg-amber-50 text-amber-600', false)
            ->assertDontSee('>Late<', false);
    }

    public function test_a_pending_today_is_marked_never_zero_percent_or_absent(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 11:00:00'));
        $department = Department::factory()->create(['name' => 'Pending Dept']);
        $in = Employee::factory()->create(['department_id' => $department->id]);
        $alsoIn = Employee::factory()->create(['department_id' => $department->id]);
        $notYet = Employee::factory()->create(['department_id' => $department->id]);

        $this->attendanceRow($in, AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 07:55:00')]);
        $this->attendanceRow($alsoIn, AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 08:02:00')]);
        $this->attendanceRow($notYet, AttendanceStatus::InProgress);

        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) {
            $today = end($attendance['trend']);
            $dept = collect($attendance['departments'])->firstWhere('name', 'Pending Dept');

            return $today['pending'] === true && $today['marker'] === 'Today' && $today['value'] === null
                && $dept['pending'] === true && $dept['checkedIn'] === 2;
        });

        $response->assertSee('Checked in', false)
            ->assertSee('Today', false)
            // In progress carries no percentage in the stat strip.
            ->assertDontSee('>100%<', false)
            ->assertDontSee('>0%<', false);
    }

    public function test_partial_present_data_today_is_a_provisional_bar_with_the_today_marker(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 16:30:00'));
        $early = Employee::factory()->create();
        $working = Employee::factory()->create();

        $this->attendanceRow($early, AttendanceStatus::Present);
        $this->attendanceRow($working, AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 07:58:00')]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', function ($attendance) {
                $today = end($attendance['trend']);

                return $today['pending'] === true && $today['marker'] === 'Today' && $today['value'] === 50.0;
            });
    }
}
