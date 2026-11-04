<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\Attendance;
use App\Livewire\Reports\Leave as LeaveReportTab;
use App\Livewire\Reports\Overtime;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6g, F04 and F35 — printing a report and its CSV's name. Each tab has a
 * Print button beside its CSV export and a print header (name, period,
 * filters, who printed it and when, the open-period notice) that only paper
 * shows; on paper the shell, the page's title, tabs and controls are hidden,
 * A4 landscape. Light paper whatever the theme, the "Printed …" time and the
 * table's flow are the browser's (resources/js/app.js, resources/css/app.css,
 * scripts/audit/playwright/shots-6g.mjs). An open period's CSV name ends
 * -OPEN; a closed one's doesn't. The clock is Mon 15 Jun 2026 12:00.
 */
class ReportPrintTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_MONTH = 'This month is still open: figures can change until it ends.';

    private User $admin;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->admin = User::factory()->create(['name' => 'Administrator'])->assignRole('admin');
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => null]);
        Employee::factory()->create(['department_id' => Department::factory()->create(['name' => 'Engineering'])->id, 'join_date' => '2020-01-01']);
    }

    /** The print header's markup: from its opening tag to the end of the block. */
    private function printHeader(string $html): string
    {
        $start = strpos($html, '<div class="hidden print:block">');
        $this->assertNotFalse($start, 'No print header.');

        return substr($html, $start, strpos($html, '</div>', $start) - $start);
    }

    public function test_the_attendance_tab_prints_its_month_filter_and_who_printed_it(): void
    {
        $page = Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05');
        $html = $page->html();
        $header = $this->printHeader($html);

        $this->assertStringContainsString('Attendance report · May 2026', $header);
        $this->assertStringContainsString('All departments ·', $header);
        $this->assertStringContainsString('Printed <span data-printed-at="Asia/Phnom_Penh">Mon 15 Jun, 12:00 PM</span> by Administrator', $header);
        $this->assertStringNotContainsString(self::OPEN_MONTH, $header);
        $this->assertStringContainsString('x-on:click="window.print()"', $html);
        // The controls and the on-screen notice stay on screen.
        $this->assertMatchesRegularExpression('/<div class="[^"]*print:!hidden">\s*<div>\s*<h2[^>]*>Attendance report<\/h2>/', $html);
        // The root the print rules scope to (Livewire's attributes come first).
        $this->assertStringContainsString(' class="space-y-6" data-report>', $html);

        $engineering = (string) Department::query()->where('name', 'Engineering')->value('id');
        $open = $page->set('month', '2026-06')->set('department', $engineering)->html();
        $header = $this->printHeader($open);
        $this->assertStringContainsString('Attendance report · June 2026', $header);
        $this->assertStringContainsString('Engineering ·', $header);
        $this->assertStringContainsString(self::OPEN_MONTH, $header);
        $this->assertStringContainsString('rounded-lg bg-amber-50 print:!hidden', $open);
    }

    public function test_the_overtime_tab_prints_its_month(): void
    {
        $header = $this->printHeader(Livewire::actingAs($this->admin)->test(Overtime::class)->html());

        $this->assertStringContainsString('Overtime report · June 2026', $header);
        $this->assertStringContainsString(self::OPEN_MONTH, $header);
        $this->assertStringContainsString('Printed <span', $header);
    }

    public function test_the_leave_tab_prints_its_year_type_and_department_and_names_an_open_year(): void
    {
        LeaveEntitlement::query()->create(['employee_id' => Employee::query()->value('id'), 'leave_type_id' => $this->annual->id, 'year' => 2025, 'days' => 18]);

        $page = Livewire::actingAs($this->admin)->test(LeaveReportTab::class);
        $header = $this->printHeader($page->html());
        $this->assertStringContainsString('Leave report · 2026', $header);
        $this->assertStringContainsString('Annual · All departments ·', $header);
        $this->assertStringContainsString('This year is still open: figures can change until it ends.', $header);
        $page->call('download')->assertFileDownloaded('leave-2026-exported-2026-06-15-1200-OPEN.csv');

        $closed = Livewire::actingAs($this->admin)->test(LeaveReportTab::class)->set('year', '2025');
        $this->assertStringNotContainsString('still open', $this->printHeader($closed->html()));
        $closed->call('download')->assertFileDownloaded('leave-2025-exported-2026-06-15-1200.csv');
    }

    public function test_a_closed_month_keeps_todays_csv_name(): void
    {
        Livewire::actingAs($this->admin)->test(Attendance::class)->set('month', '2026-05')->call('download')
            ->assertFileDownloaded('attendance-2026-05-exported-2026-06-15-1200.csv');
        Livewire::actingAs($this->admin)->test(Overtime::class)->set('month', '2026-05')->call('download')
            ->assertFileDownloaded('overtime-2026-05-exported-2026-06-15-1200.csv');
    }

    public function test_on_paper_the_page_is_a4_landscape_without_the_shell(): void
    {
        $html = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<style>@media print { @page { size: A4 landscape; margin: 10mm; } }</style>', $html);
        $this->assertStringContainsString('<div class="hidden lg:flex lg:shrink-0 print:!hidden">', $html);
        $this->assertMatchesRegularExpression('/<header class="sticky top-0 z-30 [^"]*print:!hidden/', $html);
        $this->assertStringContainsString('class="flex-1 overflow-y-auto focus:outline-none print:overflow-visible"', $html);
        $this->assertStringContainsString('<div class="print:!hidden"><div class="-mx-4 sm:mx-0">', preg_replace('/>\s+</', '><', $html));
    }
}
