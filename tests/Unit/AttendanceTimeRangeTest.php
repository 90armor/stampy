<?php

namespace Tests\Unit;

use App\Support\AttendanceTime;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Phase 6j: a time range says AM/PM once when both ends share it on the same
 * day, and marks an end on a later day "(+1)", as the attendance views do.
 */
class AttendanceTimeRangeTest extends TestCase
{
    private function range(string $from, string $to): string
    {
        return AttendanceTime::range(Carbon::parse($from), Carbon::parse($to));
    }

    public function test_am_pm_is_said_once_when_both_ends_share_it(): void
    {
        $this->assertSame('5:00 – 7:00 PM', $this->range('2026-11-10 17:00', '2026-11-10 19:00'));
        $this->assertSame('6:30 – 9:15 AM', $this->range('2026-11-10 06:30', '2026-11-10 09:15'));
    }

    public function test_both_ends_keep_theirs_across_noon(): void
    {
        $this->assertSame('11:00 AM – 1:00 PM', $this->range('2026-11-10 11:00', '2026-11-10 13:00'));
    }

    public function test_an_overnight_range_keeps_both_and_says_plus_one(): void
    {
        $this->assertSame('9:00 PM – 1:00 AM (+1)', $this->range('2026-11-10 21:00', '2026-11-11 01:00'));
        // Both ends in the PM, but on different days: never "11:00 – 11:30 PM".
        $this->assertSame('11:00 PM – 11:30 PM (+1)', $this->range('2026-11-10 23:00', '2026-11-11 23:30'));
    }

    public function test_a_format_without_am_pm_writes_both_ends_in_full(): void
    {
        config(['attendance.time_format' => 'H:i']);

        $this->assertSame('17:00 – 19:00', $this->range('2026-11-10 17:00', '2026-11-10 19:00'));
        $this->assertSame('21:00 – 01:00 (+1)', $this->range('2026-11-10 21:00', '2026-11-11 01:00'));
    }
}
