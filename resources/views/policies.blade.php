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

        <x-tab-bar label="Policy sections" :tabs="['schedules' => 'Schedules', 'holidays' => 'Holidays', 'leave-types' => 'Leave types', 'overtime' => 'Overtime']" />

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
