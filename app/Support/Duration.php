<?php

namespace App\Support;

/**
 * The single formatter for every attendance DURATION shown in the UI —
 * worked time, late arrival, early leave, a month's total — so they can't
 * drift into different notations. Under an hour is minutes only ("21m");
 * an hour or more is hours plus zero-padded minutes ("1h 20m", "8h 03m"),
 * the format the Worked column always used. Counts ("64 late · 184 early")
 * are not durations and never go through here.
 */
class Duration
{
    public static function format(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'m';
        }

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
