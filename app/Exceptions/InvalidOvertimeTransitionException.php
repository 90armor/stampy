<?php

namespace App\Exceptions;

use App\Enums\OvertimeStatus;
use RuntimeException;

/**
 * An overtime status change the request lifecycle doesn't allow
 * (OvertimeRequest::booted()) — the same transitions as a leave: pending →
 * approved | rejected | cancelled, and approved → cancelled. Also a closed
 * request still carrying a current_step, or a pending one without one.
 */
class InvalidOvertimeTransitionException extends RuntimeException
{
    public static function between(OvertimeStatus $from, OvertimeStatus $to): self
    {
        return new self("A {$from->value} overtime request can't become {$to->value}.");
    }

    public static function stepMismatch(OvertimeStatus $status): self
    {
        return $status === OvertimeStatus::Pending
            ? new self('A pending overtime request must be waiting at a step (current_step).')
            : new self("A {$status->value} overtime request has no step waiting (current_step must be null).");
    }
}
