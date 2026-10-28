<?php

namespace App\Models;

use App\Enums\ApprovalOutcome;
use App\Exceptions\AppendOnlyRecordException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One decided step of a request's approval (leave now, overtime in Phase 4)
 * — the shared half of the approval engine; the request itself stays in its
 * own typed table. Append-only: a decision, once recorded, is history
 * (AppendOnlyRecordException).
 */
class ApprovalStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'step',
        'outcome',
        'decided_by',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'outcome' => ApprovalOutcome::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $step) => throw AppendOnlyRecordException::for($step, 'updated'));
        static::deleting(fn (self $step) => throw AppendOnlyRecordException::for($step, 'deleted'));
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
