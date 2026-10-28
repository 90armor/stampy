<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\Attendance;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5b — Reports → Attendance on screen: the month and department
 * pickers, the open-month notice, the table (pinned Employee column, amber
 * timing totals only when non-zero, the totals row, the chevron as each row's
 * only link) and the empty states. The clock is Mon 15 Jun 2026 12:00.
 */
class AttendanceReportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $engineering;

    private Employee $kyaw;

    private Employee $su;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0,
            'break_minutes' => 60, 'break_start' => '12:00:00', 'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->engineering = Department::factory()->create(['name' => 'Engineering']);
        $this->admin = User::factory()->create()->assignRole('admin');

        $this->kyaw = Employee::factory()->create(['employee_code' => 'EMP-0002', 'full_name' => 'Kyaw Kyaw', 'department_id' => $this->engineering->id]);
        $this->su = Employee::factory()->create(['employee_code' => 'EMP-0005', 'full_name' => 'Su Su', 'department_id' => $this->engineering->id, 'join_date' => '2026-05-11']);

        // Kyaw: 20 minutes late on Tue 5 May; Su: on time from her first day.
        $this->punch($this->kyaw, '2026-05-05 08:20:00', 'in');
        $this->punch($this->kyaw, '2026-05-05 17:00:00', 'out');
        $this->punch($this->su, '2026-05-11 08:00:00', 'in');
        $this->punch($this->su, '2026-05-11 17:00:00', 'out');
        foreach ([$this->kyaw, $this->su] as $employee) {
            app(DailySummaryBuilder::class)->rebuildBetween($employee, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-12'));
        }
    }

    private function punch(Employee $employee, string $at, string $type): void
    {
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => $at, 'punch_type' => $type]);
    }

    public function test_a_closed_month_shows_a_row_per_employee_and_the_totals(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Attendance::class)
            ->assertSet('month', '2026-06')
            ->assertSee('This month is still open: figures can change until it ends.')
            ->set('month', '2026-05')
            ->assertDontSee('This month is still open');

        $html = $component->html();
        $this->assertMatchesRegularExpression('/Kyaw Kyaw.*EMP-0002&nbsp;· Engineering.*Su Su.*EMP-0005&nbsp;· Engineering.*Joined Mon 11 May.*Total/s', $html);

        // Late is the total over the day count, the total amber because it's non-zero.
        $this->assertMatchesRegularExpression('#<span class="block font-medium text-amber-700 dark:text-amber-300">20m</span>\s*<span class="block text-xs font-normal text-slate-500 dark:text-slate-400">1 day</span>#', $html);
        // Su was never late or early: an em dash, never an amber zero.
        $this->assertSame(2, substr_count($html, 'text-amber-700'));
        // The chevron is each row's only link, to that month of the calendar.
        $this->assertStringContainsString('href="'.route('attendance.show', ['employee' => $this->kyaw, 'month' => '2026-05']).'"', $html);
        $this->assertStringContainsString('aria-label="View attendance for Kyaw Kyaw, May 2026"', $html);
        $this->assertSame(2, substr_count($html, 'aria-label="View attendance for'));
    }

    public function test_the_employee_column_is_pinned_while_the_figures_scroll(): void
    {
        $html = Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05')->html();

        $this->assertStringContainsString('x-data="scrollEdges"', $html);
        $this->assertStringContainsString('scroll-fade-end', $html);
        // The header, both employee rows and the Total row.
        $this->assertSame(1 + 2 + 1, substr_count($html, 'table-pin-lead'));
        // Every divider runs above the pinned cells; none under the Total row.
        $this->assertSame(1 + 2, substr_count($html, 'z-[2] h-px bg-slate-divider'));
        $this->assertSame(substr_count($html, 'bg-slate-divider'), substr_count($html, 'z-[2] h-px bg-slate-divider'));
        foreach (['Present', 'Absent', 'Incomplete', 'On leave', 'Leave taken', 'Late', 'Worked', 'Not calculated'] as $column) {
            $this->assertStringContainsString('>'.$column, $html);
        }
        // Short headers keep their full name, as the attendance table's Early does.
        foreach (['Calculated workdays' => 'Workdays', 'Early leave' => 'Early'] as $full => $short) {
            $this->assertStringContainsString('<abbr title="'.$full.'" class="no-underline">'.$short.'</abbr>', $html);
        }
        // "Days off" alone read as days off taken, beside On leave: it says worked.
        $this->assertStringContainsString('Days off<span class="block font-normal normal-case tracking-normal">worked</span>', $html);
    }

    public function test_the_department_filter_offers_the_viewers_departments_and_ignores_any_other(): void
    {
        $operations = Department::factory()->create(['name' => 'Operations']);
        $sales = Department::factory()->create(['name' => 'Sales']);
        $managerUser = User::factory()->create()->assignRole('manager');
        $manager = Employee::factory()->create(['user_id' => $managerUser->id, 'department_id' => $this->engineering->id, 'full_name' => 'Aye Aye Mon']);
        $this->kyaw->update(['manager_id' => $manager->id]);
        // In the team, in Sales, gone before May.
        Employee::factory()->inactive('2026-03-31')->create(['manager_id' => $manager->id, 'department_id' => $sales->id]);
        Employee::factory()->create(['department_id' => $operations->id, 'full_name' => 'Outside The Team']);

        Livewire::actingAs($managerUser)->withQueryParams(['month' => '2026-05', 'department' => (string) $operations->id])
            ->test(Attendance::class)
            ->assertSet('department', '')
            ->assertSee('Kyaw Kyaw')
            ->assertDontSee('Su Su')
            ->assertDontSee('Outside The Team')
            ->assertSeeHtml('<option value="'.$sales->id.'">Sales</option>')
            ->assertDontSeeHtml('>Operations</option>')
            ->set('department', (string) $sales->id)
            ->assertSee('No employees in this department')
            ->assertSee('Nobody in Sales was employed in May 2026.');
    }

    public function test_a_month_with_nothing_calculated_or_nobody_employed_says_which(): void
    {
        Livewire::actingAs($this->admin)->test(Attendance::class)
            ->set('month', '2026-04')
            ->assertSee('No attendance calculated for this month')
            ->assertSee('Nothing has been calculated for April 2026 yet, for anyone here.')
            ->assertDontSee('Kyaw Kyaw');

        Employee::query()->update(['join_date' => '2026-06-01']);
        Livewire::actingAs($this->admin)->test(Attendance::class)
            ->set('month', '2026-05')
            ->assertSee('No employees in this month')
            ->assertSee('Nobody you can report on was employed in May 2026.');
    }

    public function test_changing_the_month_keeps_the_other_tabs_in_step(): void
    {
        Livewire::actingAs($this->admin)->test(Attendance::class)
            ->set('month', '2026-05')
            ->assertDispatched('report-month', month: '2026-05')
            ->set('month', 'nonsense')
            ->assertSet('month', '2026-06');

        Livewire::actingAs($this->admin)->test(Attendance::class)
            ->dispatch('report-month', month: '2026-04')
            ->assertSet('month', '2026-04');
    }

    public function test_the_csv_has_the_same_rows_in_whole_minutes_and_says_what_it_is(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 09:10:00'));

        $response = Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05')->call('download');
        $response->assertFileDownloaded('attendance-2026-05-exported-2026-06-15-0910.csv');

        // A UTF-8 byte-order mark first, for Excel; the rows after it are plain CSV.
        $content = $this->downloadedContent($response);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame(1, substr_count($content, "\xEF\xBB\xBF"));
        $lines = $this->lines($content);

        $this->assertSame('Attendance report, May 2026 — All departments — All employees — generated 2026-06-15 09:10 (Asia/Phnom_Penh)', $lines[0][0]);
        $this->assertStringStartsWith('The same counts as each employee\'s attendance summary.', $lines[1][0]);
        $this->assertSame(['Code', 'Name', 'Department', 'Joined', 'Left', 'Calculated workdays', 'Present', 'Absent', 'Incomplete', 'On leave', 'Leave taken (days)', 'Worked on leave (days)', 'Days off worked', 'Late (days)', 'Late (minutes)', 'Early leave (days)', 'Early leave (minutes)', 'Worked (minutes)', 'Not calculated'], $lines[2]);
        $this->assertSame(['EMP-0002', 'Kyaw Kyaw', 'Engineering', '', '', '21', '1', '20', '0', '0', '0.0', '0', '0', '1', '20', '0', '0', '460', '0'], $lines[3]);
        $this->assertSame(['EMP-0005', 'Su Su', 'Engineering', '2026-05-11', '', '15', '1', '14', '0', '0', '0.0', '0', '0', '0', '0', '0', '0', '480', '0'], $lines[4]);
        $this->assertSame(['Total', '', '', '', '', '36', '2', '34', '0', '0', '0.0', '0', '0', '1', '20', '0', '0', '940', '0'], $lines[5]);
        $this->assertCount(6, $lines);

        // The totals are the page's.
        $totals = Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05')->viewData('report')['totals'];
        $this->assertSame([(string) $totals['workdays'], (string) $totals['present'], (string) $totals['absent'], (string) $totals['total_worked_minutes']], [$lines[5][5], $lines[5][6], $lines[5][7], $lines[5][17]]);

        // The open month says so in the file too.
        $open = $this->lines($this->downloadedContent(Livewire::actingAs($this->admin)->test(Attendance::class)->call('download')));
        $this->assertSame('This month is still open: figures can change until it ends.', $open[1][0]);
    }

    public function test_a_managers_csv_holds_their_team_only_and_names_the_department(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 09:10:00'));
        $managerUser = User::factory()->create()->assignRole('manager');
        $manager = Employee::factory()->create(['user_id' => $managerUser->id, 'employee_code' => 'EMP-0001', 'full_name' => 'Aye Aye Mon', 'department_id' => $this->engineering->id]);
        $this->kyaw->update(['manager_id' => $manager->id]);

        $response = Livewire::actingAs($managerUser)->test(Attendance::class)
            ->set('month', '2026-05')
            ->set('department', (string) $this->engineering->id)
            ->call('download');
        $response->assertFileDownloaded('attendance-2026-05-engineering-exported-2026-06-15-0910.csv');

        $lines = $this->lines($this->downloadedContent($response));
        $this->assertSame('Attendance report, May 2026 — Engineering — Team of Aye Aye Mon — generated 2026-06-15 09:10 (Asia/Phnom_Penh)', $lines[0][0]);
        $this->assertSame(['EMP-0001', 'EMP-0002', 'Total'], array_column(array_slice($lines, 3), 0));
    }

    public function test_the_csv_is_refused_without_the_ability(): void
    {
        // The ability can go between loading the page and exporting.
        $managerUser = User::factory()->create()->assignRole('manager');
        Employee::factory()->create(['user_id' => $managerUser->id]);
        $component = Livewire::actingAs($managerUser)->test(Attendance::class);
        $managerUser->removeRole('manager')->assignRole('employee');
        $managerUser->unsetRelation('roles');

        $component->call('download')->assertForbidden();
    }

    public function test_leave_taken_says_how_many_of_its_days_were_worked(): void
    {
        // Both punches on an approved full day of leave: Present, still charged
        // (Phase 3 rule 17) — the report says so under Leave taken.
        Leave::factory()->for($this->kyaw)->between('2026-05-12', '2026-05-12')->approved()->create();
        $this->punch($this->kyaw, '2026-05-12 08:00:00', 'in');
        $this->punch($this->kyaw, '2026-05-12 17:00:00', 'out');
        app(DailySummaryBuilder::class)->rebuildBetween($this->kyaw, Carbon::parse('2026-05-12'), Carbon::parse('2026-05-12'));

        $html = Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05')->html();

        // Kyaw's row and the Total row; Su worked no leave, so no line.
        $this->assertSame(2, substr_count($html, '1 worked</span>'));
    }

    public function test_a_long_name_or_department_wraps_instead_of_widening_the_pinned_column(): void
    {
        $long = Department::factory()->create(['name' => 'Human Resources and Administration']);
        Employee::factory()->create(['full_name' => 'Aung Kyaw Moe Thandar Htun', 'department_id' => $long->id]);

        $html = Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05')->html();

        // The Employee column has a fixed width from sm; its lines may wrap.
        $this->assertStringContainsString('table-pin-lead min-w-[8.5rem] px-4 py-3 font-medium sm:w-44 sm:min-w-44', $html);
        $this->assertMatchesRegularExpression('#<span class="block max-w-\[8.75rem\] sm:max-w-none font-medium[^"]*">Aung Kyaw Moe Thandar Htun</span>#', $html);
        $this->assertStringNotContainsString('whitespace-nowrap', substr($html, strpos($html, 'Aung Kyaw Moe Thandar Htun') - 200, 400));
        $this->assertStringContainsString('&nbsp;· Human Resources and Administration', $html);
    }

    /** @return list<list<?string>> */
    private function lines(string $content): array
    {
        return array_map('str_getcsv', explode("\n", trim(substr($content, 3))));
    }

    private function downloadedContent($response): string
    {
        return base64_decode(data_get($response->effects, 'download.content'));
    }
}
