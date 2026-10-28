<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for employees.left_on, run by the migration that adds the
 * column: every employee already inactive gets the date part of their
 * updated_at — the closest thing to a deactivation date the row has, since
 * deactivating was the last write to it — and active employees stay null.
 * Clamped to join_date, so the row satisfies Employee's left_on ≥ join_date
 * invariant even if updated_at somehow predates it.
 *
 * Its own class, like EmployeeScheduleBackfill, so the rule is testable
 * without the migration file. The query builder, not Eloquent: the model's
 * invariants describe rows written from here on, and must not block the
 * backfill of rows that predate them.
 */
class EmployeeLeftOnBackfill
{
    /**
     * @return int the number of employees backfilled
     */
    public static function run(): int
    {
        return DB::table('employees')
            ->where('status', 'inactive')
            ->whereNull('left_on')
            ->update(['left_on' => DB::raw('greatest(join_date, date(updated_at))')]);
    }
}
