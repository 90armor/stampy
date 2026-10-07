<?php

namespace App\Livewire\Reports;

use App\Models\OvertimeSettings;
use App\Support\DisplayDate;
use App\Support\OvertimeReport;
use App\Support\OvertimeSummary;
use Carbon\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports → Overtime (Phase 4d): the monthly overtime report for payroll
 * (OvertimeReport) — on screen and as a CSV with the same columns. The CSV's
 * filename and first row carry when it was exported, and an open month says
 * so: a cancellation after payroll ran changes the figures, and HR must be
 * able to tell which export they paid from. Gated by the reports.overtime
 * ability (ReportPolicy), the same one the sidebar checks.
 */
class Overtime extends Component
{
    /** 'Y-m'. */
    #[Url(as: 'month', history: true)]
    public string $month = '';

    public function mount(): void
    {
        $this->authorize('reports.overtime');

        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = today()->format('Y-m');
        }
    }

    public function download(): StreamedResponse
    {
        $this->authorize('reports.overtime');

        $month = $this->selectedMonth();
        $report = OvertimeReport::forMonth($month);
        $exportedAt = now();
        $categories = OvertimeSummary::CATEGORIES;

        return response()->streamDownload(function () use ($report, $month, $exportedAt, $categories) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Overtime report, '.DisplayDate::month($month).' — generated '.$exportedAt->format('Y-m-d H:i').' ('.config('app.timezone').')']);
            if ($report['open']) {
                fputcsv($out, ['This month is still open: figures can change until it ends.']);
            }
            fputcsv($out, ['Only approved, credited minutes count. Minutes; pay-equivalent hours = sum of (minutes x rate) / 60, pay only.']);
            fputcsv($out, ['Code', 'Name', 'Department', ...array_map(fn ($category) => "Pay: {$category[0]} minutes", array_values($categories)), 'Time off minutes', 'Pay-equivalent hours']);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [$row['code'], $row['name'], $row['department'], ...array_values($row['pay']), $row['time_off'], number_format($row['pay_equivalent_hours'], 2, '.', '')]);
            }

            fputcsv($out, ['Total', '', '', ...array_values($report['totals']['pay']), $report['totals']['time_off'], number_format($report['totals']['pay_equivalent_hours'], 2, '.', '')]);
            fclose($out);
        }, 'overtime-'.$month->format('Y-m').'-exported-'.$exportedAt->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function selectedMonth(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    public function render()
    {
        $this->authorize('reports.overtime');

        $month = $this->selectedMonth();
        $options = [];
        for ($i = 0; $i < 13; $i++) {
            $option = today()->startOfMonth()->subMonthsNoOverflow($i);
            $options[$option->format('Y-m')] = DisplayDate::month($option);
        }

        return view('livewire.reports.overtime', [
            'report' => OvertimeReport::forMonth($month),
            'monthLabel' => DisplayDate::month($month),
            'months' => $options,
            'categories' => OvertimeSummary::CATEGORIES,
            'rates' => OvertimeSettings::current(),
        ])->layout('layouts.app', ['header' => 'Reports']);
    }
}
