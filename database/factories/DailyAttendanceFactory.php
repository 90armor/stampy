<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAttendance>
 */
class DailyAttendanceFactory extends Factory
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
            'work_date' => $this->faker->unique()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'work_schedule_id' => null,
            'first_in' => null,
            'last_out' => null,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'status' => AttendanceStatus::Present,
            'note' => null,
        ];
    }
}
