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
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-stat-card icon="users" label="Total employees" :value="$stats['total_employees']" icon-class="bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300" />

        <x-stat-card icon="user-circle" label="Active employees" :value="$stats['active_employees']" icon-class="bg-accent-50 text-accent-700 dark:bg-accent-900/40 dark:text-accent-300" />

        <x-stat-card icon="user-x" label="Inactive employees" :value="$stats['inactive_employees']" />
    </div>

    <x-card :padding="false">
    <div class="flex flex-wrap items-center justify-between gap-4 p-6 pb-0">
        <div>
            <h2 class="flex items-baseline gap-x-2 text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                All employees
                <span class="text-xs font-medium text-slate-400 dark:text-slate-500">{{ $employees->total() }} shown</span>
            </h2>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 p-6">
        <div class="relative flex-1 min-w-[200px]">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 w-4 h-4 text-slate-400 dark:text-slate-500" />
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search people…"
                class="block w-full rounded-lg border-slate-300 bg-white pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500"
            >
        </div>

        <select wire:model.live="departmentFilter" class="rounded-lg border-slate-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}">{{ $department->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="statusFilter" class="rounded-lg border-slate-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
            <option value="">All status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>

    @if ($employees->isEmpty())
        @if ($hasAnyEmployees)
            <x-empty-state
                icon="search"
                title="No employees found"
                description="Try adjusting your search or filters."
            />
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
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th class="px-6 py-3">Employee</th>
                        <th class="px-6 py-3">Department</th>
                        <th class="px-6 py-3">Position</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Start date</th>
                        <th class="px-6 py-3">
                            <span class="sr-only">Actions</span>
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
                        <tr
                            wire:key="employee-{{ $employee->id }}"
                            tabindex="0"
                            @click="window.location = '{{ route('employees.show', $employee) }}'"
                            @keydown.enter="window.location = '{{ route('employees.show', $employee) }}'"
                            class="group relative cursor-pointer transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 dark:hover:bg-slate-800/60 {{ $employee->status === 'inactive' ? 'opacity-60' : '' }}"
                        >
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-x-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $avatarColors[$loop->index % count($avatarColors)] }}">
                                        {{ strtoupper(substr($employee->full_name, 0, 1)) }}
                                    </span>
                                    <div>
                                        <div class="font-medium text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</div>
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
                            <td class="px-6 py-4 text-sm text-slate-500 dark:text-slate-400">{{ $employee->join_date->format('M j, Y') }}</td>
                            <td class="px-6 py-4 text-right">
                                @unless ($loop->last)
                                    <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                @endunless

                                <div class="flex items-center justify-end gap-1">
                                    @can('update', $employee)
                                        <button
                                            type="button"
                                            wire:click.stop="$dispatch('edit-employee', { id: {{ $employee->id }} })"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                            title="Edit"
                                        >
                                            <x-icon name="pencil" class="w-4 h-4" />
                                        </button>
                                    @endcan
                                    @can('deactivate', $employee)
                                        @if ($employee->status === 'active')
                                            <button
                                                type="button"
                                                @click.stop="$dispatch('confirm-dialog', {
                                                    title: 'Deactivate employee',
                                                    message: @js('Deactivate '.$employee->full_name.'? They will no longer appear in active lists. Attendance history is preserved.'),
                                                    confirmText: 'Deactivate',
                                                    method: 'deactivate',
                                                    args: [{{ $employee->id }}],
                                                })"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                title="Deactivate"
                                            >
                                                <x-icon name="user-x" class="w-4 h-4" />
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                @click.stop="$dispatch('confirm-dialog', {
                                                    title: 'Reactivate employee',
                                                    message: @js('Reactivate '.$employee->full_name.'? They will appear in active lists again.'),
                                                    confirmText: 'Reactivate',
                                                    method: 'reactivate',
                                                    args: [{{ $employee->id }}],
                                                })"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                                title="Reactivate"
                                            >
                                                <x-icon name="check" class="w-4 h-4" />
                                            </button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
            {{ $employees->links() }}
        </div>
    @endif
    </x-card>
    @endif

    <livewire:employees.form-modal />

    <x-confirm-dialog />
</div>
