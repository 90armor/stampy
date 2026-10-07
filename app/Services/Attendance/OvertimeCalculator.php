<?php

namespace App\Services\Attendance;

use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Credited overtime for one day (CLAUDE.md, Phase 4, rules 2, 3, 8–13): what
 * DailySummaryBuilder writes into daily_attendances' overtime columns. Pure
 * apart from a warning log; the builder supplies every fact.
 *
 * - Only an approved request for the work date counts (the builder passes
 *   it, or null). Its id is credited whenever it exists, even at 0 minutes.
 * - Both punches are needed: without last_out the end isn't known (rule 13).
 * - The counted span is [first_in, last_out] ∩ [starts_at, ends_at]: approval
 *   authorizes, punches decide, never more than approved (rule 2). No daily
 *   limit is re-applied here — the window already caps it, and an admin's
 *   approved override of the 2h/10h limits must stand (4c checks them).
 * - On a workday (a scheduled workday that isn't a holiday) the schedule's
 *   FULL window, start_time–end_time, is subtracted — not ExpectedWindow's
 *   half-day shrink: half-day leave doesn't make normal hours overtime (rule
 *   12). The break lies inside that window, so it never counts either.
 * - On a day with no scheduled hours — a non-workday of the schedule, or a
 *   holiday — there is no normal window: the whole span counts, less its
 *   overlap with the schedule's break window (break_start + break_minutes;
 *   nothing without break_start) — a Saturday worked 08:00–17:00 is 8 hours,
 *   not 9. Claude's decision, open to the owner's veto (Phase 4b).
 * - A full-day approved leave on the date credits 0 and logs a warning: 4c
 *   refuses such a request, so this is a defence.
 *
 * Categories, highest precedence first — each counted second in exactly one:
 * holiday (the WORK DATE is a holiday), rest day (the work date's weekday is
 * the settings' weekly_rest_day — Sunday; owner, Phase 4c's commit 0: only
 * the weekly rest day earns the premium, so a Saturday is workday or night),
 * night (the second itself falls in the settings' night window, on whichever
 * calendar day it is — the window may wrap midnight), workday. Overtime after
 * midnight belongs to its work date (rule 11), so 23:00–01:00 after a holiday
 * is all holiday minutes.
 *
 * Minutes truncate, never round (owner, Phase 4b — one rule for every minute
 * figure, as late and early minutes do): the total is intdiv(seconds, 60).
 * Each category's seconds are floored too, and whatever that loses against
 * the floored total (at most a minute per category beyond the first) goes to
 * the highest-precedence category present, so the categories always add up
 * to the total.
 */
class OvertimeCalculator
{
    public function calculate(
        ?OvertimeRequest $request,
        ?CarbonInterface $firstIn,
        ?CarbonInterface $lastOut,
        WorkSchedule $schedule,
        CarbonInterface $workDate,
        bool $isScheduledWorkday,
        bool $isHoliday,
        LeaveDay $leaveDay,
        OvertimeSettings $settings,
    ): OvertimeCredit {
        if ($request === null) {
            return OvertimeCredit::none();
        }

        $credited = new OvertimeCredit($request->id);

        if ($leaveDay->fullDay) {
            Log::warning("Overtime request #{$request->id}: approved overtime on {$workDate->format('Y-m-d')} for employee #{$request->employee_id}, a day of approved full-day leave — credited 0.");

            return $credited;
        }

        if ($firstIn === null || $lastOut === null) {
            return $credited;
        }

        $from = max($firstIn->getTimestamp(), $request->starts_at->getTimestamp());
        $to = min($lastOut->getTimestamp(), $request->ends_at->getTimestamp());

        if ($to <= $from) {
            return $credited;
        }

        $window = ExpectedWindow::for($schedule, $workDate);
        $excluded = $isScheduledWorkday && ! $isHoliday
            ? [$window->start->getTimestamp(), $window->end->getTimestamp()]
            : ($window->breakStart !== null ? [$window->breakStart->getTimestamp(), $window->breakEnd->getTimestamp()] : null);

        $segments = $excluded === null ? [[$from, $to]] : self::subtract([$from, $to], $excluded);
        $seconds = array_sum(array_map(fn (array $segment) => $segment[1] - $segment[0], $segments));

        // Highest precedence first, so the rounding shortfall below goes to the first one present.
        if ($isHoliday) {
            $byCategory = ['holiday' => $seconds];
        } elseif ($workDate->dayOfWeekIso === $settings->weekly_rest_day) {
            $byCategory = ['restDay' => $seconds];
        } else {
            $night = 0;

            foreach ($segments as $segment) {
                foreach (self::nightIntervals($settings, $workDate) as $interval) {
                    $night += self::overlap($segment, $interval);
                }
            }

            $byCategory = ['night' => $night, 'workday' => $seconds - $night];
        }

        return new OvertimeCredit($request->id, ...self::truncate($byCategory));
    }

    /**
     * Whole minutes per category: each floored, and the shortfall against the
     * floored total given to the highest-precedence category present.
     *
     * @param  array<string, int>  $seconds  category => seconds, highest precedence first
     * @return array<string, int>
     */
    private static function truncate(array $seconds): array
    {
        $minutes = array_map(fn (int $s) => intdiv($s, 60), $seconds);
        $shortfall = intdiv(array_sum($seconds), 60) - array_sum($minutes);

        foreach ($seconds as $category => $s) {
            if ($s > 0) {
                $minutes[$category] += $shortfall;

                break;
            }
        }

        return $minutes;
    }

    /**
     * $span less $cut, as zero, one or two segments.
     *
     * @param  array{0: int, 1: int}  $span
     * @param  array{0: int, 1: int}  $cut
     * @return list<array{0: int, 1: int}>
     */
    private static function subtract(array $span, array $cut): array
    {
        return array_values(array_filter([
            [$span[0], min($span[1], $cut[0])],
            [max($span[0], $cut[1]), $span[1]],
        ], fn (array $segment) => $segment[1] > $segment[0]));
    }

    /** @param array{0: int, 1: int} $a  @param array{0: int, 1: int} $b */
    private static function overlap(array $a, array $b): int
    {
        return max(0, min($a[1], $b[1]) - max($a[0], $b[0]));
    }

    /**
     * The night window's occurrences around the work date, as timestamps. An
     * approved window starts on its work date and lasts at most 12 hours, so
     * the night starting the day before (its tail after midnight) through the
     * one starting the day after covers every second it can reach.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function nightIntervals(OvertimeSettings $settings, CarbonInterface $workDate): array
    {
        $intervals = [];

        foreach ([-1, 0, 1] as $offset) {
            $day = Carbon::instance($workDate)->startOfDay()->addDays($offset)->format('Y-m-d');
            $start = Carbon::parse("{$day} {$settings->night_starts}");
            $end = Carbon::parse("{$day} {$settings->night_ends}");

            if ($end->lte($start)) {
                $end->addDay();
            }

            $intervals[] = [$start->getTimestamp(), $end->getTimestamp()];
        }

        return $intervals;
    }
}
