<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per schedule change: the schedule named here applies to this
 * employee from effective_from until their next row's effective_from (or
 * forever, if this is their latest). No effective_to — see the create
 * migration's comment for why deriving the boundary rather than storing it
 * is what makes overlaps and gaps impossible. Employee::scheduleOn() is the
 * one place this is resolved into "the schedule for date X".
 */
class EmployeeWorkSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_schedule_id',
        'effective_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
