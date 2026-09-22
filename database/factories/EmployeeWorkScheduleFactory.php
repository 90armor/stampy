<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeWorkSchedule>
 */
class EmployeeWorkScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_schedule_id' => WorkSchedule::factory(),
            'effective_from' => now()->toDateString(),
            'created_by' => null,
        ];
    }
}
