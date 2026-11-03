<?php

namespace App\Livewire\OvertimeSettings;

use App\Enums\LeaveBalanceSource;
use App\Exceptions\InvalidOvertimeSettingsException;
use App\Exceptions\OvertimeSettingsLockedException;
use App\Models\LeaveType;
use App\Models\OvertimeSettings;
use Livewire\Component;

/**
 * Policies → Overtime (Phase 4d): the one overtime settings row, grouped as
 * HR reads it — rates, when (night window, weekly rest day), limits,
 * requests, time off in lieu. The rules live on the model (OvertimeSettings):
 * a refused save shows the model's own message under its field, and the TOIL
 * fields are read-only, with the reason, once time off in lieu has been
 * credited (the 4b lock). The help text says what each change affects.
 */
class Edit extends Component
{
    public int $workday_rate_percent = 150;

    public int $night_rate_percent = 200;

    public int $rest_day_rate_percent = 200;

    public int $holiday_rate_percent = 200;

    /** 'HH:MM'. */
    public string $night_starts = '22:00';

    public string $night_ends = '05:00';

    public int $weekly_rest_day = 7;

    /**
     * The daily limits, entered and shown in hours (F25) — '2', '1.5', in
     * quarter hours — and stored as minutes, as before. A stored value that
     * isn't a whole quarter hour shows exactly ('1 h 50 min') and is kept
     * as it is while the field is left alone.
     */
    public string $max_overtime_hours = '2';

    public string $max_work_hours = '10';

    public int $claim_window_days = 7;

    public int $toil_ratio_percent = 100;

    public int $toil_block_minutes = 240;

    /** A leave type id, or '' for none. */
    public string $toil_leave_type_id = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $settings = OvertimeSettings::current();
        $this->authorize('view', $settings);

        $this->fillFrom($settings);
    }

    public function save(): void
    {
        $settings = OvertimeSettings::current();
        $this->authorize('update', $settings);

        $whole = ['required', 'integer', 'min:1', 'max:65535'];
        $this->validate([
            'workday_rate_percent' => $whole,
            'night_rate_percent' => $whole,
            'rest_day_rate_percent' => $whole,
            'holiday_rate_percent' => $whole,
            'night_starts' => ['required', 'date_format:H:i'],
            'night_ends' => ['required', 'date_format:H:i'],
            'weekly_rest_day' => ['required', 'integer', 'between:1,7'],
            'claim_window_days' => $whole,
            'toil_ratio_percent' => $whole,
            'toil_block_minutes' => $whole,
            'toil_leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
        ]);

        $limits = [];
        foreach (self::LIMITS as $property => [$column, $label]) {
            $limits[$column] = $this->minutesFrom($property, $label, (int) $settings->{$column});
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $data = [
            'workday_rate_percent' => $this->workday_rate_percent,
            'night_rate_percent' => $this->night_rate_percent,
            'rest_day_rate_percent' => $this->rest_day_rate_percent,
            'holiday_rate_percent' => $this->holiday_rate_percent,
            'night_starts' => "{$this->night_starts}:00",
            'night_ends' => "{$this->night_ends}:00",
            'weekly_rest_day' => $this->weekly_rest_day,
            ...$limits,
            'claim_window_days' => $this->claim_window_days,
        ];

        // Locked once time off in lieu has been credited: never sent, so never changed.
        if (! OvertimeSettings::toilCredited()) {
            $data += [
                'toil_ratio_percent' => $this->toil_ratio_percent,
                'toil_block_minutes' => $this->toil_block_minutes,
                'toil_leave_type_id' => filled($this->toil_leave_type_id) ? (int) $this->toil_leave_type_id : null,
            ];
        }

        try {
            $settings->update($data);
        } catch (InvalidOvertimeSettingsException $e) {
            // A limit's own rule lands on its hours field.
            $field = collect(self::LIMITS)->search(fn (array $limit) => $limit[0] === $e->field) ?: $e->field;
            $this->addError($field ?? 'form', $e->getMessage());

            return;
        } catch (OvertimeSettingsLockedException $e) {
            $this->addError('toil', $e->getMessage());

            return;
        }

        $this->fillFrom(OvertimeSettings::current());
        $this->notice = 'Saved the overtime settings.';
    }

    /** property => [column, the field's label in messages] */
    private const LIMITS = [
        'max_overtime_hours' => ['max_overtime_minutes_per_day', 'Overtime a day'],
        'max_work_hours' => ['max_work_minutes_per_day', 'Work a day'],
    ];

    /** The longest a daily limit can be: a day. */
    private const MAX_LIMIT_HOURS = 24;

    /**
     * A limit field's minutes, or $stored if the field still shows the
     * stored value exactly; null, with an error on the field, when it isn't
     * a number of quarter hours from a quarter hour to a day.
     */
    private function minutesFrom(string $property, string $label, int $stored): ?int
    {
        $value = trim($this->{$property});

        if ($value === self::hours($stored)) {
            return $stored;
        }

        if ($value === '') {
            $this->addError($property, "Enter ".lcfirst($label)." in hours, such as 2 or 1.5.");

            return null;
        }

        $hours = preg_match('/^\d+(\.\d+)?$/', preg_replace('/\s*h$/i', '', $value)) ? (float) preg_replace('/\s*h$/i', '', $value) : null;
        $minutes = $hours !== null ? $hours * 60 : null;

        if ($minutes === null || fmod($minutes, 15) !== 0.0) {
            $this->addError($property, "Enter ".lcfirst($label)." in quarter hours, such as 2, 1.5 or 1.25.");
        } elseif ($minutes < 15) {
            $this->addError($property, "{$label} must be at least a quarter of an hour (0.25 h).");
        } elseif ($hours > self::MAX_LIMIT_HOURS) {
            $this->addError($property, "{$label} can be at most ".self::MAX_LIMIT_HOURS.' hours.');
        } else {
            return (int) $minutes;
        }

        return null;
    }

    /** Minutes as the field shows them: '2', '1.5', '0.25' — or exactly, '1 h 50 min', off a quarter hour. */
    public static function hours(int $minutes): string
    {
        if ($minutes % 15 === 0) {
            return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
        }

        return trim((intdiv($minutes, 60) > 0 ? intdiv($minutes, 60).' h ' : '').($minutes % 60).' min');
    }

    private function fillFrom(OvertimeSettings $settings): void
    {
        foreach (['workday_rate_percent', 'night_rate_percent', 'rest_day_rate_percent', 'holiday_rate_percent', 'weekly_rest_day',
            'claim_window_days', 'toil_ratio_percent', 'toil_block_minutes'] as $field) {
            $this->{$field} = (int) $settings->{$field};
        }

        foreach (self::LIMITS as $property => [$column]) {
            $this->{$property} = self::hours((int) $settings->{$column});
        }

        $this->night_starts = substr($settings->night_starts, 0, 5);
        $this->night_ends = substr($settings->night_ends, 0, 5);
        $this->toil_leave_type_id = $settings->toil_leave_type_id !== null ? (string) $settings->toil_leave_type_id : '';
    }

    public function render()
    {
        $this->authorize('view', OvertimeSettings::current());

        return view('livewire.overtime-settings.edit', [
            'toilLocked' => OvertimeSettings::toilCredited(),
            'earnedTypes' => LeaveType::query()->where('balance_source', LeaveBalanceSource::Earned->value)->orderBy('name')->get(),
            'weekdays' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
        ]);
    }
}
