<?php

namespace App\Models;

use App\Exceptions\AppendOnlyRecordException;
use App\Exceptions\InvalidLeaveAdjustmentException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin's correction to a balance: signed days with a required note.
 * Append-only — a wrong adjustment is undone by a reversing one, so the
 * balance's history stays whole (AppendOnlyRecordException).
 *
 * Phase 4: TOIL settlement also posts adjustments — system-authored, so
 * created_by is null and overtime_request_id names the request whose change
 * triggered it (not the only request it was earned from). A row linked to a
 * request with an author is refused (InvalidLeaveAdjustmentException).
 */
class LeaveAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'days',
        'note',
        'overtime_request_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'days' => 'decimal:1',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment) {
            if ($adjustment->overtime_request_id !== null && $adjustment->created_by !== null) {
                throw InvalidLeaveAdjustmentException::authoredOvertimeCredit();
            }
        });
        static::updating(fn (self $adjustment) => throw AppendOnlyRecordException::for($adjustment, 'updated'));
        static::deleting(fn (self $adjustment) => throw AppendOnlyRecordException::for($adjustment, 'deleted'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** The overtime request whose change triggered this TOIL adjustment (system-authored), if any. */
    public function overtimeRequest(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
