@props(['employee'])

{{--
    The employee record's read-only "Details" summary — department,
    position, manager (linked only if the viewer can open it), start date,
    device user ID. Shared by Employees\Show and the profile page, rather
    than each keeping its own copy: the profile page shows a user their own
    employee record the same way an admin sees it on Employees\Show, so this
    is the one place that markup lives.
--}}
<x-card {{ $attributes }}>
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Details</h2>

    <dl class="mt-4 grid grid-cols-1 gap-[1.3125rem] sm:grid-cols-[max-content_minmax(0,1fr)_max-content_minmax(0,1fr)] sm:gap-x-6 sm:gap-y-4">
        <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Department</dt>
            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->department->name }}</dd>
        </div>
        <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Position</dt>
            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->position->name }}</dd>
        </div>
        <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Manager</dt>
            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                @if ($employee->manager)
                    @can('view', $employee->manager)
                        <a
                            href="{{ route('employees.show', $employee->manager) }}"
                            wire:navigate
                            class="rounded text-primary-700 underline decoration-primary-300 decoration-1 underline-offset-2 hover:decoration-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400"
                        >
                            {{ $employee->manager->full_name }}
                        </a>
                    @else
                        {{ $employee->manager->full_name }}
                    @endcan
                @else
                    &mdash;
                @endif
            </dd>
        </div>
        {{-- A leaver's Start date and Last day share a row, so Device user ID
        moves ahead of them; an active employee keeps the usual order. --}}
        @if ($employee->left_on)
            <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Device user ID</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->device_user_id ?? '—' }}</dd>
            </div>
        @endif
        <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Start date</dt>
            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ \App\Support\DisplayDate::compact($employee->join_date) }}</dd>
        </div>
        @if ($employee->left_on)
            <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Last day</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ \App\Support\DisplayDate::compact($employee->left_on) }}</dd>
            </div>
        @endif
        @unless ($employee->left_on)
            <div class="sm:col-span-2 sm:grid sm:grid-cols-subgrid sm:items-baseline">
                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Device user ID</dt>
                <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->device_user_id ?? '—' }}</dd>
            </div>
        @endunless
    </dl>
</x-card>
