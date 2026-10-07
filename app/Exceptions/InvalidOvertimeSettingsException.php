<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Overtime settings whose values don't make sense together — enforced on the
 * model (OvertimeSettings::booted()).
 *
 * - a number that isn't positive.
 * - rates out of order: every overtime minute takes the first category that
 *   applies — holiday, then rest day, then night, then workday — so the
 *   precedence never pays less only while holiday ≥ rest day ≥ night ≥
 *   workday ≥ 100%.
 * - a TOIL block that isn't a whole number of half hours.
 * - a TOIL leave type that isn't earned (LeaveBalanceSource::Earned): TOIL is
 *   credited as adjustments, which only an earned balance is built from.
 */
class InvalidOvertimeSettingsException extends InvalidArgumentException
{
    public static function notPositive(string $field): self
    {
        return new self('Overtime setting '.str_replace('_', ' ', $field).' must be greater than 0.');
    }

    public static function ratesOutOfOrder(): self
    {
        return new self('Overtime rates must be ordered holiday ≥ rest day ≥ night ≥ workday ≥ 100%. Each minute counts in the first category that applies — holiday, then rest day, then night — and that precedence relies on this ordering never to pay less.');
    }

    public static function blockNotHalfHours(): self
    {
        return new self('The time-off-in-lieu block must be a multiple of 30 minutes.');
    }

    public static function toilTypeNotEarned(string $name): self
    {
        return new self("Time off in lieu must go to a leave type whose balance is earned from overtime; {$name} isn't one.");
    }
}
