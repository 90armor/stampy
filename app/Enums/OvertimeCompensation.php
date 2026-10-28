<?php

namespace App\Enums;

/**
 * What the employee gets for approved overtime (CLAUDE.md, Phase 4, rules 4–5):
 * pay, which the app only reports, or time off in lieu, credited to the TOIL
 * leave type's balance.
 */
enum OvertimeCompensation: string
{
    case Pay = 'pay';
    case TimeOff = 'time_off';

    public function label(): string
    {
        return match ($this) {
            self::Pay => 'Pay',
            self::TimeOff => 'Time off',
        };
    }
}
