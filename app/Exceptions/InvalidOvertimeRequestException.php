<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * An overtime request whose own window doesn't make sense — enforced on the
 * model (OvertimeRequest::booted()). Policy limits (the daily caps, the claim
 * window, one active request per date) need other rows or settings and belong
 * to the request service, not here.
 *
 * - ends_at not after starts_at.
 * - starts_at not on the work date (date): a window may end after midnight,
 *   but it starts on the day it belongs to.
 * - a window longer than 12 hours: a sanity bound on the data, not a policy.
 */
class InvalidOvertimeRequestException extends InvalidArgumentException
{
    public static function endNotAfterStart(): self
    {
        return new self('The overtime must end after it starts.');
    }

    public static function startNotOnDate(): self
    {
        return new self('The overtime must start on its work date.');
    }

    public static function tooLong(int $maxHours): self
    {
        return new self("An overtime window can't be longer than {$maxHours} hours.");
    }
}
