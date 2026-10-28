<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Services\Leave\EntitlementCalculator;
use App\Support\LeaveDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * leave:grant at whatever "now" is — deliberately unpinned (LeaveGranterTest
 * pins fixed dates), so the pinned-instant check runs it on 31 Dec late
 * evening, on 1 Jan just after its 00:05 run, on a leap day and the rest:
 * a missed grant is created for the current year, a second run creates
 * nothing, next year can be granted early, and an eligibility date of today
 * grants.
 */
class LeaveGrantAtAnyInstantTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'min_service_months' => 12, 'seniority_bonus' => true, 'carry_over_cap' => 6]);
        LeaveType::factory()->create(['name' => 'Medical', 'days_per_year' => 30]);
    }

    /** The grant row for this type and year, as tenths, or null. */
    private function granted(Employee $employee, LeaveType $type, int $year): ?int
    {
        $row = LeaveEntitlement::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)->first();

        return $row ? LeaveDays::fromDecimal($row->days) : null;
    }

    public function test_leave_grant_creates_this_years_missing_grants_and_only_once(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $year = today()->year;

        // As if the year had just turned and the 00:05 run hadn't happened yet.
        LeaveEntitlement::query()->where('employee_id', $employee->id)->delete();

        $this->assertSame(0, Artisan::call('leave:grant'));
        $expected = app(EntitlementCalculator::class)->forYear($employee, $this->annual, $year)->days;
        $this->assertSame($expected, $this->granted($employee, $this->annual, $year));
        $this->assertSame(2, LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $year)->count());

        Artisan::call('leave:grant');
        $this->assertSame(2, LeaveEntitlement::query()->where('employee_id', $employee->id)->count());
    }

    public function test_next_year_can_be_granted_early_at_any_instant(): void
    {
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $next = today()->year + 1;

        $this->assertNull($this->granted($employee, $this->annual, $next));
        $this->assertSame(0, Artisan::call('leave:grant', ['--year' => $next]));
        $this->assertSame(app(EntitlementCalculator::class)->forYear($employee, $this->annual, $next)->days, $this->granted($employee, $this->annual, $next));
    }

    public function test_someone_whose_service_requirement_is_met_today_gets_their_annual_grant(): void
    {
        $joined = today()->subMonthsNoOverflow(12);
        $employee = Employee::factory()->create(['join_date' => $joined->format('Y-m-d')]);
        LeaveEntitlement::query()->where('employee_id', $employee->id)->delete();

        Artisan::call('leave:grant');

        $this->assertTrue(app(EntitlementCalculator::class)->forYear($employee, $this->annual, today()->year)->isGrantableOn(today()));
        $this->assertNotNull($this->granted($employee, $this->annual, today()->year));
    }
}
