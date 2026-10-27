<?php

namespace App\Livewire\Reports;

use App\Models\LeaveType;
use App\Support\EmployeeScope;
use App\Support\LeaveDays;
use App\Support\LeaveReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports → Leave (Phase 5b): the yearly leave report (LeaveReport) for one
 * leave type — an admin for everyone, a manager for their team. Every row is
 * that employee's balance as Time off and the profile show it. Gated by the
 * reports.leave ability (ReportPolicy), which the Reports page also shows the
 * tab by. Its year and department are its own (?year=, ?leave-department=):
 * the tabs share only the month. The CSV has every type, one row per
 * employee and type with a Total row after each type, in decimal days, and
 * says when it was exported, for which year, department and whose view.
 */
class Leave extends Component
{
    #[Url(as: 'year', history: true)]
    public string $year = '';

    /** A leave type id; '' picks the default (the first type granted yearly — Annual). */
    #[Url(as: 'type', history: true)]
    public string $type = '';

    /** A department id, or '' for all. One outside the viewer's options is ignored. */
    #[Url(as: 'leave-department', history: true)]
    public string $department = '';

    public function mount(): void
    {
        $this->authorize('reports.leave');
    }

    public function download(): StreamedResponse
    {
        $this->authorize('reports.leave');

        $year = $this->selectedYear();
        $user = auth()->user();
        $reports = LeaveReport::types($year)->map(fn (LeaveType $type) => [$type, $this->report($year, $type)]);
        $first = $reports->first()[1] ?? null;
        $department = $first !== null && $first['department'] !== null ? $first['departments'][$first['department']] : null;
        $exportedAt = now();
        $view = EmployeeScope::for($user, 'Leave report CSV')->ids === null ? 'All employees' : 'Team of '.$user->employee->full_name;
        $decimal = fn (int $tenths) => LeaveDays::toDecimal($tenths);

        return response()->streamDownload(function () use ($reports, $year, $department, $exportedAt, $view, $decimal) {
            $out = fopen('php://output', 'w');
            // A UTF-8 byte-order mark: HR opens this in Excel, which otherwise
            // reads the file as ANSI and breaks "—" and Khmer or Myanmar names.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Leave report, '.$year.' — '.($department ?? 'All departments').' — '.$view.' — generated '.$exportedAt->format('Y-m-d H:i').' ('.config('app.timezone').')']);
            fputcsv($out, ['Pending is as of the export time.']);
            fputcsv($out, ['Each row is the employee\'s balance as Time off shows it, in days. A type with no balance has only its used and pending days. Time off in lieu lists only employees who have earned some. Joined and Left only when inside the year.']);
            fputcsv($out, ['Code', 'Name', 'Department', 'Joined', 'Left', 'Type', 'Balance', 'Entitled', 'Carried in', 'Adjustments', 'Used', 'Pending', 'Available', 'Usable from']);

            foreach ($reports as [$type, $report]) {
                // A type nobody has (Time off in lieu before anyone earns
                // it) adds nothing, not a Total of zeros.
                if ($report['rows'] === []) {
                    continue;
                }
                $hasBalance = $report['hasBalance'];

                foreach ($report['rows'] as $row) {
                    $employee = $row['employee'];
                    $balance = $row['balance'];
                    fputcsv($out, [
                        $employee->employee_code, $employee->full_name, $employee->department?->name,
                        $row['joined']?->format('Y-m-d'), $row['left']?->format('Y-m-d'),
                        $type->name, $hasBalance ? 'Yes' : 'No',
                        $hasBalance ? $decimal($balance->entitled) : '',
                        $hasBalance ? $decimal($balance->carriedIn) : '',
                        $hasBalance ? $decimal($balance->adjustments) : '',
                        $decimal($balance->used), $decimal($balance->pending),
                        $hasBalance ? $decimal($balance->available()) : '',
                        $balance->usableFrom?->format('Y-m-d'),
                    ]);
                }

                $totals = $report['totals'];
                fputcsv($out, [
                    'Total', '', '', '', '', $type->name, $hasBalance ? 'Yes' : 'No',
                    $hasBalance ? $decimal($totals['entitled']) : '',
                    $hasBalance ? $decimal($totals['carriedIn']) : '',
                    $hasBalance ? $decimal($totals['adjustments']) : '',
                    $decimal($totals['used']), $decimal($totals['pending']),
                    $hasBalance ? $decimal($totals['available']) : '',
                    '',
                ]);
            }

            fclose($out);
        }, 'leave-'.$year.($department !== null ? '-'.Str::slug($department) : '').'-exported-'.$exportedAt->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The year asked for, if offered; else this year. */
    private function selectedYear(): int
    {
        $year = (int) $this->year;

        return in_array($year, LeaveReport::years(), true) ? $year : today()->year;
    }

    /**
     * The type asked for, if offered that year; else the first type granted
     * yearly (Annual), else the first.
     *
     * @param  Collection<int, LeaveType>  $types
     */
    private function selectedType(Collection $types): LeaveType
    {
        return $types->firstWhere('id', (int) $this->type)
            ?? $types->first(fn (LeaveType $type) => $type->isGrantedYearly())
            ?? $types->first();
    }

    /** @return array<string, mixed> */
    private function report(int $year, LeaveType $type): array
    {
        $report = LeaveReport::forYear($year, $type, auth()->user(), $this->department !== '' ? (int) $this->department : null);

        // A department the viewer can't filter by reads as no filter at all.
        if ($report['department'] === null) {
            $this->department = '';
        }

        return $report;
    }

    public function render()
    {
        $this->authorize('reports.leave');

        $year = $this->selectedYear();
        $types = LeaveReport::types($year);
        $type = $types->isEmpty() ? null : $this->selectedType($types);
        $this->year = (string) $year;
        $this->type = (string) $type?->id;

        return view('livewire.reports.leave', [
            'report' => $type ? $this->report($year, $type) : null,
            'selectedYear' => $year,
            'selectedType' => $type,
            'years' => LeaveReport::years(),
            'types' => $types,
        ]);
    }
}
