<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Index;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
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

    public function test_a_leave_row_in_the_list_uses_the_accent_colour_not_slate(): void
    {
        $this->attendanceRow(today()->format('Y-m-d'), AttendanceStatus::Leave, ['full_name' => 'Away On Leave']);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->assertSee('Away On Leave')
            // The status badge is accent (mint), the documented leave colour — not the slate that `off` uses.
            ->assertSeeHtml('bg-accent-50 text-accent-700 ring-accent-600/20')
            ->assertDontSeeHtml('bg-slate-100 text-slate-600 ring-slate-500/10');
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

        $this->assertSame(['present', 'late', 'early', 'absent', 'incomplete', 'incomplete_late'], $summary->keys()->all());
        $this->assertSame(1, $summary->get('present'));
        $this->assertSame(0, $summary->get('late'));
        $this->assertSame(0, $summary->get('early'));
        $this->assertSame(0, $summary->get('absent'));
        $this->assertSame(0, $summary->get('incomplete'));
        $this->assertSame(0, $summary->get('incomplete_late'));
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

    public function test_the_default_status_filter_reads_as_no_filter_applied(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Index::class);

        // The query default is unchanged: every status except Off.
        $this->assertEqualsCanonicalizing(
            ['present', 'incomplete', 'absent', 'holiday', 'leave', 'in_progress'],
            $component->get('statuses'),
        );

        // ...but no chip renders as selected, and none uses a solid fill.
        $html = $component->html();
        $this->assertStringNotContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('Show off days', $html);
        $this->assertStringNotContainsString('bg-primary-700 text-white', $html);
    }

    public function test_status_chips_narrow_from_the_default_and_return_to_it(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Index::class);

        $component->call('toggleStatus', 'absent');
        $this->assertSame(['absent'], $component->get('statuses'));
        $this->assertSame(1, substr_count($component->html(), 'aria-pressed="true"'));
        $this->assertStringContainsString('bg-primary-50 text-primary-700 ring-primary-600', $component->html());

        $component->call('toggleStatus', 'incomplete');
        $this->assertEqualsCanonicalizing(['absent', 'incomplete'], $component->get('statuses'));

        $component->call('toggleStatus', 'absent');
        $component->call('toggleStatus', 'incomplete');
        // Removing the last selected chip returns to "all working statuses".
        $this->assertEqualsCanonicalizing(
            ['present', 'incomplete', 'absent', 'holiday', 'leave', 'in_progress'],
            $component->get('statuses'),
        );
    }

    public function test_show_off_days_adds_off_independently_of_the_status_selection(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Index::class);

        $component->call('toggleStatus', 'off');
        $this->assertContains('off', $component->get('statuses'));
        $this->assertCount(7, $component->get('statuses'));
        // Every working status is still in the set, so only the off-days
        // toggle reads as selected.
        $this->assertSame(1, substr_count($component->html(), 'aria-pressed="true"'));

        $component->call('toggleStatus', 'absent');
        $this->assertEqualsCanonicalizing(['absent', 'off'], $component->get('statuses'));

        $component->call('toggleStatus', 'off');
        $this->assertSame(['absent'], $component->get('statuses'));
    }

    public function test_the_chevron_is_the_rows_only_link_and_names_the_employee_and_date(): void
    {
        // Fixed clock: this test's data sits on fixed 2026 dates (see CLAUDE.md, pinned-instant check).
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-15 12:00:00'));
        $admin = $this->admin();
        $employee = Employee::factory()->create(['full_name' => 'Chevron Chan']);

        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Absent,
        ]);

        $html = Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('fromDate', '2026-03-02')
            ->set('toDate', '2026-03-02')
            ->html();

        $href = route('attendance.show', $employee).'?month=2026-03';
        $this->assertSame(1, substr_count($html, 'href="'.$href.'"'));
        $this->assertStringContainsString('aria-label="View attendance for Chevron Chan, Mon 2 Mar"', $html);
        // The name is plain text, not a link.
        $this->assertMatchesRegularExpression('/<div class="whitespace-nowrap text-sm font-medium text-slate-900 dark:text-slate-100">Chevron Chan<\/div>/', $html);
        $this->assertStringNotContainsString('text-primary-700 underline', $html);
        // 40px target.
        $this->assertStringContainsString('inline-flex h-10 w-10', $html);
        // The shared tooltip, shown on hover and on keyboard focus; never a
        // native title, and the aria-label stays the accessible name.
        $this->assertMatchesRegularExpression('/<span role="tooltip" class="[^"]*group-hover\/action:opacity-100 group-focus-within\/action:opacity-100[^"]*">View attendance details<\/span>/', $html);
        $this->assertStringNotContainsString('title="View attendance', $html);
    }

    public function test_the_early_header_is_abbreviated_with_an_accessible_full_name(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Absent,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)->html();

        $this->assertStringContainsString('<abbr title="Early leave" class="no-underline">Early</abbr>', $html);

        // Column order is an owner decision (docs/ATTENDANCE_UI.md): Date,
        // Employee, Department, the times, then Status and the chevron.
        // Only the records table's headers (the date picker's grid has its own).
        $headers = [];
        preg_match('/<table class="min-w-\[64rem\] w-full">.*?<\/thead>/s', $html, $recordsHead);
        preg_match_all('/<th[^>]*>\s*(.*?)\s*<\/th>/s', $recordsHead[0], $matches);
        foreach ($matches[1] as $cell) {
            $headers[] = trim(strip_tags(preg_replace('/<span class="sr-only">.*?<\/span>/s', '', $cell)));
        }
        $this->assertSame(['Date', 'Employee', 'Department', 'In', 'Out', 'Worked', 'Late', 'Early', 'Status', ''], $headers);
        // From sm to below xl, Status and the chevron are the pinned trailing columns.
        $this->assertStringContainsString('<th class="table-pin table-pin-start px-6 py-3">Status</th>', $html);
        $this->assertStringContainsString('<th class="table-pin table-pin-end py-3 pl-2 pr-6">', $html);
        $this->assertStringContainsString('x-data="pinnedColumns"', $html);
        $this->assertStringContainsString('<tr class="relative whitespace-nowrap', $html);
        // Empty values are a muted em dash.
        $this->assertStringContainsString('<span class="text-slate-300 dark:text-slate-600">—</span>', $html);
    }

    public function test_the_date_range_picker_is_a_labelled_grid_with_native_inputs_below_sm(): void
    {
        $this->travelTo('2026-03-04 10:00:00');

        $html = Livewire::actingAs($this->admin())->test(Index::class)->html();

        // The grid picker gets the app-timezone today from the server.
        $this->assertStringContainsString('x-data="datePicker({ mode: \'range\', today: \'2026-03-04\', presets: ', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-label="Choose a date range"', $html);
        // The shared calendar (<x-date-picker.calendar>), Alpine-rendered,
        // so out of Livewire's morph.
        $this->assertMatchesRegularExpression('/<div x-ref="picker" @keydown="onKeydown\(\$event\)" class="hidden sm:block" wire:ignore/', $html);
        // A fixed panel, placed against the trigger so nothing can clip it.
        $this->assertMatchesRegularExpression('/x-ref="panel"\s+role="dialog"\s+aria-label="Choose a date range"\s+class="fixed /', $html);
        // Three grids — days, months, years — each labelled by what it shows,
        // and the heading zooms out from days to months to years.
        $this->assertStringContainsString('<table x-show="view === \'days\'" role="grid" :aria-label="gridLabel"', $html);
        $this->assertStringContainsString('<table x-show="view === \'months\'" x-cloak role="grid" :aria-label="gridLabel"', $html);
        $this->assertStringContainsString('<table x-show="view === \'years\'" x-cloak role="grid" :aria-label="gridLabel"', $html);
        $this->assertStringContainsString('@click="zoomOut()"', $html);
        $this->assertStringContainsString('<span class="sr-only" aria-live="polite" x-text="gridLabel"></span>', $html);
        $this->assertStringContainsString('abbr="Sunday"', $html);
        // Below sm: the native inputs, bound to the same properties as before.
        $this->assertStringContainsString('<div class="mt-2 space-y-3 sm:hidden">', $html);
        $this->assertStringContainsString('wire:model.live="fromDate"', $html);
        $this->assertStringContainsString('wire:model.live="toDate"', $html);
    }

    public function test_quick_ranges_include_last_month_and_set_both_dates(): void
    {
        $this->travelTo('2026-10-02 10:00:00');

        $component = Livewire::actingAs($this->admin())->test(Index::class);

        $this->assertSame([
            'today' => ['label' => 'Today', 'from' => '2026-10-02', 'to' => '2026-10-02'],
            'yesterday' => ['label' => 'Yesterday', 'from' => '2026-10-01', 'to' => '2026-10-01'],
            'last7' => ['label' => 'Last 7 days', 'from' => '2026-09-26', 'to' => '2026-10-02'],
            'last30' => ['label' => 'Last 30 days', 'from' => '2026-09-03', 'to' => '2026-10-02'],
            'thisMonth' => ['label' => 'This month', 'from' => '2026-10-01', 'to' => '2026-10-02'],
            'lastMonth' => ['label' => 'Last month', 'from' => '2026-09-01', 'to' => '2026-09-30'],
        ], $component->instance()->presetRanges());

        $component->call('setRange', 'lastMonth')
            ->assertSet('fromDate', '2026-09-01')
            ->assertSet('toDate', '2026-09-30');

        // The picker gets the same definitions, to show a matching preset as selected.
        $this->assertStringContainsString('@click="applyPreset(\'lastMonth\')"', $component->html());
    }

    public function test_last_month_crosses_the_year_boundary_and_ignores_month_length(): void
    {
        $this->travelTo('2027-01-31 09:00:00');

        Livewire::actingAs($this->admin())->test(Index::class)
            ->call('setRange', 'lastMonth')
            ->assertSet('fromDate', '2026-12-01')
            ->assertSet('toDate', '2026-12-31');

        $this->travelTo('2026-03-31 09:00:00');

        Livewire::actingAs($this->admin())->test(Index::class)
            ->call('setRange', 'lastMonth')
            ->assertSet('fromDate', '2026-02-01')
            ->assertSet('toDate', '2026-02-28');
    }

    public function test_the_date_range_picker_writes_the_same_properties_and_url(): void
    {
        Livewire::actingAs($this->admin())
            ->withQueryParams(['from' => '2026-02-01', 'to' => '2026-02-10'])
            ->test(Index::class)
            ->assertSet('fromDate', '2026-02-01')
            ->assertSet('toDate', '2026-02-10')
            // What datePicker (range mode) sends: both properties, one request.
            ->set(['fromDate' => '2026-01-28', 'toDate' => '2026-02-03'])
            ->assertSet('fromDate', '2026-01-28')
            ->assertSet('toDate', '2026-02-03')
            ->assertSee('28 Jan – 3 Feb');
    }

    public function test_the_summary_strip_states_its_range_wide_scope(): void
    {
        // Fixed clock: this test's data sits on fixed 2026 dates (see CLAUDE.md, pinned-instant check).
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-15 12:00:00'));
        $component = Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->set('fromDate', '2026-09-01')
            ->set('toDate', '2026-09-29');

        $component->assertSee('1–29 Sep · all statuses');

        // Narrowing the table doesn't narrow the strip, and the label says so.
        $component->set('employeeFilter', 'someone')
            ->assertSee('1–29 Sep · all employees, all statuses');

        // One strip treatment: x-stat-card cells, neutral icon tiles.
        $this->assertSame(3, substr_count($component->html(), 'text-xl font-semibold leading-7 tabular-nums'));
    }

    public function test_a_range_of_exactly_today_shows_the_live_strip(): void
    {
        $admin = $this->admin();
        [$arrived, $left, $notYet] = Employee::factory()->count(3)->create()->all();

        DailyAttendance::factory()->create(['employee_id' => $arrived->id, 'work_date' => today()->format('Y-m-d'), 'status' => AttendanceStatus::InProgress, 'first_in' => today()->setTime(7, 55)]);
        DailyAttendance::factory()->create(['employee_id' => $left->id, 'work_date' => today()->format('Y-m-d'), 'status' => AttendanceStatus::Present, 'first_in' => today()->setTime(7, 50), 'last_out' => today()->setTime(15, 0), 'early_leave_minutes' => 120]);
        DailyAttendance::factory()->create(['employee_id' => $notYet->id, 'work_date' => today()->format('Y-m-d'), 'status' => AttendanceStatus::InProgress]);

        $component = Livewire::actingAs($admin)->test(Index::class);

        $component->assertSeeInOrder(['At work', '1', 'Left', '1', '1 early', 'Not in', '1'])
            ->assertSee('Today, '.today()->format('D j M').' · so far')
            ->assertDontSee('Incomplete</dt>', false);

        // Any other range keeps the end-of-day status counts.
        $component->set('fromDate', today()->subDay()->format('Y-m-d'))
            ->assertSee('Absent')
            ->assertDontSee('dark:text-slate-400">Not in</dt>', false);
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

        // Checks the Incomplete badge's own violet classes directly rather
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

        $html = Livewire::actingAs($admin)->test(Index::class)
            // A range that isn't exactly today shows end-of-day status counts.
            ->set('fromDate', today()->subDay()->format('Y-m-d'))
            ->html();

        // A late/early day is already counted in Present, not a peer tile
        // (see CLAUDE.md's "Status vs. timing" note) — the containment is
        // shown as a sub-line, not a fourth "Late" stat card.
        $this->assertStringContainsString('2 late', $html);
        $this->assertStringContainsString('1 early', $html);
        // The exact joined text, not just its pieces — interleaved
        // @if/@endif directives around static text used to leave a stray
        // space where the raw HTML between them collapsed on render.
        $this->assertStringContainsString('2 late · 1 early', $html);
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

        $this->assertStringNotContainsString(' late', $html);
        $this->assertStringNotContainsString(' early', $html);
    }

    public function test_a_late_arrival_is_marked_by_an_amber_late_value_and_a_neutral_in_time(): void
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

        // In/Out stay neutral; the timing fact is the amber Late value.
        $this->assertMatchesRegularExpression('/class="whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums font-medium text-amber-700 dark:text-amber-300">12m</', $html);
        $this->assertStringNotContainsString('aria-label="Arrived', $html);
        $this->assertStringNotContainsString('underline decoration-red', $html);
        // Status-only colour: a late Present row is a green badge.
        $this->assertStringContainsString('bg-green-50 text-green-700', $html);
        $this->assertStringNotContainsString('bg-amber-50 text-amber-700', $html);
    }

    public function test_late_and_early_durations_use_the_same_compact_format_as_worked(): void
    {
        $admin = $this->admin();
        $employee = Employee::factory()->create();

        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => today()->format('Y-m-d'),
            'status' => AttendanceStatus::Present,
            'first_in' => today()->setTime(9, 20),
            'last_out' => today()->setTime(16, 39),
            'worked_minutes' => 379,
            'late_minutes' => 80,
            'early_leave_minutes' => 21,
        ]);

        $html = Livewire::actingAs($admin)->test(Index::class)
            // A range that isn't exactly today shows end-of-day status counts.
            ->set('fromDate', today()->subDay()->format('Y-m-d'))
            ->html();

        $this->assertStringContainsString('>1h 20m<', $html);
        $this->assertStringContainsString('>21m<', $html);
        $this->assertStringContainsString('>6h 19m<', $html);
        $this->assertStringNotContainsString('>80m<', $html);
        // The Present tile's subtext is a COUNT of timing days, not a
        // duration, and keeps its "N late · N early" wording.
        $this->assertStringContainsString('1 late · 1 early', $html);
    }

    public function test_a_present_row_with_an_early_leave_marks_the_early_value(): void
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

        $this->assertMatchesRegularExpression('/class="whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums font-medium text-amber-700 dark:text-amber-300">4m</', $html);
        // The Status badge stays "Present" — status doesn't change, only the
        // Early value is marked (docs/ATTENDANCE_UI.md).
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

    public function test_the_late_filter_includes_in_progress_and_incomplete_late_rows_but_the_present_breakdown_does_not(): void
    {
        $admin = $this->admin();
        $date = today()->subDay()->format('Y-m-d');
        $make = fn (AttendanceStatus $status, int $late) => DailyAttendance::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'work_date' => $date,
            'status' => $status,
            'first_in' => today()->subDay()->setTime(8, 0)->addMinutes($late),
            'late_minutes' => $late,
        ]);
        $make(AttendanceStatus::Present, 20);
        $make(AttendanceStatus::Incomplete, 35);
        $make(AttendanceStatus::Present, 0);

        $component = Livewire::actingAs($admin)->test(Index::class)
            ->set('fromDate', $date)->set('toDate', $date);

        // The Present sub-line is a breakdown of Present: only the present late day.
        $this->assertSame(1, $component->instance()->render()->getData()['summary']['late']);
        // ...and the late incomplete day annotates its own group, Incomplete.
        $this->assertSame(1, $component->instance()->render()->getData()['summary']['incomplete_late']);
        $this->assertMatchesRegularExpression('/>Incomplete<\/dt>.*?>1<\/dd>.*?>\s*1 late\s*<\/dd>/s', $component->html());

        // The filter returns late rows of every status, and the Late column
        // shows the incomplete day's minutes in amber.
        $filtered = $component->set('timingFilters', ['late']);
        $this->assertSame(2, $filtered->instance()->render()->getData()['attendances']->total());
        $this->assertStringContainsString('font-medium text-amber-700 dark:text-amber-300">35m<', $filtered->html());
    }

    public function test_a_filter_chip_is_the_same_size_selected_or_not(): void
    {
        $view = file_get_contents(resource_path('views/livewire/attendance/index.blade.php'));

        // Fixed height; unselected padding = selected padding + the check's 14px + 6px gap.
        $this->assertStringContainsString("\$chipBase = 'inline-flex h-7 ", $view);
        $this->assertStringContainsString("\$chipSelected = 'px-3 ", $view);
        $this->assertStringContainsString("\$chipUnselected = 'px-[1.375rem] ", $view);
        // The label reserves its semibold width.
        $this->assertStringContainsString('invisible [grid-area:1/1] font-semibold', $view);
    }
}
