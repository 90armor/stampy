<?php

namespace App\Support;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use Carbon\CarbonInterface;

/**
 * An employee's credited overtime for a month (Phase 4d) — the builder's rows,
 * never a re-derivation: minutes per category, split by the request's
 * compensation. Shared by the Overtime page, the employee dashboard and the
 * profile, so they can't disagree. Rates are the current settings', shown
 * beside the minutes; the minutes themselves never depend on them.
 */
final class OvertimeSummary
{
    /** The categories in precedence order, with their labels and rate columns. */
    public const CATEGORIES = [
        'workday' => ['Workday', 'workday_rate_percent'],
        'night' => ['Night', 'night_rate_percent'],
        'rest_day' => ['Rest day', 'rest_day_rate_percent'],
        'holiday' => ['Holiday', 'holiday_rate_percent'],
    ];

    /**
     * @return array{pay: array<string, int>, time_off: array<string, int>, total: int, pending: int}
     */
    public static function forMonth(Employee $employee, CarbonInterface $month): array
    {
        $empty = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $result = ['pay' => $empty, 'time_off' => $empty];

        $rows = DailyAttendance::query()
            ->join('overtime_requests', 'overtime_requests.id', '=', 'daily_attendances.overtime_request_id')
            ->where('daily_attendances.employee_id', $employee->id)
            ->whereBetween('daily_attendances.work_date', [$month->copy()->startOfMonth()->format('Y-m-d'), $month->copy()->endOfMonth()->format('Y-m-d')])
            ->selectRaw('overtime_requests.compensation as compensation, sum(overtime_workday_minutes) as workday, sum(overtime_night_minutes) as night, sum(overtime_rest_day_minutes) as rest_day, sum(overtime_holiday_minutes) as holiday')
            ->groupBy('overtime_requests.compensation')
            ->get();

        foreach ($rows as $row) {
            $key = $row->compensation === OvertimeCompensation::TimeOff->value ? 'time_off' : 'pay';

            foreach (array_keys(self::CATEGORIES) as $category) {
                $result[$key][$category] = (int) $row->{$category};
            }
        }

        return [
            ...$result,
            'total' => array_sum($result['pay']) + array_sum($result['time_off']),
            'pending' => OvertimeRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', OvertimeStatus::Pending->value)
                ->count(),
        ];
    }

    /**
     * "Workday (150%) 3h 20m · Night (200%) 1h 00m" — the categories with any
     * minutes, each with its current rate.
     *
     * @param  array<string, int>  $minutes  category => minutes
     */
    public static function categoryLine(array $minutes): string
    {
        $settings = OvertimeSettings::current();

        return collect(self::CATEGORIES)
            ->filter(fn (array $category, string $key) => ($minutes[$key] ?? 0) > 0)
            ->map(fn (array $category, string $key) => "{$category[0]} ({$settings->{$category[1]}}%) ".Duration::format($minutes[$key]))
            ->implode(' · ');
    }
}
