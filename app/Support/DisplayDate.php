<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The single formatter for every date shown in the UI, so dates can't drift
 * into different orders and styles (the same job App\Support\Duration does
 * for durations). Day-month order everywhere, and only five forms:
 *
 * - compact: "Tue 29 Sep" — tables, stat strip and card meta, lists
 * - range:   "23–29 Sep", "28 Sep – 3 Oct" — date ranges
 * - long:    "Tuesday, 29 September 2026" — page subtitles, modal titles,
 *            accessible labels
 * - month:   "October 2026" — a month as a whole: the calendar's heading
 *            (the date picker's heading, in resources/js/app.js, matches it)
 * - weekday range: "Mon 16 – Thu 19 Nov", "Mon 30 Nov – Wed 2 Dec" — a range
 *            whose weekdays matter at a glance: the Approvals rows (Phase 6j)
 *
 * compact, range and weekday range add the year only when it isn't the
 * current year; long and month always include it.
 */
class DisplayDate
{
    public static function compact(CarbonInterface $date): string
    {
        return $date->format('D j M').self::yearSuffix($date);
    }

    public static function range(CarbonInterface $from, CarbonInterface $to): string
    {
        if ($from->isSameDay($to)) {
            return self::compact($from);
        }

        if (! $from->isSameYear($to)) {
            return $from->format('j M Y').' – '.$to->format('j M Y');
        }

        if ($from->isSameMonth($to)) {
            return $from->format('j').'–'.$to->format('j M').self::yearSuffix($to);
        }

        return $from->format('j M').' – '.$to->format('j M').self::yearSuffix($to);
    }

    public static function weekdayRange(CarbonInterface $from, CarbonInterface $to): string
    {
        if ($from->isSameDay($to)) {
            return self::compact($from);
        }

        if (! $from->isSameYear($to)) {
            return $from->format('D j M Y').' – '.$to->format('D j M Y');
        }

        if ($from->isSameMonth($to)) {
            return $from->format('D j').' – '.$to->format('D j M').self::yearSuffix($to);
        }

        return $from->format('D j M').' – '.$to->format('D j M').self::yearSuffix($to);
    }

    public static function long(CarbonInterface $date): string
    {
        return $date->format('l, j F Y');
    }

    public static function month(CarbonInterface $date): string
    {
        return $date->format('F Y');
    }

    private static function yearSuffix(CarbonInterface $date): string
    {
        return $date->year === now()->year ? '' : ' '.$date->year;
    }
}
