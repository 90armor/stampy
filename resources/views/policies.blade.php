<x-app-layout :section="$tabs[$tab]">
    <x-slot name="header">Policies</x-slot>

    {{-- The rules attendance, leave and overtime are counted by: working time
    first (Schedules, Holidays — moved here from Organization), because an
    admin sets it up before leave and overtime. Only the tabs the user may
    see are rendered (PoliciesPolicy, Phase 5a), each by the ability its
    component checks; $tab is the requested one if allowed, else the first
    (routes/web.php). routes/web.php redirects the old Organization tab links
    here. --}}
    <div x-data="{ tab: @js($tab) }" class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Policies</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Working hours, holidays and the rules leave and overtime are counted by.</p>
        </div>

        <x-tab-bar label="Policy sections" :tabs="$tabs" />

        @foreach ($tabs as $key => $label)
            <div x-show="tab === '{{ $key }}'" @if ($key !== $tab) x-cloak @endif>
                @switch ($key)
                    @case ('schedules')
                        <livewire:schedules.index />
                        @break
                    @case ('holidays')
                        <livewire:holidays.index />
                        @break
                    @case ('leave-types')
                        <livewire:leave-types.index />
                        @break
                    @case ('overtime')
                        <livewire:overtime-settings.edit />
                        @break
                @endswitch
            </div>
        @endforeach
    </div>
</x-app-layout>
