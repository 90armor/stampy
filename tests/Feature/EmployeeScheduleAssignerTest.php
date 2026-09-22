<?php

namespace Tests\Feature;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Services\Attendance\EmployeeScheduleAssigner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeScheduleAssignerTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create(array_merge([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'workdays' => [1, 2, 3, 4, 5],
        ], $overrides));
    }

    public function test_a_backdated_assignment_rebuilds_from_its_effective_date_through_today(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $default = $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2020-01-01']);
        $morning = $this->schedule(['name' => 'Morning']);

        $built = app(EmployeeScheduleAssigner::class)->assign($employee, $morning, Carbon::parse('2026-02-06'));

        $this->assertSame(5, $built); // 02-06 through 02-10
        $this->assertSame(
            ['2026-02-06', '2026-02-07', '2026-02-08', '2026-02-09', '2026-02-10'],
            DailyAttendance::where('employee_id', $employee->id)->orderBy('work_date')->pluck('work_date')->map(fn ($d) => $d->format('Y-m-d'))->all()
        );
        $this->assertSame($morning->id, $employee->scheduleOn(Carbon::parse('2026-02-06'))->id);
        $this->assertSame($default->id, $employee->scheduleOn(Carbon::parse('2026-02-05'))->id);
    }

    public function test_a_future_dated_assignment_rebuilds_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 12:00:00'));
        $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create();
        $future = $this->schedule(['name' => 'Future shift']);

        $built = app(EmployeeScheduleAssigner::class)->assign($employee, $future, Carbon::parse('2026-03-01'));

        $this->assertSame(0, $built);
        $this->assertSame(0, DailyAttendance::where('employee_id', $employee->id)->count());
        // Not in effect yet — today still resolves to whatever was assigned before.
        $this->assertNotSame($future->id, $employee->scheduleOn(today())->id);
    }

    public function test_reassigning_at_an_existing_effective_date_replaces_that_row_not_adds_one(): void
    {
        $this->schedule(['is_default' => true]);
        $employee = Employee::factory()->create();
        $a = $this->schedule(['name' => 'A']);
        $b = $this->schedule(['name' => 'B']);

        app(EmployeeScheduleAssigner::class)->assign($employee, $a, today());
        app(EmployeeScheduleAssigner::class)->assign($employee, $b, today());

        $this->assertSame(1, EmployeeWorkSchedule::where('employee_id', $employee->id)->where('effective_from', today()->format('Y-m-d'))->count());
        $this->assertSame($b->id, $employee->scheduleOn(today())->id);
    }

    public function test_bulk_reassignment_moves_exactly_the_right_employees(): void
    {
        $a = $this->schedule(['name' => 'A', 'is_default' => true]);
        $b = $this->schedule(['name' => 'B']);

        $onA1 = Employee::factory()->create(['full_name' => 'On A One']);
        $onA2 = Employee::factory()->create(['full_name' => 'On A Two']);
        $onB = Employee::factory()->create(['full_name' => 'On B']);
        app(EmployeeScheduleAssigner::class)->assign($onB, $b, today());
        $inactiveOnA = Employee::factory()->create(['status' => 'inactive', 'full_name' => 'Inactive On A']);

        $result = app(EmployeeScheduleAssigner::class)->bulkReassign($a, $b, today());

        // Only the two genuinely on A — not $onB (already reassigned to B
        // before the bulk run) and not the inactive one (also on A, but
        // excluded from the active-only scope).
        $this->assertSame(2, $result['employees']);
        $this->assertSame($b->id, $onA1->fresh()->scheduleOn(today())->id);
        $this->assertSame($b->id, $onA2->fresh()->scheduleOn(today())->id);
        $this->assertSame($b->id, $onB->fresh()->scheduleOn(today())->id);
        // Inactive — left alone even though it was on A.
        $this->assertSame($a->id, $inactiveOnA->fresh()->scheduleOn(today())->id);
    }
}
