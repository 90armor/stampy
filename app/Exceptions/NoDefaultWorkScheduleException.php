<?php

namespace App\Exceptions;

use App\Models\Employee;
use RuntimeException;

/**
 * Attendance can't be calculated for an employee with no schedule of their
 * own when no work_schedules row is flagged is_default — there is nothing to
 * measure lateness or workdays against. Only an admin creates employees, so
 * only an admin (or the log) reads it: it names where to fix it.
 */
class NoDefaultWorkScheduleException extends RuntimeException
{
    public function __construct(Employee $employee)
    {
        parent::__construct(
            "No default work schedule exists: employee {$employee->employee_code} has no work schedule assigned, "
            .'and no row in work_schedules has is_default = true. '
            .'Make an existing schedule the default in Policies → Schedules, or create one there '
            .'(or with `php artisan db:seed --class=WorkScheduleSeeder`).'
        );
    }
}
