<?php

namespace App\Exceptions;

use App\Models\LeaveType;
use RuntimeException;

/**
 * A leave type anything points at — a leave, an entitlement, an adjustment,
 * or another type deducting from it — can't be deleted; deactivate it
 * instead (is_active). Raised before the database's own restrictOnDelete
 * would, so the caller gets this instead of a raw foreign-key error.
 */
class LeaveTypeInUseException extends RuntimeException
{
    public function __construct(LeaveType $type)
    {
        parent::__construct(
            "Leave type \"{$type->name}\" can't be deleted — it has leave, balances or another type that depends on it. Deactivate it instead."
        );
    }
}
