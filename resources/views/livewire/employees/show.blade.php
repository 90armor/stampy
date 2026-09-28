<div class="space-y-6">
    <div>
        <a href="{{ route('employees.index') }}" wire:navigate class="inline-flex items-center gap-x-1 rounded text-sm font-medium text-slate-500 transition hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:text-primary-300">
            <x-icon name="chevron-right" class="h-4 w-4 rotate-180" />
            Back to employees
        </a>
    </div>

    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-center gap-x-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-100 text-base font-semibold text-primary-700 dark:bg-primary-800 dark:text-primary-100">
                {{ strtoupper(substr($employee->full_name, 0, 1)) }}
            </span>
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $employee->employee_code }}</p>
                    <x-badge :color="$employee->status === 'active' ? 'green' : 'slate'">
                        {{ ucfirst($employee->status) }}
                    </x-badge>
                </div>
            </div>
        </div>

        @can('update', $employee)
            <x-button type="button" variant="secondary" wire:click="$dispatch('edit-employee', { id: {{ $employee->id }} })" class="self-start sm:self-auto">
                <x-icon name="pencil" class="w-4 h-4" />
                Edit employee
            </x-button>
        @endcan
    </header>

    <div class="grid items-start gap-6 lg:grid-cols-12">
        <x-employee-details-card :employee="$employee" class="order-1 lg:col-span-8 lg:row-start-1" />

        <x-card class="order-2 lg:col-span-4 lg:row-start-1">
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Login</p>

            <dl class="mt-4 space-y-4">
                <div class="sm:grid sm:grid-cols-[max-content_minmax(0,1fr)] sm:items-baseline sm:gap-x-4">
                    <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Account</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                        @if ($employee->user)
                            <x-badge color="green">Has an account</x-badge>
                        @else
                            <x-badge color="slate">No account</x-badge>
                        @endif
                    </dd>
                </div>
                @if ($employee->user)
                    <div class="sm:grid sm:grid-cols-[max-content_minmax(0,1fr)] sm:items-baseline sm:gap-x-4">
                        <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Username</dt>
                        <dd class="mt-1 break-words text-sm text-slate-900 dark:text-slate-100">{{ $employee->user->username }}</dd>
                    </div>
                    <div class="sm:grid sm:grid-cols-[max-content_minmax(0,1fr)] sm:items-baseline sm:gap-x-4">
                        <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Password</dt>
                        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                            @if ($employee->user->must_change_password)
                                <x-badge color="amber">Change pending</x-badge>
                            @else
                                <span class="text-slate-500 dark:text-slate-400">Up to date</span>
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </x-card>

        @can('view', $employee)
            <x-card class="order-3 lg:col-span-4 lg:col-start-9 lg:row-start-2">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Attendance</p>
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Review this employee’s monthly attendance record.</p>
                <a href="{{ route('attendance.show', $employee) }}" wire:navigate class="mt-4 inline-flex items-center gap-1 rounded text-sm font-medium text-primary-700 underline decoration-primary-300 decoration-1 underline-offset-2 transition hover:decoration-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400">
                    View monthly attendance
                    <x-icon name="chevron-right" class="h-4 w-4" />
                </a>
            </x-card>
        @endcan

        <x-card class="order-4 lg:col-span-8 lg:row-start-2">
            <livewire:employees.schedule-assignments :employee="$employee" :key="'schedule-assignments-'.$employee->id" />
        </x-card>
    </div>

    <livewire:employees.form-modal />
</div>
