<?php

namespace App\Enums;

/**
 * How a leave type counts the days a request covers: workdays (dates that
 * are workdays of the employee's schedule and not holidays) or every
 * calendar day, weekends and holidays included — Maternity, by law.
 */
enum LeaveCounting: string
{
    case Workdays = 'workdays';
    case CalendarDays = 'calendar_days';

    public function label(): string
    {
        return match ($this) {
            self::Workdays => 'Workdays',
            self::CalendarDays => 'Calendar days',
        };
    }
}
