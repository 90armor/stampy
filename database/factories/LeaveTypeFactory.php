<?php

namespace Database\Factories;

use App\Enums\LeaveCounting;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    /**
     * A plain balance type: 12 workdays a year, no carry-over, half days allowed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'days_per_year' => 12,
            'min_service_months' => null,
            'seniority_bonus' => false,
            'carry_over_cap' => null,
            'counts' => LeaveCounting::Workdays,
            'max_days_per_request' => null,
            'deducts_from_leave_type_id' => null,
            'allows_half_day' => true,
            'is_paid' => true,
            'is_active' => true,
        ];
    }

    /** No yearly balance (Unpaid, Maternity). */
    public function withoutBalance(): static
    {
        return $this->state(['days_per_year' => null, 'carry_over_cap' => null, 'seniority_bonus' => false]);
    }

    public function calendarDays(): static
    {
        return $this->state(['counts' => LeaveCounting::CalendarDays]);
    }

    public function withoutHalfDays(): static
    {
        return $this->state(['allows_half_day' => false]);
    }

    /** Takes its days from $type's balance, with none of its own (Special). */
    public function deductsFrom(LeaveType $type): static
    {
        return $this->withoutBalance()->state(['deducts_from_leave_type_id' => $type->id]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
