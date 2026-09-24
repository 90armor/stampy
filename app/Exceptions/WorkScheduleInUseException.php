<?php

namespace App\Exceptions;

use App\Models\WorkSchedule;
use RuntimeException;

/**
 * A schedule referenced by any employee_work_schedules assignment or any
 * daily_attendances row can't be deleted — deleting it would either break
 * the foreign key those rows depend on, or (worse, if that FK were ever
 * relaxed) silently make past attendance unrecomputable.
 */
class WorkScheduleInUseException extends RuntimeException
{
    public function __construct(WorkSchedule $schedule)
    {
        parent::__construct(
            "Schedule \"{$schedule->name}\" can't be deleted — it's currently assigned to at least one employee "
            .'or has already been used to calculate attendance.'
        );
    }
}
