<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">People directory</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Employees</h1>
        </div>

        @can('create', \App\Models\Employee::class)
            <x-button type="button" variant="primary" wire:click="$dispatch('create-employee')">
                <x-icon name="plus" class="w-4 h-4" />
                Add Employee
            </x-button>
        @endcan
    </div>

    @if ($scopeHasNoEmployeeRecord)
        <x-no-employee-record subject="The employee directory" />
    @else
    <x-card :padding="false">
        <dl class="grid grid-cols-3 divide-x divide-slate-200/60 dark:divide-slate-800/60">
            <div class="min-w-0 px-3 py-3 sm:flex sm:items-center sm:gap-3 sm:px-4">
                <span class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 sm:flex dark:bg-primary-900/40 dark:text-primary-300">
                    <x-icon name="users" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <dt class="text-[11px] font-medium leading-4 text-slate-500 sm:text-xs dark:text-slate-400">Total employees</dt>
                    <dd class="mt-0.5 text-xl font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $stats['total_employees'] }}</dd>
                </div>
            </div>
            <div class="min-w-0 px-3 py-3 sm:flex sm:items-center sm:gap-3 sm:px-4">
                <span class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent-50 text-accent-700 sm:flex dark:bg-accent-900/40 dark:text-accent-300">
                    <x-icon name="user-circle" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <dt class="text-[11px] font-medium leading-4 text-slate-500 sm:text-xs dark:text-slate-400">Active employees</dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-800 dark:text-slate-200">{{ $stats['active_employees'] }}</dd>
                </div>
            </div>
            <div class="min-w-0 px-3 py-3 sm:flex sm:items-center sm:gap-3 sm:px-4">
                <span class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 sm:flex dark:bg-slate-800 dark:text-slate-400">
                    <x-icon name="user-x" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <dt class="text-[11px] font-medium leading-4 text-slate-500 sm:text-xs dark:text-slate-400">Inactive employees</dt>
                    <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-800 dark:text-slate-200">{{ $stats['inactive_employees'] }}</dd>
                </div>
            </div>
        </dl>
    </x-card>

    <x-card :padding="false">
    <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-0 sm:p-6 sm:pb-0">
        <div class="min-w-0">
            <h2 class="flex items-baseline gap-x-2 text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                All employees
                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">{{ $employees->total() }} {{ $employees->total() === 1 ? 'employee' : 'employees' }}</span>
            </h2>
        </div>

        <div class="flex items-center gap-3">
            <span wire:loading.delay class="text-xs text-slate-500 dark:text-slate-400" role="status">Updating…</span>
            @if ($filtersActive)
                <button type="button" wire:click="resetFilters" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-primary-300">
                    <x-icon name="x-mark" class="h-3.5 w-3.5" />
                    Reset filters
                </button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-[minmax(18rem,1fr)_14rem_11rem]">
        <div class="relative min-w-0 sm:col-span-2 lg:col-span-1">
            <label for="employee-search" class="sr-only">Search employees by name or code</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 w-4 h-4 text-slate-400 dark:text-slate-500" />
            <input
                id="employee-search"
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name or employee code…"
                class="block w-full rounded-lg border-slate-300 bg-white pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500"
            >
        </div>

        <label for="employee-department" class="sr-only">Filter by department</label>
        <x-select id="employee-department" wire:model.live="departmentFilter">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}">{{ $department->name }}</option>
            @endforeach
        </x-select>

        <label for="employee-status" class="sr-only">Filter by employee status</label>
        <x-select id="employee-status" wire:model.live="statusFilter">
            <option value="">All status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </x-select>
    </div>

    @if ($employees->isEmpty())
        @if ($hasAnyEmployees)
            <div class="border-t border-slate-200/60 px-6 py-10 text-center dark:border-slate-800/60">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500"><x-icon name="search" class="h-5 w-5" /></span>
                <h3 class="mt-3 text-sm font-medium text-slate-900 dark:text-slate-100">No employees found</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Try adjusting your search or filters.</p>
                @if ($filtersActive)
                    <button type="button" wire:click="resetFilters" class="mt-3 rounded-lg px-2 py-1 text-sm font-medium text-primary-700 underline decoration-primary-300 underline-offset-2 transition hover:decoration-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400">Reset filters</button>
                @endif
            </div>
        @else
            <x-empty-state
                icon="users"
                title="No employees yet"
                description="Get started by adding your first employee to the directory."
            >
                @can('create', \App\Models\Employee::class)
                    <x-slot name="action">
                        <x-button type="button" variant="primary" wire:click="$dispatch('create-employee')">
                            <x-icon name="plus" class="w-4 h-4" />
                            Add your first employee
                        </x-button>
                    </x-slot>
                @endcan
            </x-empty-state>
        @endif
    @else
        <div class="border-t border-slate-200/60 dark:border-slate-800/60">
            <div class="flex items-center justify-end gap-1.5 px-5 py-2 text-xs text-slate-500 dark:text-slate-400 lg:hidden" aria-hidden="true">
                <span>Scroll to view all columns</span>
                <x-icon name="chevron-right" class="h-3.5 w-3.5" />
            </div>
            <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-60">
            <table class="w-full min-w-[64rem]">
                <thead>
                    <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th class="px-6 py-3">Employee</th>
                        <th class="px-6 py-3">Department</th>
                        <th class="px-6 py-3">Position</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Start date</th>
                        <th class="px-6 py-3 text-right">
                            Actions
                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $avatarColors = [
                            'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-100',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                            'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                            'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300',
                            'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300',
                        ];
                    @endphp
                    @foreach ($employees as $employee)
                        <tr wire:key="employee-{{ $employee->id }}" class="group relative transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-x-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $avatarColors[$loop->index % count($avatarColors)] }}">
                                        {{ strtoupper(substr($employee->full_name, 0, 1)) }}
                                    </span>
                                    <div class="min-w-0">
                                        <a href="{{ route('employees.show', $employee) }}" wire:navigate class="rounded font-medium whitespace-nowrap text-primary-700 underline decoration-1 decoration-primary-300 underline-offset-2 transition hover:decoration-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400">{{ $employee->full_name }}</a>
                                        <div class="text-sm text-slate-500 dark:text-slate-400">{{ $employee->employee_code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">{{ $employee->department->name }}</td>
                            <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">{{ $employee->position->name }}</td>
                            <td class="px-6 py-4">
                                <x-badge :color="$employee->status === 'active' ? 'green' : 'slate'">
                                    {{ ucfirst($employee->status) }}
                                </x-badge>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ $employee->join_date->format('M j, Y') }}</td>
                            <td class="px-6 py-4 text-right">
                                @unless ($loop->last)
                                    <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                @endunless

                                <div class="flex items-center justify-end gap-1">
                                    @can('update', $employee)
                                        <span class="group/action relative inline-flex">
                                            <button
                                                type="button"
                                                wire:click.stop="$dispatch('edit-employee', { id: {{ $employee->id }} })"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                                aria-label="Edit {{ $employee->full_name }}"
                                            >
                                                <x-icon name="pencil" class="w-4 h-4" />
                                            </button>
                                            <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Edit employee</span>
                                        </span>
                                    @endcan
                                    @can('deactivate', $employee)
                                        @if ($employee->status === 'active')
                                            <span class="group/action relative inline-flex">
                                                <button
                                                    type="button"
                                                    @click.stop="$dispatch('confirm-dialog', {
                                                        title: 'Deactivate employee',
                                                        message: @js('Deactivate '.$employee->full_name.'? They will no longer appear in active lists. Attendance history is preserved.'),
                                                        confirmText: 'Deactivate',
                                                        method: 'deactivate',
                                                        args: [{{ $employee->id }}],
                                                    })"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                    aria-label="Deactivate {{ $employee->full_name }}"
                                                >
                                                    <x-icon name="user-x" class="w-4 h-4" />
                                                </button>
                                                <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Deactivate employee</span>
                                            </span>
                                        @else
                                            <span class="group/action relative inline-flex">
                                                <button
                                                    type="button"
                                                    @click.stop="$dispatch('confirm-dialog', {
                                                        title: 'Reactivate employee',
                                                        message: @js('Reactivate '.$employee->full_name.'? They will appear in active lists again.'),
                                                        confirmText: 'Reactivate',
                                                        method: 'reactivate',
                                                        args: [{{ $employee->id }}],
                                                    })"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                                    aria-label="Reactivate {{ $employee->full_name }}"
                                                >
                                                    <x-icon name="check" class="w-4 h-4" />
                                                </button>
                                                <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Reactivate employee</span>
                                            </span>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        {{-- No footer (and no empty divider band) when everything fits on one page. --}}
        @if ($employees->hasPages())
            <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                {{ $employees->links() }}
            </div>
        @endif
    @endif
    </x-card>
    @endif

    <livewire:employees.form-modal />

    <x-confirm-dialog />
</div>
