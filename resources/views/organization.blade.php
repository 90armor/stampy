<x-app-layout>
    <x-slot name="header">Organization</x-slot>

    {{-- ?tab=schedules opens directly on the Schedules tab — used by the
    employee profile's "bulk reassign" hint link (Employees\
    ScheduleAssignments) so it actually lands where it points, not on the
    default Departments tab. Read once on load; the tab buttons below don't
    write it back to the URL, since nothing else needs to deep-link out of
    this page while it's open. --}}
    <div x-data="{ tab: new URLSearchParams(location.search).get('tab') || 'departments' }" class="space-y-6">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Organization</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Departments, Positions, Holidays &amp; Schedules</h1>
        </div>

        <div class="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
            <button
                type="button"
                @click="tab = 'departments'"
                :class="tab === 'departments' ? 'bg-white shadow-sm text-primary-700 dark:bg-slate-700 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                class="rounded-md px-4 py-1.5 text-sm font-medium transition"
            >
                Departments
            </button>
            <button
                type="button"
                @click="tab = 'positions'"
                :class="tab === 'positions' ? 'bg-white shadow-sm text-primary-700 dark:bg-slate-700 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                class="rounded-md px-4 py-1.5 text-sm font-medium transition"
            >
                Positions
            </button>
            <button
                type="button"
                @click="tab = 'holidays'"
                :class="tab === 'holidays' ? 'bg-white shadow-sm text-primary-700 dark:bg-slate-700 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                class="rounded-md px-4 py-1.5 text-sm font-medium transition"
            >
                Holidays
            </button>
            <button
                type="button"
                @click="tab = 'schedules'"
                :class="tab === 'schedules' ? 'bg-white shadow-sm text-primary-700 dark:bg-slate-700 dark:text-primary-300' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                class="rounded-md px-4 py-1.5 text-sm font-medium transition"
            >
                Schedules
            </button>
        </div>

        <div x-show="tab === 'departments'">
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
