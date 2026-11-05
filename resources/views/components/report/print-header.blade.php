@props([
    // "Attendance report"
    'title',
    // "November 2026", or a year
    'period',
    // What the table is narrowed to — "Engineering", "Annual · All departments" — or null.
    'filters' => null,
    // The open-period notice while the month or year isn't over, else null.
    'open' => null,
])

{{-- What a printed report says about itself (Phase 6g, F04; docs/DESIGN_SYSTEM.md,
Printing): only on paper, where the page's title, controls and notice are hidden.
"Printed …" is when it was printed: resources/js/app.js rewrites it on
beforeprint, in the app's timezone; the server's text is only its fallback. --}}
<div class="hidden print:block">
    <h2 class="text-lg font-semibold text-slate-900">{{ $title }} · {{ $period }}</h2>
    <p class="mt-0.5 text-sm text-slate-700">
        @if ($filters){{ $filters }} · @endif
        Printed <span data-printed-at="{{ config('app.timezone') }}">{{ \App\Support\DisplayDate::compact(now()) }}, {{ \App\Support\AttendanceTime::format(now()) }}</span> by {{ auth()->user()->name }}
    </p>
    @if ($open)
        <p class="mt-1 text-sm font-medium text-slate-900">{{ $open }}</p>
    @endif
</div>
