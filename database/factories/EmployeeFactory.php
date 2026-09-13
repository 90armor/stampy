<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'employee_code' => 'EMP-'.$this->faker->unique()->numerify('####'),
            'full_name' => $this->faker->name(),
            'department_id' => Department::factory(),
            'position_id' => Position::factory(),
            'join_date' => $this->faker->dateTimeBetween('-3 years', 'now'),
            'device_user_id' => $this->faker->unique()->numerify('####'),
            'status' => 'active',
        ];
    }
}
