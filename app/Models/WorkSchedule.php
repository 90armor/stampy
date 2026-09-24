<?php

namespace App\Models;

use App\Exceptions\InvalidWorkScheduleException;
use App\Exceptions\WorkScheduleInUseException;
use App\Exceptions\WorkScheduleIsDefaultException;
use App\Exceptions\WorkScheduleLockedException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class WorkSchedule extends Model
{
    use HasFactory;

    /**
     * Once this schedule is referenced (see isReferenced()), these fields
     * are locked — see WorkScheduleLockedException. name and is_default are
     * deliberately not in this list: the name is always editable, and
     * is_default has its own separate rule below.
     */
    private const LOCKED_FIELDS = ['start_time', 'end_time', 'grace_minutes', 'break_minutes', 'workdays'];

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

    protected static function booted(): void
    {
        static::saving(function (self $schedule) {
            if ($schedule->exists && $schedule->isDirty(self::LOCKED_FIELDS) && $schedule->isReferenced()) {
                throw new WorkScheduleLockedException($schedule);
            }

            if (! $schedule->is_default
                && $schedule->exists
                && $schedule->isDirty('is_default')
                && $schedule->getOriginal('is_default') === true) {
                throw WorkScheduleIsDefaultException::cannotUnset($schedule);
            }

            if ($schedule->isDirty(['start_time', 'end_time', 'break_minutes', 'workdays']) || ! $schedule->exists) {
                $schedule->validateOwnFields();
            }
        });

        // Setting a new default unsets whichever schedule held it before, in
        // the same transaction as the save that made this one the default —
        // there is never a moment with two (or, mid-request, zero) defaults.
        static::saved(function (self $schedule) {
            if ($schedule->is_default) {
                static::query()
                    ->where('id', '!=', $schedule->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        static::deleting(function (self $schedule) {
            if ($schedule->is_default) {
                throw WorkScheduleIsDefaultException::cannotDelete($schedule);
            }

            if ($schedule->isReferenced()) {
                throw new WorkScheduleInUseException($schedule);
            }
        });
    }

    /**
     * Wraps the default-swap (saved()) and the save itself in one
     * transaction, so another request can never observe zero or two
     * defaults between the two writes.
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /**
     * @throws InvalidWorkScheduleException
     */
    private function validateOwnFields(): void
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            throw InvalidWorkScheduleException::endNotAfterStart();
        }

        if ($this->workdays === [] || $this->workdays === null) {
            throw InvalidWorkScheduleException::noWorkdays();
        }

        $shiftMinutes = $start->diffInMinutes($end);

        if ($this->break_minutes >= $shiftMinutes) {
            throw InvalidWorkScheduleException::breakTooLong();
        }
    }

    public function employeeWorkSchedules(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function dailyAttendances(): HasMany
    {
        return $this->hasMany(DailyAttendance::class);
    }

    /**
     * Whether anything depends on this schedule's own fields staying put —
     * either an employee assignment (present, past or future) or a
     * daily_attendances row already calculated against it. Used both to
     * lock the hour fields (see LOCKED_FIELDS) and to block deletion.
     */
    public function isReferenced(): bool
    {
        return $this->employeeWorkSchedules()->exists() || $this->dailyAttendances()->exists();
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }
}
