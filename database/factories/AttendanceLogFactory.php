<?php

namespace Database\Factories;

use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
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
            'source' => PunchSource::Device,
            'device_id' => null,
            'created_by' => null,
            'raw' => null,
            'voided_at' => null,
            'voided_by' => null,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'voided_at' => now(),
            'voided_by' => User::factory(),
        ]);
    }
}
