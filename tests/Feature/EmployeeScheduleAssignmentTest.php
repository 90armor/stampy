<?php

namespace Tests\Feature;

use App\Exceptions\NoDefaultWorkScheduleException;
use App\Exceptions\NoScheduleAssignmentException;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Support\EmployeeScheduleBackfill;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee::scheduleOn() and the employee_work_schedules assignment it
 * reads — creation-time auto-assignment, the date-resolution rule itself,
 * and the one-time migration backfill. DailySummaryBuilderTest covers the
 * builder using whatever scheduleOn() returns; this file covers scheduleOn()
 * and its assignment rows on their own.
 */
class EmployeeScheduleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function assign(Employee $employee, WorkSchedule $schedule, string $effectiveFrom): EmployeeWorkSchedule
    {
        return EmployeeWorkSchedule::updateOrCreate(
            ['employee_id' => $employee->id, 'effective_from' => $effectiveFrom],
            ['work_schedule_id' => $schedule->id],
        );
    }

    public function test_creating_an_employee_assigns_the_current_default_effective_from_their_join_date(): void
    {
        $default = WorkSchedule::factory()->create(['is_default' => true]);

        $employee = Employee::factory()->create(['join_date' => '2024-03-10']);

        $this->assertSame(1, EmployeeWorkSchedule::where('employee_id', $employee->id)->count());

        $assignment = EmployeeWorkSchedule::where('employee_id', $employee->id)->first();
        $this->assertSame($default->id, $assignment->work_schedule_id);
        $this->assertSame('2024-03-10', $assignment->effective_from->format('Y-m-d'));
    }

    public function test_creating_an_employee_with_no_default_schedule_creates_nothing_and_throws(): void
    {
        $this->assertSame(0, WorkSchedule::count());

        try {
            Employee::factory()->create(['employee_code' => 'EMP-4242']);
            $this->fail('Expected NoDefaultWorkScheduleException.');
        } catch (NoDefaultWorkScheduleException $e) {
            $this->assertStringContainsString('No default work schedule exists', $e->getMessage());
            $this->assertStringContainsString('EMP-4242', $e->getMessage());
            $this->assertStringContainsString('WorkScheduleSeeder', $e->getMessage());
        }

        // The whole thing rolled back — not an orphaned employee with no
        // assignment (see Employee::save()'s transaction wrapping).
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, EmployeeWorkSchedule::count());
    }

    public function test_schedule_on_before_the_earliest_assignment_uses_the_earliest_one(): void
    {
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2024-01-01']);
        $only = $employee->scheduleAssignments()->first()->workSchedule;

        // join_date edited to something earlier than the employee's one
        // real assignment — 2023-06-01 predates it.
        $resolved = $employee->scheduleOn(Carbon::parse('2023-06-01'));

        $this->assertSame($only->id, $resolved->id);
    }

    public function test_schedule_on_a_boundary_date_resolves_to_that_dates_own_assignment(): void
    {
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2024-01-01']);
        $morning = WorkSchedule::factory()->create(['name' => 'Morning shift']);
        $this->assign($employee, $morning, '2025-06-01');

        $resolved = $employee->scheduleOn(Carbon::parse('2025-06-01'));

        $this->assertSame($morning->id, $resolved->id);
    }

    public function test_schedule_on_between_two_assignments_uses_the_earlier_one(): void
    {
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2024-01-01']);
        $original = $employee->scheduleAssignments()->first()->workSchedule;
        $later = WorkSchedule::factory()->create(['name' => 'Later shift']);
        $this->assign($employee, $later, '2025-06-01');

        $resolved = $employee->scheduleOn(Carbon::parse('2025-03-15'));

        $this->assertSame($original->id, $resolved->id);
    }

    public function test_schedule_on_after_the_last_assignment_uses_the_latest_one(): void
    {
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2024-01-01']);
        $latest = WorkSchedule::factory()->create(['name' => 'Latest shift']);
        $this->assign($employee, $latest, '2025-06-01');

        $resolved = $employee->scheduleOn(Carbon::parse('2026-01-01'));

        $this->assertSame($latest->id, $resolved->id);
    }

    public function test_schedule_on_works_for_a_future_date_with_no_attendance_row(): void
    {
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create(['join_date' => '2024-01-01']);

        // Nothing about scheduleOn() depends on daily_attendances existing —
        // it reads only employee_work_schedules.
        $resolved = $employee->scheduleOn(today()->addYears(2));

        $this->assertNotNull($resolved);
    }

    public function test_changing_the_default_does_not_change_an_already_assigned_employees_schedule(): void
    {
        $originalDefault = WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create();

        WorkSchedule::factory()->create(['is_default' => true, 'name' => 'New default']);

        $resolved = $employee->scheduleOn(today());

        $this->assertSame($originalDefault->id, $resolved->id);
    }

    public function test_an_employee_with_zero_assignment_rows_throws_a_clear_data_integrity_error(): void
    {
        WorkSchedule::factory()->create(['is_default' => true]);
        $employee = Employee::factory()->create(['employee_code' => 'EMP-7777']);

        // Not a normal path — every employee has at least one row from
        // creation onward. Simulates the assignment(s) being removed some
        // other way.
        EmployeeWorkSchedule::where('employee_id', $employee->id)->delete();

        try {
            $employee->scheduleOn(today());
            $this->fail('Expected NoScheduleAssignmentException.');
        } catch (NoScheduleAssignmentException $e) {
            $this->assertStringContainsString('EMP-7777', $e->getMessage());
            $this->assertStringContainsString('no work schedule assignment', $e->getMessage());
        }
    }

    public function test_backfill_gives_every_existing_employee_exactly_one_row_from_their_join_date(): void
    {
        $default = WorkSchedule::factory()->create(['is_default' => true]);
        $a = Employee::factory()->create(['join_date' => '2021-06-15']);
        $b = Employee::factory()->create(['join_date' => '2022-11-02']);

        // Reproduces the pre-migration state the real migration's backfill
        // runs against: employees exist, but employee_work_schedules is empty.
        EmployeeWorkSchedule::query()->delete();
        $this->assertSame(0, EmployeeWorkSchedule::count());

        $backfilled = EmployeeScheduleBackfill::run();

        $this->assertSame(2, $backfilled);

        foreach ([[$a, '2021-06-15'], [$b, '2022-11-02']] as [$employee, $joinDate]) {
            $this->assertSame(1, EmployeeWorkSchedule::where('employee_id', $employee->id)->count());

            $row = EmployeeWorkSchedule::where('employee_id', $employee->id)->first();
            $this->assertSame($default->id, $row->work_schedule_id);
            $this->assertSame($joinDate, $row->effective_from->format('Y-m-d'));
        }
    }

    public function test_backfill_does_nothing_when_there_is_no_default_schedule(): void
    {
        // No WorkSchedule at all yet — mirrors a fresh install's migration
        // order, where employee_work_schedules is created before any
        // schedule or employee exists.
        $this->assertSame(0, EmployeeScheduleBackfill::run());
    }
}
