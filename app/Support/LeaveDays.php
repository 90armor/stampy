<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Leave day amounts as integer tenths of a day — 18 days is 180, a half day
 * is 5 — never floats. Every amount the schema stores is decimal(_,1), so
 * tenths hold each one exactly, and the rules' only rounding ("up to the next
 * 0.5", ceilToHalf()) is integer arithmetic with no representation error at
 * the boundary. Conversion happens only at the edges: fromDecimal() for a
 * decimal:1 cast or a SUM() string, toDecimal()/format() for storage and
 * display.
 */
final class LeaveDays
{
    public const HALF = 5;

    public const DAY = 10;

    /**
     * "18.0", "-1.5", "3", 18 or null (an empty SUM) → tenths.
     *
     * @throws InvalidArgumentException for anything finer than a tenth
     */
    public static function fromDecimal(string|int|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value * self::DAY;
        }

        if (! preg_match('/^(-)?(\d+)(?:\.(\d)0*)?$/', trim($value), $m)) {
            throw new InvalidArgumentException("Not a day amount with at most one decimal: \"{$value}\".");
        }

        $tenths = (int) $m[2] * self::DAY + (int) ($m[3] ?? 0);

        return $m[1] === '-' ? -$tenths : $tenths;
    }

    /** Tenths → "18.0" / "-1.5", the decimal(_,1) column form. */
    public static function toDecimal(int $tenths): string
    {
        $sign = $tenths < 0 ? '-' : '';
        $abs = abs($tenths);

        return $sign.intdiv($abs, self::DAY).'.'.($abs % self::DAY);
    }

    /** Tenths → "18" / "15.5" / "-1.5", for display. */
    public static function format(int $tenths): string
    {
        $decimal = self::toDecimal($tenths);

        return str_ends_with($decimal, '.0') ? substr($decimal, 0, -2) : $decimal;
    }

    /**
     * $numerator / $denominator tenths, rounded up to the next half day —
     * ceil(n / (d × 5)) × 5, all in integers. Non-negative inputs only.
     */
    public static function ceilToHalf(int $numerator, int $denominator): int
    {
        $halves = intdiv($numerator + $denominator * self::HALF - 1, $denominator * self::HALF);

        return $halves * self::HALF;
    }
}
