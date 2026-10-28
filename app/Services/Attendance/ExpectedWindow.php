<?php

namespace App\Services\Attendance;

use App\Enums\LeaveHalf;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * When someone is expected at work on a date — the one definition the builder
 * (late, early leave, in_progress) and DailyAttendance (the "not in yet" due
 * time, workedOnLeave()) both read (CLAUDE.md, Phase 3, rules 18–19).
 *
 * Normally start_time–end_time. On a half-day leave it shrinks to the half
 * that's worked, using the schedule's break: AM leave starts at the PM start
 * (break_start + break_minutes), PM leave ends at break_start. Without a
 * break_start there are no halves, and the window stays whole.
 */
final readonly class ExpectedWindow
{
    private function __construct(
        public Carbon $start,
        public Carbon $end,
        public ?Carbon $breakStart,
        public ?Carbon $breakEnd,
    ) {}

    public static function for(WorkSchedule $schedule, CarbonInterface $date, ?LeaveHalf $half = null): self
    {
        $day = $date->format('Y-m-d');
        $start = Carbon::parse("{$day} {$schedule->start_time}");
        $end = Carbon::parse("{$day} {$schedule->end_time}");
        $breakStart = $schedule->break_start !== null ? Carbon::parse("{$day} {$schedule->break_start}") : null;
        $breakEnd = $breakStart?->copy()->addMinutes($schedule->break_minutes);

        if ($breakStart !== null && $half === LeaveHalf::Am) {
            $start = $breakEnd->copy();
        } elseif ($breakStart !== null && $half === LeaveHalf::Pm) {
            $end = $breakStart->copy();
        }

        return new self($start, $end, $breakStart, $breakEnd);
    }

    /** Seconds of $from..$to that fall inside the break window. */
    public function breakOverlapSeconds(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($this->breakStart === null) {
            return 0;
        }

        $overlapStart = max($from->getTimestamp(), $this->breakStart->getTimestamp());
        $overlapEnd = min($to->getTimestamp(), $this->breakEnd->getTimestamp());

        return max(0, $overlapEnd - $overlapStart);
    }
}
