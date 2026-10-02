<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Employees</h1>
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
        <dl class="grid grid-cols-3 divide-x divide-slate-200/60 dark:divide-slate-600/15">
            <x-stat-card icon="users" label="Total employees" :value="$stats['total_employees']" />
            <x-stat-card icon="user-circle" label="Active employees" :value="$stats['active_employees']" />
            <x-stat-card icon="user-x" label="Inactive employees" :value="$stats['inactive_employees']" />
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
                <button type="button" wire:click="resetFilters" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-600/30 dark:hover:text-primary-300">
                    <x-icon name="x-mark" class="h-3.5 w-3.5" />
                    Reset filters
                </button>
            @endif
        </div>
    </div>

    {{-- Three columns only from xl: at lg (1024px, a 718px card) the
    18rem + 14rem + 11rem minimums overflowed the card. Below xl the search
    spans the row and the two selects share the next. --}}
    <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-[minmax(18rem,1fr)_14rem_11rem]">
        <div class="relative min-w-0 sm:col-span-2 xl:col-span-1">
            <label for="employee-search" class="sr-only">Search employees by name or code</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 w-4 h-4 text-slate-400 dark:text-slate-500" />
            <input
                id="employee-search"
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by name or employee code…"
                class="block w-full rounded-lg border-slate-300 bg-white pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-750 dark:border-slate-600 dark:text-slate-100 dark:placeholder-slate-400"
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
            <div class="border-t border-slate-200/60 px-6 py-10 text-center dark:border-slate-600/15">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-750 dark:text-slate-500"><x-icon name="search" class="h-5 w-5" /></span>
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
        <div class="border-t border-slate-200/60 dark:border-slate-600/15">
            {{-- The table's natural width is ~959px (min-w-[60rem]); it fits the
            card from xl (974px) up, so the cue shows exactly while it scrolls. --}}
            <div class="flex items-center justify-end gap-1.5 px-5 py-2 text-xs text-slate-500 dark:text-slate-400 xl:hidden" aria-hidden="true">
                <span>Scroll to view all columns</span>
                <x-icon name="chevron-right" class="h-3.5 w-3.5" />
            </div>
            {{-- From sm to below xl the Status and Actions columns are pinned to the right
            edge (.table-pin in resources/css/app.css; pinnedColumns in
            resources/js/app.js keeps the offset and edge shadow in sync), so
            status and the row's actions stay on screen while the rest scrolls.
            wire:ignore.self so a re-render doesn't strip what pinnedColumns
            sets on this element; the rows inside still morph normally. --}}
            <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-60" wire:ignore.self x-data="pinnedColumns" @scroll.passive="measure()">
            {{-- Column order (owner decision, docs/ATTENDANCE_UI.md): Employee,
            Department, Position, Start date, then Status and Actions. --}}
            <table class="w-full min-w-[60rem]">
                <thead>
                    <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th class="px-6 py-3">
                            Employee
                            <span class="pointer-events-none absolute inset-x-6 bottom-0 z-[2] h-px bg-slate-200/60 dark:bg-slate-600/15"></span>
                        </th>
                        <th class="px-6 py-3">Department</th>
                        <th class="px-6 py-3">Position</th>
                        <th class="px-6 py-3">Start date</th>
                        <th class="table-pin table-pin-start px-6 py-3">Status</th>
                        <th class="table-pin table-pin-end px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($employees as $employee)
                        <tr wire:key="employee-{{ $employee->id }}" class="group relative transition hover:bg-slate-50 dark:hover:bg-slate-750/60">
                            <td class="px-6 py-4">
                                {{-- The row divider lives in the first cell (positioned
                                against the row) and sits above the pinned cells, so it
                                runs unbroken beneath them. --}}
                                @unless ($loop->last)
                                    <span class="pointer-events-none absolute inset-x-6 bottom-0 z-[2] h-px bg-slate-200/60 dark:bg-slate-600/15"></span>
                                @endunless
                                <div class="flex items-center gap-x-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold bg-slate-100 text-slate-600 dark:bg-slate-750 dark:text-slate-300">
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
                            <td class="whitespace-nowrap px-6 py-4 text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ \App\Support\DisplayDate::compact($employee->join_date) }}</td>
                            <td class="table-pin table-pin-start px-6 py-4">
                                <x-badge :color="$employee->status === 'active' ? 'green' : 'slate'">
                                    {{ ucfirst($employee->status) }}
                                </x-badge>
                            </td>
                            <td class="table-pin table-pin-end px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    @can('update', $employee)
                                        <span class="group/action relative inline-flex">
                                            <button
                                                type="button"
                                                wire:click.stop="$dispatch('edit-employee', { id: {{ $employee->id }} })"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-400"
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
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-400"
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
            <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-600/15">
                {{ $employees->links() }}
            </div>
        @endif
    @endif
    </x-card>
    @endif

    <livewire:employees.form-modal />

    <x-confirm-dialog />
</div>
