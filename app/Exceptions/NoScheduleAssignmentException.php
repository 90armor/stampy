<?php

namespace App\Exceptions;

use App\Models\Employee;
use RuntimeException;

/**
 * Every employee must have at least one employee_work_schedules row from the
 * moment they're created (Employee::booted() assigns the current default
 * schedule in the same transaction the employee row is inserted in) — this
 * should never fire in practice. If it does, the employee's row was created
 * some other way that skipped that step, or its assignment(s) were deleted
 * out from under it; either is a data-integrity bug to investigate, not a
 * normal "nothing is configured yet" case — that's NoDefaultWorkScheduleException,
 * thrown when the employee itself is created, not when its schedule is
 * later resolved for a date.
 */
class NoScheduleAssignmentException extends RuntimeException
{
    public function __construct(Employee $employee)
    {
        parent::__construct(
            "Employee {$employee->employee_code} has no work schedule assignment at all — every employee should have "
            .'one from creation onward (see Employee::booted()). This points at a data-integrity problem, not a '
            .'missing default: investigate how this employee ended up with zero employee_work_schedules rows.'
        );
    }
}
