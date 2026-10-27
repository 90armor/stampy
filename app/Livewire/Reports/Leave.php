<?php

namespace App\Livewire\Reports;

use App\Models\LeaveType;
use App\Support\LeaveReport;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reports → Leave (Phase 5b): the yearly leave report (LeaveReport) for one
 * leave type — an admin for everyone, a manager for their team. Every row is
 * that employee's balance as Time off and the profile show it. Gated by the
 * reports.leave ability (ReportPolicy), which the Reports page also shows the
 * tab by. Its year and department are its own (?year=, ?leave-department=):
 * the tabs share only the month.
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
