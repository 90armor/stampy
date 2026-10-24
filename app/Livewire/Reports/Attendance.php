<?php

namespace App\Livewire\Reports;

use App\Support\AttendanceReport;
use App\Support\DisplayDate;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reports → Attendance (Phase 5b): the monthly attendance report
 * (AttendanceReport) — an admin for everyone, a manager for their team.
 * Every row is that employee's My attendance summary for the month. Gated by
 * the reports.attendance ability (ReportPolicy), which the Reports page also
 * shows the tab by; its month follows the other tabs' (the report-month
 * event).
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
