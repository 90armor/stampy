<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Services\EmployeeLifecycle;
use App\Support\DisplayDate;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Deactivate / reactivate an employee — the only place either happens
 * (CLAUDE.md, Employee lifecycle). Embedded on Employees\Index (the row
 * actions) and Employees\Show (the header), both of which open it through
 * the 'deactivate-employee' / 'reactivate-employee' browser events, the same
 * way FormModal is shared.
 *
 * A modal of its own rather than <x-confirm-dialog>: that dialog is
 * Alpine-only and closes the moment it's confirmed, so it could show neither
 * a server-side error on the last-day field (before the join date) nor the
 * rebuild warning EmployeeLifecycle can return.
 */
class StatusModal extends Component
{
    public bool $showModal = false;

    public ?Employee $employee = null;

    /** 'deactivate' or 'reactivate'. */
    public string $action = 'deactivate';

    public string $left_on = '';

    /**
     * The status change already landed whenever this is set — only the
     * attendance rebuild after it failed partway (EmployeeLifecycle). Kept
     * as a property, not a one-shot validation error, so it stays on screen
     * until the modal is closed.
     */
    public ?string $rebuildError = null;

    #[On('deactivate-employee')]
    public function openDeactivate(int $id): void
    {
        $employee = Employee::findOrFail($id);

        $this->authorize('deactivate', $employee);

        $this->open($employee, 'deactivate');
        $this->left_on = today()->format('Y-m-d');
    }

    #[On('reactivate-employee')]
    public function openReactivate(int $id): void
    {
        $employee = Employee::findOrFail($id);

        $this->authorize('update', $employee);

        $this->open($employee, 'reactivate');
    }

    public function confirm(EmployeeLifecycle $lifecycle): void
    {
        abort_if($this->employee === null, 404);

        if ($this->action === 'deactivate') {
            $this->authorize('deactivate', $this->employee);

            abort_unless($this->employee->status === 'active', 422, 'This employee is already inactive.');

            $joinDate = $this->employee->join_date->format('Y-m-d');
            $today = today()->format('Y-m-d');

            $this->validate([
                'left_on' => ['required', 'date_format:Y-m-d', "after_or_equal:{$joinDate}", "before_or_equal:{$today}"],
            ], [
                'left_on.required' => 'Choose their last day.',
                'left_on.date_format' => 'Choose their last day.',
                'left_on.after_or_equal' => 'The last day can\'t be before they joined ('.DisplayDate::compact($this->employee->join_date).').',
                'left_on.before_or_equal' => 'The last day can\'t be in the future.',
            ]);

            $result = $lifecycle->deactivate($this->employee, Carbon::parse($this->left_on));
        } else {
            $this->authorize('update', $this->employee);

            abort_unless($this->employee->status === 'inactive', 422, 'This employee is already active.');

            $result = $lifecycle->reactivate($this->employee);
        }

        $this->dispatch('employee-saved');

        if ($result['rebuildError'] !== null) {
            $this->rebuildError = $result['rebuildError'];

            return;
        }

        $this->close();
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->reset(['employee', 'left_on', 'rebuildError']);
        $this->resetErrorBag();
    }

    private function open(Employee $employee, string $action): void
    {
        $this->resetErrorBag();
        $this->reset(['left_on', 'rebuildError']);
        $this->employee = $employee;
        $this->action = $action;
        $this->showModal = true;
    }

    public function render()
    {
        // Holds the employee as a typed model and has a read-only action
        // (opening) beyond the mutating one — re-assert on every request
        // (CLAUDE.md, Authorization convention).
        if ($this->employee !== null) {
            $this->authorize($this->action === 'deactivate' ? 'deactivate' : 'update', $this->employee);
        }

        return view('livewire.employees.status-modal');
    }
}
