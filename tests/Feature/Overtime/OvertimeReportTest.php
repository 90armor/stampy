<?php

namespace Tests\Feature\Overtime;

use App\Livewire\Reports\Overtime;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Support\OvertimeReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — the monthly overtime report: one row per employee with approved,
 * credited minutes that month, pay minutes by category, time off as a total,
 * pay-equivalent hours from today's rates, and a CSV with the same columns
 * that says when it was exported and whether the month was still open.
 * The clock is Mon 15 Jun 2026 12:00.
 */
class OvertimeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $kyaw;

    private Employee $su;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $toil->id]);

        $engineering = Department::factory()->create(['name' => 'Engineering']);
        $this->kyaw = Employee::factory()->create(['employee_code' => 'EMP-0002', 'full_name' => 'Kyaw Kyaw', 'department_id' => $engineering->id]);
        $this->su = Employee::factory()->create(['employee_code' => 'EMP-0005', 'full_name' => 'Su Su', 'department_id' => $engineering->id]);
        Employee::factory()->create(['employee_code' => 'EMP-0009', 'full_name' => 'No Overtime']);

        $this->admin = User::factory()->create()->assignRole('admin');
    }

    /** Punches 08:00 → $out on $date and a request for $from–$to, built. */
    private function worked(Employee $employee, string $date, string $out, string $from, string $to, string $compensation = 'pay', string $status = 'approved'): void
    {
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => "{$date} 08:00:00", 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $employee->id, 'punched_at' => $out, 'punch_type' => 'out']);

        $request = OvertimeRequest::factory()->for($employee)->window($date, $from, $to)->{$compensation === 'pay' ? 'pay' : 'timeOff'}()->{$status}()->create();
        app(DailySummaryBuilder::class)->rebuildOvertimeDate($request);
    }

    private function seedMay(): void
    {
        // Kyaw: Tue 5 May 17:00–19:00 paid workday (120); Thu 7 May 21:00–23:00 paid, 1h workday + 1h night; Sun 10 May 08:00–12:00 rest day (240).
        $this->worked($this->kyaw, '2026-05-05', '2026-05-05 19:00:00', '17:00', '19:00');
        $this->worked($this->kyaw, '2026-05-07', '2026-05-07 23:00:00', '21:00', '23:00');
        $this->worked($this->kyaw, '2026-05-10', '2026-05-10 12:00:00', '08:00', '12:00');
        // Su: Wed 6 May 17:00–18:30 as time off (90), and a pending request that counts for nothing.
        $this->worked($this->su, '2026-05-06', '2026-05-06 18:30:00', '17:00', '18:30', 'timeOff');
        $this->worked($this->su, '2026-05-08', '2026-05-08 19:00:00', '17:00', '19:00', 'pay', 'pending');
        // A June day doesn't belong in May.
        $this->worked($this->kyaw, '2026-06-02', '2026-06-02 18:00:00', '17:00', '18:00');
    }

    public function test_one_row_per_employee_with_pay_by_category_time_off_and_pay_equivalent_hours(): void
    {
        $this->seedMay();

        $report = OvertimeReport::forMonth(Carbon::parse('2026-05-01'));

        $this->assertFalse($report['open']);
        $this->assertSame(['EMP-0002', 'EMP-0005'], array_column($report['rows'], 'code'));

        [$kyaw, $su] = $report['rows'];
        $this->assertSame(['workday' => 180, 'night' => 60, 'rest_day' => 240, 'holiday' => 0], $kyaw['pay']);
        $this->assertSame(0, $kyaw['time_off']);
        $this->assertSame('Engineering', $kyaw['department']);
        // (180 × 150 + 60 × 200 + 240 × 200) ÷ 100 ÷ 60 = 14.5
        $this->assertSame(14.5, $kyaw['pay_equivalent_hours']);

        // Time off is a plain total, never paid; the pending request counts for nothing.
        $this->assertSame(['workday' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0], $su['pay']);
        $this->assertSame(90, $su['time_off']);
        $this->assertSame(0.0, $su['pay_equivalent_hours']);

        $this->assertSame(['workday' => 180, 'night' => 60, 'rest_day' => 240, 'holiday' => 0], $report['totals']['pay']);
        $this->assertSame(90, $report['totals']['time_off']);
        $this->assertSame(14.5, $report['totals']['pay_equivalent_hours']);
    }

    public function test_pay_equivalent_hours_follow_todays_rates(): void
    {
        $this->seedMay();
        OvertimeSettings::current()->update(['workday_rate_percent' => 200]);

        // (180 × 200 + 60 × 200 + 240 × 200) ÷ 100 ÷ 60 = 16
        $this->assertSame(16.0, OvertimeReport::forMonth(Carbon::parse('2026-05-01'))['rows'][0]['pay_equivalent_hours']);
    }

    public function test_only_admins_open_it_and_the_sidebar_follows_the_same_ability(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $employee = User::factory()->create()->assignRole('employee');

        $this->actingAs($this->admin)->get(route('reports.overtime'))->assertOk()->assertSee('Overtime report');
        $this->actingAs($manager)->get(route('reports.overtime'))->assertForbidden();
        $this->actingAs($employee)->get(route('reports.overtime'))->assertForbidden();

        $this->assertTrue($this->admin->can('reports.overtime'));
        $this->assertFalse($manager->can('reports.overtime'));

        // The admin's Reports item links to the report; the manager's is still "Soon"; an employee has none.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('reports.overtime'), false);
        $this->actingAs($manager)->get(route('dashboard'))->assertSee('Reports')->assertDontSee(route('reports.overtime'), false);
        $this->actingAs($employee)->get(route('dashboard'))->assertDontSee('Reports');
    }

    public function test_the_page_shows_the_month_and_says_when_it_is_still_open(): void
    {
        $this->seedMay();

        Livewire::actingAs($this->admin)->test(Overtime::class)
            ->assertSet('month', '2026-06')
            ->assertSee('This month is still open: figures can change until it ends.')
            ->set('month', '2026-05')
            ->assertDontSee('This month is still open')
            ->assertSee('Only approved, credited minutes count.')
            ->assertSee('Kyaw Kyaw')
            ->assertSee('Su Su')
            ->assertDontSee('No Overtime')
            ->assertSee('14.50');

        Livewire::actingAs($this->admin)->test(Overtime::class)->set('month', '2026-03')->assertSee('No credited overtime');
    }

    public function test_the_csv_has_the_same_columns_and_says_when_it_was_generated(): void
    {
        $this->seedMay();
        $this->travelTo(Carbon::parse('2026-06-15 09:10:00'));

        $response = Livewire::actingAs($this->admin)->test(Overtime::class)->set('month', '2026-05')->call('download');
        $response->assertFileDownloaded('overtime-2026-05-exported-2026-06-15-0910.csv');

        $lines = array_map('str_getcsv', explode("\n", trim($this->downloadedContent($response))));
        $this->assertSame('Overtime report, May 2026 — generated 2026-06-15 09:10 (Asia/Phnom_Penh)', $lines[0][0]);
        $this->assertStringStartsWith('Only approved, credited minutes count.', $lines[1][0]);
        $this->assertSame(['Code', 'Name', 'Department', 'Pay: Workday minutes', 'Pay: Night minutes', 'Pay: Rest day minutes', 'Pay: Holiday minutes', 'Time off minutes', 'Pay-equivalent hours'], $lines[2]);
        $this->assertSame(['EMP-0002', 'Kyaw Kyaw', 'Engineering', '180', '60', '240', '0', '0', '14.50'], $lines[3]);
        $this->assertSame(['EMP-0005', 'Su Su', 'Engineering', '0', '0', '0', '0', '90', '0.00'], $lines[4]);
        $this->assertSame(['Total', '', '', '180', '60', '240', '0', '90', '14.50'], $lines[5]);

        // The open month says so in the file too.
        $open = Livewire::actingAs($this->admin)->test(Overtime::class)->call('download');
        $this->assertSame('This month is still open: figures can change until it ends.', str_getcsv(explode("\n", $this->downloadedContent($open))[1])[0]);
    }

    private function downloadedContent($response): string
    {
        return base64_decode(data_get($response->effects, 'download.content'));
    }
}
