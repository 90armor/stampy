<?php

namespace Tests\Feature;

use App\Models\Employee;
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

    public function test_seeded_attendance_never_includes_a_punch_later_than_now(): void
    {
        // Mid-shift, so today's generated day has punches on both sides of now.
        $this->travelTo(now()->setTime(10, 30));

        $this->seed();

        $this->assertGreaterThan(0, \App\Models\AttendanceLog::count());
        $this->assertSame(0, \App\Models\AttendanceLog::where('punched_at', '>', now())->count());
    }

    public function test_seeded_attendance_includes_late_incomplete_days(): void
    {
        $this->travelTo(now()->setTime(10, 30));

        $this->seed();

        // Some in-only days punch in late, so dev data exercises late on
        // incomplete days (Phase 2.6) as well as on-time ones.
        $inOnly = \App\Models\DailyAttendance::query()
            ->where('status', 'incomplete')
            ->whereNotNull('first_in')
            ->whereDate('work_date', '<', today());

        $this->assertGreaterThan(0, (clone $inOnly)->where('late_minutes', '>', 0)->count());
        $this->assertGreaterThan(0, (clone $inOnly)->where('late_minutes', 0)->count());
    }
}
