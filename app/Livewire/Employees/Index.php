<?php

namespace App\Livewire\Employees;

use App\Livewire\Attributes\QueryParam;
use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeScope;
use App\Support\ListUrl;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    /** 25 a page (F32, Phase 6f): 10 made a 112-employee directory twelve pages long. */
    public const PER_PAGE = 25;

    use WithPagination;

    #[QueryParam(as: 'q', history: true)]
    public string $search = '';

    #[QueryParam(as: 'department', history: true)]
    public string $departmentFilter = '';

    #[QueryParam(as: 'status', history: true)]
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

        // A department or status the URL got wrong is ignored (Phase 6h).
        if (! ctype_digit($this->departmentFilter)) {
            $this->departmentFilter = '';
        }

        if (! in_array($this->statusFilter, ['active', 'inactive'], true)) {
            $this->statusFilter = '';
        }
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
        // The list as the user has it now, for a profile's way back (ListUrl, Phase 6i).
        ListUrl::remember('employees.index', [
            'q' => $this->search,
            'department' => $this->departmentFilter,
            'status' => $this->statusFilter,
            'page' => $this->getPage() > 1 ? (string) $this->getPage() : '',
        ]);

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
            ->paginate(self::PER_PAGE);

        $user = auth()->user();

        return view('livewire.employees.index', [
            'employees' => $employees,
            // F24: the card says whose list it is — the whole company only when the scope is.
            'listTitle' => $scope->ids === null ? 'All employees' : 'Your team',
            // No Actions column when no row on the page has an action (a manager's team).
            'showActions' => $employees->contains(fn (Employee $employee) => $user->can('update', $employee) || $user->can('deactivate', $employee)),
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
