<?php

namespace App\Support;

use App\Enums\OvertimeStatus;
use App\Models\DailyAttendance;
use App\Models\OvertimeRequest;

/**
 * What an approved request has credited, in words (Phase 4d) — read from the
 * builder's row for its work date, never recomputed:
 *
 * - "2h 00m credited" — all of the approved window;
 * - "1h 20m credited of 2h 00m approved" — they left inside it;
 * - "Nothing credited — no out-punch on Mon 5 Oct";
 * - "Nothing credited — out at 4:58 PM, before the window" (left before it
 *   started), or "— on approved leave" (a full day off; 4c refuses those);
 * - "Not yet — Fri 9 Oct": the date hasn't come, or today has no out-punch yet.
 *
 * Null for a request that isn't approved: there's nothing to credit.
 */
final class OvertimeResult
{
    public static function line(OvertimeRequest $request, ?DailyAttendance $row, int $approvedMinutes): ?string
    {
        if ($request->status !== OvertimeStatus::Approved) {
            return null;
        }

        $date = DisplayDate::compact($request->date);

        if ($request->date->isFuture() || ($request->date->isToday() && $row?->last_out === null)) {
            return "Not yet — {$date}";
        }

        if ($row === null || $row->overtime_request_id !== $request->id) {
            return 'Not calculated yet';
        }

        $credited = $row->overtime_workday_minutes + $row->overtime_night_minutes + $row->overtime_rest_day_minutes + $row->overtime_holiday_minutes;

        if ($credited > 0) {
            return $credited >= $approvedMinutes
                ? Duration::format($credited).' credited'
                : Duration::format($credited).' credited of '.Duration::format($approvedMinutes).' approved';
        }

        return match (true) {
            $row->last_out === null => "Nothing credited — no out-punch on {$date}",
            $row->status->value === 'leave' || $row->leaveDay()->fullDay => 'Nothing credited — on approved leave',
            default => 'Nothing credited — out at '.AttendanceTime::format($row->last_out).', before the window',
        };
    }
}
