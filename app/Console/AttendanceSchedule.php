<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;

/**
 * Builds daily_attendances automatically. Dates are computed when the schedule
 * is registered, which is every minute: `schedule:work` starts a fresh
 * `schedule:run` process per tick, so "today" is never stale.
 */
class AttendanceSchedule
{
    /** How far back the daily task rebuilds; anything older needs a manual run (see CLAUDE.md). */
    public const HEAL_DAYS = 7;

    // sendOutputTo() names a fixed file per task (overwritten each run). Left to default, Laravel derives the name
    // from the command line, which contains the date, and would leave a new log file behind every day.
    public static function register(Schedule $schedule): void
    {
        $today = today()->format('Y-m-d');

        // Moves an in_progress day to its final status once end_time passes, and picks up punches imported during the day.
        $schedule->command('attendance:build-daily', ['--date' => $today])
            ->everyFifteenMinutes()
            ->withoutOverlapping(10)
            ->sendOutputTo(storage_path('logs/attendance-rebuild-today.log'))
            ->onFailureWithOutput(self::logFailure('today'));

        // A window, not just yesterday: a gap from downtime heals itself with no state to track, because the builder is idempotent.
        $schedule->command('attendance:build-daily', [
            '--from' => today()->subDays(self::HEAL_DAYS - 1)->format('Y-m-d'),
            '--to' => $today,
        ])
            ->dailyAt('02:10')
            ->withoutOverlapping(60)
            ->sendOutputTo(storage_path('logs/attendance-rebuild-heal.log'))
            ->onFailureWithOutput(self::logFailure('last '.self::HEAL_DAYS.' days'));
    }

    public static function logFailure(string $label): callable
    {
        return function (Stringable $output) use ($label): void {
            Log::error("Scheduled attendance rebuild ({$label}) failed: ".trim((string) $output));
        };
    }
}
