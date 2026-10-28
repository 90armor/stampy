<?php

namespace App\Exceptions;

use App\Enums\LeaveStatus;
use RuntimeException;

/**
 * A leave status change the request lifecycle doesn't allow (Leave::booted()):
 * pending → approved | rejected | cancelled, and approved → cancelled — nothing
 * else. Also a closed request (anything but pending) still carrying a
 * current_step, or a pending one without one.
 */
class InvalidLeaveTransitionException extends RuntimeException
{
    public static function between(LeaveStatus $from, LeaveStatus $to): self
    {
        return new self("A {$from->value} leave can't become {$to->value}.");
    }

    public static function stepMismatch(LeaveStatus $status): self
    {
        return $status === LeaveStatus::Pending
            ? new self('A pending leave must be waiting at a step (current_step).')
            : new self("A {$status->value} leave has no step waiting (current_step must be null).");
    }
}
