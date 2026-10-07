<?php

namespace App\Enums;

/**
 * Where a leave type's balance comes from (Phase 4a):
 *
 * - yearly: a grant each leave year (leave_entitlements, LeaveGranter), of
 *   days_per_year — Annual, Medical.
 * - earned: no grant; the balance is built from adjustments only — Time off
 *   in lieu, credited from overtime.
 * - none: no balance at all — Unpaid, Maternity, and Special, which draws
 *   from Annual's.
 *
 * Explicit rather than implied by days_per_year being set: an earned type
 * has a balance and no days_per_year, so "has a balance" and "is granted
 * yearly" are two different questions.
 */
enum LeaveBalanceSource: string
{
    case Yearly = 'yearly';
    case Earned = 'earned';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Yearly => 'Yearly grant',
            self::Earned => 'Earned from overtime',
            self::None => 'No balance',
        };
    }

    public function hasBalance(): bool
    {
        return $this !== self::None;
    }
}
