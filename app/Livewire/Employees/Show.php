<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public Employee $employee;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);

        $this->employee = $employee->load(['department', 'position']);
    }

    /**
     * The edit form lives in the sibling FormModal component; when it saves,
     * refresh the employee data shown on this page without navigating away.
     */
    #[On('employee-saved')]
    public function refreshEmployee(): void
    {
        $this->employee->refresh()->load(['department', 'position']);
    }

    public function render()
    {
        // Every request ends here — re-assert access rather than trusting Livewire to keep $employee pinned,
        // and so a page opened while authorised stops serving data once access is revoked (see CLAUDE.md, Authorization).
        $this->authorize('view', $this->employee);

        return view('livewire.employees.show')
            ->layout('layouts.app', [
                'breadcrumbs' => [
                    ['label' => 'Employees', 'route' => route('employees.index')],
                    ['label' => $this->employee->full_name],
                ],
            ]);
    }
}
