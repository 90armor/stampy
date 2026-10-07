<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Services\Attendance\ExpectedWindow;
use App\Services\Attendance\LeaveDay;
use App\Support\Duration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class DailyAttendance extends Model
{
    use HasFactory;

    /** leaveDay()'s answer, once resolved (or preloaded by withLeaveDays()). */
    private ?LeaveDay $resolvedLeaveDay = null;

    protected $fillable = [
        'employee_id',
        'work_date',
        'work_schedule_id',
        'leave_id',
        'first_in',
        'last_out',
        'worked_minutes',
        'late_minutes',
        'early_leave_minutes',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'status' => AttendanceStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * The approved leave covering this date, set by the builder whatever the
     * status (Phase 3d). One leave only: on a date with both an AM and a PM
     * leave it's the AM one, so a view listing a day's leave must query every
     * approved leave covering the date, not just this one.
     */
    public function leave(): BelongsTo
    {
        return $this->belongsTo(Leave::class);
    }

    /**
     * "7h 30m" / "45m" (App\Support\Duration), or null when there's nothing
     * worked to show — callers decide how to render that (e.g. an em dash).
     */
    public function formattedWorkedMinutes(): ?string
    {
        return $this->worked_minutes === 0 ? null : Duration::format($this->worked_minutes);
    }

    /**
     * "21m" / "1h 20m", or null when the arrival wasn't late — the same
     * Duration format as worked time.
     */
    public function formattedLateMinutes(): ?string
    {
        return $this->isLate() ? Duration::format($this->late_minutes) : null;
    }

    /**
     * "21m" / "1h 20m", or null when there was no early leave.
     */
    public function formattedEarlyLeaveMinutes(): ?string
    {
        return $this->leftEarly() ? Duration::format($this->early_leave_minutes) : null;
    }

    /**
     * True when last_out lands on the calendar day after work_date — an
     * overnight shift. By construction (DailySummaryBuilder's 18h pairing
     * window) it can never be more than one day later.
     */
    public function isOvernightOut(): bool
    {
        return $this->last_out !== null
            && $this->last_out->format('Y-m-d') !== $this->work_date->format('Y-m-d');
    }

    public function isLate(): bool
    {
        return $this->late_minutes > 0;
    }

    public function leftEarly(): bool
    {
        return $this->early_leave_minutes > 0;
    }

    public function hasTimingException(): bool
    {
        return $this->isLate() || $this->leftEarly();
    }

    /**
     * The approved leave on this date, as the builder saw it (LeaveDay): a
     * full day (a whole-day leave, or an AM and a PM leave together), one
     * half, or none. leave_id alone can't tell an AM+PM date from an AM one,
     * so this looks at every approved leave covering the date — one query per
     * row, or none after withLeaveDays().
     */
    public function leaveDay(): LeaveDay
    {
        if ($this->leave_id === null) {
            return LeaveDay::none();
        }

        return $this->resolvedLeaveDay ??= LeaveDay::on(
            Leave::query()
                ->where('employee_id', $this->employee_id)
                ->where('status', LeaveStatus::Approved->value)
                ->whereDate('start_date', '<=', $this->work_date->format('Y-m-d'))
                ->whereDate('end_date', '>=', $this->work_date->format('Y-m-d'))
                ->get(),
            $this->work_date,
        );
    }

    /**
     * Resolves leaveDay() for every row with a leave_id in one query.
     *
     * @param  Collection<int, self>  $rows
     */
    public static function withLeaveDays(Collection $rows): void
    {
        $withLeave = $rows->whereNotNull('leave_id');

        if ($withLeave->isEmpty()) {
            return;
        }

        $leaves = Leave::query()
            ->whereIn('employee_id', $withLeave->pluck('employee_id')->unique())
            ->where('status', LeaveStatus::Approved->value)
            ->whereDate('start_date', '<=', $withLeave->max('work_date')->format('Y-m-d'))
            ->whereDate('end_date', '>=', $withLeave->min('work_date')->format('Y-m-d'))
            ->get()
            ->groupBy('employee_id');

        foreach ($withLeave as $row) {
            $row->resolvedLeaveDay = LeaveDay::on($leaves->get($row->employee_id, collect()), $row->work_date);
        }
    }

    /**
     * When this person was expected at work (ExpectedWindow — the builder's
     * definition): the schedule's day, or the half worked on a half-day
     * leave. Null when the row carries no schedule.
     */
    public function expectedWindow(): ?ExpectedWindow
    {
        if ($this->workSchedule === null) {
            return null;
        }

        return ExpectedWindow::for($this->workSchedule, $this->work_date, $this->leaveDay()->half);
    }

    /**
     * When a punchless person counts as "not in yet": the expected start plus
     * grace_minutes on work_date, in the app timezone — start_time, or the PM
     * start on an AM-leave day (Phase 3d). Null when the row carries no
     * schedule.
     */
    public function notInYetAfter(): ?CarbonInterface
    {
        return $this->expectedWindow()?->start->copy()->addMinutes($this->workSchedule->grace_minutes);
    }

    /**
     * A punchless In progress row still inside its half-day leave (an AM
     * leave, before the PM start + grace): the person isn't due yet, so the
     * live strip counts them "on leave", not "due" (Phase 3d).
     */
    public function isAwayOnLeaveNow(?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $due = $this->notInYetAfter();

        return $this->status === AttendanceStatus::InProgress
            && $this->first_in === null
            && $this->last_out === null
            && $this->leaveDay()->half === LeaveHalf::Am
            && $due !== null
            && $now->lt($due);
    }

    /**
     * "Worked on approved leave" (Phase 3d) — a derived display fact, never a
     * status, like isNotInYet(): someone punched during leave they were
     * granted. On a full-day leave (an AM plus a PM leave included), any
     * punch; on an AM leave, an in-punch before break_start; on a PM leave, an
     * out-punch after the PM start. Arriving at 11:55 on an AM-leave day
     * counts — it's an annotation, and the admin decides whether to cancel
     * the leave; the balance is never refunded automatically.
     */
    public function workedOnLeave(): bool
    {
        $leaveDay = $this->leaveDay();

        if ($leaveDay->fullDay) {
            return $this->first_in !== null || $this->last_out !== null;
        }

        $window = $leaveDay->isHalfDay() ? $this->expectedWindow() : null;

        if ($window?->breakStart === null) {
            return false;
        }

        return match ($leaveDay->half) {
            LeaveHalf::Am => $this->first_in !== null && $this->first_in->lt($window->breakStart),
            LeaveHalf::Pm => $this->last_out !== null && $this->last_out->gt($window->breakEnd),
        };
    }

    /**
     * "Not in yet" (Phase 2.6) is a derived display fact, never a status and
     * never "absent": an In progress row (so today, a workday, not a holiday
     * or leave) with no punch at all, once the schedule's start_time +
     * grace_minutes has passed. Before that point the person is simply in
     * progress. Off/holiday/leave rows are never In progress, so they never
     * qualify. The live strip's own "Not in yet" count is wider (anyone
     * without an in-punch at any time of day); this is the narrower,
     * actionable subset Needs attention lists (docs/ATTENDANCE_UI.md).
     */
    public function isNotInYet(?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $due = $this->notInYetAfter();

        return $this->status === AttendanceStatus::InProgress
            && $this->first_in === null
            && $this->last_out === null
            && $this->work_date->isSameDay($now)
            && $due !== null
            && $now->gt($due);
    }

    /**
     * The single resolver every view colours a cell/badge from. It encodes
     * the attendance STATUS only — one value per day. Attributes that can
     * co-occur on the same day (timing exceptions now, partial leave later)
     * are annotations inside the cell, exposed through isLate()/leftEarly()/
     * hasTimingException(), never a colour bucket: a Present day is 'present'
     * whether or not it was late. No view may re-derive a colour bucket from
     * late_minutes/early_leave_minutes/status itself; they all call this.
     *
     * A 'timing' variant used to exist (Present + a timing exception, amber)
     * and was removed in Design System v1.1: it made one cell colour carry
     * two independent facts, and would have needed yet another combined
     * bucket the first time a second co-occurring attribute (partial leave)
     * arrived.
     */
    public function displayVariant(): string
    {
        return match ($this->status) {
            AttendanceStatus::Off => 'off',
            AttendanceStatus::Holiday => 'holiday',
            AttendanceStatus::Leave => 'leave',
            AttendanceStatus::Absent => 'absent',
            AttendanceStatus::Incomplete => 'incomplete',
            AttendanceStatus::InProgress => 'in_progress',
            AttendanceStatus::Present => 'present',
        };
    }
}
