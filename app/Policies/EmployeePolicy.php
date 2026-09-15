<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['admin', 'manager']);
    }

    /**
     * Row-level: unlike viewAny (which only gates reaching a list at all),
     * this decides whether a specific employee's own record/attendance may
     * be viewed. Admin sees everyone; anyone may view their own record
     * regardless of role; a manager additionally sees their transitive
     * subordinates. A manager-role user with no linked employees row has no
     * position in the tree and so is denied here, which usually means the
     * account is misconfigured rather than that the org chart genuinely has
     * nobody under them — Attendance\Index::scopedEmployeeIds() logs this
     * case when it's the reason a manager lands on the list's empty state,
     * but a plain denial here (e.g. via Employees\Show) is not itself
     * logged.
     */
    public function view(User $user, Employee $employee): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        $actingEmployee = $user->employee;

        if ($actingEmployee === null) {
            return false;
        }

        if ($actingEmployee->id === $employee->id) {
            return true;
        }

        return $user->hasRole('manager') && $actingEmployee->isManagerOf($employee);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasRole('admin');
    }
}
