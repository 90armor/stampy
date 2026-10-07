<?php

namespace App\Models;

use App\Enums\LeaveBalanceSource;
use App\Exceptions\InvalidOvertimeSettingsException;
use App\Exceptions\OvertimeSettingsLockedException;
use App\Exceptions\OvertimeSettingsRowException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The overtime policy (CLAUDE.md, Phase 4): exactly one row, inserted by its
 * migration so production has it without the demo seed. Every number in the
 * policy lives here, never as a constant in code. Rules on the model:
 * InvalidOvertimeSettingsException for the values, OvertimeSettingsRowException
 * for the row itself (no second row, no deleting it).
 *
 * Rates are whole percentages, minutes and days whole numbers; the TOIL ratio
 * is a percentage too (100 = 1:1), never a float.
 */
class OvertimeSettings extends Model
{
    /**
     * Locked once a system TOIL adjustment exists (OvertimeSettingsLockedException):
     * the reconciler recomputes from all-time minutes with these. Rates and the
     * night window stay editable — rates only feed the report's arithmetic, and
     * a new night window changes categories only for days built afterwards.
     */
    private const TOIL_FIELDS = ['toil_ratio_percent', 'toil_block_minutes', 'toil_leave_type_id'];

    /** Container key for current()'s per-request cache. */
    private const CURRENT = 'overtime.settings';

    private const POSITIVE = [
        'workday_rate_percent',
        'night_rate_percent',
        'rest_day_rate_percent',
        'holiday_rate_percent',
        'max_overtime_minutes_per_day',
        'max_work_minutes_per_day',
        'claim_window_days',
        'toil_ratio_percent',
        'toil_block_minutes',
    ];

    protected $table = 'overtime_settings';

    protected $fillable = [
        ...self::POSITIVE,
        'night_starts',
        'night_ends',
        'weekly_rest_day',
        'toil_leave_type_id',
    ];

    protected function casts(): array
    {
        return [...array_fill_keys(self::POSITIVE, 'integer'), 'weekly_rest_day' => 'integer'];
    }

    /**
     * The settings row, read once per request (cached in the container, so a
     * new request or test starts fresh; a save clears it).
     *
     * @throws OvertimeSettingsRowException when the row is missing
     */
    public static function current(): self
    {
        if (! app()->bound(self::CURRENT)) {
            app()->instance(self::CURRENT, static::query()->first() ?? throw OvertimeSettingsRowException::missing());
        }

        return app(self::CURRENT);
    }

    protected static function booted(): void
    {
        // saving fires before creating, so the one-row check comes first here.
        // The cache is dropped first: a save the checks refuse must not leave
        // current() handing out an instance that holds the refused values.
        static::saving(function (self $settings) {
            app()->forgetInstance(self::CURRENT);

            if (! $settings->exists && static::query()->exists()) {
                throw OvertimeSettingsRowException::second();
            }

            if ($settings->exists && $settings->isDirty(self::TOIL_FIELDS) && self::toilCredited()) {
                throw new OvertimeSettingsLockedException;
            }

            $settings->validate();
        });

        static::saved(fn () => app()->forgetInstance(self::CURRENT));

        static::deleting(fn () => throw OvertimeSettingsRowException::delete());
    }

    /**
     * @throws InvalidOvertimeSettingsException
     */
    private function validate(): void
    {
        foreach (self::POSITIVE as $field) {
            if ($this->{$field} !== null && $this->{$field} <= 0) {
                throw InvalidOvertimeSettingsException::notPositive($field);
            }
        }

        // The category precedence (holiday > rest day > night > workday) never
        // pays less only if the rates are ordered the same way.
        if (! ($this->holiday_rate_percent >= $this->rest_day_rate_percent
            && $this->rest_day_rate_percent >= $this->night_rate_percent
            && $this->night_rate_percent >= $this->workday_rate_percent
            && $this->workday_rate_percent >= 100)) {
            throw InvalidOvertimeSettingsException::ratesOutOfOrder();
        }

        if ($this->weekly_rest_day !== null && ($this->weekly_rest_day < 1 || $this->weekly_rest_day > 7)) {
            throw InvalidOvertimeSettingsException::restDayNotAWeekday();
        }

        if ($this->toil_block_minutes % 30 !== 0) {
            throw InvalidOvertimeSettingsException::blockNotHalfHours();
        }

        if ($this->toil_leave_type_id !== null) {
            $type = LeaveType::query()->find($this->toil_leave_type_id);

            if ($type !== null && $type->balance_source !== LeaveBalanceSource::Earned) {
                throw InvalidOvertimeSettingsException::toilTypeNotEarned($type->name);
            }
        }
    }

    /** Whether any system-authored TOIL adjustment exists — what locks TOIL_FIELDS. */
    public static function toilCredited(): bool
    {
        return LeaveAdjustment::query()->whereNull('created_by')->whereNotNull('overtime_request_id')->exists();
    }

    /** The leave type TOIL is credited to (an earned one), if set. */
    public function toilLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'toil_leave_type_id');
    }
}
