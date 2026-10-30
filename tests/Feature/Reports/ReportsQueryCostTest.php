<?php

namespace Tests\Feature\Reports;

use App\Enums\AttendanceStatus;
use App\Livewire\Reports\Attendance;
use App\Livewire\Reports\Leave as LeaveReportTab;
use App\Livewire\Reports\Overtime;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6c (F26) — what the Reports page costs: only the open tab computes
 * its report with the page, the others load when first opened, and no tab's
 * queries grow with the number of employees (10 or 50). The clock is Fri 30
 * Oct 2026 12:00, so October is the open month.
 */
class ReportsQueryCostTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $manager;

    private Department $sales;

    private LeaveType $annual;

    private LeaveType $special;

    private LeaveType $unpaid;

    private LeaveType $toil;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'carry_over_cap' => 6]);
        $this->special = LeaveType::factory()->deductsFrom($this->annual)->create(['name' => 'Special']);
        $this->unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid']);
        $this->toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $this->toil->id]);
        Holiday::factory()->create(['date' => '2026-10-15']);

        $this->sales = Department::factory()->create(['name' => 'Sales']);
        Department::factory()->create(['name' => 'Engineering']);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
        $managerUser = User::factory()->create(['name' => 'Manager'])->assignRole('manager');
        $this->manager = Employee::factory()->create(['full_name' => 'Manager', 'user_id' => $managerUser->id, 'department_id' => $this->sales->id]);
    }

    /**
     * $count more employees in the manager's team, each with a month of
     * figures for every tab: present and late days, a holiday worked, approved
     * Annual and Special leave and pending Unpaid, paid and time-off overtime
     * credited, and time off in lieu — posted for every other one, only a
     * remainder for the rest.
     */
    private function employees(int $count): void
    {
        for ($i = 0; $i < $count; $i++, $this->made++) {
            $employee = Employee::factory()->create([
                'full_name' => "Employee {$this->made}",
                'employee_code' => sprintf('RPT-%04d', $this->made + 1),
                'manager_id' => $this->manager->id,
                'department_id' => $this->sales->id,
            ]);
            $leave = Leave::factory()->approved()->between('2026-10-05', '2026-10-05')->create(['employee_id' => $employee->id, 'leave_type_id' => $this->annual->id]);
            Leave::factory()->approved()->between('2026-10-08', '2026-10-09')->create(['employee_id' => $employee->id, 'leave_type_id' => $this->special->id]);
            Leave::factory()->pending()->between('2026-11-16', '2026-11-17')->create(['employee_id' => $employee->id, 'leave_type_id' => $this->unpaid->id]);
            $pay = OvertimeRequest::factory()->approved()->pay()->window('2026-10-06', '17:00', '18:00')->create(['employee_id' => $employee->id]);
            $timeOff = OvertimeRequest::factory()->approved()->timeOff()->window('2026-10-07', '17:00', '18:30')->create(['employee_id' => $employee->id]);

            $row = fn (string $date, array $extra = []) => DailyAttendance::factory()->for($employee)->create([
                'work_date' => $date, 'status' => AttendanceStatus::Present,
                'first_in' => "{$date} 08:00:00", 'last_out' => "{$date} 17:00:00", 'worked_minutes' => 480, ...$extra,
            ]);
            $row('2026-10-01', ['first_in' => '2026-10-01 08:21:00', 'late_minutes' => 21]);
            $row('2026-10-02');
            $row('2026-10-05', ['status' => AttendanceStatus::Leave, 'first_in' => null, 'last_out' => null, 'worked_minutes' => 0, 'leave_id' => $leave->id]);
            $row('2026-10-06', ['last_out' => '2026-10-06 18:00:00', 'overtime_request_id' => $pay->id, 'overtime_workday_minutes' => 60]);
            $row('2026-10-07', ['last_out' => '2026-10-07 18:30:00', 'overtime_request_id' => $timeOff->id, 'overtime_workday_minutes' => 90]);
            $row('2026-10-15');

            if ($this->made % 2 === 0) {
                LeaveAdjustment::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $this->toil->id, 'year' => 2026, 'days' => 0.5, 'note' => 'Time off in lieu', 'created_by' => null, 'overtime_request_id' => $timeOff->id]);
            }
        }
    }

    /** The queries $run runs, every memo earlier runs left cold again. */
    private function queries(callable $run): int
    {
        Employee::forgetSubordinateIds();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * $run's queries with 10 employees and with 50: they must be the same.
     *
     * @return array{0: int, 1: int}
     */
    private function costAt10And50(string $what, callable $run): array
    {
        $this->employees(10);
        $this->queries($run);
        $ten = $this->queries($run);
        $this->employees(40);
        $fifty = $this->queries($run);

        $this->assertSame($ten, $fifty, "{$what} ran {$ten} queries for 10 employees and {$fifty} for 50.");

        return [$ten, $fifty];
    }

    private function page(User $user, string $tab): callable
    {
        return fn () => $this->actingAs($user)->get(route('reports.index', ['tab' => $tab]))->assertOk();
    }

    public function test_the_page_on_the_attendance_tab_costs_the_same_for_10_or_50_employees(): void
    {
        $this->costAt10And50('/reports?tab=attendance', $this->page($this->admin, 'attendance'));
    }

    public function test_the_page_on_the_leave_tab_costs_the_same_for_10_or_50_employees(): void
    {
        $this->costAt10And50('/reports?tab=leave', $this->page($this->admin, 'leave'));
    }

    public function test_the_page_on_the_overtime_tab_costs_the_same_for_10_or_50_employees(): void
    {
        $this->costAt10And50('/reports?tab=overtime', $this->page($this->admin, 'overtime'));
    }

    public function test_a_managers_tabs_cost_the_same_for_a_team_of_10_or_50(): void
    {
        $this->costAt10And50('The manager\'s attendance tab', $this->page($this->manager->user, 'attendance'));

        // The same team, 50 strong, on the leave tab; then 90.
        $fifty = $this->queries($this->page($this->manager->user, 'leave'));
        $this->employees(40);
        $ninety = $this->queries($this->page($this->manager->user, 'leave'));
        $this->assertSame($fifty, $ninety, "The manager's leave tab ran {$fifty} queries for 50 employees and {$ninety} for 90.");
    }

    public function test_each_report_costs_the_same_when_its_tab_is_opened(): void
    {
        $this->actingAs($this->admin);
        $tabs = [
            'attendance' => fn () => Livewire::test(Attendance::class),
            'leave (Annual)' => fn () => Livewire::test(LeaveReportTab::class),
            'leave (Special)' => fn () => Livewire::test(LeaveReportTab::class, ['type' => (string) $this->special->id]),
            'leave (Unpaid)' => fn () => Livewire::test(LeaveReportTab::class, ['type' => (string) $this->unpaid->id]),
            'leave (Time off in lieu)' => fn () => Livewire::test(LeaveReportTab::class, ['type' => (string) $this->toil->id]),
            'overtime' => fn () => Livewire::test(Overtime::class),
        ];

        $this->employees(10);
        // A first run warms what every run shares (roles, settings).
        array_map(fn (callable $run) => $this->queries($run), $tabs);
        $ten = array_map(fn (callable $run) => $this->queries($run), $tabs);
        $this->employees(40);
        $fifty = array_map(fn (callable $run) => $this->queries($run), $tabs);

        $this->assertSame($ten, $fifty);
    }

    public function test_the_csvs_cost_the_same_for_10_or_50_employees(): void
    {
        $this->actingAs($this->admin);
        $csvs = [
            'attendance' => fn () => Livewire::test(Attendance::class)->call('download')->assertFileDownloaded(),
            // Every type, one after another: Annual, Special, Unpaid, Time off in lieu.
            'leave' => fn () => Livewire::test(LeaveReportTab::class)->call('download')->assertFileDownloaded(),
            'overtime' => fn () => Livewire::test(Overtime::class)->call('download')->assertFileDownloaded(),
        ];

        $this->employees(10);
        // A first run warms what every run shares (roles, settings).
        array_map(fn (callable $run) => $this->queries($run), $csvs);
        $ten = array_map(fn (callable $run) => $this->queries($run), $csvs);
        $this->employees(40);
        $fifty = array_map(fn (callable $run) => $this->queries($run), $csvs);

        $this->assertSame($ten, $fifty);
    }

    public function test_only_the_open_tab_computes_its_report_with_the_page(): void
    {
        $this->employees(3);
        $queriesOf = function (string $tab) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = $this->actingAs($this->admin)->get(route('reports.index', ['tab' => $tab]))->assertOk();
            DB::disableQueryLog();

            return [$page, collect(DB::getQueryLog())->pluck('query')];
        };
        // A query only each report runs.
        $leaveReport = fn ($queries) => $queries->contains(fn (string $sql) => str_contains($sql, 'from `leave_entitlements`'));
        $overtimeReport = fn ($queries) => $queries->contains(fn (string $sql) => str_contains($sql, 'overtime_requests.compensation as compensation'));
        $attendanceReport = fn ($queries) => $queries->contains(fn (string $sql) => str_starts_with($sql, 'select * from `daily_attendances` where `employee_id` in'));

        [$page, $queries] = $queriesOf('overtime');
        $page->assertSee('Overtime report')->assertDontSee('Attendance report')->assertDontSee('Leave report')
            ->assertSee('Loading the attendance report…')->assertSee('Loading the leave report…')->assertDontSee('Loading the overtime report…')
            // The page tells a tab when it's first shown.
            ->assertSeeHtml("Livewire.dispatchTo('reports.' + value, 'report-open')");
        $this->assertTrue($overtimeReport($queries));
        $this->assertFalse($leaveReport($queries));
        $this->assertFalse($attendanceReport($queries));

        [$page, $queries] = $queriesOf('leave');
        $page->assertSee('Leave report')->assertDontSee('Attendance report')->assertDontSee('Overtime report')
            ->assertSee('Loading the attendance report…')->assertSee('Loading the overtime report…');
        $this->assertTrue($leaveReport($queries));
        $this->assertFalse($overtimeReport($queries));
        $this->assertFalse($attendanceReport($queries));

        [$page, $queries] = $queriesOf('attendance');
        $page->assertSee('Attendance report')->assertDontSee('Leave report')->assertDontSee('Overtime report');
        $this->assertTrue($attendanceReport($queries));
        $this->assertFalse($leaveReport($queries));
        $this->assertFalse($overtimeReport($queries));
    }

    public function test_a_tab_computes_its_report_when_first_opened_and_follows_the_month_before_that(): void
    {
        $this->employees(2);
        $this->actingAs($this->admin);

        $tab = Livewire::test(Overtime::class, ['opened' => false])
            ->assertSee('Loading the overtime report…')
            ->assertDontSee('Overtime report')
            // Another tab changed the month while this one wasn't open yet.
            ->dispatch('report-month', month: '2026-09')
            ->assertSee('Loading the overtime report…');

        $tab->dispatch('report-open')
            ->assertSet('month', '2026-09')
            ->assertSee('Overtime report')
            ->assertSee('September 2026')
            ->assertDontSee('Loading the overtime report…');

        Livewire::test(LeaveReportTab::class, ['opened' => false])->assertSee('Loading the leave report…')
            ->dispatch('report-open')->assertSee('Leave report')->assertSee('Employee 1');
        Livewire::test(Attendance::class, ['opened' => false])->assertSee('Loading the attendance report…')
            ->dispatch('report-open')->assertSee('Attendance report')->assertSee('Employee 1');
    }

    public function test_a_direct_link_opens_its_tab_with_data_and_its_filters(): void
    {
        $this->employees(2);

        $this->actingAs($this->admin)->get(route('reports.index', ['tab' => 'leave', 'type' => $this->unpaid->id]))->assertOk()
            ->assertSee('Leave report')
            ->assertSee('Employee 0')
            ->assertDontSee('Loading the leave report…');

        $this->actingAs($this->admin)->get(route('reports.index', ['tab' => 'overtime', 'month' => '2026-10']))->assertOk()
            ->assertSee('Overtime report')
            ->assertSee('Employee 1');

        // A manager asking for the admin-only tab opens on their first one, with data.
        $this->actingAs($this->manager->user)->get(route('reports.index', ['tab' => 'overtime']))->assertOk()
            ->assertSee('Attendance report')
            ->assertSee('Employee 0')
            ->assertSee('Loading the leave report…');
    }
}
