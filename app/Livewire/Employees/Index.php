<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'department', history: true)]
    public string $departmentFilter = '';

    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingDepartmentFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function deactivate(Employee $employee): void
    {
        $this->authorize('delete', $employee);

        // Status change only — the record (and its attendance history, once
        // that exists) is preserved. Never soft- or hard-delete here.
        $employee->update(['status' => 'inactive']);
    }

    public function reactivate(Employee $employee): void
    {
        $this->authorize('update', $employee);

        $employee->update(['status' => 'active']);
    }

    /**
     * The create/edit form lives in the sibling FormModal component; this is
     * just here so saving there re-renders this list with the fresh data.
     */
    #[On('employee-saved')]
    public function refreshEmployees(): void
    {
        //
    }

    public function render()
    {
        $employees = Employee::query()
            ->with(['department', 'position'])
            ->when($this->search, fn ($query) => $query->where(function ($query) {
                $query->where('full_name', 'like', "%{$this->search}%")
                    ->orWhere('employee_code', 'like', "%{$this->search}%");
            }))
            ->when($this->departmentFilter, fn ($query) => $query->where('department_id', $this->departmentFilter))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->orderBy('full_name')
            ->paginate(10);

        return view('livewire.employees.index', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->get(),
            'hasAnyEmployees' => Employee::query()->exists(),
            'stats' => [
                'total_employees' => Employee::count(),
                'active_employees' => Employee::where('status', 'active')->count(),
                'inactive_employees' => Employee::where('status', 'inactive')->count(),
            ],
        ])->layout('layouts.app', ['header' => 'Employees']);
    }
}
