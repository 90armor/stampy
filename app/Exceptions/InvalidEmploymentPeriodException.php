<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * An employee whose status and employment period (join_date..left_on)
 * disagree — enforced on the model (Employee::booted()), not only in the
 * deactivate form. Four cases:
 *
 * - inactive with no left_on: every "active on a date" question
 *   (Employee::scopeActiveOn()) would treat them as still employed.
 * - active with a left_on: the reverse — they'd drop out of every date
 *   after it while the directory calls them active.
 * - left_on before join_date: an employment period that ends before it
 *   starts.
 * - left_on in the future: deactivation records what happened; a planned
 *   leaving date is not modelled, and builds never reach past today anyway.
 */
class InvalidEmploymentPeriodException extends InvalidArgumentException
{
    public static function inactiveWithoutLeftOn(): self
    {
        return new self('An inactive employee needs a last day (left_on).');
    }

    public static function activeWithLeftOn(): self
    {
        return new self('An active employee can\'t have a last day (left_on) — reactivating clears it.');
    }

    public static function leftBeforeJoining(): self
    {
        return new self('The last day can\'t be before the join date.');
    }

    public static function leftInFuture(): self
    {
        return new self('The last day can\'t be in the future.');
    }
}
