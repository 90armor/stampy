<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A decision on a request that has moved on since the actor saw it — it's no
 * longer pending, or it's waiting at a different step, or someone else
 * recorded the same step first (approval_steps' unique index). Fails cleanly
 * with a readable message instead of a unique-index crash. The leave
 * lifecycle throws StaleLeaveDecisionException, the overtime one this class.
 */
class StaleDecisionException extends RuntimeException
{
    public static function noLongerPending(string $status): static
    {
        return new static("This request has already been {$status}.");
    }

    public static function movedOn(): static
    {
        return new static('This request has moved on since you opened it — someone else has already decided this step.');
    }
}
