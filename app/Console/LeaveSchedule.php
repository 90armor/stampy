<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;

/**
 * Grants leave automatically (leave:grant, LeaveGranter), registered the same
 * way as AttendanceSchedule: every day, not only on 1 Jan, because
 * first-eligibility dates fall throughout the year, and a missed run catches
 * up the next day.
 */
class LeaveSchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command('leave:grant')
            ->dailyAt('00:05')
            ->withoutOverlapping(30)
            ->sendOutputTo(storage_path('logs/leave-grant.log'))
            ->onFailureWithOutput(function (Stringable $output): void {
                Log::error('Scheduled leave grant failed: '.trim((string) $output));
            });
    }
}
