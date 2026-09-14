<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Incomplete = 'incomplete';
    case Absent = 'absent';
    case Off = 'off';
    case Holiday = 'holiday';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Incomplete => 'Incomplete',
            self::Absent => 'Absent',
            self::Off => 'Off',
            self::Holiday => 'Holiday',
            self::Leave => 'Leave',
        };
    }
}
