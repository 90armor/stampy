<?php

namespace App\Livewire\Reports;

use App\Support\AttendanceReport;
use App\Support\DisplayDate;
use App\Support\EmployeeScope;
use App\Support\LeaveDays;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports → Attendance (Phase 5b): the monthly attendance report
 * (AttendanceReport) — an admin for everyone, a manager for their team.
 * Every row is that employee's My attendance summary for the month. Gated by
 * the reports.attendance ability (ReportPolicy), which the Reports page also
 * shows the tab by; its month follows the other tabs' (the report-month
 * event). The CSV has the same rows and totals, durations in whole minutes
 * so HR can sum them, and says when it was exported, for which month,
 * department and whose view — and whether the month was still open.
 */
class Attendance extends Component
{
    /** 'Y-m'. */
    #[Url(as: 'month', history: true)]
    public string $month = '';

    /** A department id, or '' for all. One outside the viewer's options is ignored. */
    #[Url(as: 'department', history: true)]
    public string $department = '';

    public function mount(): void
    {
        $this->authorize('reports.attendance');

        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }
    }

    public function updatedMonth(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }

        $this->dispatch('report-month', month: $this->month);
    }

    /** Another report tab changed the month: show the same one. */
    #[On('report-month')]
    public function followMonth(string $month): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->month = $month;
        }
    }

    public function download(): StreamedResponse
    {
        $this->authorize('reports.attendance');

        $month = $this->selectedMonth();
        $report = $this->report();
        $department = $report['department'] !== null ? $report['departments'][$report['department']] : null;
        $exportedAt = now();
        $user = auth()->user();
        $view = EmployeeScope::for($user, 'Attendance report CSV')->ids === null ? 'All employees' : 'Team of '.$user->employee->full_name;

        $line = fn (array $summary) => [
            $summary['workdays'], $summary['present'], $summary['absent'], $summary['incomplete'], $summary['leave'],
            LeaveDays::toDecimal($summary['leave_tenths']),
            $summary['days_off_worked'],
            AttendanceReport::lateDays($summary), $summary['late_minutes'],
            $summary['early_leave_days'], $summary['early_leave_minutes'],
            $summary['total_worked_minutes'], $summary['not_calculated'],
        ];

        return response()->streamDownload(function () use ($report, $month, $department, $exportedAt, $view, $line) {
            $out = fopen('php://output', 'w');
            // A UTF-8 byte-order mark: HR opens this in Excel, which otherwise
            // reads the file as ANSI and breaks "—" and Khmer or Myanmar names.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Attendance report, '.DisplayDate::month($month).' — '.($department ?? 'All departments').' — '.$view.' — generated '.$exportedAt->format('Y-m-d H:i').' ('.config('app.timezone').')']);
            if ($report['open']) {
                fputcsv($out, ['This month is still open: figures can change until it ends.']);
            }
            fputcsv($out, ['The same counts as each employee\'s attendance summary. Workdays are scheduled workdays only; a day worked on a day off or holiday counts under Days off worked. Durations in whole minutes; leave taken in days. Joined and Left only when inside the month.']);
            fputcsv($out, ['Code', 'Name', 'Department', 'Joined', 'Left', 'Calculated workdays', 'Present', 'Absent', 'Incomplete', 'On leave', 'Leave taken (days)', 'Days off worked', 'Late (days)', 'Late (minutes)', 'Early leave (days)', 'Early leave (minutes)', 'Worked (minutes)', 'Not calculated']);

            foreach ($report['rows'] as $row) {
                $employee = $row['employee'];
                fputcsv($out, [
                    $employee->employee_code, $employee->full_name, $employee->department?->name,
                    $row['joined']?->format('Y-m-d'), $row['left']?->format('Y-m-d'),
                    ...$line($row['summary']),
                ]);
            }

            fputcsv($out, ['Total', '', '', '', '', ...$line($report['totals'])]);
            fclose($out);
        }, 'attendance-'.$month->format('Y-m').($department !== null ? '-'.Str::slug($department) : '').'-exported-'.$exportedAt->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function selectedMonth(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $report = AttendanceReport::forMonth($this->selectedMonth(), auth()->user(), $this->department !== '' ? (int) $this->department : null);

        // A department the viewer can't filter by reads as no filter at all.
        if ($report['department'] === null) {
            $this->department = '';
        }

        return $report;
    }

    public function render()
    {
        $this->authorize('reports.attendance');

        $month = $this->selectedMonth();
        $options = [];
        for ($i = 0; $i < 13; $i++) {
            $option = today()->startOfMonth()->subMonthsNoOverflow($i);
            $options[$option->format('Y-m')] = DisplayDate::month($option);
        }

        return view('livewire.reports.attendance', [
            'report' => $this->report(),
            'monthLabel' => DisplayDate::month($month),
            'months' => $options,
        ]);
    }
}
