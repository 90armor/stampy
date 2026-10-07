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
 * - a weekly rest day that isn't an ISO weekday (1–7).
 * - a TOIL block that isn't a whole number of half hours.
 * - a TOIL leave type that isn't earned (LeaveBalanceSource::Earned): TOIL is
 *   credited as adjustments, which only an earned balance is built from.
 */
class InvalidOvertimeSettingsException extends InvalidArgumentException
{
    /** The form field it's about (Policies → Overtime puts the message under it). */
    public ?string $field = null;

    private function about(string $field): self
    {
        $this->field = $field;

        return $this;
    }

    public static function notPositive(string $field): self
    {
        return (new self('Overtime setting '.str_replace('_', ' ', $field).' must be greater than 0.'))->about($field);
    }

    public static function ratesOutOfOrder(): self
    {
        return (new self('Overtime rates must be ordered holiday ≥ rest day ≥ night ≥ workday ≥ 100%. Each minute counts in the first category that applies — holiday, then rest day, then night — and that precedence relies on this ordering never to pay less.'))->about('rates');
    }

    public static function restDayNotAWeekday(): self
    {
        return (new self('The weekly rest day must be an ISO weekday, 1 (Monday) to 7 (Sunday).'))->about('weekly_rest_day');
    }

    public static function blockNotHalfHours(): self
    {
        return (new self('The time-off-in-lieu block must be a multiple of 30 minutes.'))->about('toil_block_minutes');
    }

    public static function toilTypeNotEarned(string $name): self
    {
        return (new self("Time off in lieu must go to a leave type whose balance is earned from overtime; {$name} isn't one."))->about('toil_leave_type_id');
    }
}
