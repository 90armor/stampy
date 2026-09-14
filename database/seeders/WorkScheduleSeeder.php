<?php

namespace Database\Seeders;

use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    public function run(): void
    {
        WorkSchedule::firstOrCreate(
            ['name' => 'Default'],
            [
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'grace_minutes' => 10,
                'break_minutes' => 60,
                'workdays' => [1, 2, 3, 4, 5],
                'is_default' => true,
            ]
        );
    }
}
