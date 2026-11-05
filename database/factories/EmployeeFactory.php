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
     * Codes and device ids by sequence (Phase 6h): FAC-00001, F00001, …, a
     * prefix no test or seeder writes by hand. A random EMP-#### could take a
     * code a test had chosen (EMP-0031) about one run in a few hundred, which
     * is why 6c's query-cost test had to name its own (RPT-).
     */
    private static int $sequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = ++self::$sequence;

        return [
            'user_id' => null,
            'employee_code' => sprintf('FAC-%05d', $sequence),
            'full_name' => $this->faker->name(),
            'department_id' => Department::factory(),
            'position_id' => Position::factory(),
            'join_date' => '2020-01-01',
            'device_user_id' => sprintf('F%05d', $sequence),
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
