<?php

namespace App\Enums;

/**
 * "Did they attend?" only — present / incomplete / absent / off / holiday /
 * leave. Whether the timing was off (late arrival, early leave) is a
 * SEPARATE dimension, tracked on DailyAttendance's late_minutes/
 * early_leave_minutes columns and surfaced through its isLate()/leftEarly()/
 * displayVariant() methods — never as a status value. A `Late` case used to
 * live here; it was removed because it mixed the two dimensions (a day that
 * was both late AND left early would need a third, combined status, and the
 * question "what do we call a day that's both?" was the sign timing didn't
 * belong in this enum at all).
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Incomplete = 'incomplete';
    case Absent = 'absent';
    case Off = 'off';
    case Holiday = 'holiday';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Incomplete => 'Incomplete',
            self::Absent => 'Absent',
            self::Off => 'Off',
            self::Holiday => 'Holiday',
            self::Leave => 'Leave',
        };
    }
}
