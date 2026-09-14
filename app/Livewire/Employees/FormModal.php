<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Create/Edit employee form, shared by the Employees list page and the
 * employee detail page. Both embed this component and trigger it via the
 * 'create-employee' / 'edit-employee' browser events rather than calling
 * create()/edit() directly, so it works the same whether it's sitting next
 * to the list or the detail view.
 */
class FormModal extends Component
{
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

    public string $username = '';

    public string $email = '';

    public string $role = 'employee';

    public ?string $generatedPassword = null;

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
            'username' => ['required_if:create_user,true', 'nullable', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required_if:create_user,true', 'nullable', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required_if:create_user,true', 'nullable', 'in:admin,manager,employee'],
        ];
    }

    #[On('create-employee')]
    public function create(): void
    {
        $this->authorize('create', Employee::class);

        $this->resetForm();
        $this->showModal = true;
    }

    #[On('edit-employee')]
    public function edit($id): void
    {
        $employee = Employee::findOrFail($id);

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

    public function updatedCreateUser(bool $value): void
    {
        if ($value && $this->username === '') {
            $this->username = $this->employee_code;
        }
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
                'username' => $this->username,
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

        // Lets any page embedding this modal (the list, the detail page) refresh
        // the employee data it's displaying.
        $this->dispatch('employee-saved');

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
            'join_date', 'device_user_id', 'create_user', 'username', 'email', 'generatedPassword',
        ]);
        $this->status = 'active';
        $this->role = 'employee';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.employees.form-modal', [
            'departments' => Department::orderBy('name')->get(),
            'positions' => Position::orderBy('name')->get(),
        ]);
    }
}
