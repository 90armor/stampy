<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            WorkScheduleSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            EmployeeSeeder::class,
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
    }
}
