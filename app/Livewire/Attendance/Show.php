<?php

namespace App\Livewire\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    public ?Employee $employee = null;

    public bool $noEmployeeRecord = false;

    #[Url(as: 'month', history: true)]
    public string $month = '';

    /**
     * Route-model-bound when reached via /attendance/{employee} (admin or a
     * manager viewing someone else); left null when reached via
     * /my-attendance, which has no {employee} segment at all — that case
     * resolves the viewer's own linked employee record instead.
     */
    public function mount(?Employee $employee = null): void
    {
        if ($employee === null) {
            $employee = auth()->user()->employee;

            if ($employee === null) {
                $this->noEmployeeRecord = true;

                return;
            }
        }

        $this->authorize('view', $employee);

        $this->employee = $employee->load(['department', 'position']);

        if ($this->month === '') {
            $this->month = today()->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonthNoOverflow()->format('Y-m');
    }

    private function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
    }

    /**
     * Every calendar day of the month paired with its daily_attendances row
     * (or null). A missing row means the builder hasn't run for that date
     * yet — it must never be conflated with "absent", which is why this
     * pads the whole month rather than only returning rows that exist.
     *
     * @return Collection<int, array{date: Carbon, record: ?DailyAttendance}>
     */
    private function calendarDays(): Collection
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();

        $existing = DailyAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereBetween('work_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get()
            ->keyBy(fn (DailyAttendance $row) => $row->work_date->format('Y-m-d'));

        $days = collect();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $days->push([
                'date' => $cursor->copy(),
                'record' => $existing->get($cursor->format('Y-m-d')),
            ]);

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Counts only — no derived or payroll-adjacent figures. "Workdays" is
     * simply present+late+absent+incomplete (i.e. every calculated day that
     * isn't off/holiday/leave); "Early leave" and "Total worked" are plain
     * counts/sums over the same calculated rows, not anything interpreted.
     *
     * @param  Collection<int, array{date: Carbon, record: ?DailyAttendance}>  $days
     * @return array<string, int>
     */
    private function summary(Collection $days): array
    {
        $records = $days->pluck('record')->filter();

        $counts = $records->countBy(fn (DailyAttendance $row) => $row->status->value);

        $workdayStatuses = [
            AttendanceStatus::Present->value,
            AttendanceStatus::Late->value,
            AttendanceStatus::Absent->value,
            AttendanceStatus::Incomplete->value,
        ];

        return [
            'workdays' => $records->whereIn('status', array_map(
                fn (string $value) => AttendanceStatus::from($value),
                $workdayStatuses
            ))->count(),
            'present' => $counts->get(AttendanceStatus::Present->value, 0),
            'late' => $counts->get(AttendanceStatus::Late->value, 0),
            'absent' => $counts->get(AttendanceStatus::Absent->value, 0),
            'incomplete' => $counts->get(AttendanceStatus::Incomplete->value, 0),
            'early_leave_days' => $records->filter(fn (DailyAttendance $row) => $row->early_leave_minutes > 0)->count(),
            'total_worked_minutes' => (int) $records->sum('worked_minutes'),
        ];
    }

    public function render()
    {
        // request()->routeIs(), not stored mount()-time state: mount() only
        // runs on the initial load, but render() runs on every subsequent
        // request too (e.g. clicking prev/next month), and the route name
        // is stable and available either way.
        $viaSelfView = request()->routeIs('attendance.mine');

        if ($this->employee === null) {
            return view('livewire.attendance.show')
                ->layout('layouts.app', ['header' => 'My attendance']);
        }

        $days = $this->calendarDays();

        // A plain employee reaching this page via /my-attendance can't
        // reach /attendance (role:admin|manager) at all, so a breadcrumb
        // linking back there would just be a dead end for them — use a
        // plain header instead, same as the admin/manager path uses
        // breadcrumbs (matching Employees\Show's convention).
        $layoutData = $viaSelfView
            ? ['header' => 'My attendance']
            : ['breadcrumbs' => [
                ['label' => 'Attendance', 'route' => route('attendance.index')],
                ['label' => $this->employee->full_name],
            ]];

        return view('livewire.attendance.show', [
            'days' => $days,
            'summary' => $this->summary($days),
            'monthLabel' => $this->monthStart()->format('F Y'),
            'isCurrentMonth' => $this->month === today()->format('Y-m'),
        ])->layout('layouts.app', $layoutData);
    }
}
