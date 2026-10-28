<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\Leave as LeaveReportTab;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5b — Reports → Leave on screen: the year, type and department
 * pickers, the balance table (Pending muted, Available bold, a negative as
 * it is, "usable from" for a type not usable yet), Used only for a type
 * without a balance, the empty states, the pinned Employee column and the
 * chevron to the profile's Leave card. The clock is Mon 15 Jun 2026 12:00.
 */
class LeaveReportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LeaveType $annual;

    private LeaveType $unpaid;

    private LeaveType $toil;

    private Employee $kyaw;

    private Employee $su;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => false, 'carry_over_cap' => 6]);
        $this->unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid', 'is_paid' => false]);
        $this->toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);

        $engineering = Department::factory()->create(['name' => 'Engineering']);
        $this->kyaw = Employee::factory()->create(['employee_code' => 'EMP-0002', 'full_name' => 'Kyaw Kyaw', 'department_id' => $engineering->id, 'join_date' => '2020-01-01']);
        $this->su = Employee::factory()->create(['employee_code' => 'EMP-0005', 'full_name' => 'Su Su', 'department_id' => $engineering->id, 'join_date' => '2026-03-02']);
    }

    public function test_annual_is_the_default_and_shows_the_balance_figures(): void
    {
        // 20 days used against an 18-day grant, and one pending.
        Leave::factory()->for($this->kyaw)->between('2026-05-04', '2026-05-29')->approved()->create(['leave_type_id' => $this->annual->id]);
        Leave::factory()->for($this->kyaw)->between('2026-06-22', '2026-06-22')->pending()->create(['leave_type_id' => $this->annual->id]);

        $component = Livewire::actingAs($this->admin)->test(LeaveReportTab::class)
            ->assertSet('year', '2026')
            ->assertSet('type', (string) $this->annual->id)
            ->assertSee('Annual · 2026')
            ->assertSee('Pending is as of now.');

        $html = $component->html();
        foreach (['Entitled', 'Carried in', 'Adjustments', 'Used', 'Pending', 'Available'] as $column) {
            $this->assertStringContainsString('>'.$column, $html);
        }
        // Kyaw: used 20, pending 1 (muted), available −3 — the number as it is, bold, not red.
        $this->assertStringContainsString('20 days', $html);
        $this->assertMatchesRegularExpression('#text-slate-500 dark:text-slate-400">1 day</td>#', $html);
        $this->assertMatchesRegularExpression('#font-semibold tabular-nums whitespace-nowrap text-slate-900 dark:text-slate-100">\s*-3 days\s*(<!--\[if (END)?BLOCK\]><!\[endif\]-->\s*)*</td>#', $html);
        $this->assertStringNotContainsString('text-red', $html);
        // Su joined in March: Annual isn't usable yet, so Time off's line, not a 0.
        $this->assertStringContainsString('Joined Mon 2 Mar', $html);
        $this->assertStringContainsString('Usable from Tue 2 Mar 2027', $html);
        // The chevron opens the profile's Leave card.
        $this->assertStringContainsString('href="'.route('employees.show', $this->kyaw).'#leave"', $html);
        $this->assertStringContainsString('aria-label="View leave for Kyaw Kyaw"', $html);
        // Pinned: the header, two rows and Total.
        $this->assertSame(1 + 2 + 1, substr_count($html, 'table-pin-lead'));
        $this->assertSame(substr_count($html, 'bg-slate-divider'), substr_count($html, 'z-[2] h-px bg-slate-divider'));
    }

    public function test_a_type_without_a_balance_shows_used_only(): void
    {
        Leave::factory()->for($this->kyaw)->between('2026-06-01', '2026-06-02')->approved()->create(['leave_type_id' => $this->unpaid->id]);

        $html = Livewire::actingAs($this->admin)->test(LeaveReportTab::class)
            ->set('type', (string) $this->unpaid->id)
            ->assertSee('Unpaid has no balance: the days each employee took.')
            ->html();

        $this->assertStringContainsString('>Used', $html);
        $this->assertStringNotContainsString('>Available', $html);
        $this->assertSame(2, substr_count($html, '2 days'));
    }

    public function test_time_off_in_lieu_with_nobody_earning_it_says_so(): void
    {
        Livewire::actingAs($this->admin)->test(LeaveReportTab::class)
            ->set('type', (string) $this->toil->id)
            ->assertSee('Nobody has Time off in lieu in 2026')
            ->assertDontSee('Kyaw Kyaw');
    }

    public function test_last_year_says_nothing_about_pending_as_of_now(): void
    {
        Livewire::actingAs($this->admin)->test(LeaveReportTab::class)
            ->set('year', '2025')
            ->assertSet('year', '2026');

        // 2025 has a grant: it is offered, and not "as of now".
        \App\Models\LeaveEntitlement::factory()->create(['employee_id' => $this->kyaw->id, 'leave_type_id' => $this->annual->id, 'year' => 2025, 'days' => '18.0']);
        Livewire::actingAs($this->admin)->test(LeaveReportTab::class)
            ->set('year', '2025')
            ->assertSet('year', '2025')
            ->assertSee('Annual · 2025')
            ->assertDontSee('Pending is as of now.');
    }

    public function test_the_csv_has_every_type_in_long_form_and_says_what_it_is(): void
    {
        Leave::factory()->for($this->kyaw)->between('2026-05-04', '2026-05-29')->approved()->create(['leave_type_id' => $this->annual->id]);
        Leave::factory()->for($this->kyaw)->between('2026-06-01', '2026-06-02')->approved()->create(['leave_type_id' => $this->unpaid->id]);
        $this->travelTo(Carbon::parse('2026-06-15 09:10:00'));

        $response = Livewire::actingAs($this->admin)->test(LeaveReportTab::class)->call('download');
        $response->assertFileDownloaded('leave-2026-exported-2026-06-15-0910.csv');

        $content = $this->downloadedContent($response);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame(1, substr_count($content, "\xEF\xBB\xBF"));
        $lines = $this->lines($content);

        $this->assertSame('Leave report, 2026 — All departments — All employees — generated 2026-06-15 09:10 (Asia/Phnom_Penh)', $lines[0][0]);
        $this->assertSame('Pending is as of the export time.', $lines[1][0]);
        $this->assertSame(['Code', 'Name', 'Department', 'Joined', 'Left', 'Type', 'Balance', 'Entitled', 'Carried in', 'Adjustments', 'Used', 'Pending', 'Available', 'Available at last day', 'Usable from'], $lines[3]);
        $this->assertSame([
            ['EMP-0002', 'Kyaw Kyaw', 'Engineering', '', '', 'Annual', 'Yes', '18.0', '0.0', '0.0', '20.0', '0.0', '-2.0', '', ''],
            ['EMP-0005', 'Su Su', 'Engineering', '2026-03-02', '', 'Annual', 'Yes', '0.0', '0.0', '0.0', '0.0', '0.0', '0.0', '', '2027-03-02'],
            ['Total', '', '', '', '', 'Annual', 'Yes', '18.0', '0.0', '0.0', '20.0', '0.0', '-2.0', '', ''],
            ['EMP-0002', 'Kyaw Kyaw', 'Engineering', '', '', 'Unpaid', 'No', '', '', '', '2.0', '0.0', '', '', ''],
            ['EMP-0005', 'Su Su', 'Engineering', '2026-03-02', '', 'Unpaid', 'No', '', '', '', '0.0', '0.0', '', '', ''],
            ['Total', '', '', '', '', 'Unpaid', 'No', '', '', '', '2.0', '0.0', '', '', ''],
        ], array_slice($lines, 4));

        // Each type's Total is the page's.
        foreach ([$this->annual, $this->unpaid] as $type) {
            $totals = Livewire::actingAs($this->admin)->test(LeaveReportTab::class)->set('type', (string) $type->id)->viewData('report')['totals'];
            $csvTotal = collect($lines)->first(fn ($line) => $line[0] === 'Total' && $line[5] === $type->name);
            $this->assertSame(\App\Support\LeaveDays::toDecimal($totals['used']), $csvTotal[10]);
        }
    }

    public function test_a_managers_csv_holds_their_team_only_and_names_the_department(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 09:10:00'));
        $managerUser = User::factory()->create()->assignRole('manager');
        Employee::factory()->create(['user_id' => $managerUser->id, 'employee_code' => 'EMP-0001', 'full_name' => 'Aye Aye Mon', 'department_id' => $this->kyaw->department_id]);
        $this->kyaw->update(['manager_id' => Employee::where('employee_code', 'EMP-0001')->value('id')]);

        $response = Livewire::actingAs($managerUser)->test(LeaveReportTab::class)
            ->set('department', (string) $this->kyaw->department_id)
            ->call('download');
        $response->assertFileDownloaded('leave-2026-engineering-exported-2026-06-15-0910.csv');

        $lines = $this->lines($this->downloadedContent($response));
        $this->assertSame('Leave report, 2026 — Engineering — Team of Aye Aye Mon — generated 2026-06-15 09:10 (Asia/Phnom_Penh)', $lines[0][0]);
        $this->assertSame(['EMP-0001', 'EMP-0002'], collect(array_slice($lines, 4))->pluck(0)->reject(fn ($code) => $code === 'Total')->unique()->values()->all());
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
