<x-app-layout>
    <x-slot name="header">Organization</x-slot>

    {{-- Structure only: Departments and Positions. Schedules and Holidays are
    rules, so they live on Policies; routes/web.php redirects their old
    ?tab=schedules / ?tab=holidays links there. ?tab=positions opens on
    Positions; anything else falls back to Departments. Read once on load; the
    tab buttons don't write it back to the URL. --}}
    <div
        x-data="{
            tab: ['departments', 'positions'].includes(new URLSearchParams(location.search).get('tab'))
                ? new URLSearchParams(location.search).get('tab')
                : 'departments'
        }"
        class="space-y-6"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Organization</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Manage the workforce structure: departments and positions.</p>
        </div>

        <x-tab-bar label="Organization sections" :tabs="['departments' => 'Departments', 'positions' => 'Positions']" />

        <div x-show="tab === 'departments'" aria-live="polite">
            <livewire:departments.index />
        </div>

        <div x-show="tab === 'positions'" x-cloak>
            <livewire:positions.index />
        </div>
    </div>
</x-app-layout>
