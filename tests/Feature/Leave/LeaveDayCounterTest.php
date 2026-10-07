<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveDayCounter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3b — what a leave costs, per calendar year (CLAUDE.md, Phase 3,
 * Policy rule 7). Amounts are tenths of a day.
 */
class LeaveDayCounterTest extends TestCase
{
    use RefreshDatabase;

    private LeaveDayCounter $counter;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-04-01 12:00:00'));

        WorkSchedule::factory()->create(['is_default' => true]); // Mon–Fri
        $this->employee = Employee::factory()->create();
        $this->annual = LeaveType::factory()->create(['name' => 'Annual']);
        $this->counter = app(LeaveDayCounter::class);
    }

    private function days(string $from, string $to, ?LeaveType $type = null, bool $half = false): array
    {
        return $this->counter->count($this->employee->fresh(), $type ?? $this->annual, Carbon::parse($from), Carbon::parse($to), $half);
    }

    public function test_weekends_inside_a_leave_cost_nothing(): void
    {
        // Mon 6 – Sun 19 Apr 2026: ten workdays.
        $this->assertSame([2026 => 100], $this->days('2026-04-06', '2026-04-19'));
        // A leave that is only a weekend costs nothing at all.
        $this->assertSame([], $this->days('2026-04-11', '2026-04-12'));
    }

    public function test_a_holiday_inside_a_leave_costs_nothing_even_one_added_later(): void
    {
        $this->assertSame([2026 => 100], $this->days('2026-04-06', '2026-04-17'));

        Holiday::factory()->create(['date' => '2026-04-14', 'name' => 'Khmer New Year']);

        $this->assertSame([2026 => 90], $this->days('2026-04-06', '2026-04-17'));
    }

    public function test_a_schedule_reassignment_partway_through_is_counted_per_date(): void
    {
        $sixDays = WorkSchedule::factory()->create(['workdays' => [1, 2, 3, 4, 5, 6]]);
        EmployeeWorkSchedule::create(['employee_id' => $this->employee->id, 'work_schedule_id' => $sixDays->id, 'effective_from' => '2026-04-13']);

        // Week of 6 Apr on Mon–Fri (5), week of 13 Apr on Mon–Sat (6).
        $this->assertSame([2026 => 110], $this->days('2026-04-06', '2026-04-19'));
    }

    public function test_calendar_day_types_count_weekends_and_holidays(): void
    {
        $maternity = LeaveType::factory()->withoutBalance()->calendarDays()->withoutHalfDays()->create(['name' => 'Maternity']);
        Holiday::factory()->create(['date' => '2026-04-14', 'name' => 'Khmer New Year']);

        $this->assertSame([2026 => 140], $this->days('2026-04-06', '2026-04-19', $maternity));
    }

    public function test_a_half_day_counts_half_and_only_on_a_day_that_counts(): void
    {
        $this->assertSame([2026 => 5], $this->days('2026-04-15', '2026-04-15', half: true));
        $this->assertSame([], $this->days('2026-04-18', '2026-04-18', half: true));
    }

    public function test_a_leave_across_new_year_costs_each_years_balance_separately(): void
    {
        // Wed 30 Dec 2026 – Sun 3 Jan 2027: two workdays in 2026, Fri 1 Jan in 2027.
        $this->assertSame([2026 => 20, 2027 => 10], $this->days('2026-12-30', '2027-01-03'));
        // Entirely in next year.
        $this->assertSame([2027 => 50], $this->days('2027-01-04', '2027-01-08'));
    }

    public function test_count_leave_reads_the_leaves_own_dates_type_and_half(): void
    {
        $leave = Leave::factory()->for($this->employee)->for($this->annual)->between('2026-04-15', '2026-04-15')->halfDay('pm')->create();

        $this->assertSame([2026 => 5], $this->counter->countLeave($leave));
    }
}
