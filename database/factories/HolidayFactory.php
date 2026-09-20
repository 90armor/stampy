<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => $this->faker->unique()->dateTimeBetween('-1 month', '+2 months')->format('Y-m-d'),
            'name' => $this->faker->words(2, true),
            'note' => null,
            'created_by' => null,
        ];
    }
}
