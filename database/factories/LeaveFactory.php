<?php

namespace Database\Factories;

use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leave>
 */
class LeaveFactory extends Factory
{
    /**
     * A pending, one-day, full-day request for today, waiting on step 1.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => today()->format('Y-m-d'),
            'end_date' => today()->format('Y-m-d'),
            'half' => null,
            'reason' => null,
            'status' => LeaveStatus::Pending,
            'current_step' => 1,
            'requested_by' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
        ];
    }

    public function between(string $start, string $end): static
    {
        return $this->state(['start_date' => $start, 'end_date' => $end]);
    }

    /** A single date's morning ('am') or afternoon ('pm'). */
    public function halfDay(string $half = 'am'): static
    {
        return $this->state(fn (array $attributes) => [
            'half' => LeaveHalf::from($half),
            'end_date' => $attributes['start_date'],
        ]);
    }

    public function pending(int $step = 1): static
    {
        return $this->state(['status' => LeaveStatus::Pending, 'current_step' => $step]);
    }

    public function approved(): static
    {
        return $this->state(['status' => LeaveStatus::Approved, 'current_step' => null]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => LeaveStatus::Rejected, 'current_step' => null]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => LeaveStatus::Cancelled, 'current_step' => null, 'cancelled_at' => now()]);
    }
}
