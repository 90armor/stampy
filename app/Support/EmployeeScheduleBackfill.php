<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for employee_work_schedules, run by the migration that
 * introduces the table: every existing employee row gets one assignment,
 * the schedule currently flagged is_default, effective from their own
 * join_date — not each employee's own (removed) employees.work_schedule_id,
 * which nothing in this app has ever set to anything but null, so this is
 * exactly equivalent to each employee's prior effective schedule.
 *
 * Kept as its own class, called from the migration rather than written
 * inline in it, so the backfill rule itself is directly testable without
 * exercising the migration file (PHPUnit's RefreshDatabase re-runs every
 * migration against an empty database per test class, so there's never an
 * "existing employees, about to be migrated" state to test the migration
 * itself against).
 */
class EmployeeScheduleBackfill
{
    /**
     * @return int the number of employees backfilled (0 if no default schedule
     *             exists — a pre-existing employee already had no way to
     *             resolve a schedule in that case, so there's nothing correct
     *             to backfill; scheduleOn() will name the same problem later)
     */
    public static function run(): int
    {
        $defaultId = DB::table('work_schedules')->where('is_default', true)->value('id');

        if ($defaultId === null) {
            return 0;
        }

        $now = now();
        $count = 0;

        foreach (DB::table('employees')->select('id', 'join_date')->orderBy('id')->cursor() as $employee) {
            DB::table('employee_work_schedules')->insertOrIgnore([
                'employee_id' => $employee->id,
                'work_schedule_id' => $defaultId,
                'effective_from' => $employee->join_date,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }
}
