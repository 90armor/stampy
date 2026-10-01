<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Support\Duration;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_date',
        'work_schedule_id',
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
     * When a punchless person counts as "not in yet": the schedule this row
     * was built with, start_time plus grace_minutes on work_date, in the app
     * timezone. Null when the row carries no schedule.
     */
    public function notInYetAfter(): ?CarbonInterface
    {
        if ($this->workSchedule === null) {
            return null;
        }

        return Carbon::parse($this->work_date->format('Y-m-d').' '.$this->workSchedule->start_time)
            ->addMinutes($this->workSchedule->grace_minutes);
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
