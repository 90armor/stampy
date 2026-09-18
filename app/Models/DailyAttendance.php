<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
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
     * "7h 30m", or null when there's nothing worked to show — callers decide
     * how to render that (e.g. an em dash).
     */
    public function formattedWorkedMinutes(): ?string
    {
        if ($this->worked_minutes === 0) {
            return null;
        }

        return sprintf('%dh %02dm', intdiv($this->worked_minutes, 60), $this->worked_minutes % 60);
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
     * The single resolver every view colours a cell/badge from — "did they
     * attend" (status) and "was the timing off" (isLate()/leftEarly()) are
     * independent facts, so no view may re-derive a colour bucket by
     * checking late_minutes/early_leave_minutes/status itself; they all
     * call this instead. A timing exception can only ever coincide with
     * Present: Incomplete/Absent/Off/Holiday/Leave days structurally carry
     * zero late/early minutes (DailySummaryBuilder only computes either when
     * both punches exist on a workday), so 'timing' and those five statuses
     * are mutually exclusive by construction, not by a check here.
     */
    public function displayVariant(): string
    {
        return match ($this->status) {
            AttendanceStatus::Off => 'off',
            AttendanceStatus::Holiday => 'holiday',
            AttendanceStatus::Leave => 'leave',
            AttendanceStatus::Absent => 'absent',
            AttendanceStatus::Incomplete => 'incomplete',
            AttendanceStatus::Present => $this->hasTimingException() ? 'timing' : 'present',
        };
    }
}
