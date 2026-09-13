<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    public bool $showModal = false;

    public ?Employee $editing = null;

    public string $full_name = '';

    public string $employee_code = '';

    public ?int $department_id = null;

    public ?int $position_id = null;

    public string $join_date = '';

    public string $device_user_id = '';

    public string $status = 'active';

    public bool $create_user = false;

    public string $email = '';

    public string $role = 'employee';

    public ?string $generatedPassword = null;

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

    protected function rules(): array
    {
        $employeeId = $this->editing?->id;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'employee_code' => ['required', 'string', 'max:50', 'unique:employees,employee_code,'.$employeeId],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['required', 'exists:positions,id'],
            'join_date' => ['required', 'date'],
            'device_user_id' => ['nullable', 'string', 'max:50', 'unique:employees,device_user_id,'.$employeeId],
            'status' => ['required', 'in:active,inactive'],
            'create_user' => ['boolean'],
            'email' => ['required_if:create_user,true', 'nullable', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required_if:create_user,true', 'nullable', 'in:admin,manager,employee'],
        ];
    }

    public function create(): void
    {
        $this->authorize('create', Employee::class);

        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(Employee $employee): void
    {
        $this->authorize('update', $employee);

        $this->resetForm();

        $this->editing = $employee;
        $this->full_name = $employee->full_name;
        $this->employee_code = $employee->employee_code;
        $this->department_id = $employee->department_id;
        $this->position_id = $employee->position_id;
        $this->join_date = $employee->join_date?->format('Y-m-d') ?? '';
        $this->device_user_id = $employee->device_user_id ?? '';
        $this->status = $employee->status;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing ?? Employee::class);

        $this->validate();

        $data = [
            'full_name' => $this->full_name,
            'employee_code' => $this->employee_code,
            'department_id' => $this->department_id,
            'position_id' => $this->position_id,
            'join_date' => $this->join_date,
            'device_user_id' => $this->device_user_id ?: null,
            'status' => $this->status,
        ];

        if ($this->create_user && ! ($this->editing?->user_id)) {
            $password = Str::password(12);

            $user = User::create([
                'name' => $this->full_name,
                'email' => $this->email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            $user->assignRole($this->role);
            $data['user_id'] = $user->id;
            $this->generatedPassword = $password;
        }

        if ($this->editing) {
            $this->editing->update($data);
        } else {
            $this->editing = Employee::create($data);
        }

        // Keep the modal open when a password was just generated so it can be
        // shown once — closing here would lose credentials that can't be retrieved later.
        if ($this->generatedPassword) {
            return;
        }

        $this->showModal = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editing', 'full_name', 'employee_code', 'department_id', 'position_id',
            'join_date', 'device_user_id', 'create_user', 'email', 'generatedPassword',
        ]);
        $this->status = 'active';
        $this->role = 'employee';
        $this->resetErrorBag();
    }

    public function deactivate(Employee $employee): void
    {
        $this->authorize('delete', $employee);

        $employee->update(['status' => 'inactive']);
        $employee->delete();
    }

    public function render()
    {
        $employees = Employee::query()
            // Deactivated employees are soft-deleted, so withTrashed() is needed for the
            // "All status" / "Inactive" filter to be able to surface them at all.
            ->withTrashed()
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
            'positions' => Position::orderBy('name')->get(),
            'stats' => [
                'total_employees' => Employee::withTrashed()->count(),
                'active_employees' => Employee::where('status', 'active')->count(),
                // Deactivated employees are soft-deleted, so they need onlyTrashed()
                // to be counted at all — where('status', 'inactive') alone always
                // returns 0 since the default query scope excludes trashed rows.
                'inactive_employees' => Employee::onlyTrashed()->count(),
            ],
        ])->layout('layouts.app', ['header' => 'Employees']);
    }
}
