<?php

namespace App\Models;

use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Exceptions\InvalidLeaveException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A leave request. Its own shape is enforced here (InvalidLeaveException);
 * request rules that need other rows — overlap, balance, who may approve —
 * belong to the request lifecycle (Phase 3c), not this model.
 */
class Leave extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'half',
        'reason',
        'status',
        'current_step',
        'requested_by',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'half' => LeaveHalf::class,
            'status' => LeaveStatus::class,
            'current_step' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $leave) {
            if ($leave->exists && ! $leave->isDirty(['start_date', 'end_date', 'half', 'leave_type_id'])) {
                return;
            }

            if ($leave->end_date->lt($leave->start_date)) {
                throw InvalidLeaveException::endBeforeStart();
            }

            if ($leave->half === null) {
                return;
            }

            if (! $leave->end_date->eq($leave->start_date)) {
                throw InvalidLeaveException::halfDaySpansDays();
            }

            $type = $leave->leaveType()->first();

            if (! $type->allows_half_day) {
                throw InvalidLeaveException::halfDayNotAllowed($type->name);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalStep::class, 'approvable')->orderBy('step');
    }
}
