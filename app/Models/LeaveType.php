<?php

namespace App\Models;

use App\Enums\LeaveCounting;
use App\Exceptions\InvalidLeaveTypeException;
use App\Exceptions\LeaveTypeInUseException;
use App\Exceptions\LeaveTypeLockedException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory;

    /**
     * Locked once any leave uses this type (isUsedByLeaves()) — see
     * LeaveTypeLockedException. Everything else stays editable: changing
     * days_per_year, for one, affects future grants only.
     */
    private const LOCKED_FIELDS = ['counts', 'deducts_from_leave_type_id', 'allows_half_day'];

    protected $fillable = [
        'name',
        'days_per_year',
        'min_service_months',
        'seniority_bonus',
        'carry_over_cap',
        'counts',
        'max_days_per_request',
        'deducts_from_leave_type_id',
        'allows_half_day',
        'is_paid',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:1',
            'min_service_months' => 'integer',
            'seniority_bonus' => 'boolean',
            'carry_over_cap' => 'decimal:1',
            'counts' => LeaveCounting::class,
            'max_days_per_request' => 'decimal:1',
            'allows_half_day' => 'boolean',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Two different "referenced" tests, on purpose — unlike WorkSchedule,
     * which uses one for both. The lock asks isUsedByLeaves(): the locked
     * fields change results only through leave rows, and locking on
     * entitlements too would lock Annual and Medical as soon as they're
     * granted, before anyone has taken leave. Deletion asks isReferenced():
     * anything pointing at the type blocks it (restrictOnDelete everywhere),
     * so it fails here with a named exception rather than as a raw FK error.
     */
    protected static function booted(): void
    {
        static::saving(function (self $type) {
            if ($type->exists && $type->isDirty(self::LOCKED_FIELDS) && $type->isUsedByLeaves()) {
                throw new LeaveTypeLockedException($type);
            }

            if (! $type->exists || $type->isDirty(['days_per_year', 'carry_over_cap', 'seniority_bonus', 'deducts_from_leave_type_id'])) {
                $type->validateOwnFields();
            }
        });

        static::deleting(function (self $type) {
            if ($type->isReferenced()) {
                throw new LeaveTypeInUseException($type);
            }
        });
    }

    /**
     * @throws InvalidLeaveTypeException
     */
    private function validateOwnFields(): void
    {
        if ($this->days_per_year === null && ($this->carry_over_cap !== null || $this->seniority_bonus)) {
            throw InvalidLeaveTypeException::balanceOptionsWithoutBalance();
        }

        if ($this->deducts_from_leave_type_id === null) {
            return;
        }

        if ($this->exists && $this->deducts_from_leave_type_id === $this->id) {
            throw InvalidLeaveTypeException::deductsFromItself();
        }

        $target = static::query()->find($this->deducts_from_leave_type_id);

        if ($target?->deducts_from_leave_type_id !== null || ($this->exists && $this->deductedBy()->exists())) {
            throw InvalidLeaveTypeException::deductionChain();
        }
    }

    public function deductsFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deducts_from_leave_type_id');
    }

    public function deductedBy(): HasMany
    {
        return $this->hasMany(self::class, 'deducts_from_leave_type_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(LeaveEntitlement::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LeaveAdjustment::class);
    }

    /** Whether any leave uses this type — the lock's test (LOCKED_FIELDS). */
    public function isUsedByLeaves(): bool
    {
        return $this->leaves()->exists();
    }

    /** Whether anything points at this type — the deletion test. */
    public function isReferenced(): bool
    {
        return $this->isUsedByLeaves()
            || $this->entitlements()->exists()
            || $this->adjustments()->exists()
            || $this->deductedBy()->exists();
    }
}
