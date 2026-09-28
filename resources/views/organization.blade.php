<x-app-layout>
    <x-slot name="header">Organization</x-slot>

    {{-- ?tab=schedules opens directly on the Schedules tab — used by the
    employee profile's "bulk reassign" hint link (Employees\
    ScheduleAssignments) so it actually lands where it points, not on the
    default Departments tab. Read once on load; the tab buttons below don't
    write it back to the URL, since nothing else needs to deep-link out of
    this page while it's open. --}}
    <div
        x-data="{
            tab: ['departments', 'positions', 'holidays', 'schedules'].includes(new URLSearchParams(location.search).get('tab'))
                ? new URLSearchParams(location.search).get('tab')
                : 'departments'
        }"
        class="space-y-6"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Organization</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manage the workforce structure, holidays, and work schedules.</p>
        </div>

        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <nav aria-label="Organization sections" class="flex min-w-max border-b border-slate-200/70 dark:border-slate-800/70">
                @foreach (['departments' => 'Departments', 'positions' => 'Positions', 'holidays' => 'Holidays', 'schedules' => 'Schedules'] as $section => $label)
                    <button
                        type="button"
                        @click="tab = '{{ $section }}'"
                        :aria-current="tab === '{{ $section }}' ? 'page' : null"
                        :class="tab === '{{ $section }}'
                            ? 'border-primary-600 font-semibold text-primary-700 dark:border-primary-400 dark:text-primary-300'
                            : 'border-transparent font-medium text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:border-slate-700 dark:hover:text-slate-200'"
                        class="-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm transition focus:outline-none focus-visible:relative focus-visible:z-10 focus-visible:rounded-t-lg focus-visible:ring-2 focus-visible:ring-primary-500"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        <div x-show="tab === 'departments'" aria-live="polite">
            <livewire:departments.index />
        </div>

        <div x-show="tab === 'positions'" x-cloak>
            <livewire:positions.index />
        </div>

        <div x-show="tab === 'holidays'" x-cloak>
            <livewire:holidays.index />
        </div>

        <div x-show="tab === 'schedules'" x-cloak>
            <livewire:schedules.index />
        </div>
    </div>
</x-app-layout>
