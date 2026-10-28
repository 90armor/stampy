<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A leave row whose own shape doesn't make sense — enforced on the model
 * (Leave::booted()). Request rules that depend on other rows (overlap,
 * balance, approval) are not this exception's job.
 *
 * - end_date before start_date.
 * - a half day spanning more than one date: a half day is always a
 *   single-day request; a span starting or ending on a half day is two.
 * - a half day of a type that doesn't allow one (Maternity).
 */
class InvalidLeaveException extends InvalidArgumentException
{
    public static function endBeforeStart(): self
    {
        return new self('The leave can\'t end before it starts.');
    }

    public static function halfDaySpansDays(): self
    {
        return new self('A half-day leave covers a single date — start and end must be the same day.');
    }

    public static function halfDayNotAllowed(string $type): self
    {
        return new self("{$type} leave can't be taken as a half day.");
    }
}
