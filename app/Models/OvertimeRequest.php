<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeKind;
use App\Enums\OvertimeStatus;
use App\Exceptions\InvalidOvertimeRequestException;
use App\Exceptions\InvalidOvertimeTransitionException;
use App\Policies\OvertimePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An overtime request (CLAUDE.md, Phase 4): an approved window on a work date.
 * The window's own shape (InvalidOvertimeRequestException) and the status
 * transitions (InvalidOvertimeTransitionException, the same as a leave's) are
 * enforced here; rules that need other rows or settings — the daily limits,
 * the claim window, one active request per date, who may approve — belong to
 * the request service. Goes through the shared approval engine as an
 * Approvable (ApprovalFlow), under the morph alias 'overtime'. Its policy is
 * OvertimePolicy (named for the feature, so bound here rather than found by
 * the OvertimeRequestPolicy naming convention).
 */
#[UsePolicy(OvertimePolicy::class)]
class OvertimeRequest extends Model implements Approvable
{
    use HasFactory;

    /** A sanity bound on the window, not a policy limit (those are settings). */
    public const MAX_WINDOW_HOURS = 12;

    /**
     * Where each status may go. Creation takes any status (an admin's filing
     * on someone's behalf is approved on submit); only changes are checked.
     */
    private const TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['cancelled'],
        'rejected' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'employee_id',
        'date',
        'starts_at',
        'ends_at',
        'kind',
        'compensation',
        'reason',
        'status',
        'current_step',
        'requested_by',
        'cancelled_by',
        'cancelled_at',
        'limit_override_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'kind' => OvertimeKind::class,
            'compensation' => OvertimeCompensation::class,
            'status' => OvertimeStatus::class,
            'current_step' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $request) {
            $status = $request->status ?? OvertimeStatus::Pending;

            if ($request->exists && $request->isDirty('status')) {
                $from = OvertimeStatus::from($request->getRawOriginal('status'));

                if (! in_array($status->value, self::TRANSITIONS[$from->value], true)) {
                    throw InvalidOvertimeTransitionException::between($from, $status);
                }
            }

            if (($status === OvertimeStatus::Pending) !== ($request->current_step !== null)) {
                throw InvalidOvertimeTransitionException::stepMismatch($status);
            }
        });

        static::saving(function (self $request) {
            if ($request->exists && ! $request->isDirty(['date', 'starts_at', 'ends_at'])) {
                return;
            }

            if ($request->ends_at->lte($request->starts_at)) {
                throw InvalidOvertimeRequestException::endNotAfterStart();
            }

            if (! $request->starts_at->isSameDay($request->date)) {
                throw InvalidOvertimeRequestException::startNotOnDate();
            }

            if ($request->ends_at->gt($request->starts_at->copy()->addHours(self::MAX_WINDOW_HOURS))) {
                throw InvalidOvertimeRequestException::tooLong(self::MAX_WINDOW_HOURS);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The system-authored TOIL adjustments this request's changes triggered
     * (TOIL settlement, Phase 4c) — "triggered by", not "earned from only this
     * request": settlement reconciles the employee's all-time total.
     */
    public function leaveAdjustments(): HasMany
    {
        return $this->hasMany(LeaveAdjustment::class);
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalStep::class, 'approvable')->orderBy('step');
    }

    public function approvalSubject(): Employee
    {
        return $this->employee;
    }

    public function currentApprovalStep(): ?int
    {
        return $this->current_step;
    }
}
