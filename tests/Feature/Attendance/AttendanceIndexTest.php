<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Index;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceIndexTest extends TestCase
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

    private function attendanceRow(string $workDate, AttendanceStatus $status, array $employeeOverrides = []): DailyAttendance
    {
        $employee = Employee::factory()->create($employeeOverrides);

        return DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'status' => $status,
        ]);
    }

    private function attendanceRowForEmployee(Employee $employee, string $workDate, AttendanceStatus $status): DailyAttendance
    {
        return DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'status' => $status,
        ]);
    }

    private function managerUser(Employee $employee): User
    {
        $user = User::factory()->create()->assignRole('manager');
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    public function test_admin_can_view_the_attendance_list(): void
    {
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, ['full_name' => 'Admin List Target']);

        $this->actingAs($this->admin())
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('Daily attendance')
            ->assertSee('Admin List Target');
    }

    public function test_employee_role_cannot_view_the_attendance_list(): void
    {
        $employee = User::factory()->create()->assignRole('employee');

        $this->actingAs($employee)->get(route('attendance.index'))->assertForbidden();
    }

    public function test_default_state_shows_only_today_and_excludes_off(): void
    {
        $admin = $this->admin();

        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, ['full_name' => 'Filter Target Today']);
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Off, ['full_name' => 'Filter Target Off']);
        $this->attendanceRow(today()->subDay()->format('Y-m-d'), AttendanceStatus::Present, ['full_name' => 'Filter Target Yesterday']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('Filter Target Today')
            ->assertDontSee('Filter Target Off')
            ->assertDontSee('Filter Target Yesterday');
    }

    public function test_employee_filter_narrows_by_name_or_code(): void
    {
        $admin = $this->admin();

        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, [
            'full_name' => 'Zaw Naming Match', 'employee_code' => 'EMP-8001',
        ]);
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, [
            'full_name' => 'Different Person', 'employee_code' => 'EMP-8002',
        ]);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('employeeFilter', 'Naming')
            ->assertSee('Zaw Naming Match')
            ->assertDontSee('Different Person');
    }

    public function test_department_filter_narrows_across_the_relation(): void
    {
        $admin = $this->admin();
        $deptA = Department::factory()->create(['name' => 'Dept Alpha']);
        $deptB = Department::factory()->create(['name' => 'Dept Beta']);

        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, [
            'full_name' => 'Alpha Person', 'department_id' => $deptA->id,
        ]);
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, [
            'full_name' => 'Beta Person', 'department_id' => $deptB->id,
        ]);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('departmentFilter', (string) $deptA->id)
            ->assertSee('Alpha Person')
            ->assertDontSee('Beta Person');
    }

    public function test_status_filter_narrows(): void
    {
        $admin = $this->admin();

        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, ['full_name' => 'Present Person']);
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Absent, ['full_name' => 'Absent Person']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('statuses', ['present'])
            ->assertSee('Present Person')
            ->assertDontSee('Absent Person');
    }

    public function test_date_range_narrows(): void
    {
        $admin = $this->admin();

        $this->attendanceRow(today()->subDays(5)->format('Y-m-d'), AttendanceStatus::Present, ['full_name' => 'Ranged Person']);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertDontSee('Ranged Person')
            ->set('fromDate', today()->subDays(10)->format('Y-m-d'))
            ->set('toDate', today()->format('Y-m-d'))
            ->assertSee('Ranged Person');
    }

    public function test_summary_ignores_employee_department_and_status_filters(): void
    {
        $admin = $this->admin();
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present, ['department_id' => $deptA->id]);
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Absent, ['department_id' => $deptB->id]);

        $component = Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('departmentFilter', (string) $deptA->id)
            ->set('statuses', ['present']);

        $summary = $component->instance()->render()->getData()['summary'];

        // The table is narrowed to deptA/present only, but the summary must
        // still show both statuses across both departments — it only
        // scopes by date range.
        $this->assertSame(1, $summary->get('present'));
        $this->assertSame(1, $summary->get('absent'));
    }

    public function test_summary_reflects_the_whole_filtered_set_not_just_page_one(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 25; $i++) {
            $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present);
        }

        $component = Livewire::actingAs($admin)->test(Index::class);
        $data = $component->instance()->render()->getData();

        $this->assertSame(25, $data['summary']->get('present'));
        $this->assertSame(25, $data['attendances']->total());
        $this->assertSame(20, $data['attendances']->count());
    }

    public function test_summary_is_a_fixed_key_set_with_zero_fallback(): void
    {
        $admin = $this->admin();

        // Only a present row and an off row exist today — late/early/
        // absent/incomplete have zero rows, and off isn't part of the
        // fixed set.
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present);
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Off);

        $summary = Livewire::actingAs($admin)
            ->test(Index::class)
            ->instance()
            ->render()
            ->getData()['summary'];

        $this->assertSame(['present', 'late', 'early', 'absent', 'incomplete'], $summary->keys()->all());
        $this->assertSame(1, $summary->get('present'));
        $this->assertSame(0, $summary->get('late'));
        $this->assertSame(0, $summary->get('early'));
        $this->assertSame(0, $summary->get('absent'));
        $this->assertSame(0, $summary->get('incomplete'));
    }

    public function test_changing_a_filter_resets_pagination_to_page_one(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 25; $i++) {
            $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present);
        }

        $component = Livewire::actingAs($admin)->test(Index::class)->call('gotoPage', 2);

        $this->assertSame(2, $component->instance()->render()->getData()['attendances']->currentPage());

        $component->set('employeeFilter', 'x');

        $this->assertSame(1, $component->instance()->render()->getData()['attendances']->currentPage());
    }

    public function test_date_range_past_the_last_built_date_shows_the_notice(): void
    {
        $admin = $this->admin();

        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Present);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('toDate', today()->addDays(5)->format('Y-m-d'))
            ->assertSee('only been calculated up to');
    }

    public function test_admin_sees_every_employees_attendance(): void
    {
        $admin = $this->admin();

        $topA = Employee::factory()->create(['full_name' => 'Top A']);
        $topB = Employee::factory()->create(['full_name' => 'Top B']);
        $this->attendanceRowForEmployee($topA, today()->format('Y-m-d'), AttendanceStatus::Present);
        $this->attendanceRowForEmployee($topB, today()->format('Y-m-d'), AttendanceStatus::Present);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('Top A')
            ->assertSee('Top B');
    }

    public function test_manager_sees_only_their_own_and_transitive_subordinates(): void
    {
        $top = Employee::factory()->create(['full_name' => 'Manager Self']);
        $mid = Employee::factory()->create(['full_name' => 'Direct Report', 'manager_id' => $top->id]);
        $leaf = Employee::factory()->create(['full_name' => 'Report Of Report', 'manager_id' => $mid->id]);
        $otherBranch = Employee::factory()->create(['full_name' => 'Unrelated Person']);

        $this->attendanceRowForEmployee($top, today()->format('Y-m-d'), AttendanceStatus::Present);
        $this->attendanceRowForEmployee($mid, today()->format('Y-m-d'), AttendanceStatus::Present);
        $this->attendanceRowForEmployee($leaf, today()->format('Y-m-d'), AttendanceStatus::Present);
        $this->attendanceRowForEmployee($otherBranch, today()->format('Y-m-d'), AttendanceStatus::Present);

        $manager = $this->managerUser($top);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertSee('Manager Self')
            ->assertSee('Direct Report')
            ->assertSee('Report Of Report')
            ->assertDontSee('Unrelated Person');
    }

    public function test_manager_summary_reflects_only_the_scoped_set(): void
    {
        $top = Employee::factory()->create();
        $subordinate = Employee::factory()->create(['manager_id' => $top->id]);
        $outsider = Employee::factory()->create();

        $this->attendanceRowForEmployee($top, today()->format('Y-m-d'), AttendanceStatus::Present);
        $this->attendanceRowForEmployee($subordinate, today()->format('Y-m-d'), AttendanceStatus::Absent);
        $this->attendanceRowForEmployee($outsider, today()->format('Y-m-d'), AttendanceStatus::Present);

        $manager = $this->managerUser($top);

        $summary = Livewire::actingAs($manager)
            ->test(Index::class)
            ->instance()
            ->render()
            ->getData()['summary'];

        $this->assertSame(1, $summary->get('present'));
        $this->assertSame(1, $summary->get('absent'));
    }

    public function test_manager_department_dropdown_only_offers_departments_in_scope(): void
    {
        $deptA = Department::factory()->create(['name' => 'Scoped Dept']);
        $deptB = Department::factory()->create(['name' => 'Unscoped Dept']);

        $top = Employee::factory()->create(['department_id' => $deptA->id]);
        Employee::factory()->create(['department_id' => $deptB->id]);

        $manager = $this->managerUser($top);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertSee('Scoped Dept')
            ->assertDontSee('Unscoped Dept');
    }

    public function test_a_manager_role_user_with_no_linked_employee_sees_an_explicit_message(): void
    {
        $manager = User::factory()->create()->assignRole('manager');

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->assertSee("isn't linked to an employee record");
    }

    public function test_overnight_row_renders_the_plus_one_marker(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();

        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'first_in' => today()->setTime(20, 0),
            'last_out' => today()->addDay()->setTime(2, 0),
            'status' => AttendanceStatus::Present,
        ]);

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('(+1)');
    }

    public function test_incomplete_badge_and_stat_card_use_violet_not_amber(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();

        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Incomplete,
            'first_in' => today()->setTime(8, 0),
            'last_out' => null,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        // bg-amber-50 text-amber-600 legitimately appears elsewhere on this
        // page (Late's own stat card/badge, even at a zero count), so this
        // checks the Incomplete badge's own violet classes directly rather
        // than a broad "no amber anywhere" assertion.
        $this->assertStringContainsString('bg-violet-50 text-violet-700', $html);
    }

    public function test_the_present_tile_shows_a_breakdown_subtext_not_a_peer_late_tile(): void
    {
        $admin = $this->admin();

        // The subtext counts ROWS with a timing exception, not minutes —
        // two late employees (whatever their individual late_minutes) is
        // "2 late", not the sum of their minutes.
        foreach ([12, 30] as $lateMinutes) {
            DailyAttendance::factory()->create([
                'employee_id' => Employee::factory()->create()->id,
                'work_date' => today()->format('Y-m-d'),
                'status' => AttendanceStatus::Present,
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => 0,
            ]);
        }
        DailyAttendance::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_leave_minutes' => 5,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        // A late/early day is already counted in Present, not a peer tile
        // (see CLAUDE.md's "Status vs. timing" note) — the containment is
        // shown as a sub-line, not a fourth "Late" stat card.
        $this->assertStringContainsString('of which', $html);
        $this->assertStringContainsString('2 late', $html);
        $this->assertStringContainsString('1 left early', $html);
    }

    public function test_no_breakdown_subtext_when_nothing_is_late_or_early(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        $this->assertStringNotContainsString('of which', $html);
    }

    public function test_a_late_arrival_marks_the_in_time_with_a_red_underline_and_a_label(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();

        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'first_in' => today()->setTime(8, 12),
            'last_out' => today()->setTime(17, 0),
            'late_minutes' => 12,
            'early_leave_minutes' => 0,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        $this->assertStringContainsString('aria-label="Arrived 12 minutes late"', $html);
        $this->assertStringNotContainsString('aria-label="Left', $html);
    }

    public function test_a_present_row_with_an_early_leave_marks_the_out_time(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();

        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'first_in' => today()->setTime(8, 0),
            'last_out' => today()->setTime(16, 56),
            'late_minutes' => 0,
            'early_leave_minutes' => 4,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        $this->assertStringContainsString('aria-label="Left 4 minutes early"', $html);
        // The Status badge stays "Present" — status doesn't change, only the
        // specific Out time is marked (see CLAUDE.md's "Marked times" note).
        $this->assertStringContainsString('Present', $html);
        // No separate "Early 4m" chip beside the badge — the marked time and
        // the numeric Early leave column already show this.
        $this->assertStringNotContainsString('>Early 4m<', $html);
    }

    /**
     * @return array{lateOnly: Employee, earlyOnly: Employee, both: Employee, clean: Employee}
     */
    private function seedTimingScenarios(): array
    {
        $lateOnly = Employee::factory()->create(['full_name' => 'Timing Late Only']);
        DailyAttendance::factory()->create([
            'employee_id' => $lateOnly->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 12,
            'early_leave_minutes' => 0,
        ]);

        $earlyOnly = Employee::factory()->create(['full_name' => 'Timing Early Only']);
        DailyAttendance::factory()->create([
            'employee_id' => $earlyOnly->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_leave_minutes' => 8,
        ]);

        $both = Employee::factory()->create(['full_name' => 'Timing Both']);
        DailyAttendance::factory()->create([
            'employee_id' => $both->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 5,
            'early_leave_minutes' => 5,
        ]);

        $clean = Employee::factory()->create(['full_name' => 'Timing Clean']);
        DailyAttendance::factory()->create([
            'employee_id' => $clean->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        return compact('lateOnly', 'earlyOnly', 'both', 'clean');
    }

    public function test_the_timing_filter_late_only_returns_late_and_both_but_not_early_only_or_clean(): void
    {
        $admin = $this->admin();
        $this->seedTimingScenarios();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('timingFilters', ['late'])
            ->assertSee('Timing Late Only')
            ->assertSee('Timing Both')
            ->assertDontSee('Timing Early Only')
            ->assertDontSee('Timing Clean');
    }

    public function test_the_timing_filter_early_only_returns_early_and_both_but_not_late_only_or_clean(): void
    {
        $admin = $this->admin();
        $this->seedTimingScenarios();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('timingFilters', ['early'])
            ->assertSee('Timing Early Only')
            ->assertSee('Timing Both')
            ->assertDontSee('Timing Late Only')
            ->assertDontSee('Timing Clean');
    }

    public function test_the_timing_filter_combines_late_and_early_independently(): void
    {
        $admin = $this->admin();
        $this->seedTimingScenarios();

        // Both chips selected together — an "any of the selected" (OR) union,
        // matching how the status chips already combine — returns every
        // scenario that has EITHER exception, still excluding the clean day.
        Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('timingFilters', ['late', 'early'])
            ->assertSee('Timing Late Only')
            ->assertSee('Timing Early Only')
            ->assertSee('Timing Both')
            ->assertDontSee('Timing Clean');
    }

    public function test_no_timing_filter_is_unrestricted_like_the_other_filters(): void
    {
        $admin = $this->admin();
        $this->seedTimingScenarios();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->assertSee('Timing Late Only')
            ->assertSee('Timing Early Only')
            ->assertSee('Timing Both')
            ->assertSee('Timing Clean');
    }

    public function test_summary_late_count_agrees_with_what_the_timing_filter_returns(): void
    {
        $admin = $this->admin();
        $this->seedTimingScenarios();

        $component = Livewire::actingAs($admin)->test(Index::class);

        $summary = $component->instance()->render()->getData()['summary'];

        $lateFiltered = Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('timingFilters', ['late'])
            ->instance()
            ->render()
            ->getData()['attendances'];

        $earlyFiltered = Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('timingFilters', ['early'])
            ->instance()
            ->render()
            ->getData()['attendances'];

        // lateOnly + both = 2 rows have late_minutes > 0, matching both the
        // stat card's count and what filtering by 'late' actually returns.
        // Same for earlyOnly + both on the 'early' side.
        $this->assertSame(2, $summary['late']);
        $this->assertSame(2, $lateFiltered->total());
        $this->assertSame(2, $summary['early']);
        $this->assertSame(2, $earlyFiltered->total());
    }
}
