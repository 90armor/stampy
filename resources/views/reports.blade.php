<x-app-layout>
    <x-slot name="header">Reports</x-slot>

    {{-- One page, a tab per report (Phase 5b). Only the tabs the user may see
    are rendered (ReportPolicy), each by the ability its component checks;
    $tab is the requested one if allowed, else the first (routes/web.php).
    The open tab is kept in the URL, so a reload or a bookmark comes back to
    it; the reports share ?month= and keep each other's month in step
    (the report-month event). --}}
    <div
        x-data="{ tab: @js($tab) }"
        x-init="$watch('tab', value => { const url = new URL(window.location.href); url.searchParams.set('tab', value); history.replaceState(history.state, '', url); })"
        class="space-y-6"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Reports</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Figures per employee for a month or a year, on screen and as a CSV.</p>
        </div>

        <x-tab-bar label="Report sections" :tabs="$tabs" />

        @foreach ($tabs as $key => $label)
            <div x-show="tab === '{{ $key }}'" @if ($key !== $tab) x-cloak @endif>
                @switch ($key)
                    @case ('attendance')
                        <livewire:reports.attendance />
                        @break
                    @case ('leave')
                        <livewire:reports.leave />
                        @break
                    @case ('overtime')
                        <livewire:reports.overtime />
                        @break
                @endswitch
            </div>
        @endforeach
    </div>
</x-app-layout>
