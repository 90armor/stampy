<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Support\ListUrl;
use App\Support\UsernameCodeCollision;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public Employee $employee;

    public bool $showUsernameModal = false;

    public string $username = '';

    public ?string $notice = null;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);

        $this->employee = $employee->load(['department', 'position', 'manager', 'user']);
    }

    /**
     * The edit form lives in the sibling FormModal component; when it saves,
     * refresh the employee data shown on this page without navigating away.
     */
    #[On('employee-saved')]
    public function refreshEmployee(): void
    {
        $this->employee->refresh()->load(['department', 'position', 'manager', 'user']);
    }

    /**
     * The Login card's Change username (Phase 6i): an admin renames any linked
     * login, an admin's own included, under the employee form's rules — unique
     * and never another employee's code, in any case (Phase 6g); their own
     * code is allowed. Sign-in finds the new name at once, and nothing else
     * changes: sessions and "remember me" stay as they were.
     */
    public function openUsername(): void
    {
        $this->authorize('update', $this->employee);

        $user = $this->employee->user()->first();
        if ($user === null) {
            return;
        }

        $this->username = $user->username;
        $this->notice = null;
        $this->resetErrorBag();
        $this->showUsernameModal = true;
    }

    public function saveUsername(): void
    {
        $this->authorize('update', $this->employee);

        /** @var User|null $user */
        $user = $this->employee->user()->first();
        if ($user === null) {
            $this->showUsernameModal = false;

            return;
        }

        $this->username = trim($this->username);

        $this->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')->ignore($user->id), UsernameCodeCollision::forUsername($this->employee->id)],
        ]);

        $changed = $user->username !== $this->username;
        $user->forceFill(['username' => $this->username])->save();
        $this->employee->setRelation('user', $user);

        $this->showUsernameModal = false;
        $this->notice = $changed
            ? "Username changed. {$this->employee->full_name} signs in as “{$user->username}” from now on."
            : null;
    }

    public function render()
    {
        // Every request ends here — re-assert access rather than trusting Livewire to keep $employee pinned,
        // and so a page opened while authorised stops serving data once access is revoked (see CLAUDE.md, Authorization).
        $this->authorize('view', $this->employee);

        return view('livewire.employees.show')
            ->layout('layouts.app', [
                'breadcrumbs' => [
                    ['label' => 'Employees', 'route' => auth()->user()->can('viewAny', Employee::class) ? ListUrl::for('employees.index') : null],
                    ['label' => $this->employee->full_name],
                ],
            ]);
    }
}
