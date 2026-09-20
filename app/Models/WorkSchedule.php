<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'grace_minutes',
        'break_minutes',
        'workdays',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'workdays' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }
}
