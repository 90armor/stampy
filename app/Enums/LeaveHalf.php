<?php

namespace App\Enums;

/**
 * Which half of the day a half-day leave covers. The boundary comes from the
 * schedule's break_start (AM = start_time–break_start, PM = break_start +
 * break_minutes–end_time).
 */
enum LeaveHalf: string
{
    case Am = 'am';
    case Pm = 'pm';

    public function label(): string
    {
        return match ($this) {
            self::Am => 'AM',
            self::Pm => 'PM',
        };
    }
}
