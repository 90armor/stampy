<?php

namespace Database\Factories;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeRequest>
 */
class OvertimeRequestFactory extends Factory
{
    /**
     * A pending, planned request for pay: 18:00–20:00 today, waiting on step 1.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => today()->format('Y-m-d'),
            'starts_at' => fn (array $attributes) => Carbon::parse($attributes['date'])->setTime(18, 0),
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['date'])->setTime(20, 0),
            'kind' => OvertimeKind::Planned,
            'compensation' => OvertimeCompensation::Pay,
            'reason' => null,
            'status' => OvertimeStatus::Pending,
            'current_step' => 1,
            'requested_by' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'limit_override_reason' => null,
        ];
    }

    /** On $date, $from–$to ("HH:MM"); an end before the start is on the next day. */
    public function window(string $date, string $from, string $to): static
    {
        $start = Carbon::parse("{$date} {$from}");
        $end = Carbon::parse("{$date} {$to}");

        return $this->state([
            'date' => $date,
            'starts_at' => $start,
            'ends_at' => $end->lte($start) ? $end->addDay() : $end,
        ]);
    }

    public function planned(): static
    {
        return $this->state(['kind' => OvertimeKind::Planned]);
    }

    public function claim(): static
    {
        return $this->state(['kind' => OvertimeKind::Claim]);
    }

    public function pay(): static
    {
        return $this->state(['compensation' => OvertimeCompensation::Pay]);
    }

    public function timeOff(): static
    {
        return $this->state(['compensation' => OvertimeCompensation::TimeOff]);
    }

    /** 20:00 on its date until 01:00 the next day. */
    public function overnight(): static
    {
        return $this->state([
            'starts_at' => fn (array $attributes) => Carbon::parse($attributes['date'])->setTime(20, 0),
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['date'])->addDay()->setTime(1, 0),
        ]);
    }

    public function pending(int $step = 1): static
    {
        return $this->state(['status' => OvertimeStatus::Pending, 'current_step' => $step]);
    }

    public function approved(): static
    {
        return $this->state(['status' => OvertimeStatus::Approved, 'current_step' => null]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => OvertimeStatus::Rejected, 'current_step' => null]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => OvertimeStatus::Cancelled, 'current_step' => null, 'cancelled_at' => now()]);
    }
}
