<?php

namespace Tests\Feature\Reports;

use App\Enums\LeaveStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\EmployeeLifecycle;
use App\Services\Leave\Balance;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveGranter;
use App\Support\LeaveDays;
use App\Support\LeaveReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5b — the yearly leave report. Its rows are LeaveBalance's own
 * Balance, so every row must equal what Time off and the profile show for
 * that employee, type and year: the guard against a second calculation. The
 * seeded comparison runs at whatever instant the suite does; the hand-built
 * cases at Mon 15 Jun 2026 12:00.
 */
class LeaveReportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> The fields a row shows, comparable with assertSame. */
    private function fields(Balance $balance): array
    {
        return [
            'hasBalance' => $balance->hasBalance,
            'entitled' => $balance->entitled,
            'carriedIn' => $balance->carriedIn,
            'adjustments' => $balance->adjustments,
            'used' => $balance->used,
            'pending' => $balance->pending,
            'available' => $balance->hasBalance ? $balance->available() : null,
            'usableFrom' => $balance->usableFrom?->format('Y-m-d'),
            'earnedToLastDay' => $balance->earnedToLastDay,
        ];
    }

    private function assertEveryRowEqualsTheBalance(array $report, LeaveType $type, int $year): void
    {
        foreach ($report['rows'] as $row) {
            // A fresh instance, so nothing the report loaded is reused.
            $employee = Employee::find($row['employee']->id);
            $this->assertSame(
                $this->fields(app(LeaveBalance::class)->for($employee, $type, $year)),
                $this->fields($row['balance']),
                "{$employee->employee_code}, {$type->name}, {$year}",
            );
        }
    }

    public function test_every_row_of_the_seed_equals_the_leave_balance(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();
        $manager = User::where('email', 'aye.aye.mon@example.com')->firstOrFail();
        $team = [$manager->employee->id, ...$manager->employee->subordinateIds()];
        $rowsSeen = 0;

        foreach ([today()->year, today()->year - 1] as $year) {
            foreach (LeaveReport::types($year) as $type) {
                $report = LeaveReport::forYear($year, $type, $admin);
                $this->assertEveryRowEqualsTheBalance($report, $type, $year);
                $rowsSeen += count($report['rows']);

                // Everyone active in the year, except where an earned type isn't shown.
                $this->assertSame(
                    Employee::query()->activeBetween(Carbon::create($year), Carbon::create($year)->endOfYear())->get()
                        ->filter(fn (Employee $employee) => $type->isShownFor($employee))->count(),
                    count($report['rows']),
                    "{$type->name}, {$year}",
                );

                $teamReport = LeaveReport::forYear($year, $type, $manager);
                foreach ($teamReport['rows'] as $row) {
                    $this->assertContains($row['employee']->id, $team);
                }
                $this->assertEveryRowEqualsTheBalance($teamReport, $type, $year);
            }
        }

        $this->assertGreaterThan(0, $rowsSeen);

        // A leaver: the balance as at their last day, and Left in the year.
        $leaver = Employee::where('employee_code', 'EMP-0010')->firstOrFail();
        app(EmployeeLifecycle::class)->deactivate($leaver, today()->subDay()->max($leaver->join_date), $admin);
        $annual = LeaveType::where('name', 'Annual')->firstOrFail();
        $report = LeaveReport::forYear(today()->year, $annual, $admin);
        $row = collect($report['rows'])->first(fn ($row) => $row['employee']->is($leaver));
        $this->assertNotNull($row['left']);
        $this->assertEveryRowEqualsTheBalance($report, $annual, today()->year);
    }

    public function test_the_edge_cases_show_what_time_off_shows(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);
        $admin = User::factory()->create()->assignRole('admin');

        // Types first, so new employees get their grants as they're created.
        $annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => false, 'carry_over_cap' => 6]);
        $medical = LeaveType::factory()->create(['name' => 'Medical', 'days_per_year' => 30, 'min_service_months' => null, 'seniority_bonus' => false, 'carry_over_cap' => null]);
        $unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid', 'is_paid' => false]);
        $toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);

        $veteran = Employee::factory()->create(['employee_code' => 'EMP-0001', 'join_date' => '2020-01-01']);
        $joiner = Employee::factory()->create(['employee_code' => 'EMP-0002', 'join_date' => '2026-03-02']);
        $leaver = Employee::factory()->inactive('2026-04-30')->create(['employee_code' => 'EMP-0003', 'join_date' => '2020-01-01']);

        // Not yet usable: Time off's "usable from", never a bare 0.
        $annualReport = LeaveReport::forYear(2026, $annual, $admin);
        $joinerRow = collect($annualReport['rows'])->first(fn ($row) => $row['employee']->is($joiner));
        $this->assertSame('2027-03-02', $joinerRow['balance']->usableFrom->format('Y-m-d'));
        $this->assertGreaterThan(0, $joinerRow['earnedSoFar']);
        $this->assertSame('2026-03-02', $joinerRow['joined']->format('Y-m-d'));

        // A leaver: Left in the year, the balance as at their last day.
        $leaverRow = collect($annualReport['rows'])->first(fn ($row) => $row['employee']->is($leaver));
        $this->assertSame('2026-04-30', $leaverRow['left']->format('Y-m-d'));
        $this->assertNotNull($leaverRow['balance']->earnedToLastDay);

        // A joiner this year: Medical pro-rated from the join date.
        $medicalRow = collect(LeaveReport::forYear(2026, $medical, $admin)['rows'])->first(fn ($row) => $row['employee']->is($joiner));
        $this->assertSame(LeaveDays::ceilToHalf(300 * 305, 365), $medicalRow['balance']->entitled);

        // A negative available is shown as it is: over-used Annual.
        Leave::factory()->for($veteran)->between('2026-05-04', '2026-05-29')->approved()->create(['leave_type_id' => $annual->id]);
        $veteranRow = collect(LeaveReport::forYear(2026, $annual, $admin)['rows'])->first(fn ($row) => $row['employee']->is($veteran));
        $this->assertSame(180 - 200, $veteranRow['balance']->available());

        // No balance: used days only.
        Leave::factory()->for($veteran)->between('2026-06-01', '2026-06-02')->approved()->create(['leave_type_id' => $unpaid->id]);
        Leave::factory()->for($veteran)->between('2026-06-08', '2026-06-08')->pending()->create(['leave_type_id' => $unpaid->id]);
        $unpaidReport = LeaveReport::forYear(2026, $unpaid, $admin);
        $this->assertFalse($unpaidReport['hasBalance']);
        $this->assertSame(20, collect($unpaidReport['rows'])->first(fn ($row) => $row['employee']->is($veteran))['balance']->used);
        $this->assertSame(20, $unpaidReport['totals']['used']);
        $this->assertSame(0, $unpaidReport['totals']['available']);

        // Time off in lieu: only whoever it's shown for — nobody yet.
        $this->assertSame([], LeaveReport::forYear(2026, $toil, $admin)['rows']);
        // Earned half a day, then took a whole one: negative, and shown.
        LeaveAdjustment::factory()->create(['employee_id' => $veteran->id, 'leave_type_id' => $toil->id, 'year' => 2026, 'days' => '0.5', 'created_by' => $admin->id, 'note' => 'Opening balance']);
        Leave::factory()->for($veteran)->between('2026-06-10', '2026-06-10')->approved()->create(['leave_type_id' => $toil->id]);
        $toilReport = LeaveReport::forYear(2026, $toil, $admin);
        $this->assertSame([$veteran->id], array_map(fn ($row) => $row['employee']->id, $toilReport['rows']));
        $this->assertSame(-5, $toilReport['rows'][0]['balance']->available());

        // Next year, once granted: offered, with its entitlements.
        $this->assertNotContains(2027, LeaveReport::years());
        app(LeaveGranter::class)->grant($veteran, 2027, today());
        $this->assertContains(2027, LeaveReport::years());
        $next = collect(LeaveReport::forYear(2027, $medical, $admin)['rows'])->first(fn ($row) => $row['employee']->is($veteran));
        $this->assertSame(300, $next['balance']->entitled);
        $this->assertSame(0, $next['balance']->used);

        foreach ([$annual, $medical, $unpaid, $toil] as $type) {
            $this->assertEveryRowEqualsTheBalance(LeaveReport::forYear(2026, $type, $admin), $type, 2026);
        }
    }

    public function test_the_types_offered_include_an_inactive_one_with_data_that_year(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create();
        $retired = LeaveType::factory()->withoutBalance()->inactive()->create(['name' => 'Study']);
        $unused = LeaveType::factory()->withoutBalance()->inactive()->create(['name' => 'Sabbatical']);
        Leave::factory()->for($employee)->between('2026-02-02', '2026-02-02')->approved()->create(['leave_type_id' => $retired->id]);

        $this->assertContains('Study', LeaveReport::types(2026)->pluck('name'));
        $this->assertNotContains('Study', LeaveReport::types(2025)->pluck('name'));
        $this->assertNotContains('Sabbatical', LeaveReport::types(2026)->pluck('name'));
    }

    public function test_a_manager_sees_their_team_and_the_department_filter_never_widens_it(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);
        $medical = LeaveType::factory()->create(['name' => 'Medical', 'days_per_year' => 30, 'min_service_months' => null, 'seniority_bonus' => false, 'carry_over_cap' => null]);
        $engineering = Department::factory()->create(['name' => 'Engineering']);
        $operations = Department::factory()->create(['name' => 'Operations']);
        $managerUser = User::factory()->create()->assignRole('manager');
        $manager = Employee::factory()->create(['user_id' => $managerUser->id, 'department_id' => $engineering->id, 'employee_code' => 'EMP-0001']);
        Employee::factory()->create(['manager_id' => $manager->id, 'department_id' => $engineering->id, 'employee_code' => 'EMP-0002']);
        Employee::factory()->create(['department_id' => $operations->id, 'employee_code' => 'EMP-0003']);

        $codes = fn (array $report) => array_map(fn ($row) => $row['employee']->employee_code, $report['rows']);

        $report = LeaveReport::forYear(2026, $medical, $managerUser);
        $this->assertSame(['EMP-0001', 'EMP-0002'], $codes($report));
        $this->assertSame([$engineering->id => 'Engineering'], $report['departments']);

        $outside = LeaveReport::forYear(2026, $medical, $managerUser, $operations->id);
        $this->assertNull($outside['department']);
        $this->assertSame(['EMP-0001', 'EMP-0002'], $codes($outside));
    }
}
