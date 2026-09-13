<?php

namespace App\Livewire\Departments;

use App\Models\Department;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?Department $editing = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);
    }

    public function create(): void
    {
        $this->authorize('create', Department::class);

        $this->reset(['name', 'description', 'editing']);
        $this->showModal = true;
    }

    public function edit(Department $department): void
    {
        $this->authorize('update', $department);

        $this->editing = $department;
        $this->name = $department->name;
        $this->description = $department->description;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing ?? Department::class);

        $this->validate();

        $data = ['name' => $this->name, 'description' => $this->description];

        if ($this->editing) {
            $this->editing->update($data);
        } else {
            Department::create($data);
        }

        $this->showModal = false;
        $this->reset(['name', 'description', 'editing']);
    }

    public function delete(Department $department): void
    {
        $this->authorize('delete', $department);

        // withTrashed() matters here: a deactivated (soft-deleted) employee still
        // physically occupies the row that the department_id foreign key
        // (restrictOnDelete) points at, so excluding trashed rows would let this
        // check pass while the DB delete still fails with an unhandled exception.
        if ($department->employees()->withTrashed()->exists()) {
            $this->addError('delete', 'Cannot delete a department that still has employees assigned.');

            return;
        }

        $department->delete();
    }

    public function render()
    {
        return view('livewire.departments.index', [
            'departments' => Department::withCount('employees')->orderBy('name')->paginate(10, ['*'], 'departmentsPage'),
        ]);
    }
}
