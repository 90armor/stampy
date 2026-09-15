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
}
