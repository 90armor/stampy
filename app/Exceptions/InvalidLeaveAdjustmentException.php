<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A leave adjustment whose own fields contradict each other
 * (LeaveAdjustment::booted()): one linked to an overtime request is a
 * system-authored TOIL credit, so it has no author (created_by null).
 */
class InvalidLeaveAdjustmentException extends InvalidArgumentException
{
    public static function authoredOvertimeCredit(): self
    {
        return new self('An adjustment linked to an overtime request is posted by the system and has no author (created_by must be null).');
    }
}
