<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Services\Leave\EntitlementCalculator;
use App\Support\LeaveDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards specifically against the DatabaseSeeder regression Phase 2.5b's
 * review surfaced: `use WithoutModelEvents;` (present in the stub since the
 * very first commit) silently suppressed Employee::booted()'s auto-assignment
 * listener, seeding 35 employees with zero employee_work_schedules rows and
 * no error anywhere — attendance:build-daily was the first thing to notice,
 * and only because it happened to run right after. This runs the real
 * seeder, not a synthetic approximation of it, so it fails the same way
 * that bug would if the trait (or any other blanket event-suppression) were
 * ever reintroduced.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_employee_has_at_least_one_schedule_assignment(): void
    {
        $this->seed();

        $employees = Employee::all();
        $this->assertGreaterThan(0, $employees->count(), 'Sanity check: the seeder should have created employees at all.');

        $withoutAssignment = $employees->filter(fn (Employee $employee) => $employee->scheduleAssignments()->doesntExist());

        $this->assertCount(
            0,
            $withoutAssignment,
            'Employees with no schedule assignment: '.$withoutAssignment->pluck('employee_code')->implode(', '),
        );
    }

    /**
     * The same kind of guard for leave: LeaveTypeSeeder runs before
     * EmployeeSeeder, so each employee's creation grants this year's leave
     * (Employee::booted(), LeaveGranter). Every seeded employee must hold
     * exactly the grants the calculator says they're eligible for today — a
     * seeder order or event-suppression regression leaves them with none.
     */
    public function test_every_seeded_employee_has_the_leave_grants_they_are_eligible_for(): void
    {
        $this->seed();

        $calculator = app(EntitlementCalculator::class);
        $types = LeaveType::query()->whereNotNull('days_per_year')->get();
        $this->assertGreaterThan(0, $types->count(), 'Sanity check: the seeder should have created leave types with a balance.');

        $problems = [];

        foreach (Employee::all() as $employee) {
            foreach ($types as $type) {
                $expected = $calculator->forYear($employee, $type, today()->year);
                $row = LeaveEntitlement::query()
                    ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', today()->year)
                    ->first();

                $want = $expected->isGrantableOn(today()) ? LeaveDays::toDecimal($expected->days) : null;

                if ($row?->days !== $want) {
                    $problems[] = "{$employee->employee_code} {$type->name}: expected ".($want ?? 'no grant').', got '.($row?->days ?? 'no grant');
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_seeded_attendance_never_includes_a_punch_later_than_now(): void
    {
        // Mid-shift, so today's generated day has punches on both sides of now.
        $this->travelTo(now()->setTime(10, 30));

        $this->seed();

        $this->assertGreaterThan(0, AttendanceLog::count());
        $this->assertSame(0, AttendanceLog::where('punched_at', '>', now())->count());
    }

    public function test_seeded_attendance_includes_late_incomplete_days(): void
    {
        $this->travelTo(now()->setTime(10, 30));

        $this->seed();

        // Some in-only days punch in late, so dev data exercises late on
        // incomplete days (Phase 2.6) as well as on-time ones.
        $inOnly = DailyAttendance::query()
            ->where('status', 'incomplete')
            ->whereNotNull('first_in')
            ->whereDate('work_date', '<', today());

        $this->assertGreaterThan(0, (clone $inOnly)->where('late_minutes', '>', 0)->count());
        $this->assertGreaterThan(0, (clone $inOnly)->where('late_minutes', 0)->count());
    }
}
