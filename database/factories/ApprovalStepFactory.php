<?php

namespace Database\Factories;

use App\Enums\ApprovalOutcome;
use App\Models\ApprovalStep;
use App\Models\Leave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalStep>
 */
class ApprovalStepFactory extends Factory
{
    /**
     * Step 1 of a leave request, approved.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approvable_type' => 'leave',
            'approvable_id' => Leave::factory(),
            'step' => 1,
            'outcome' => ApprovalOutcome::Approved,
            'decided_by' => null,
            'note' => null,
            'decided_at' => now(),
        ];
    }

    public function step(int $step): static
    {
        return $this->state(['step' => $step]);
    }

    public function outcome(ApprovalOutcome $outcome): static
    {
        return $this->state(['outcome' => $outcome]);
    }

    public function rejected(): static
    {
        return $this->outcome(ApprovalOutcome::Rejected);
    }

    public function skipped(): static
    {
        return $this->state(['outcome' => ApprovalOutcome::Skipped, 'decided_by' => null]);
    }
}
