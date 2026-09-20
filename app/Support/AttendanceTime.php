<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The single point every punch-time render goes through (calendar, day
 * modal, table view, attendance list) — see config/attendance.php's
 * time_format for why: one config line changes the format everywhere
 * instead of a hunt through blade files that inevitably drifts.
 */
class AttendanceTime
{
    public static function format(?CarbonInterface $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return $time->format(config('attendance.time_format', 'g:i A'));
    }
}
