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

    /**
     * A time range, "5:00 – 7:00 PM": the AM/PM said once when both ends
     * share it on the same day, else at each end — "11:00 AM – 1:00 PM",
     * "9:00 PM – 1:00 AM (+1)" when it ends on a later day (the attendance
     * views' (+1)). With a time format that has no trailing AM/PM, both ends
     * are written in full.
     */
    public static function range(CarbonInterface $from, CarbonInterface $to): string
    {
        $start = self::format($from);
        $end = self::format($to);
        $sameDay = $from->isSameDay($to);
        $suffix = ' '.$from->format('A');

        if ($sameDay && $from->format('A') === $to->format('A') && str_ends_with(config('attendance.time_format', 'g:i A'), ' A') && str_ends_with($start, $suffix)) {
            $start = substr($start, 0, -strlen($suffix));
        }

        return $start.' – '.$end.($sameDay ? '' : ' (+1)');
    }
}
