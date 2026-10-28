<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\LeaveHalf;
use App\Enums\LeaveStatus;
use App\Exceptions\InvalidLeaveException;
use App\Exceptions\InvalidLeaveTransitionException;
use App\Support\DisplayDate;
use App\Support\EmployeeScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A leave request. Its own shape (InvalidLeaveException) and its status
 * transitions (InvalidLeaveTransitionException) are enforced here; rules that
 * need other rows — overlap, balance, who may approve — belong to
 * LeaveRequestService. Goes through the shared approval engine as an
 * Approvable (ApprovalFlow).
 */
class Leave extends Model implements Approvable
{
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

    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'half',
        'reason',
        'status',
        'current_step',
        'requested_by',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'half' => LeaveHalf::class,
            'status' => LeaveStatus::class,
            'current_step' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $leave) {
            $status = $leave->status ?? LeaveStatus::Pending;

            if ($leave->exists && $leave->isDirty('status')) {
                $from = LeaveStatus::from($leave->getRawOriginal('status'));

                if (! in_array($status->value, self::TRANSITIONS[$from->value], true)) {
                    throw InvalidLeaveTransitionException::between($from, $status);
                }
            }

            if (($status === LeaveStatus::Pending) !== ($leave->current_step !== null)) {
                throw InvalidLeaveTransitionException::stepMismatch($status);
            }
        });

        static::saving(function (self $leave) {
            if ($leave->exists && ! $leave->isDirty(['start_date', 'end_date', 'half', 'leave_type_id'])) {
                return;
            }

            if ($leave->end_date->lt($leave->start_date)) {
                throw InvalidLeaveException::endBeforeStart();
            }

            if ($leave->half === null) {
                return;
            }

            if (! $leave->end_date->eq($leave->start_date)) {
                throw InvalidLeaveException::halfDaySpansDays();
            }

            $type = $leave->leaveType()->first();

            if (! $type->allows_half_day) {
                throw InvalidLeaveException::halfDayNotAllowed($type->name);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalStep::class, 'approvable')->orderBy('step');
    }

    public function approvalSubject(): Employee
    {
        return $this->employee;
    }

    public function approvalDate(): CarbonInterface
    {
        return $this->start_date;
    }

    public function approvalStartsOn(): ?CarbonInterface
    {
        return $this->start_date;
    }

    public function currentApprovalStep(): ?int
    {
        return $this->status === LeaveStatus::Pending ? $this->current_step : null;
    }

    /**
     * The leaves $user may see in a list — EmployeeScope's rule, the same as
     * every other list: an admin everyone's, a manager their team's, anyone
     * else their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = EmployeeScope::for($user, 'Leave list')->ids;

        return $ids === null ? $query : $query->whereIn('employee_id', $ids);
    }

    public function isHalfDay(): bool
    {
        return $this->half !== null;
    }

    /**
     * The dates as every leave list shows them: "Mon 22 Jun", "22–24 Jun",
     * with " · AM"/" · PM" on a half day (DisplayDate's forms).
     */
    public function displayDates(): string
    {
        return ($this->start_date->eq($this->end_date)
            ? DisplayDate::compact($this->start_date)
            : DisplayDate::range($this->start_date, $this->end_date))
            .($this->half !== null ? ' · '.$this->half->label() : '');
    }

    /**
     * Whose decision a pending request waits for, or null once none is.
     */
    public function waitingLabel(): ?string
    {
        return match ($this->currentApprovalStep()) {
            1 => 'Waiting for manager',
            2 => 'Waiting for admin',
            default => null,
        };
    }

    /**
     * How soon it starts, while that's within a week — "Starts today",
     * "Starts tomorrow", "Starts in 3 days" — or that it's under way: one
     * that has ended is "Already taken" (sick leave filed after the fact),
     * one that began and hasn't ended "Already started".
     */
    public function startsLabel(): ?string
    {
        $days = (int) today()->diffInDays($this->start_date->copy()->startOfDay(), false);

        return match (true) {
            $this->end_date->lt(today()) => 'Already taken',
            $days < 0 => 'Already started',
            $days === 0 => 'Starts today',
            $days === 1 => 'Starts tomorrow',
            $days <= 7 => "Starts in {$days} days",
            default => null,
        };
    }
}
