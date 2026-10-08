<x-app-layout>
    <x-slot name="header">Policies</x-slot>

    {{-- The rules attendance, leave and overtime are counted by: working time
    first (Schedules, Holidays — moved here from Organization), because an
    admin sets it up before leave and overtime. ?tab= opens on any of the
    four; anything else falls back to Schedules. routes/web.php redirects the
    old Organization tab links here. --}}
    <div
        x-data="{
            tab: ['schedules', 'holidays', 'leave-types', 'overtime'].includes(new URLSearchParams(location.search).get('tab'))
                ? new URLSearchParams(location.search).get('tab')
                : 'schedules'
        }"
        class="space-y-6"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Policies</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Working hours, holidays and the rules leave and overtime are counted by.</p>
        </div>

        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <nav aria-label="Policy sections" class="flex min-w-max border-b border-slate-divider">
                @foreach (['schedules' => 'Schedules', 'holidays' => 'Holidays', 'leave-types' => 'Leave types', 'overtime' => 'Overtime'] as $section => $label)
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

        <div x-show="tab === 'schedules'">
            <livewire:schedules.index />
        </div>

        <div x-show="tab === 'holidays'" x-cloak>
            <livewire:holidays.index />
        </div>

        <div x-show="tab === 'leave-types'" x-cloak>
            <livewire:leave-types.index />
        </div>

        <div x-show="tab === 'overtime'" x-cloak>
            <livewire:overtime-settings.edit />
        </div>
    </div>
</x-app-layout>
