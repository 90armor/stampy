@php
    // Colour comes from DailyAttendance::displayVariant() everywhere on this
    // page, and that variant is the attendance STATUS only — a late or early
    // Present day is still 'present' (green). Timing is an annotation: the
    // amber Late/Early values in their own columns, never the badge colour.
    // See docs/ATTENDANCE_UI.md.
    // Badge colour per status variant (x-badge palette). violet for
    // Incomplete (a device defect) so it never reads as the amber timing
    // annotation; blue for In progress ("not yet", not a failure); fuchsia
    // for Holiday, outside the green family; accent for Leave, which must
    // not look like a weekend (Off). See CLAUDE.md's displayVariant() table.
    $variantStyles = [
        'present' => ['badge' => 'green'],
        'incomplete' => ['badge' => 'violet'],
        'absent' => ['badge' => 'red'],
        'off' => ['badge' => 'slate'],
        'in_progress' => ['badge' => 'blue'],
        'holiday' => ['badge' => 'fuchsia'],
        'leave' => ['badge' => 'accent'],
    ];

    $isToday = $fromDate === $toDate && $fromDate === today()->format('Y-m-d');
    $rangeLabel = match (true) {
        $isToday => 'Today',
        $fromDate === $toDate => \App\Support\DisplayDate::compact(\Illuminate\Support\Carbon::parse($fromDate)),
        default => \App\Support\DisplayDate::range(\Illuminate\Support\Carbon::parse($fromDate), \Illuminate\Support\Carbon::parse($toDate)),
    };
    $defaultStatuses = collect($allStatuses)
        ->reject(fn ($status) => $status === \App\Enums\AttendanceStatus::Off)
        ->pluck('value')
        ->sort()
        ->values()
        ->all();
    $selectedStatuses = collect($statuses)->sort()->values()->all();
    $filtersActive = ! $isToday
        || $employeeFilter !== ''
        || $departmentFilter !== ''
        || $selectedStatuses !== $defaultStatuses
        || $timingFilters !== [];
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Daily attendance</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Review attendance records, timing exceptions, and calculated work time.</p>
    </div>

    @if ($scopeHasNoEmployeeRecord)
        <x-no-employee-record subject="Attendance" />
    @else
    @php
        $timingParts = array_filter([
            $summary['late'] > 0 ? $summary['late'].' late' : null,
            $summary['early'] > 0 ? $summary['early'].' early' : null,
        ]);
        // The strip is range-wide on purpose (Index::summaryQuery()): it
        // ignores the status/timing chips, search and department, so it
        // states its own scope rather than read as contradicting a filtered
        // table below it.
        $summaryRange = \App\Support\DisplayDate::range(\Illuminate\Support\Carbon::parse($fromDate), \Illuminate\Support\Carbon::parse($toDate));
        $summaryScope = $live
            ? 'Today, '.$summaryRange.' · so far'
            : $summaryRange.' · '.($employeeFilter !== '' || $departmentFilter !== '' ? 'all employees, all statuses' : 'all statuses');
    @endphp
    <x-card :padding="false">
        {{-- Card header pattern: the scope is right-aligned muted meta on a
        header row, inset to the cells' own padding. --}}
        <div class="flex items-baseline justify-end gap-4 px-6 pt-4">
            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ $summaryScope }}</p>
        </div>
        <dl class="grid grid-cols-3 divide-x divide-slate-divider">
            @if ($live)
                {{-- Exactly today: the live "who is here now" strip. Any other
                range: end-of-day status counts ("did they attend"). --}}
                @foreach (\App\Support\DashboardAttendance::liveTodayCells($live) as $cell)
                    <x-stat-card :icon="$cell['icon']" :label="$cell['label']" :value="$cell['value']">
                        @if ($cell['subtext'] !== null)
                            <x-slot:subtext>{{ $cell['subtext'] }}</x-slot:subtext>
                        @endif
                    </x-stat-card>
                @endforeach
            @else
                <x-stat-card icon="check" label="Present" :value="$summary['present']">
                    @if ($timingParts)
                        <x-slot:subtext>{{ implode(' · ', $timingParts) }}</x-slot:subtext>
                    @endif
                </x-stat-card>
                <x-stat-card icon="user-x" label="Absent" :value="$summary['absent']" />
                <x-stat-card icon="exclamation-triangle" label="Incomplete" :value="$summary['incomplete']">
                    {{-- Late annotates its own status group (docs/ATTENDANCE_UI.md). --}}
                    @if ($summary['incomplete_late'] > 0)
                        <x-slot:subtext>{{ $summary['incomplete_late'] }} late</x-slot:subtext>
                    @endif
                </x-stat-card>
            @endif
        </dl>
    </x-card>

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-0 sm:p-6 sm:pb-0">
            <h2 class="flex items-baseline gap-x-2 text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                Attendance records
                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">{{ $attendances->total() }} {{ $attendances->total() === 1 ? 'record' : 'records' }}</span>
            </h2>

            <div class="flex items-center gap-3">
                <span wire:loading.delay class="text-xs text-slate-500 dark:text-slate-400" role="status">Updating…</span>
                @if ($filtersActive)
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-600/30 dark:hover:text-primary-300"
                    >
                        <x-icon name="x-mark" class="h-3.5 w-3.5" />
                        Reset filters
                    </button>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-[auto_minmax(16rem,1fr)_14rem]">
            {{-- Date range: presets plus the date picker in range mode (datePicker
            in resources/js/app.js, calendar in <x-date-picker.calendar>; rules
            in docs/ATTENDANCE_UI.md). It sets the
            same fromDate/toDate properties the native inputs did, so the query
            and the from/to URL are unchanged. Escape closes and returns focus
            to the trigger. --}}
            <div
                class="relative"
                x-data="datePicker({ mode: 'range', today: '{{ today()->format('Y-m-d') }}', presets: @js($presetRanges) })"
                @click.outside="closePanel(false)"
                @keydown.escape.stop="closePanel()"
                @keydown.escape.window="closePanel(false)"
            >
                <button
                    type="button"
                    x-ref="trigger"
                    @click="togglePanel()"
                    :aria-expanded="panelOpen"
                    aria-haspopup="dialog"
                    aria-controls="attendance-date-panel"
                    class="inline-flex h-control w-full items-center justify-between gap-2 rounded-lg border border-slate-border bg-white px-3 text-sm text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-slate-750 dark:text-slate-200 dark:hover:bg-slate-600 lg:w-auto"
                >
                    <span class="inline-flex items-center gap-2"><x-icon name="calendar-days" class="h-4 w-4 text-slate-400 dark:text-slate-500" />{{ $rangeLabel }}</span>
                    <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                </button>
                {{-- Outside the panel, so a completed range is still announced
                after the panel closes. --}}
                <p class="sr-only" aria-live="polite" x-text="announcement"></p>

                {{-- Popovers are content surfaces and therefore opaque, so the
                chips and rows below can't bleed through. Not teleported: the
                panel holds wire:model inputs (below 640px), which must stay
                inside the Livewire component root. It is position: fixed and
                placed against the trigger by datePicker's place() — below it,
                or above when there isn't room — so no ancestor can clip it. --}}
                <div
                    id="attendance-date-panel"
                    x-show="panelOpen"
                    x-cloak
                    x-ref="panel"
                    role="dialog"
                    aria-label="Choose a date range"
                    class="fixed z-20 w-80 max-w-[calc(100vw-2rem)] overflow-y-auto overscroll-contain rounded-xl bg-white p-4 shadow-xl ring-1 ring-slate-border dark:bg-slate-750"
                >
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Quick ranges</p>
                    {{-- A preset shows the shared selected state while the
                    applied range — or the pending one — equals it
                    (Index::presetRanges()). wire:ignore: Alpine owns the
                    selected classes, so a re-render must not reset them. --}}
                    <div class="mt-2 grid grid-cols-2 gap-2" wire:ignore>
                        @foreach ($presetRanges as $preset => $range)
                            <button
                                type="button"
                                @click="applyPreset('{{ $preset }}')"
                                :aria-pressed="String(presetActive('{{ $preset }}'))"
                                class="rounded-lg px-2 py-1.5 text-left text-sm ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                :class="presetActive('{{ $preset }}')
                                    ? 'bg-primary-50 font-semibold text-primary-700 ring-primary-600 dark:bg-primary-600/35 dark:text-primary-200 dark:ring-primary-500'
                                    : 'font-normal text-slate-600 ring-transparent hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-600/30'"
                            >{{ $range['label'] }}</button>
                        @endforeach
                    </div>

                    <div class="mt-4 border-t border-slate-divider pt-4">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Custom</p>

                        {{-- From 640px: the shared calendar (treatments and keys in
                        docs/ATTENDANCE_UI.md). wire:ignore: Alpine renders it. --}}
                        <x-date-picker.calendar class="hidden sm:block" wire:ignore />

                        {{-- Below 640px: native date inputs, stacked. A native
                        input's intrinsic width (~150-180px) doesn't reliably fit
                        two across this panel, so each takes the full width;
                        min-w-0 so a grid child can't be held wider by content. --}}
                        <div class="mt-2 space-y-3 sm:hidden">
                            <div class="min-w-0">
                                <x-input-label for="attendance-from" value="From" class="!mb-1 !text-xs" />
                                <input
                                    id="attendance-from"
                                    type="date"
                                    wire:model.live="fromDate"
                                    class="block h-control w-full min-w-0 rounded-lg border-slate-border bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-750 dark:text-slate-100"
                                >
                            </div>
                            <div class="min-w-0">
                                <x-input-label for="attendance-to" value="To" class="!mb-1 !text-xs" />
                                <input
                                    id="attendance-to"
                                    type="date"
                                    wire:model.live="toDate"
                                    class="block h-control w-full min-w-0 rounded-lg border-slate-border bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-750 dark:text-slate-100"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative min-w-0 sm:col-span-2 lg:col-span-1">
                <label for="attendance-search" class="sr-only">Search by employee name or code</label>
                <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 w-4 h-4 text-slate-400 dark:text-slate-500" />
                <input
                    id="attendance-search"
                    type="text"
                    wire:model.live.debounce.300ms="employeeFilter"
                    placeholder="Name or employee code…"
                    class="block h-control w-full rounded-lg border-slate-border bg-white pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-750 dark:text-slate-100 dark:placeholder-slate-400"
                >
            </div>

            <div class="min-w-0">
                <label for="attendance-department" class="sr-only">Department</label>
                <x-select id="attendance-department" wire:model.live="departmentFilter" class="w-full">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </x-select>
            </div>
        </div>

        {{-- Two independent filters, not one long chip list — a label per
        row is the whole point, since Status and Timing combine with AND
        between them (and OR within each), and nothing about the chips
        themselves signals that grouping.

        Chips are controls, not actions (docs/ATTENDANCE_UI.md): selected is
        the shared selected state — a primary-50 tint with semibold primary
        text, a primary border — plus a check;
        unselected is a neutral outline; never a solid fill. The status chips
        narrow rather than enumerate — while every working status is in the
        set (the default), none of them reads as selected, so the default
        state looks like what it is: no filter. Off is its own "Show off
        days" toggle. See Index::toggleStatus() for the mapping. --}}
        @php
            $chipBase = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-offset-slate-800';
            $chipSelected = 'font-semibold bg-primary-50 text-primary-700 ring-primary-600 hover:bg-primary-100 dark:bg-primary-600/35 dark:text-primary-200 dark:ring-primary-500 dark:hover:bg-primary-600/45';
            $chipUnselected = 'font-medium bg-white text-slate-600 ring-slate-border hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 dark:bg-slate-750 dark:text-slate-300 dark:hover:bg-slate-600 dark:hover:text-white dark:active:bg-slate-500';
            $workingStatuses = collect($allStatuses)->reject(fn ($status) => $status === \App\Enums\AttendanceStatus::Off);
            $allWorkingSelected = $workingStatuses->every(fn ($status) => in_array($status->value, $statuses, true));
            $showsOffDays = in_array(\App\Enums\AttendanceStatus::Off->value, $statuses, true);
        @endphp
        <div class="grid gap-4 border-t border-slate-divider px-5 py-4 sm:px-6 xl:grid-cols-[minmax(0,1fr)_auto]">
        <fieldset>
        <legend class="text-xs font-medium text-slate-700 dark:text-slate-300">Status</legend>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($workingStatuses as $status)
                @php $selected = ! $allWorkingSelected && in_array($status->value, $statuses, true); @endphp
                <button
                    type="button"
                    wire:click="toggleStatus('{{ $status->value }}')"
                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    @class([$chipBase, $chipSelected => $selected, $chipUnselected => ! $selected])
                >
                    @if ($selected)<x-icon name="check" class="h-3.5 w-3.5" />@endif
                    {{ $status->label() }}
                </button>
            @endforeach
            <button
                type="button"
                wire:click="toggleStatus('{{ \App\Enums\AttendanceStatus::Off->value }}')"
                aria-pressed="{{ $showsOffDays ? 'true' : 'false' }}"
                @class([$chipBase, $chipSelected => $showsOffDays, $chipUnselected => ! $showsOffDays])
            >
                @if ($showsOffDays)<x-icon name="check" class="h-3.5 w-3.5" />@endif
                Show off days
            </button>
        </div>
        </fieldset>

        {{-- Timing (late arrival / early departure) is independent of status
        (see AttendanceStatus's doc comment) — a separate #[Url]-bound
        property so the two filters combine independently. Same chip
        treatment; unselected by default. --}}
        <fieldset>
        <legend class="text-xs font-medium text-slate-700 dark:text-slate-300">Timing</legend>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach (['late' => 'Late arrival', 'early' => 'Early departure'] as $value => $label)
                @php $selected = in_array($value, $timingFilters, true); @endphp
                <button
                    type="button"
                    wire:click="toggleTimingFilter('{{ $value }}')"
                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    @class([$chipBase, $chipSelected => $selected, $chipUnselected => ! $selected])
                >
                    @if ($selected)<x-icon name="check" class="h-3.5 w-3.5" />@endif
                    {{ $label }}
                </button>
            @endforeach
        </div>
        </fieldset>
        </div>

        @if ($maxBuiltDate && $toDate > $maxBuiltDate)
            <div class="mx-6 mb-6 flex items-start gap-2 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-500/30">
                <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Attendance has only been calculated up to <strong>{{ \App\Support\DisplayDate::compact(\Illuminate\Support\Carbon::parse($maxBuiltDate)) }}</strong>.
                    Dates after that aren't missing punches — they simply haven't been processed yet.
                </span>
            </div>
        @endif

        @if ($attendances->isEmpty())
            <div class="border-t border-slate-divider px-6 py-10 text-center">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-750 dark:text-slate-400">
                    <x-icon name="clock" class="h-5 w-5" />
                </span>
                <h3 class="mt-3 text-sm font-medium text-slate-900 dark:text-slate-100">No attendance records</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Try widening the date range or adjusting the filters above.</p>
            </div>
        @else
            <div class="border-t border-slate-divider">
                {{-- The table's natural width is ~1119px; it first fits the card
                at a 1440px viewport (1134px card), so the cue shows below that —
                including 1024–1439, where it used to be hidden while the table
                still scrolled. --}}
                <div class="flex items-center justify-end gap-1.5 px-5 py-2 text-xs text-slate-500 dark:text-slate-400 min-[1440px]:hidden" aria-hidden="true">
                    <span>Scroll to view all columns</span>
                    <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                </div>

                {{-- From sm to below xl the Status and chevron columns are pinned to the
                right edge (.table-pin in resources/css/app.css), so "did they
                attend" and the way into the record stay on screen while the
                times scroll beneath them. pinnedColumns (resources/js/app.js)
                keeps the pin offset and the edge shadow in sync with scrolling;
                wire:ignore.self so a re-render doesn't strip what it sets on
                this element (the rows inside still morph normally). --}}
                <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-60" wire:ignore.self x-data="pinnedColumns" @scroll.passive="measure()">
                    {{-- Column order (owner decision, docs/ATTENDANCE_UI.md): Date,
                    Employee, Department, the times, then Status and the chevron.
                    No column is ever hidden. --}}
                    <table class="min-w-[64rem] w-full">
                    <thead>
                        <tr class="relative whitespace-nowrap text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th class="px-6 py-3">
                                Date
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 z-[2] h-px bg-slate-divider"></span>
                            </th>
                            <th class="min-w-[11rem] px-6 py-3">Employee</th>
                            <th class="px-6 py-3">Department</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right"><abbr title="Early leave" class="no-underline">Early</abbr></th>
                            <th class="table-pin table-pin-start px-6 py-3">Status</th>
                            <th class="table-pin table-pin-end py-3 pl-2 pr-6">
                                <span class="sr-only">Open detail</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            // A single-day range already says the date in the range
                            // control, so the repeated Date column recedes to muted.
                            $dateCellClass = $fromDate === $toDate ? 'text-slate-500 dark:text-slate-400' : 'text-slate-700 dark:text-slate-300';
                            $emDash = '<span class="text-slate-300 dark:text-slate-600">—</span>';
                        @endphp
                        @foreach ($attendances as $attendance)
                            @php
                                $style = $variantStyles[$attendance->displayVariant()];
                                $workDateLabel = \App\Support\DisplayDate::compact($attendance->work_date);
                            @endphp
                            <tr wire:key="daily-attendance-{{ $attendance->id }}" class="group relative hover:bg-slate-50 dark:hover:bg-slate-750/60">
                                <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums {{ $dateCellClass }}">
                                    {{ $workDateLabel }}
                                    {{-- The row divider lives in the first cell (positioned
                                    against the row) and sits above the pinned cells, so it
                                    runs unbroken beneath them. --}}
                                    @unless ($loop->last)
                                        <span class="pointer-events-none absolute inset-x-6 bottom-0 z-[2] h-px bg-slate-divider"></span>
                                    @endunless
                                </td>
                                {{-- Plain text, not a link: the row's one navigation target is
                                the chevron at the end, so the identity column reads as data
                                and every cell stays selectable. --}}
                                <td class="px-6 py-2">
                                    <div class="whitespace-nowrap text-sm font-medium text-slate-900 dark:text-slate-100">{{ $attendance->employee->full_name }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ $attendance->employee->employee_code }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-2 text-sm text-slate-700 dark:text-slate-300">{{ $attendance->employee->department->name }}</td>
                                <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                    @if ($attendance->first_in)
                                        <x-time :time="$attendance->first_in" />
                                    @else
                                        {!! $emDash !!}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                    @if ($attendance->last_out)
                                        <x-time :time="$attendance->last_out" />
                                        @if ($attendance->isOvernightOut())
                                            <span class="text-slate-400 dark:text-slate-400">(+1)</span>
                                        @endif
                                    @else
                                        {!! $emDash !!}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums text-slate-700 dark:text-slate-300">{!! e($attendance->formattedWorkedMinutes()) ?: $emDash !!}</td>
                                {{-- In/Out stay neutral; the timing fact is marked here, on
                                the duration itself, in amber (docs/ATTENDANCE_UI.md). --}}
                                <td @class(['whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums', 'font-medium text-amber-700 dark:text-amber-300' => $attendance->isLate()])>{!! e($attendance->formattedLateMinutes()) ?: $emDash !!}</td>
                                <td @class(['whitespace-nowrap px-6 py-2 text-right text-sm tabular-nums', 'font-medium text-amber-700 dark:text-amber-300' => $attendance->leftEarly()])>{!! e($attendance->formattedEarlyLeaveMinutes()) ?: $emDash !!}</td>
                                <td class="table-pin table-pin-start px-6 py-2">
                                    {{-- Status only — the Late/Early columns already show the
                                    timing (aligned, scannable, amber); a "Late 21m" chip here
                                    repeated the same fact and bloated row height. Colour comes
                                    from displayVariant(), which is status-only: a late Present
                                    day is a green "Present". --}}
                                    <x-badge :color="$style['badge']">{{ $attendance->status->label() }}</x-badge>
                                </td>
                                <td class="table-pin table-pin-end py-2 pl-2 pr-6 text-right">
                                    {{-- The row's only link: visible at rest, a 40px target
                                    (negative margin keeps it from growing the row), no
                                    whole-row click handler. The tooltip is the app's row
                                    action tooltip (shown on hover and on keyboard focus,
                                    never a native title); the aria-label stays the
                                    accessible name, since it also names the record. --}}
                                    <span class="group/action relative inline-flex">
                                        <a
                                            href="{{ route('attendance.show', $attendance->employee) }}?month={{ $attendance->work_date->format('Y-m') }}"
                                            wire:navigate
                                            aria-label="View attendance for {{ $attendance->employee->full_name }}, {{ $workDateLabel }}"
                                            class="-my-1 ml-auto inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-300 dark:group-hover:text-primary-400"
                                        >
                                            <x-icon name="chevron-right" class="h-4 w-4" />
                                        </a>
                                        <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">View attendance details</span>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    </table>
                </div>
            </div>

            {{-- No footer (and no empty divider band) when everything fits on one page. --}}
            @if ($attendances->hasPages())
                <div class="mx-6 border-t border-slate-divider py-4">
                    {{ $attendances->links() }}
                </div>
            @endif
        @endif
    </x-card>
    @endif
</div>
