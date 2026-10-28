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
            'join_date' => '2020-01-01',
            'device_user_id' => $this->faker->unique()->numerify('####'),
            'status' => 'active',
            // An inactive employee needs a last day (Employee::booted()). A
            // plain ['status' => 'inactive'] left on their join date, so they
            // are outside every recent date — use inactive() for a real one.
            'left_on' => fn (array $attributes) => $attributes['status'] === 'inactive' ? $attributes['join_date'] : null,
        ];
    }

    public function inactive(?string $leftOn = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'left_on' => $leftOn ?? $attributes['join_date'],
        ]);
    }
}
