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

        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <nav aria-label="Organization sections" class="flex min-w-max border-b border-slate-divider">
                @foreach (['departments' => 'Departments', 'positions' => 'Positions'] as $section => $label)
                    <button
                        type="button"
                        @click="tab = '{{ $section }}'"
                        :aria-current="tab === '{{ $section }}' ? 'page' : null"
                        :class="tab === '{{ $section }}'
                            ? 'border-primary-600 font-semibold text-primary-700 dark:border-primary-400 dark:text-primary-300'
                            : 'border-transparent font-medium text-slate-600 hover:border-slate-border hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
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
    </div>
</x-app-layout>
