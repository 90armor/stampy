<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeScope;
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

    public function resetFilters(): void
    {
        $this->reset(['search', 'departmentFilter', 'statusFilter']);
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Employee::class);
    }

    /**
     * The create/edit form (FormModal) and deactivate/reactivate
     * (StatusModal) live in sibling components; this is just here so saving
     * there re-renders this list with the fresh data.
     */
    #[On('employee-saved')]
    public function refreshEmployees(): void
    {
        //
    }

    public function render()
    {
        $scope = EmployeeScope::for(auth()->user(), 'Employee directory');

        // Everything on this page — rows, stats, the department filter — is
        // drawn from the same scope, so no count or option describes anyone the
        // viewer couldn't open. Admin: unrestricted.
        $visible = fn () => Employee::query()->when($scope->ids !== null, fn ($query) => $query->whereIn('id', $scope->ids));

        $employees = $visible()
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
            'departments' => Department::query()
                ->when($scope->ids !== null, fn ($query) => $query->whereHas('employees', fn ($q) => $q->whereIn('id', $scope->ids)))
                ->orderBy('name')
                ->get(),
            'hasAnyEmployees' => $visible()->exists(),
            'scopeHasNoEmployeeRecord' => $scope->hasNoEmployeeRecord,
            'filtersActive' => $this->search !== '' || $this->departmentFilter !== '' || $this->statusFilter !== '',
            'stats' => [
                'total_employees' => $visible()->count(),
                'active_employees' => $visible()->where('status', 'active')->count(),
                'inactive_employees' => $visible()->where('status', 'inactive')->count(),
            ],
        ])->layout('layouts.app', ['header' => 'Employees']);
    }
}
