<?php

namespace App\Exceptions;

use App\Models\WorkSchedule;
use RuntimeException;

/**
 * Exactly one schedule is the default at all times — the one new employees
 * are assigned (Employee::booted()). It can't be deleted (that would leave
 * new-employee creation with nothing to assign) or unset directly (leaving
 * none default the same way). The only way to change which schedule is
 * default is to mark a *different* one as default — WorkSchedule::booted()
 * unsets the old one automatically when that happens.
 */
class WorkScheduleIsDefaultException extends RuntimeException
{
    public static function cannotDelete(WorkSchedule $schedule): self
    {
        return new self(
            "Schedule \"{$schedule->name}\" can't be deleted — it's the default schedule new employees are "
            .'assigned. Make a different schedule the default first.'
        );
    }

    public static function cannotUnset(WorkSchedule $schedule): self
    {
        return new self(
            "Schedule \"{$schedule->name}\" can't be un-defaulted directly — there must always be exactly one "
            .'default. Make a different schedule the default instead; this one will stop being it automatically.'
        );
    }
}
