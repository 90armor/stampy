<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveStatus;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\WorkSchedule;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Computes (or recomputes) one employee's daily_attendances row for one
 * date, purely from attendance_logs and approved leave. daily_attendances is
 * derived data and must always be fully recomputable — nothing else may
 * write to it.
 *
 * Approved leave is an input since Phase 3d (LeaveDay): a range build loads
 * the employee's approved leaves once (approvedLeavesBetween()) and passes
 * them to build() for every date; a lone build() loads its own.
 *
 * Every attendance_logs query here uses AttendanceLog::notVoided() — a
 * voided punch (e.g. someone else's finger matched the device) must never
 * contribute to first_in, last_out, or the overnight lookback in either
 * direction, so each of the four queries below carries the scope
 * individually rather than relying on a single shared starting point.
 */
class DailySummaryBuilder
{
    /**
     * A last_out more than this many hours after first_in is not paired with
     * it — the shift is left incomplete rather than pairing punches that are
     * probably unrelated (e.g. the start of the *next* shift).
     */
    public const MAX_SHIFT_HOURS = 18;

    /**
     * Returns null, having removed any existing row, for a date the employee
     * wasn't employed on (Employee::isActiveOn(): before join_date, or after
     * left_on). A backdated deactivation must not leave the rows built while
     * they were still active behind it, and the builder stays the only writer
     * of daily_attendances — so it's the builder that removes them.
     */
    public function build(Employee $employee, CarbonInterface $date, ?Collection $leaves = null): ?DailyAttendance
    {
        $workDate = Carbon::instance($date)->startOfDay();

        if (! $employee->isActiveOn($workDate)) {
            DailyAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $workDate)
                ->delete();

            return null;
        }

        $schedule = $employee->scheduleOn($workDate);

        $isWorkday = WorkdayCalendar::isScheduledWorkday($schedule, $workDate);

        $firstIn = AttendanceLog::query()
            ->notVoided()
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
                ->notVoided()
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
                ->notVoided()
                ->where('employee_id', $employee->id)
                ->where('punch_type', PunchType::Out->value)
                ->whereBetween('punched_at', [$workDate, $workDate->copy()->endOfDay()])
                ->orderByDesc('punched_at')
                ->get();

            foreach ($candidates as $candidate) {
                $claimedByEarlierShift = AttendanceLog::query()
                    ->notVoided()
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

        $isHoliday = WorkdayCalendar::isHoliday($workDate);
        $leaveDay = LeaveDay::on($leaves ?? $this->approvedLeavesBetween($employee, $workDate, $workDate), $workDate);

        $attributes = $this->calculate($schedule, $workDate, $isWorkday, $firstIn, $lastOut, $isHoliday, $leaveDay);
        $attributes['work_schedule_id'] = $schedule->id;
        $attributes['leave_id'] = $leaveDay->leaveId;

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
     * Rebuilds a date (or a from..to range) PLUS the day before and the day
     * after — the one place this rule lives, shared by a manual punch and by
     * an import.
     *
     * The day-before case is the obvious one: an overnight out-punch added
     * or imported near midnight is the previous day's last_out, via the
     * forward 18h lookahead from that day's first_in. The day-after case is
     * real too: when a day has no first_in of its own, build() walks that
     * day's out-punch candidates and excludes any one already "claimed" by an
     * in-punch up to 18h *before* it — including an in-punch on the PREVIOUS
     * calendar day. Adding or voiding a late in-punch today can flip
     * tomorrow's candidate from claimed to unclaimed (or vice versa) without
     * tomorrow's own data changing at all. Two days out is never needed: 18h
     * from even a midnight in-punch can't reach the day after next.
     *
     * The widened window is clamped to the employee's real history — never
     * before their join_date, never after today — so widening can't invent a
     * pre-hire or future row (CLAUDE.md: builds can't reach outside real time).
     *
     * @return int the number of days built (a date after left_on is removed, not built)
     */
    public function rebuildAround(Employee $employee, CarbonInterface $from, ?CarbonInterface $to = null): int
    {
        $first = Carbon::instance($from)->startOfDay()->subDay()->max($employee->join_date->copy()->startOfDay());
        $last = Carbon::instance($to ?? $from)->startOfDay()->addDay()->min(today());

        return $this->buildRange($employee, $first, $last);
    }

    /**
     * Rebuilds a schedule assignment's effect: from its effective date to
     * today. A reassignment can change how every day from that date forward
     * resolves (Employee::scheduleOn() is what changed, not the punches
     * themselves), so — unlike rebuildAround()'s D-1/D+1 window, which
     * exists for punch changes specifically — the whole range needs
     * rebuilding, not just a day either side of one date.
     *
     * Clamped the same way rebuildAround() clamps its own window: never
     * before the employee's join_date, never after today. A future-dated
     * effective_from (still after today once clamped to join_date) rebuilds
     * nothing — correct, since nothing about "today" has changed yet for an
     * assignment that doesn't take effect until later.
     *
     * Also how a deactivation or reactivation is applied (EmployeeLifecycle):
     * from the day after left_on, build() removes or rebuilds each row.
     *
     * @return int the number of days built (0 for a future-dated assignment;
     *             dates after left_on are removed, not built)
     */
    public function rebuildFrom(Employee $employee, CarbonInterface $effectiveFrom): int
    {
        $first = Carbon::instance($effectiveFrom)->startOfDay()->max($employee->join_date->copy()->startOfDay());
        $last = today();

        if ($first->gt($last)) {
            return 0;
        }

        return $this->buildRange($employee, $first, $last);
    }

    /**
     * Rebuilds $from..$to as given, clamped like the others to join_date and
     * today — for a change that affects exactly a known span, such as an
     * approved or cancelled leave (LeaveRequestService). An empty range (a
     * leave entirely in the future) builds nothing.
     *
     * @return int the number of days built
     */
    public function rebuildBetween(Employee $employee, CarbonInterface $from, CarbonInterface $to): int
    {
        $first = Carbon::instance($from)->startOfDay()->max($employee->join_date->copy()->startOfDay());
        $last = Carbon::instance($to)->startOfDay()->min(today());

        if ($first->gt($last)) {
            return 0;
        }

        return $this->buildRange($employee, $first, $last);
    }

    /**
     * The employee's approved leaves touching $from..$to, in one query — what
     * a range build passes to build() for every date in it.
     *
     * @return Collection<int, Leave>
     */
    public function approvedLeavesBetween(Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Leave::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'))
            ->get();
    }

    private function buildRange(Employee $employee, Carbon $first, Carbon $last): int
    {
        $built = 0;
        $leaves = $this->approvedLeavesBetween($employee, $first, $last);

        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            if ($this->build($employee, $day, $leaves) !== null) {
                $built++;
            }
        }

        return $built;
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
        bool $isHoliday,
        LeaveDay $leaveDay,
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
        }

        // Timing (Phase 2.6). Late is a fact about the in-punch alone, so it
        // is computed whenever the day HAS an in-punch — the same $firstIn the
        // pairing above already chose, never a second pairing rule — whatever
        // the status turns out to be: in_progress (late while the day is
        // still open), incomplete (the out-punch never came) or present. A
        // late fact therefore never disappears when an in_progress day later
        // becomes incomplete: both builds read the same in-punch. An out-only
        // day has no in-punch, so no late. Early leave is a fact about the
        // out-punch measured against a shift that was actually worked, so it
        // still needs both punches — before the out-punch it isn't knowable.
        //
        // Working a holiday is never late or early-leaving, regardless of
        // when they clocked in/out — matters for OT later, where a holiday's
        // worked hours must not also register as a timing exception. That
        // now covers in_progress and incomplete holiday days too. isWorkday is
        // checked alongside isHoliday: an ordinary weekend someone came in on
        // isn't measured against a schedule they weren't on. The zeroing
        // after the status match() below enforces both for every status.
        if ($hasIn && $isWorkday && ! $isHoliday) {
            $scheduledStart = Carbon::parse($workDate->format('Y-m-d').' '.$schedule->start_time);

            // Grace only decides WHETHER first_in counts as late, not how
            // much: once outside grace, late_minutes is the full gap from
            // start_time, not the remainder past the grace period. intdiv,
            // never round: seconds never count against the employee.
            $minutesAfterStart = intdiv($firstIn->punched_at->getTimestamp() - $scheduledStart->getTimestamp(), 60);

            if ($minutesAfterStart > $schedule->grace_minutes) {
                $lateMinutes = $minutesAfterStart;
            }

            if ($hasBoth) {
                $scheduledEnd = Carbon::parse($workDate->format('Y-m-d').' '.$schedule->end_time);
                $minutesBeforeEnd = intdiv($scheduledEnd->getTimestamp() - $lastOut->punched_at->getTimestamp(), 60);
                $earlyLeaveMinutes = max(0, $minutesBeforeEnd);
            }
        }

        // Status precedence, highest to lowest — the one place this is
        // decided; every rule below is a special case of "what wins when
        // several could apply to the same punch-less or punch-partial day":
        //
        //   1. off         — no punches at all, and not a scheduled workday.
        //                    Wins outright, holiday or not: a holiday
        //                    landing on a weekend doesn't change anything,
        //                    it's already non-working — but this is
        //                    specifically the no-punches case. Someone who
        //                    works a non-workday (holiday or an ordinary
        //                    weekend) is covered by rule 3, not this one —
        //                    that already worked before holidays existed
        //                    and holidays don't change it.
        //   2. holiday      — no punches at all, a workday, marked as a
        //                    holiday. Beats absent and in_progress (an
        //                    unworked holiday is never "still open", it's
        //                    just a holiday) but not present: see 3.
        //   3. present      — both punches exist, on any day (workday,
        //                    holiday, or an ordinary weekend someone came in
        //                    on). A holiday doesn't need to override this —
        //                    timing is zeroed for it below — and a
        //                    fully-punched day is never "in progress"
        //                    regardless of the time of day.
        //   4. leave        — an approved full-day leave covers the date (Phase
        //                    3d, LeaveDay; an AM plus a PM leave count as
        //                    one), and fewer than both punches exist. From
        //                    00:00: never in_progress, so never "due" or "not
        //                    in yet". A single punch doesn't make it
        //                    incomplete — the punch stays stored and the day
        //                    is flagged (DailyAttendance::workedOnLeave()).
        //                    Both punches are present (rule 3), and an off day
        //                    or holiday inside the leave stays off/holiday
        //                    (rules 1–2), costing no balance. A leave row
        //                    carries no timing and no worked minutes.
        //   5. in_progress  — the day is still open, and punches so far would
        //                    otherwise resolve to incomplete or absent below.
        //                    "Open" depends on the punches (Phase 2.7, see
        //                    isInProgress()): an in-only day stays open until
        //                    its pairing window closes (first in-punch +
        //                    MAX_SHIFT_HOURS) — past the schedule's end and
        //                    past midnight, so someone on overtime is still
        //                    "at work" and a (+1) out-punch turns the day
        //                    present with no incomplete in between. A day
        //                    with no punches, or only an out-punch, is open
        //                    while it is today and the schedule's end_time
        //                    hasn't passed. Not conditioned on isWorkday,
        //                    matching rule 6's own workday-agnostic rule.
        //   6. incomplete   — exactly one of {in, out}, on any day. This
        //                    includes a one-sided punch on a holiday: the
        //                    punch being incomplete is a device-defect fact
        //                    independent of whether the day was a holiday,
        //                    so holiday does not suppress it the way it does
        //                    for "no punches at all" in rule 2.
        //   7. absent       — a workday, no punches, not a holiday, and not
        //                    (today and still before end_time) — unchanged
        //                    by Phase 2.7: no punches still closes at the
        //                    schedule's end.
        //
        // Timing is a separate dimension from all of this — see
        // AttendanceStatus's doc comment for why a `Late` status doesn't
        // exist here. Since Phase 2.6 late_minutes may be non-zero on
        // in_progress, incomplete and present rows (any row with an
        // in-punch on a non-holiday workday); early_leave_minutes only ever
        // on present rows.
        $status = match (true) {
            ! $hasIn && ! $hasOut && ! $isWorkday => AttendanceStatus::Off,
            ! $hasIn && ! $hasOut && $isHoliday => AttendanceStatus::Holiday,
            $hasBoth => AttendanceStatus::Present,
            $leaveDay->fullDay => AttendanceStatus::Leave,
            $this->isInProgress($workDate, $schedule, $firstIn, $lastOut) => AttendanceStatus::InProgress,
            $hasIn xor $hasOut => AttendanceStatus::Incomplete,
            default => AttendanceStatus::Absent,
        };

        // A full-day leave has no expected window either: a leave row carries
        // no timing, and neither does a day worked anyway (present, rule 17).
        if (! $isWorkday || $isHoliday || $leaveDay->fullDay) {
            $lateMinutes = 0;
            $earlyLeaveMinutes = 0;
        }

        // InProgress isn't listed here: it can only ever be reached when
        // hasBoth is false (rule 3 above claims every hasBoth day as
        // Present first), so worked_minutes is already 0 from its
        // initialization above, same as it already was for a one-sided
        // Incomplete punch before InProgress existed.
        if (in_array($status, [AttendanceStatus::Incomplete, AttendanceStatus::Absent, AttendanceStatus::Leave], true)) {
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

    /**
     * Whether the day is still open (Phase 2.7). Only asked when the day
     * doesn't already have both punches (rule 3 claims those as present).
     *
     * - An in-only day is open until its pairing window closes: first
     *   in-punch + MAX_SHIFT_HOURS, the same bound build() uses to pair an
     *   out-punch (inclusive, so the instant an out could still pair, the day
     *   is still open). Past the schedule's end and past midnight: until
     *   then an out-punch can still arrive and pair, so calling the day
     *   incomplete would be premature — and someone on overtime would read
     *   as gone. Rebuilding after the window closes turns it incomplete; the
     *   scheduler rebuilds yesterday while it has open rows for exactly that.
     * - A day with no punches, or only an out-punch, is open only while it is
     *   today and the schedule's end_time hasn't passed — unchanged.
     *
     * A future date is never built at all. Nothing here un-opens a day by
     * itself: a rebuild after the relevant moment simply falls through to
     * incomplete or absent in calculate()'s match().
     */
    private function isInProgress(Carbon $workDate, WorkSchedule $schedule, ?AttendanceLog $firstIn, ?AttendanceLog $lastOut): bool
    {
        if ($firstIn !== null && $lastOut === null) {
            return now()->lte($firstIn->punched_at->copy()->addHours(self::MAX_SHIFT_HOURS));
        }

        if (! $workDate->isToday()) {
            return false;
        }

        $scheduledEnd = Carbon::parse($workDate->format('Y-m-d').' '.$schedule->end_time);

        return now()->lt($scheduledEnd);
    }
}
