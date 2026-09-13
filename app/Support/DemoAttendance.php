<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Placeholder attendance figures for the dashboard, used until a real
 * Attendance model / device-punch pipeline exists (see the disabled
 * "Attendance" sidebar item). Ratios are fixed demo percentages applied to
 * the real employee/department data so numbers stay proportional to actual
 * headcount. Replace this class with real queries once attendance tracking
 * ships.
 */
class DemoAttendance
{
    /**
     * @return array<string, array{count: int, percent: float}>
     */
    public static function todayBreakdown(int $totalEmployees): array
    {
        if ($totalEmployees === 0) {
            $empty = ['count' => 0, 'percent' => 0.0];

            return ['present' => $empty, 'late' => $empty, 'leave' => $empty, 'absent' => $empty];
        }

        $present = (int) round($totalEmployees * 0.81);
        $late = (int) round($totalEmployees * 0.06);
        $leave = (int) round($totalEmployees * 0.09);
        $absent = max(0, $totalEmployees - $present - $late - $leave);

        $percent = fn (int $count): float => round($count / $totalEmployees * 100, 1);

        return [
            'present' => ['count' => $present, 'percent' => $percent($present)],
            'late' => ['count' => $late, 'percent' => $percent($late)],
            'leave' => ['count' => $leave, 'percent' => $percent($leave)],
            'absent' => ['count' => $absent, 'percent' => $percent($absent)],
        ];
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    public static function weeklyTrend(): array
    {
        return [
            ['label' => 'Mon', 'value' => 96],
            ['label' => 'Tue', 'value' => 94],
            ['label' => 'Wed', 'value' => 97],
            ['label' => 'Thu', 'value' => 92],
            ['label' => 'Fri', 'value' => 95],
        ];
    }

    /**
     * Assigns each employee a single status for today, in the exact counts
     * from todayBreakdown(). This is the one source of truth both
     * needsAttention() and recentActivity() read from, so an employee marked
     * absent here can never also show up "checked in" in the activity feed.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, array{count: int, percent: float}>  $todayBreakdown
     * @return Collection<int, array{employee: Employee, status: string}>
     */
    public static function assignStatuses(Collection $employees, array $todayBreakdown): Collection
    {
        $employees = $employees->values();
        $assigned = collect();
        $cursor = 0;

        foreach (['late', 'absent', 'leave', 'present'] as $status) {
            $count = $todayBreakdown[$status]['count'];

            foreach ($employees->slice($cursor, $count) as $employee) {
                $assigned->push(['employee' => $employee, 'status' => $status]);
            }

            $cursor += $count;
        }

        return $assigned;
    }

    /**
     * @param  Collection<int, array{employee: Employee, status: string}>  $assigned
     * @return list<array{name: string, status: string, time: ?string}>
     */
    public static function needsAttention(Collection $assigned): array
    {
        $lateTimes = ['09:12 AM', '09:24 AM', '09:05 AM', '09:31 AM'];
        $lateIndex = 0;

        return $assigned
            ->filter(fn ($row) => in_array($row['status'], ['late', 'absent'], true))
            ->values()
            ->map(function ($row) use ($lateTimes, &$lateIndex) {
                $isLate = $row['status'] === 'late';
                $time = $isLate ? $lateTimes[$lateIndex % count($lateTimes)] : null;

                if ($isLate) {
                    $lateIndex++;
                }

                return ['name' => $row['employee']->full_name, 'status' => $row['status'], 'time' => $time];
            })
            ->all();
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return list<array{name: string, employees: int, attendance: int}>
     */
    public static function departmentAttendance(Collection $departments): array
    {
        $rates = [94, 91, 87, 96, 90, 93];

        return $departments->values()->map(fn ($department, $index) => [
            'name' => $department->name,
            'employees' => $department->employees_count,
            'attendance' => $rates[$index % count($rates)],
        ])->all();
    }

    /**
     * Only statuses that correspond to a real event (a punch or a leave
     * request) produce an activity row — an absent employee has no event to
     * show, so they never appear here.
     *
     * @param  Collection<int, array{employee: Employee, status: string}>  $assigned
     * @return list<array{name: string, action: string, time: string, tone: string}>
     */
    public static function recentActivity(Collection $assigned, int $limit = 4): array
    {
        $templates = [
            'present' => ['action' => 'Checked in', 'tone' => 'present'],
            'late' => ['action' => 'Marked as late', 'tone' => 'late'],
            'leave' => ['action' => 'Leave request submitted', 'tone' => 'leave'],
        ];
        $times = ['08:42 AM', '08:15 AM', '08:03 AM', '07:58 AM', '07:51 AM', '07:47 AM'];

        return $assigned
            ->filter(fn ($row) => array_key_exists($row['status'], $templates))
            ->take($limit)
            ->values()
            ->map(fn ($row, $index) => [
                'name' => $row['employee']->full_name,
                'action' => $templates[$row['status']]['action'],
                'time' => $times[$index % count($times)],
                'tone' => $templates[$row['status']]['tone'],
            ])
            ->all();
    }
}
