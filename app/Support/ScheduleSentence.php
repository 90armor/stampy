<?php

namespace App\Support;

use App\Models\WorkSchedule;
use Carbon\Carbon;

/**
 * A work schedule in plain words, for the person working it (Phase 6i, the
 * profile): "Mon–Fri · 8:00 AM – 5:00 PM · Break 12:00 PM". Consecutive
 * workdays are a range; days that aren't consecutive are listed ("Mon, Wed,
 * Fri", "Mon–Wed, Sat"). Every workday of a schedule has the same hours, so
 * the hours are said once.
 */
class ScheduleSentence
{
    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public static function for(WorkSchedule $schedule): string
    {
        $parts = [
            self::days($schedule->workdays ?? []),
            AttendanceTime::format(Carbon::parse($schedule->start_time)).' – '.AttendanceTime::format(Carbon::parse($schedule->end_time)),
        ];

        if ($schedule->break_start !== null) {
            $parts[] = 'Break '.AttendanceTime::format(Carbon::parse($schedule->break_start));
        } elseif ($schedule->break_minutes > 0) {
            $parts[] = 'Break '.Duration::format((int) $schedule->break_minutes);
        }

        return implode(' · ', array_filter($parts));
    }

    /**
     * @param  array<int, int|string>  $workdays  ISO weekday numbers
     */
    public static function days(array $workdays): string
    {
        $days = collect($workdays)->map(fn ($day) => (int) $day)->filter(fn ($day) => isset(self::DAYS[$day]))->unique()->sort()->values();

        $runs = [];
        foreach ($days as $day) {
            $last = array_key_last($runs);

            if ($last !== null && end($runs[$last]) === $day - 1) {
                $runs[$last][] = $day;
            } else {
                $runs[] = [$day];
            }
        }

        return collect($runs)
            ->map(fn (array $run) => count($run) >= 3
                ? self::DAYS[$run[0]].'–'.self::DAYS[end($run)]
                : implode(', ', array_map(fn ($day) => self::DAYS[$day], $run)))
            ->implode(', ');
    }
}
