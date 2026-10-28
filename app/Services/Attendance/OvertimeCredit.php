<?php

namespace App\Services\Attendance;

/**
 * OvertimeCalculator's answer for one day: the approved request the day is
 * credited against (null when there's none) and the credited minutes per
 * category — every minute in exactly one (CLAUDE.md, Phase 4, rule 3). An
 * approved request that credits nothing (no out-punch, left before the
 * window) still carries its id, so a view can say "approved, nothing
 * credited".
 */
final readonly class OvertimeCredit
{
    public function __construct(
        public ?int $requestId = null,
        public int $workday = 0,
        public int $night = 0,
        public int $restDay = 0,
        public int $holiday = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function total(): int
    {
        return $this->workday + $this->night + $this->restDay + $this->holiday;
    }

    /**
     * The daily_attendances columns.
     *
     * @return array<string, int|null>
     */
    public function attributes(): array
    {
        return [
            'overtime_request_id' => $this->requestId,
            'overtime_workday_minutes' => $this->workday,
            'overtime_night_minutes' => $this->night,
            'overtime_rest_day_minutes' => $this->restDay,
            'overtime_holiday_minutes' => $this->holiday,
        ];
    }
}
