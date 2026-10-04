<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for work_schedules.break_start, run by the migration
 * that adds the column: 12:00 on every schedule with a break that fits
 * there (break_minutes > 0, start_time before 12:00, and 12:00 +
 * break_minutes no later than end_time — WorkSchedule's own break_start
 * rule); every other schedule stays null.
 *
 * The query builder, not Eloquent, on purpose: WorkSchedule::booted() locks
 * the calculation fields of a referenced schedule, and would rightly refuse
 * this write as a model update. Writing it anyway is safe for one reason
 * only — nothing reads break_start yet (DailySummaryBuilder only will once
 * half-day leave exists, Phase 3d), so no daily_attendances row built
 * against a locked schedule can change because of it.
 *
 * Its own class, like EmployeeScheduleBackfill, so the rule is testable
 * without the migration file.
 */
class WorkScheduleBreakStartBackfill
{
    public const BREAK_START = '12:00:00';

    /**
     * @return int the number of schedules backfilled
     */
    public static function run(): int
    {
        $count = 0;

        foreach (DB::table('work_schedules')->whereNull('break_start')->where('break_minutes', '>', 0)->get() as $schedule) {
            $breakStart = Carbon::parse(self::BREAK_START);

            if (Carbon::parse($schedule->start_time)->lt($breakStart)
                && $breakStart->copy()->addMinutes($schedule->break_minutes)->lte(Carbon::parse($schedule->end_time))) {
                DB::table('work_schedules')->where('id', $schedule->id)->update(['break_start' => self::BREAK_START]);
                $count++;
            }
        }

        return $count;
    }
}
