<?php

namespace App\Enums;

enum PunchSource: string
{
    case Device = 'device';
    case Import = 'import';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Device => 'Device',
            self::Import => 'Import',
            self::Manual => 'Manual',
        };
    }
}
