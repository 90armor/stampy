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

    public int $max_overtime_minutes_per_day = 120;

    public int $max_work_minutes_per_day = 600;

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
            'max_overtime_minutes_per_day' => $whole,
            'max_work_minutes_per_day' => $whole,
            'claim_window_days' => $whole,
            'toil_ratio_percent' => $whole,
            'toil_block_minutes' => $whole,
            'toil_leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
        ]);

        $data = [
            'workday_rate_percent' => $this->workday_rate_percent,
            'night_rate_percent' => $this->night_rate_percent,
            'rest_day_rate_percent' => $this->rest_day_rate_percent,
            'holiday_rate_percent' => $this->holiday_rate_percent,
            'night_starts' => "{$this->night_starts}:00",
            'night_ends' => "{$this->night_ends}:00",
            'weekly_rest_day' => $this->weekly_rest_day,
            'max_overtime_minutes_per_day' => $this->max_overtime_minutes_per_day,
            'max_work_minutes_per_day' => $this->max_work_minutes_per_day,
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
            $this->addError($e->field ?? 'form', $e->getMessage());

            return;
        } catch (OvertimeSettingsLockedException $e) {
            $this->addError('toil', $e->getMessage());

            return;
        }

        $this->fillFrom(OvertimeSettings::current());
        $this->notice = 'Saved the overtime settings.';
    }

    private function fillFrom(OvertimeSettings $settings): void
    {
        foreach (['workday_rate_percent', 'night_rate_percent', 'rest_day_rate_percent', 'holiday_rate_percent', 'weekly_rest_day',
            'max_overtime_minutes_per_day', 'max_work_minutes_per_day', 'claim_window_days', 'toil_ratio_percent', 'toil_block_minutes'] as $field) {
            $this->{$field} = (int) $settings->{$field};
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
