<div>
    <div class="mb-6">
        <a href="{{ route('employees.index') }}" wire:navigate class="inline-flex items-center gap-x-1 text-sm font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">
            <x-icon name="chevron-right" class="h-4 w-4 rotate-180" />
            Back to employees
        </a>
    </div>

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-x-4">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg font-semibold text-primary-700 dark:bg-primary-800 dark:text-primary-100">
                {{ strtoupper(substr($employee->full_name, 0, 1)) }}
            </span>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $employee->employee_code }}</p>
            </div>
        </div>

        <div class="flex items-center gap-x-3">
            <x-badge :color="$employee->status === 'active' ? 'green' : 'slate'">
                {{ ucfirst($employee->status) }}
            </x-badge>

            @can('update', $employee)
                <x-button type="button" variant="secondary" wire:click="$dispatch('edit-employee', { id: {{ $employee->id }} })">
                    <x-icon name="pencil" class="w-4 h-4" />
                    Edit
                </x-button>
            @endcan
        </div>
    </div>

    <x-card>
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Details</p>

        <dl class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Department</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->department->name }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Position</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->position->name }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Start date</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->join_date->format('M j, Y') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Device user ID</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->device_user_id ?? '—' }}</dd>
            </div>
        </dl>
    </x-card>

    @can('view', $employee)
        <x-card class="mt-6">
            <div class="flex items-center justify-between gap-4">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Attendance</p>
                <a href="{{ route('attendance.show', $employee) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
                    View monthly attendance
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            </div>
        </x-card>
    @endcan

    <livewire:employees.form-modal />
</div>
