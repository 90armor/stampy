<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A decision on a leave request that has moved on since the actor saw it —
 * it's no longer pending, or it's waiting at a different step, or someone
 * else recorded the same step first (approval_steps' unique index). Fails
 * cleanly with a readable message instead of a unique-index crash.
 */
class StaleLeaveDecisionException extends RuntimeException
{
    public static function noLongerPending(string $status): self
    {
        return new self("This request has already been {$status}.");
    }

    public static function movedOn(): self
    {
        return new self('This request has moved on since you opened it — someone else has already decided this step.');
    }
}
