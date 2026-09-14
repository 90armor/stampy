<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use App\Enums\PunchType;
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
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'punch_type' => PunchType::class,
            'source' => AttendanceSource::class,
            'raw' => 'array',
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
}
