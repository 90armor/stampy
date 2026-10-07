<?php

namespace App\Enums;

/**
 * Where a leave request is in its life — not the approval steps themselves,
 * which live in approval_steps (ApprovalOutcome).
 */
enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The x-badge colour for a request's status — one value per request. Not
     * an attendance status: pending is amber (the alert role, waiting on
     * someone), never the timing annotation's amber text.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Cancelled => 'slate',
        };
    }
}
