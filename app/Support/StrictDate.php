<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

class StrictDate
{
    /**
     * Carbon::createFromFormat() silently rolls an impossible date over (2026-02-30 becomes
     * 2026-03-02, hour 25 becomes 01:00 the next day) and reports it only as a warning. That is
     * not a round-trip check on purpose: PHP also accepts real but unpadded input ("2026-1-5"),
     * which a strict round-trip would wrongly reject.
     *
     * @throws InvalidArgumentException with a message fit to follow "Invalid … value"
     */
    public static function parse(string $format, string $value): Carbon
    {
        try {
            $date = Carbon::createFromFormat($format, $value);
        } catch (Throwable) {
            $date = false;
        }

        if (! $date) {
            throw new InvalidArgumentException("expected {$format}");
        }

        $errors = Carbon::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('that is not a real calendar date');
        }

        return $date;
    }

    /**
     * A query-string date or month (Phase 6h): exactly zero-padded `Y-m-d` /
     * `Y-m` and a real calendar date, so whatever is typed into the address
     * bar either names a real day or is ignored.
     */
    public static function isDay(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && self::parses('Y-m-d', $value);
    }

    public static function isMonth(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}$/', $value) === 1 && self::parses('!Y-m', $value);
    }

    private static function parses(string $format, string $value): bool
    {
        try {
            self::parse($format, $value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
