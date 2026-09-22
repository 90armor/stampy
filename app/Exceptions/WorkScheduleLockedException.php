<?php

namespace App\Exceptions;

use App\Models\WorkSchedule;
use RuntimeException;

/**
 * Once any daily_attendances row or employee_work_schedules assignment
 * references a schedule, its hours are locked: start_time, end_time,
 * grace_minutes, break_minutes and workdays can no longer change, because
 * DailySummaryBuilder must always be able to recompute an already-built row
 * identically — CLAUDE.md's "daily_attendances must always be fully
 * recomputable" rule would otherwise no longer hold for any day already
 * built against this schedule. The name stays editable; to change hours,
 * create a new schedule and reassign the affected employees instead.
 */
class WorkScheduleLockedException extends RuntimeException
{
    public function __construct(WorkSchedule $schedule)
    {
        parent::__construct(
            "Schedule \"{$schedule->name}\" can't have its hours changed — it's already been used to calculate "
            .'attendance or is currently assigned to an employee. The name can still be edited; to change hours, '
            .'create a new schedule and reassign the affected employees to it.'
        );
    }
}
