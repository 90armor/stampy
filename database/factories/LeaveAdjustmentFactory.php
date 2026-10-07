<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveAdjustment>
 */
class LeaveAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => today()->year,
            'days' => 1,
            'note' => $this->faker->sentence(),
            'created_by' => null,
        ];
    }
}
