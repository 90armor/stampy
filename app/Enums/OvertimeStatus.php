<?php

namespace App\Enums;

/**
 * Where an overtime request is in its life — not the approval steps
 * themselves, which live in approval_steps (ApprovalOutcome). The same four
 * values as LeaveStatus, kept as its own enum: LeaveStatus is used only by
 * leave code, never by the shared approval engine, so the two can't drift
 * into each other.
 */
enum OvertimeStatus: string
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

    /** The x-badge colour, as for leave requests (docs/DESIGN_SYSTEM.md, Leave request status badges). */
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
