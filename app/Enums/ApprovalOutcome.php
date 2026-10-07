<?php

namespace App\Enums;

/**
 * How one approval step ended. skipped: the step had nobody to act (a
 * requester with no manager skips step 1). self_approved: the requester is
 * the only admin, so their own step 2 is recorded as such rather than
 * leaving a one-admin company unable to take leave.
 */
enum ApprovalOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
    case SelfApproved = 'self_approved';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Skipped => 'Skipped',
            self::SelfApproved => 'Self-approved',
        };
    }
}
