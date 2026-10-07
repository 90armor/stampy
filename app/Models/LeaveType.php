<?php

namespace App\Models;

use App\Enums\LeaveBalanceSource;
use App\Enums\LeaveCounting;
use App\Exceptions\InvalidLeaveTypeException;
use App\Exceptions\LeaveTypeInUseException;
use App\Exceptions\LeaveTypeLockedException;
use Illuminate\Database\Eloquent\Builder;
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
    private const LOCKED_FIELDS = ['counts', 'deducts_from_leave_type_id', 'allows_half_day', 'balance_source'];

    /** The fields validateOwnFields() reads — it runs when one of them changes. */
    private const SHAPE_FIELDS = ['balance_source', 'days_per_year', 'min_service_months', 'carry_over_cap', 'seniority_bonus', 'deducts_from_leave_type_id'];

    /** The column default, so a new model reads the same before it's saved. */
    protected $attributes = [
        'balance_source' => 'yearly',
    ];

    protected $fillable = [
        'name',
        'balance_source',
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
            'balance_source' => LeaveBalanceSource::class,
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

            if (! $type->exists || $type->isDirty(self::SHAPE_FIELDS)) {
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
        $source = $this->balance_source;

        if ($source === LeaveBalanceSource::Yearly && $this->days_per_year === null) {
            throw InvalidLeaveTypeException::yearlyWithoutDays();
        }

        if ($source !== LeaveBalanceSource::Yearly && $this->days_per_year !== null) {
            throw InvalidLeaveTypeException::daysWithoutYearlyGrant($source);
        }

        if ($source === LeaveBalanceSource::None && ($this->carry_over_cap !== null || $this->seniority_bonus)) {
            throw InvalidLeaveTypeException::balanceOptionsWithoutBalance();
        }

        if ($source !== LeaveBalanceSource::Yearly && ($this->seniority_bonus || $this->min_service_months !== null)) {
            throw InvalidLeaveTypeException::yearlyOptionsWithoutYearlyGrant();
        }

        if ($this->exists && $source !== LeaveBalanceSource::Earned && $this->isToilType()) {
            throw InvalidLeaveTypeException::toilTypeMustBeEarned($this->name);
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

    /** Whether the type has a balance of its own — yearly or earned. */
    public function hasBalance(): bool
    {
        return $this->balance_source->hasBalance();
    }

    /** Whether the type is granted each year (LeaveGranter, EntitlementCalculator). */
    public function isGrantedYearly(): bool
    {
        return $this->balance_source === LeaveBalanceSource::Yearly;
    }

    /** Types with a balance of their own — yearly or earned. */
    public function scopeWithBalance(Builder $query): void
    {
        $query->whereIn('balance_source', [LeaveBalanceSource::Yearly->value, LeaveBalanceSource::Earned->value]);
    }

    /**
     * Types with a balance worth showing $employee (Phase 4d): every yearly
     * one, and an earned one (Time off in lieu) only once they've had an
     * adjustment of it — otherwise everyone who never did overtime would see
     * "Time off in lieu 0".
     */
    public function scopeShownFor(Builder $query, Employee $employee): void
    {
        $query->withBalance()->where(fn (Builder $query) => $query
            ->where('balance_source', '!=', LeaveBalanceSource::Earned->value)
            ->orWhereHas('adjustments', fn (Builder $adjustments) => $adjustments->where('employee_id', $employee->id)));
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

    /** Whether the overtime settings credit time off in lieu to this type (Phase 4). */
    public function isToilType(): bool
    {
        return OvertimeSettings::query()->where('toil_leave_type_id', $this->id)->exists();
    }

    /** Whether anything points at this type — the deletion test. */
    public function isReferenced(): bool
    {
        return $this->isUsedByLeaves()
            || $this->entitlements()->exists()
            || $this->adjustments()->exists()
            || $this->deductedBy()->exists()
            || $this->isToilType();
    }
}
