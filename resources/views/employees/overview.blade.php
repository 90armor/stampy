<x-app-layout>
    <x-slot name="header">Team management</x-slot>

    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">
                {{ auth()->user()->hasRole('admin') ? 'Admin workspace' : 'Manager workspace' }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Team management</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Manage employees, departments, and access details from one place.
            </p>
        </div>

        @can('create', \App\Models\Employee::class)
            <a href="{{ route('employees.create') }}" wire:navigate>
                <x-button variant="primary">
                    <x-icon name="plus" class="w-4 h-4" />
                    Add Employee
                </x-button>
            </a>
        @endcan
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
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
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                <x-icon name="building-office" class="w-5 h-5" />
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Departments</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $stats['departments'] }}</p>
        </x-card>

        <x-card class="p-5">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <x-icon name="archive-box" class="w-5 h-5" />
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Inactive employees</p>
            <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $stats['inactive_employees'] }}</p>
        </x-card>
    </div>

    @can('viewAny', \App\Models\Department::class)
        <div class="grid grid-cols-1 lg:grid-cols-[1.65fr_0.9fr] gap-4 items-start">
            <livewire:employees.index />
            <livewire:departments.index :compact="true" />
        </div>
    @else
        <livewire:employees.index />
    @endcan
</x-app-layout>
