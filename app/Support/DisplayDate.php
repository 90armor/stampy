<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The single formatter for every date shown in the UI, so dates can't drift
 * into different orders and styles (the same job App\Support\Duration does
 * for durations). Day-month order everywhere, and only three forms:
 *
 * - compact: "Tue 29 Sep" — tables, stat strip and card meta, lists
 * - range:   "23–29 Sep", "28 Sep – 3 Oct" — date ranges
 * - long:    "Tuesday, 29 September 2026" — page subtitles, modal titles,
 *            accessible labels
 *
 * compact and range add the year only when it isn't the current year; long
 * always includes it.
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

    public static function long(CarbonInterface $date): string
    {
        return $date->format('l, j F Y');
    }

    private static function yearSuffix(CarbonInterface $date): string
    {
        return $date->year === now()->year ? '' : ' '.$date->year;
    }
}
