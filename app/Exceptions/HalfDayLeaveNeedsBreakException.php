<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A schedule change that would put a pending or approved half-day leave on a
 * date whose schedule has no break_start — so the day would have no halves,
 * and the builder could only treat it as an ordinary day (a punchless PM-leave
 * morning would build as absent). Refused before anything is written
 * (EmployeeScheduleAssigner, Employees\ScheduleAssignments::deleteAssignment()).
 * The fix is to set "Break starts" on that schedule first — allowed even on a
 * locked one, once.
 */
class HalfDayLeaveNeedsBreakException extends RuntimeException
{
    /**
     * @param  list<string>  $leaves  e.g. "Kyaw Kyaw Naing: Tue 22 Jun (AM)"
     */
    public function __construct(string $scheduleName, array $leaves)
    {
        parent::__construct(
            "Schedule \"{$scheduleName}\" has no break time set, so it can't take half-day leave — and this change would put "
            .implode('; ', $leaves).' on it. Set "Break starts" on that schedule first.'
        );
    }
}
