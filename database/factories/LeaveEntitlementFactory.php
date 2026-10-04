<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveEntitlement>
 */
class LeaveEntitlementFactory extends Factory
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
            'days' => 12,
            'granted_by' => null,
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(['year' => $year]);
    }
}
