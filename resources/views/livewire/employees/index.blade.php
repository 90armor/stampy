<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">People directory</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Employees</h1>
        </div>

        @can('create', \App\Models\Employee::class)
            <x-button type="button" variant="primary" wire:click="create">
                <x-icon name="plus" class="w-4 h-4" />
                Add Employee
            </x-button>
        @endcan
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-card class="p-5">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">
                <x-icon name="users" class="w-5 h-5" />
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Total employees</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $stats['total_employees'] }}</p>
        </x-card>

        <x-card class="p-5">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent-50 text-accent-700 dark:bg-accent-900/40 dark:text-accent-300">
                <x-icon name="user-circle" class="w-5 h-5" />
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Active employees</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $stats['active_employees'] }}</p>
        </x-card>

        <x-card class="p-5">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <x-icon name="archive-box" class="w-5 h-5" />
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Inactive employees</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $stats['inactive_employees'] }}</p>
        </x-card>
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
                class="block w-full rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500"
            >
        </div>

        <select wire:model.live="departmentFilter" class="rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}">{{ $department->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="statusFilter" class="rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100">
            <option value="">All status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>

    @if ($employees->isEmpty())
        <x-empty-state
            icon="users"
            title="No employees found"
            description="Try adjusting your search or filters, or add a new employee."
        />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-800">
                <thead>
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th class="px-6 py-3">Employee</th>
                        <th class="px-6 py-3">Department</th>
                        <th class="px-6 py-3">Position</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Start date</th>
                        <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
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
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
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
                                @unless ($employee->trashed())
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $employee)
                                            <button
                                                type="button"
                                                wire:click="edit({{ $employee->id }})"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                                title="Edit"
                                            >
                                                <x-icon name="pencil" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                        @can('delete', $employee)
                                            <button
                                                type="button"
                                                @click="$dispatch('confirm-dialog', {
                                                    title: 'Deactivate employee',
                                                    message: @js('Deactivate '.$employee->full_name.'? This can be reversed by a database administrator.'),
                                                    confirmText: 'Deactivate',
                                                    method: 'deactivate',
                                                    args: [{{ $employee->id }}],
                                                })"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                title="Deactivate"
                                            >
                                                <x-icon name="archive-box" class="w-4 h-4" />
                                            </button>
                                        @endcan
                                    </div>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-6 py-4 dark:border-slate-800">
            {{ $employees->links() }}
        </div>
    @endif
    </x-card>

    @include('livewire.employees.partials.modal')

    <x-confirm-dialog />
</div>
