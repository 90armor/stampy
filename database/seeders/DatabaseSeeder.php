<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends DemoSeeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately NOT `use WithoutModelEvents;` (Laravel's default stub
     * had it from the very first commit, with no seeder here ever relying
     * on it): EmployeeSeeder's employees need Employee::booted()'s created()
     * listener to fire, which assigns each one their initial
     * employee_work_schedules row — silencing model events left every
     * seeded employee with zero assignments, the exact data-integrity state
     * scheduleOn() exists to catch.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            WorkScheduleSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            // Before EmployeeSeeder: each employee's creation grants this
            // year's leave (Employee::booted(), LeaveGranter), which needs the
            // types to exist. Real configuration, not demo data — see the seeder.
            LeaveTypeSeeder::class,
            EmployeeSeeder::class,
            // Before AttendanceLogSeeder/build-daily below — holidays must
            // already exist when the builder first runs, or the seeded
            // system comes up with those dates built as plain absent/
            // present instead of holiday/present-with-no-timing-exception.
            HolidaySeeder::class,
            AttendanceLogSeeder::class,
        ]);

        // Same window AttendanceLogSeeder just populated, so the seeded
        // system comes up with daily_attendances already built, not empty.
        $to = Carbon::today();
        $from = $to->copy()->subDays(AttendanceLogSeeder::DAYS - 1);

        Artisan::call('attendance:build-daily', [
            '--from' => $from->format('Y-m-d'),
            '--to' => $to->format('Y-m-d'),
        ]);

        $this->command?->info(trim(Artisan::output()));

        // Demo leave requests, after the build: a past leave is placed by what
        // its day was built as (see the seeder), and approving one rebuilds it.
        $this->call(LeaveSeeder::class);
    }
}
