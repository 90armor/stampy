<x-app-layout>
    <x-slot name="header">Policies</x-slot>

    {{-- Organization's tab pattern (organization.blade.php): Leave types, then
    Overtime (Phase 4d). ?tab=overtime opens on it. --}}
    <div x-data="{ tab: new URLSearchParams(location.search).get('tab') === 'overtime' ? 'overtime' : 'leave-types' }" class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Policies</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">The rules leave and overtime are granted, requested and counted by.</p>
        </div>

        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <nav aria-label="Policy sections" class="flex min-w-max border-b border-slate-divider">
                @foreach (['leave-types' => 'Leave types', 'overtime' => 'Overtime'] as $section => $label)
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

        <div x-show="tab === 'leave-types'">
            <livewire:leave-types.index />
        </div>

        <div x-show="tab === 'overtime'" x-cloak>
            <livewire:overtime-settings.edit />
        </div>
    </div>
</x-app-layout>
