<?php

namespace Database\Factories;

use App\Enums\AttendanceSource;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceLog>
 */
class AttendanceLogFactory extends Factory
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
            'punched_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'punch_type' => PunchType::In,
            'source' => AttendanceSource::Device,
            'device_id' => null,
            'created_by' => null,
            'raw' => null,
        ];
    }
}
