<?php

namespace Database\Factories;

use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            // Null by default: a schedule built with other hours or no break
            // would otherwise fail break_start's own rule. withBreakStart() sets it.
            'break_start' => null,
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => false,
        ];
    }

    public function withBreakStart(string $time = '12:00:00'): static
    {
        return $this->state(['break_start' => $time]);
    }
}
