<?php

namespace App\Support;

use App\Enums\LeaveBalanceSource;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for leave_types.balance_source, run by the migration that
 * adds the column (Phase 4a). It states the rule the column replaces — "has a
 * balance" meant days_per_year is set — so no existing type changes meaning:
 * yearly where days_per_year is set, none otherwise. Nothing is backfilled as
 * earned; that source is new.
 *
 * Its own class, like EmployeeLeftOnBackfill, so the rule is testable without
 * the migration file; the query builder, so the model's rules can't block it.
 */
class LeaveBalanceSourceBackfill
{
    public static function run(): void
    {
        DB::table('leave_types')->whereNotNull('days_per_year')->update(['balance_source' => LeaveBalanceSource::Yearly->value]);
        DB::table('leave_types')->whereNull('days_per_year')->update(['balance_source' => LeaveBalanceSource::None->value]);
    }
}
