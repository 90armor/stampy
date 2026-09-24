<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A schedule whose own fields don't make sense — never a question of what
 * else references it (see WorkScheduleLockedException/InUseException for
 * that). Three cases:
 *
 * - end_time <= start_time: DailySummaryBuilder's late/early-leave
 *   arithmetic assumes a shift within one calendar day; an overnight
 *   schedule would compute silently wrong numbers. Documented in CLAUDE.md
 *   as a known, unsupported limitation, next to the 18h overnight-pairing
 *   note.
 * - no workdays at all: every date would resolve to `off` forever, which is
 *   never a useful schedule.
 * - break_minutes >= the shift length: would make worked_minutes negative
 *   before DailySummaryBuilder's max(0, ...) floor silently hides it.
 */
class InvalidWorkScheduleException extends InvalidArgumentException
{
    public static function endNotAfterStart(): self
    {
        return new self('End time must be after start time — an overnight schedule is not supported.');
    }

    public static function noWorkdays(): self
    {
        return new self('A schedule needs at least one workday.');
    }

    public static function breakTooLong(): self
    {
        return new self('Break minutes must be shorter than the shift itself (end time minus start time).');
    }
}
