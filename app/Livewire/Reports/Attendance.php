<?php

namespace App\Livewire\Reports;

use Livewire\Component;

/**
 * Reports → Attendance (Phase 5b): the monthly attendance report — an admin
 * for everyone, a manager for their team. Gated by the reports.attendance
 * ability (ReportPolicy), which the Reports page also shows the tab by.
 */
class Attendance extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.attendance');
    }

    public function render()
    {
        $this->authorize('reports.attendance');

        return view('livewire.reports.attendance');
    }
}
