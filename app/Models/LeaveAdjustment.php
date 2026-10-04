<?php

namespace App\Models;

use App\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin's correction to a balance: signed days with a required note.
 * Append-only — a wrong adjustment is undone by a reversing one, so the
 * balance's history stays whole (AppendOnlyRecordException).
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
