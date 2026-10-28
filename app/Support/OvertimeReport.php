<?php

namespace App\Support;

use App\Enums\OvertimeCompensation;
use App\Models\DailyAttendance;
use App\Models\OvertimeSettings;
use Carbon\CarbonInterface;

/**
 * The monthly overtime report (Phase 4d) — the one report Phase 4 needs, for
 * payroll. Only approved, credited minutes count: the builder's rows, which
 * carry an approved request's minutes and nothing else. One row per employee
 * with any that month: minutes per category on Pay requests, the total on
 * Time off requests (earned as leave, not paid), and the pay-equivalent hours
 * — Σ (category minutes × its current rate) ÷ 60, Pay only — which payroll
 * multiplies by the hourly wage. The app never computes money.
 */
final class OvertimeReport
{
    /**
     * @return array{rows: list<array{code: string, name: string, department: ?string, pay: array<string, int>, time_off: int, pay_equivalent_hours: float}>, totals: array{pay: array<string, int>, time_off: int, pay_equivalent_hours: float}, open: bool}
     */
    public static function forMonth(CarbonInterface $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $categories = array_keys(OvertimeSummary::CATEGORIES);
        $empty = array_fill_keys($categories, 0);

        $lines = DailyAttendance::query()
            ->join('overtime_requests', 'overtime_requests.id', '=', 'daily_attendances.overtime_request_id')
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->whereBetween('daily_attendances.work_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('employees.id as employee_id, employees.employee_code as code, employees.full_name as name, departments.name as department, overtime_requests.compensation as compensation,
                sum(overtime_workday_minutes) as workday, sum(overtime_night_minutes) as night, sum(overtime_rest_day_minutes) as rest_day, sum(overtime_holiday_minutes) as holiday')
            ->groupBy('employees.id', 'employees.employee_code', 'employees.full_name', 'departments.name', 'overtime_requests.compensation')
            ->orderBy('employees.employee_code')
            ->get();

        $rows = [];

        foreach ($lines as $line) {
            $rows[$line->employee_id] ??= ['code' => $line->code, 'name' => $line->name, 'department' => $line->department, 'pay' => $empty, 'time_off' => 0];

            if ($line->compensation === OvertimeCompensation::TimeOff->value) {
                $rows[$line->employee_id]['time_off'] += array_sum(array_map(fn ($category) => (int) $line->{$category}, $categories));
            } else {
                foreach ($categories as $category) {
                    $rows[$line->employee_id]['pay'][$category] += (int) $line->{$category};
                }
            }
        }

        $rows = array_values(array_filter(
            array_map(fn (array $row) => [...$row, 'pay_equivalent_hours' => self::payEquivalentHours($row['pay'])], $rows),
            fn (array $row) => array_sum($row['pay']) + $row['time_off'] > 0,
        ));

        $totals = ['pay' => $empty, 'time_off' => 0];
        foreach ($rows as $row) {
            foreach ($categories as $category) {
                $totals['pay'][$category] += $row['pay'][$category];
            }
            $totals['time_off'] += $row['time_off'];
        }
        $totals['pay_equivalent_hours'] = self::payEquivalentHours($totals['pay']);

        return ['rows' => $rows, 'totals' => $totals, 'open' => $to->gte(today())];
    }

    /**
     * Σ (category minutes × its current rate) ÷ 60, in hours to two places.
     *
     * @param  array<string, int>  $pay
     */
    public static function payEquivalentHours(array $pay): float
    {
        $settings = OvertimeSettings::current();
        $weighted = 0;

        foreach (OvertimeSummary::CATEGORIES as $category => [, $rateColumn]) {
            $weighted += ($pay[$category] ?? 0) * $settings->{$rateColumn};
        }

        return round($weighted / 100 / 60, 2);
    }
}
