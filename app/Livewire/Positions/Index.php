<?php

namespace App\Livewire\Positions;

use App\Models\Position;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public bool $showModal = false;

    public ?Position $editing = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Position::class);
    }

    public function create(): void
    {
        $this->authorize('create', Position::class);

        $this->reset(['name', 'description', 'editing']);
        $this->showModal = true;
    }

    public function edit(Position $position): void
    {
        $this->authorize('update', $position);

        $this->editing = $position;
        $this->name = $position->name;
        $this->description = $position->description;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'update' : 'create', $this->editing ?? Position::class);

        $this->validate();

        $data = ['name' => $this->name, 'description' => $this->description];

        if ($this->editing) {
            $this->editing->update($data);
        } else {
            Position::create($data);
        }

        $this->showModal = false;
        $this->reset(['name', 'description', 'editing']);
    }

    public function delete(Position $position): void
    {
        $this->authorize('delete', $position);

        // withTrashed() matters here: a deactivated (soft-deleted) employee still
        // physically occupies the row that the position_id foreign key
        // (restrictOnDelete) points at, so excluding trashed rows would let this
        // check pass while the DB delete still fails with an unhandled exception.
        if ($position->employees()->withTrashed()->exists()) {
            $this->addError('delete', 'Cannot delete a position that still has employees assigned.');

            return;
        }

        $position->delete();
    }

    public function render()
    {
        return view('livewire.positions.index', [
            'positions' => Position::withCount('employees')->orderBy('name')->paginate(10, ['*'], 'positionsPage'),
        ]);
    }
}
