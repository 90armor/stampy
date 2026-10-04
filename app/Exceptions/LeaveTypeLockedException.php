<?php

namespace App\Exceptions;

use App\Models\LeaveType;
use RuntimeException;

/**
 * Once any leaves row references a type, counts, deducts_from_leave_type_id
 * and allows_half_day can't change: they decide what an existing request
 * costs and how it's built. Locked on leaves only (LeaveType::isUsedByLeaves())
 * — an entitlement alone doesn't depend on them.
 */
class LeaveTypeLockedException extends RuntimeException
{
    public function __construct(LeaveType $type)
    {
        parent::__construct(
            "Leave type \"{$type->name}\" can't change how it counts days, what it deducts from, or whether it allows half days — "
            .'leave has already been requested with it. Create a new type instead.'
        );
    }
}
