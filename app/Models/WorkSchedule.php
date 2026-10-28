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
     *
     * One exception (lockedFieldsChanged()): break_start may go from null to
     * a value once, even when locked. It's only read for half-day leave, and
     * half-day leave is refused on a schedule without it — so no built row
     * can depend on it having been null.
     */
    private const LOCKED_FIELDS = ['start_time', 'end_time', 'grace_minutes', 'break_minutes', 'break_start', 'workdays'];

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'grace_minutes',
        'break_minutes',
        'break_start',
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
            if ($schedule->exists && $schedule->lockedFieldsChanged() && $schedule->isReferenced()) {
                throw new WorkScheduleLockedException($schedule);
            }

            if (! $schedule->is_default
                && $schedule->exists
                && $schedule->isDirty('is_default')
                && $schedule->getOriginal('is_default') === true) {
                throw WorkScheduleIsDefaultException::cannotUnset($schedule);
            }

            if ($schedule->isDirty(['start_time', 'end_time', 'break_minutes', 'break_start', 'workdays']) || ! $schedule->exists) {
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

        if ($this->break_start === null) {
            return;
        }

        if ($this->break_minutes <= 0) {
            throw InvalidWorkScheduleException::breakStartWithoutBreak();
        }

        $breakStart = Carbon::parse($this->break_start);

        if ($breakStart->lessThanOrEqualTo($start) || $breakStart->copy()->addMinutes($this->break_minutes)->greaterThan($end)) {
            throw InvalidWorkScheduleException::breakOutsideShift();
        }
    }

    /**
     * Whether a save would change a locked field — except break_start going
     * from null to a value, which LOCKED_FIELDS' doc comment allows once.
     */
    private function lockedFieldsChanged(): bool
    {
        $changed = array_keys(array_intersect_key($this->getDirty(), array_flip(self::LOCKED_FIELDS)));

        if (in_array('break_start', $changed, true) && $this->getOriginal('break_start') === null && $this->break_start !== null) {
            $changed = array_diff($changed, ['break_start']);
        }

        return $changed !== [];
    }

    /**
     * Whether a save could no longer change break_start: set once on a
     * referenced schedule, it's locked like the other calculation fields.
     */
    public function breakStartIsLocked(): bool
    {
        return $this->break_start !== null && $this->isReferenced();
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
