<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Computes (or recomputes) one employee's daily_attendances row for one
 * date, purely from attendance_logs. daily_attendances is derived data and
 * must always be fully recomputable — nothing else may write to it.
 */
class DailySummaryBuilder
{
    /**
     * A last_out more than this many hours after first_in is not paired with
     * it — the shift is left incomplete rather than pairing punches that are
     * probably unrelated (e.g. the start of the *next* shift).
     */
    private const MAX_SHIFT_HOURS = 18;

    public function build(Employee $employee, CarbonInterface $date): DailyAttendance
    {
        $workDate = Carbon::instance($date)->startOfDay();
        $schedule = $employee->effectiveSchedule();

        $isWorkday = in_array($workDate->dayOfWeekIso, $schedule->workdays, true);

        $firstIn = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('punch_type', PunchType::In->value)
            ->whereBetween('punched_at', [$workDate, $workDate->copy()->endOfDay()])
            ->orderBy('punched_at')
            ->first();

        // When this day HAS a first_in, last_out is looked up relative to it
        // and must be strictly after it — so an out-punch this day's
        // first_in consumes can never also be picked up as some other day's
        // own last_out (it would fail that day's own ">first_in" test, since
        // it precedes that day's first_in), and it can never become another
        // day's first_in at all (wrong punch type). The one case this
        // doesn't fully rule out: two "in" punches on consecutive calendar
        // days whose 18h windows overlap (e.g. one at 20:00 and the next at
        // 06:00) could both reach for the same out-punch in between. That
        // doesn't happen with a single morning check-in per day, which is
        // all this app currently produces, but a future multi-shift-per-day
        // pattern would need to revisit this.
        //
        // When this day has NO first_in, the symmetric risk is an out-punch
        // that isn't this day's own data at all — it's the tail end of the
        // PREVIOUS day's overnight shift. The else-branch below excludes
        // those explicitly (see its comment).
        $lastOut = null;

        if ($firstIn) {
            $lastOut = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('punch_type', PunchType::Out->value)
                ->where('punched_at', '>', $firstIn->punched_at)
                ->where('punched_at', '<=', $firstIn->punched_at->copy()->addHours(self::MAX_SHIFT_HOURS))
                ->orderByDesc('punched_at')
                ->first();
        } else {
            // No in-punch to pair from, but an out-punch on this calendar day
            // still needs recording — otherwise "out only" is indistinguishable
            // from "no punches at all". It's not paired with anything (there's
            // no first_in), so it never contributes worked/late/early minutes.
            //
            // But an out-punch here could just as easily be the tail end of
            // an *earlier* day's overnight shift (that day's first_in, up to
            // MAX_SHIFT_HOURS before it, already claims it) rather than a
            // genuine "forgot to punch in" signal for today. Walk candidates
            // latest-first and skip any that pair to an earlier in-punch,
            // computed directly from the punches — not from whatever the
            // previous day's daily_attendances row says — so the result
            // doesn't depend on the order dates are built in.
            $candidates = AttendanceLog::query()
                ->where('employee_id', $employee->id)
                ->where('punch_type', PunchType::Out->value)
                ->whereBetween('punched_at', [$workDate, $workDate->copy()->endOfDay()])
                ->orderByDesc('punched_at')
                ->get();

            foreach ($candidates as $candidate) {
                $claimedByEarlierShift = AttendanceLog::query()
                    ->where('employee_id', $employee->id)
                    ->where('punch_type', PunchType::In->value)
                    ->where('punched_at', '<', $candidate->punched_at)
                    ->where('punched_at', '>=', $candidate->punched_at->copy()->subHours(self::MAX_SHIFT_HOURS))
                    ->exists();

                if (! $claimedByEarlierShift) {
                    $lastOut = $candidate;

                    break;
                }
            }
        }

        $attributes = $this->calculate($schedule, $workDate, $isWorkday, $firstIn, $lastOut);
        $attributes['work_schedule_id'] = $schedule->id;

        // Not updateOrCreate(): work_date has a 'date' cast, which formats
        // through the connection's full datetime format when set on the
        // model but is compared here as a plain 'Y-m-d' string — on SQLite
        // (no real DATE column type) those never string-match, so every call
        // after the first would find nothing and collide with the unique
        // index on insert. whereDate() compares only the date part, correctly,
        // on every driver.
        $existing = DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($existing) {
            $existing->fill($attributes)->save();

            return $existing;
        }

        $new = new DailyAttendance($attributes);
        $new->employee_id = $employee->id;
        $new->work_date = $workDate;
        $new->save();

        return $new;
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(
        WorkSchedule $schedule,
        Carbon $workDate,
        bool $isWorkday,
        ?AttendanceLog $firstIn,
        ?AttendanceLog $lastOut,
    ): array {
        $hasIn = $firstIn !== null;
        $hasOut = $lastOut !== null;
        $hasBoth = $hasIn && $hasOut;

        $workedMinutes = 0;
        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;

        if ($hasBoth) {
            $workedSeconds = $lastOut->punched_at->getTimestamp() - $firstIn->punched_at->getTimestamp();
            $workedMinutes = max(0, intdiv($workedSeconds, 60) - $schedule->break_minutes);

            if ($isWorkday) {
                $scheduledStart = Carbon::parse($workDate->format('Y-m-d').' '.$schedule->start_time);
                $scheduledEnd = Carbon::parse($workDate->format('Y-m-d').' '.$schedule->end_time);

                // Grace only decides WHETHER first_in counts as late, not how
                // much: once outside grace, late_minutes is the full gap from
                // start_time, not the remainder past the grace period.
                $minutesAfterStart = intdiv($firstIn->punched_at->getTimestamp() - $scheduledStart->getTimestamp(), 60);

                if ($minutesAfterStart > $schedule->grace_minutes) {
                    $lateMinutes = $minutesAfterStart;
                }

                $minutesBeforeEnd = intdiv($scheduledEnd->getTimestamp() - $lastOut->punched_at->getTimestamp(), 60);
                $earlyLeaveMinutes = max(0, $minutesBeforeEnd);
            }
        }

        // Exactly one of {in, out} is always incomplete, regardless of
        // whether the date is a workday — a lone punch on a day off is just
        // as unresolved as one on a scheduled day.
        $status = match (true) {
            ! $hasIn && ! $hasOut => $isWorkday ? AttendanceStatus::Absent : AttendanceStatus::Off,
            $hasIn xor $hasOut => AttendanceStatus::Incomplete,
            ! $isWorkday => AttendanceStatus::Present,
            $lateMinutes > 0 => AttendanceStatus::Late,
            default => AttendanceStatus::Present,
        };

        if (! $isWorkday) {
            $lateMinutes = 0;
            $earlyLeaveMinutes = 0;
        }

        if ($status === AttendanceStatus::Incomplete || $status === AttendanceStatus::Absent) {
            $workedMinutes = 0;
        }

        return [
            'first_in' => $firstIn?->punched_at,
            'last_out' => $lastOut?->punched_at,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'status' => $status->value,
        ];
    }
}
