<?php

namespace App\Models;

use App\Enums\PunchSource;
use App\Enums\PunchType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'punched_at',
        'punch_type',
        'source',
        'device_id',
        'created_by',
        'raw',
        'voided_at',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'punch_type' => PunchType::class,
            'source' => PunchSource::class,
            'raw' => 'array',
            'voided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * A voided punch is a correction of a mistake (e.g. someone else's
     * finger matched a device) — it must never be treated as real data by
     * anything that computes attendance from raw punches.
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
