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

    public function test_weekly_trend_is_seven_days_ending_today_as_the_attendance_rate_of_active_employees(): void
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

        // Mar 11, today, closed (every row final): attended = present + incomplete (Phase 2.7); absent doesn't count.
        $this->attendanceRow($a, AttendanceStatus::Present);
        $this->attendanceRow($b, AttendanceStatus::Incomplete);
        $this->attendanceRow($c, AttendanceStatus::Absent);

        // Mar 4 is one day before the window opens, so it appears nowhere.
        $this->attendanceRow($a, AttendanceStatus::Present, ['work_date' => '2026-03-04']);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => $attendance['trend'] === [
                ['label' => 'Thu', 'date' => '2026-03-05', 'value' => 100.0, 'marker' => null, 'pending' => false],
                // No rows at all: never a 0% bar, never absent.
                ['label' => 'Fri', 'date' => '2026-03-06', 'value' => null, 'marker' => 'Not calculated', 'pending' => false],
                ['label' => 'Sat', 'date' => '2026-03-07', 'value' => null, 'marker' => 'Not calculated', 'pending' => false],
                ['label' => 'Sun', 'date' => '2026-03-08', 'value' => null, 'marker' => 'Not calculated', 'pending' => false],
                ['label' => 'Mon', 'date' => '2026-03-09', 'value' => null, 'marker' => 'Not calculated', 'pending' => false],
                ['label' => 'Tue', 'date' => '2026-03-10', 'value' => 66.7, 'marker' => null, 'pending' => false],
                ['label' => 'Wed', 'date' => '2026-03-11', 'value' => 66.7, 'marker' => null, 'pending' => false],
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

    public function test_recent_activity_is_a_neutral_log_without_timing(): void
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

        // A log: plain "Checked in", no late minutes and no amber — the late
        // fact is shown once, in Needs attention.
        $response->assertViewHas('attendance', function ($attendance) {
            $activity = collect($attendance['recent'])->firstWhere('name', 'Punchy Person');

            return $activity !== null && $activity['action'] === 'Checked in' && ! array_key_exists('tone', $activity);
        });
        $response->assertDontSee('Checked in 8m late')
            ->assertSee('<p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Checked in</p>', false);
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

    public function test_the_dashboard_uses_the_shared_three_cell_stat_strip(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $employee = Employee::factory()->create();
        $this->attendanceRow($employee, AttendanceStatus::Present, ['first_in' => today()->setTime(7, 50), 'last_out' => today()->setTime(16, 0), 'early_leave_minutes' => 60]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertSee('Today, '.today()->format('D j M'))
            // Live "who is here now" language, not end-of-day status counts.
            ->assertSeeInOrder(['At work', '0', 'Left', '1', '1 early', 'Not in', '0'])
            // No separate headcount cell (Phase 2.6): the total lives in the
            // strip's meta, and the three cells stay one row at every width.
            ->assertSee('1 active employee')
            ->assertDontSee('added this month')
            ->assertSee('grid grid-cols-3 divide-x', false)
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
            ->assertSee('Wed 11 Mar')
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

            // Two of three checked in: a provisional bar of the checked-in
            // share, never 0% for the one not in yet.
            return $today['pending'] === true && $today['marker'] === 'Today' && $today['value'] === 66.7
                && $dept['pending'] === true && $dept['checkedIn'] === 2;
        });

        $response->assertSee('Checked in', false)
            ->assertSee('Today', false)
            // In progress carries no percentage in the stat strip.
            ->assertDontSee('>100%<', false)
            ->assertDontSee('>0%<', false);
    }

    public function test_todays_provisional_bar_is_the_checked_in_share_with_the_today_marker(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 16:30:00'));
        $early = Employee::factory()->create();
        $working = Employee::factory()->create();

        $notYet = Employee::factory()->create();

        $this->attendanceRow($early, AttendanceStatus::Present, ['first_in' => Carbon::parse('2026-03-11 07:52:00'), 'last_out' => Carbon::parse('2026-03-11 15:00:00')]);
        $this->attendanceRow($working, AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 07:58:00')]);
        $this->attendanceRow($notYet, AttendanceStatus::InProgress);

        // The provisional bar is checked in so far (2 of 3), not present (1 of 3).
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', function ($attendance) {
                $today = end($attendance['trend']);

                return $today['pending'] === true && $today['marker'] === 'Today' && $today['value'] === 66.7;
            });
    }

    public function test_recent_activity_dates_only_entries_that_are_not_from_today(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 12:00:00'));
        $employee = Employee::factory()->create(['full_name' => 'Yesterday Yan']);
        $other = Employee::factory()->create(['full_name' => 'Today Tess']);

        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punch_type' => PunchType::Out, 'punched_at' => Carbon::parse('2026-03-10 17:05:00')]);
        AttendanceLog::factory()->create(['employee_id' => $other->id, 'punch_type' => PunchType::In, 'punched_at' => Carbon::parse('2026-03-11 07:55:00')]);

        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertViewHas('attendance', function ($attendance) {
                $byName = collect($attendance['recent'])->keyBy('name');

                return $byName['Yesterday Yan']['date'] === 'Tue 10 Mar' && $byName['Today Tess']['date'] === null;
            })
            ->assertSee('Tue 10 Mar');
    }

    public function test_the_live_strip_is_a_partition_that_sums_to_active_employees(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 12:00:00'));
        $at = fn () => Carbon::parse('2026-03-11 07:55:00');

        $working = Employee::factory()->create();
        $workingIncomplete = Employee::factory()->create();
        $leftOnTime = Employee::factory()->create();
        $leftEarly = Employee::factory()->create();
        $noPunch = Employee::factory()->create();
        $outOnly = Employee::factory()->create();
        $absent = Employee::factory()->create();
        Employee::factory()->create(); // not calculated yet: no row at all
        $inactive = Employee::factory()->create(['status' => 'inactive']);

        $this->attendanceRow($working, AttendanceStatus::InProgress, ['first_in' => $at()]);
        $this->attendanceRow($workingIncomplete, AttendanceStatus::Incomplete, ['first_in' => $at()]);
        $this->attendanceRow($leftOnTime, AttendanceStatus::Present, ['first_in' => $at(), 'last_out' => Carbon::parse('2026-03-11 11:00:00')]);
        $this->attendanceRow($leftEarly, AttendanceStatus::Present, ['first_in' => $at(), 'last_out' => Carbon::parse('2026-03-11 11:30:00'), 'early_leave_minutes' => 330]);
        $this->attendanceRow($noPunch, AttendanceStatus::InProgress);
        $this->attendanceRow($outOnly, AttendanceStatus::InProgress, ['last_out' => Carbon::parse('2026-03-11 11:45:00')]);
        $this->attendanceRow($absent, AttendanceStatus::Absent);
        // An inactive employee's row is outside the active partition.
        $this->attendanceRow($inactive, AttendanceStatus::Present, ['first_in' => $at(), 'last_out' => Carbon::parse('2026-03-11 11:00:00')]);

        $live = \App\Support\DashboardAttendance::liveToday(null);

        // Partitioned by punches: an out-punch means Left, even with no in-punch.
        $this->assertSame(8, $live['total']);
        $this->assertSame(2, $live['atWork']);
        $this->assertSame(3, $live['left']);
        $this->assertSame(1, $live['leftEarly']);
        $this->assertSame(3, $live['notIn']);
        $this->assertSame($live['total'], $live['atWork'] + $live['left'] + $live['notIn']);
        // Not in's sub-counts are its exact breakdown: no punch yet + not built (due), absent.
        $this->assertSame(['notInDue' => 2, 'notInAbsent' => 1], array_intersect_key($live, array_flip(['notInDue', 'notInAbsent'])));
        $this->assertSame($live['notIn'], $live['notInDue'] + $live['notInAbsent'] + $live['notInOff'] + $live['notInHoliday'] + $live['notInLeave']);
        // "Checked in" overlaps the partition: everyone with an in-punch,
        // whether still at work or already left.
        $this->assertSame(4, $live['checkedIn']);
    }

    private function onSchedule(Employee $employee, array $overrides = []): WorkSchedule
    {
        $schedule = WorkSchedule::factory()->create(array_merge([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 10,
            'break_minutes' => 60, 'workdays' => [1, 2, 3, 4, 5],
        ], $overrides));
        $employee->scheduleAssignments()->update(['work_schedule_id' => $schedule->id]);

        return $schedule;
    }

    public function test_not_in_yet_starts_only_after_start_time_plus_grace(): void
    {
        $employee = Employee::factory()->create();
        $schedule = $this->onSchedule($employee);
        $row = $this->attendanceRow($employee, AttendanceStatus::InProgress, [
            'work_date' => '2026-03-11', 'work_schedule_id' => $schedule->id,
        ]);
        $row->load('workSchedule');

        $this->assertFalse($row->isNotInYet(Carbon::parse('2026-03-11 07:30:00')));
        // 08:10 is the end of grace: still simply in progress.
        $this->assertFalse($row->isNotInYet(Carbon::parse('2026-03-11 08:10:00')));
        $this->assertTrue($row->isNotInYet(Carbon::parse('2026-03-11 08:10:01')));
        // Only for today's row.
        $this->assertFalse($row->isNotInYet(Carbon::parse('2026-03-12 09:00:00')));

        // A punch of either kind means they're not "not in yet".
        $row->first_in = Carbon::parse('2026-03-11 08:30:00');
        $this->assertFalse($row->isNotInYet(Carbon::parse('2026-03-11 09:00:00')));
    }

    public function test_not_in_yet_is_never_off_holiday_leave_or_absent(): void
    {
        $now = Carbon::parse('2026-03-11 10:00:00');

        foreach ([AttendanceStatus::Off, AttendanceStatus::Holiday, AttendanceStatus::Leave, AttendanceStatus::Absent] as $status) {
            $employee = Employee::factory()->create();
            $schedule = $this->onSchedule($employee);
            $row = $this->attendanceRow($employee, $status, ['work_date' => '2026-03-11', 'work_schedule_id' => $schedule->id]);

            $this->assertFalse($row->load('workSchedule')->isNotInYet($now), $status->value);
        }
    }

    public function test_needs_attention_lists_late_arrivals_then_not_in_yet_mid_day(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 09:30:00'));
        $make = function (string $name, AttendanceStatus $status, array $overrides = []) {
            $employee = Employee::factory()->create(['full_name' => $name]);
            $schedule = $this->onSchedule($employee);

            return $this->attendanceRow($employee, $status, ['work_schedule_id' => $schedule->id, ...$overrides]);
        };

        $make('Not Yet Nina', AttendanceStatus::InProgress);
        $make('Late Lou', AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 08:45:00'), 'late_minutes' => 45]);
        $make('On Time Oscar', AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 07:55:00')]);
        $make('Later Lena', AttendanceStatus::InProgress, ['first_in' => Carbon::parse('2026-03-11 09:20:00'), 'late_minutes' => 80]);

        $response = $this->actingAs($this->admin())->get(route('dashboard'));

        $response->assertViewHas('attendance', function ($attendance) {
            $list = collect($attendance['needsAttention']);

            return $list->pluck('name')->all() === ['Later Lena', 'Late Lou', 'Not Yet Nina']
                && $list->pluck('kind')->all() === ['late', 'late', 'not_in_yet']
                && $list[0]['detail'] === '1h 20m late'
                && $list[2]['detail'] === 'Not in yet · due 8:00 AM'
                && $list->every(fn ($item) => $item['badge'] === null);
        });
        $response->assertSeeInOrder(['Later Lena', '1h 20m late', 'Late Lou', '45m late', 'Not Yet Nina', 'Not in yet · due 8:00 AM']);
    }

    public function test_before_start_plus_grace_a_punchless_employee_is_not_listed_as_not_in_yet(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 08:05:00'));
        $employee = Employee::factory()->create(['full_name' => 'Early Bird Ed']);
        $schedule = $this->onSchedule($employee);
        $this->attendanceRow($employee, AttendanceStatus::InProgress, ['work_schedule_id' => $schedule->id]);

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertViewHas('attendance', fn ($attendance) => $attendance['needsAttention'] === []
                // ...but the strip still counts them as not in yet (wider scope).
                && $attendance['live']['notIn'] === 1)
            ->assertSee('Nothing needs attention today.');
    }

    public function test_the_live_strip_carries_late_as_a_sub_line_on_at_work_and_left(): void
    {
        $this->travelTo(Carbon::parse('2026-03-11 15:00:00'));
        $in = fn (string $time) => Carbon::parse('2026-03-11 '.$time);

        foreach ([['08:40', 40], ['08:30', 30], ['07:55', 0]] as [$time, $late]) {
            $this->attendanceRow(Employee::factory()->create(), AttendanceStatus::InProgress, ['first_in' => $in($time), 'late_minutes' => $late]);
        }
        $this->attendanceRow(Employee::factory()->create(), AttendanceStatus::Present, ['first_in' => $in('08:20'), 'last_out' => $in('14:00'), 'late_minutes' => 20, 'early_leave_minutes' => 180]);
        $this->attendanceRow(Employee::factory()->create(), AttendanceStatus::InProgress);

        $live = \App\Support\DashboardAttendance::liveToday(null);

        $this->assertSame(['atWork' => 3, 'atWorkLate' => 2, 'left' => 1, 'leftLate' => 1, 'leftEarly' => 1, 'notIn' => 1], array_intersect_key($live, array_flip(['atWork', 'atWorkLate', 'left', 'leftLate', 'leftEarly', 'notIn'])));
        // Late is an annotation on a group, never a fourth group: the
        // partition still sums to active employees.
        $this->assertSame($live['total'], $live['atWork'] + $live['left'] + $live['notIn']);

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertSeeInOrder(['At work', '3', '2 late', 'Left', '1', '1 late · 1 early', 'Not in', '1']);
    }
}
