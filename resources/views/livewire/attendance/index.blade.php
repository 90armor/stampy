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
        <dl class="grid grid-cols-3 divide-x divide-slate-200/60 dark:divide-slate-800/60">
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
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-primary-300"
                    >
                        <x-icon name="x-mark" class="h-3.5 w-3.5" />
                        Reset filters
                    </button>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-[auto_minmax(16rem,1fr)_14rem]">
            {{-- Date range: presets plus a month-grid range picker (dateRangePicker
            in resources/js/app.js; rules in docs/ATTENDANCE_UI.md). It sets the
            same fromDate/toDate properties the native inputs did, so the query
            and the from/to URL are unchanged. Escape closes and returns focus
            to the trigger. --}}
            <div
                class="relative"
                x-data="dateRangePicker({ today: '{{ today()->format('Y-m-d') }}' })"
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
                    class="inline-flex w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 lg:w-auto"
                >
                    <span class="inline-flex items-center gap-2"><x-icon name="calendar-days" class="h-4 w-4 text-slate-400 dark:text-slate-500" />{{ $rangeLabel }}</span>
                    <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                </button>
                {{-- Outside the panel, so a completed range is still announced
                after the panel closes. --}}
                <p class="sr-only" aria-live="polite" x-text="announcement"></p>

                {{-- Popovers are content surfaces and therefore opaque. This
                also prevents the status chips and rows below from bleeding
                through and reducing legibility.

                Not teleported to <body>: tried that for a suspected
                vertical-overflow issue, but the actual bug was the date
                inputs overflowing the panel horizontally (see below) —
                unrelated to where the panel lives in the DOM. Teleporting
                also has real downsides for a panel containing wire:model
                inputs: Alpine's x-teleport moves nodes outside the Livewire
                component root, where Livewire's morph doesn't reliably
                reach them on re-render (Livewire 3 has its own @teleport
                directive specifically because x-teleport isn't morph-safe
                inside a component) — bindings could silently stop syncing.
                Reverted; if a genuine vertical-overflow case shows up later,
                solve it with max-height + overflow-y-auto on the panel, or
                flip it to open upward, not with teleport. --}}
                <div
                    id="attendance-date-panel"
                    x-show="panelOpen"
                    x-cloak
                    role="dialog"
                    aria-label="Choose a date range"
                    class="absolute left-0 z-20 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl bg-white p-4 shadow-xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800"
                >
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Quick ranges</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'thisMonth' => 'This month'] as $preset => $label)
                            <button type="button" wire:click="setRange('{{ $preset }}')" @click="closePanel()" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-300 dark:hover:bg-slate-800">{{ $label }}</button>
                        @endforeach
                    </div>

                    <div class="mt-4 border-t border-slate-200/60 pt-4 dark:border-slate-800/60">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Custom</p>

                        {{-- From 640px: the range picker. Alpine renders it, so
                        Livewire's morph leaves it alone (wire:ignore); it reads
                        and writes $wire.fromDate / $wire.toDate directly.

                        Three views, like an ordinary calendar: days (where the
                        range is picked), and — through the heading — months and
                        years, for long jumps. A start day picked before zooming
                        out stays the anchor. Visual language is the employee
                        calendar's: Sunday first, today a filled primary circle
                        (aria-current="date"); the range is the shared selected
                        tint as a continuous band, its endpoints with the full
                        selected state (tint, primary border, semibold) — never a
                        solid primary fill. Zoomed out, the month or year being
                        opened carries the selected state and any month or year
                        the range reaches into carries the tint. --}}
                        @php
                            $pickerNavButton = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100';
                            // Month and year cells: same states as a day, as a 3-wide grid of buttons-in-cells.
                            $pickerZoomCell = 'flex h-10 w-full items-center justify-center rounded-lg text-sm transition group-focus-visible:ring-2 group-focus-visible:ring-primary-500 dark:group-focus-visible:ring-primary-400';
                        @endphp
                        <div class="hidden sm:block" wire:ignore x-ref="picker" @keydown="onKeydown($event)">
                            <div class="mt-2 flex items-center justify-between">
                                <button type="button" @click="step(-1)" :aria-label="stepLabels[0]" class="{{ $pickerNavButton }}">
                                    <x-icon name="chevron-left" class="h-4 w-4" />
                                </button>
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    <button
                                        type="button"
                                        x-show="view !== 'years'"
                                        @click="zoomOut()"
                                        :aria-label="zoomLabel"
                                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:hover:bg-slate-800"
                                    >
                                        <span x-text="heading"></span>
                                        <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                                    </button>
                                    <span x-show="view === 'years'" class="inline-block px-2 py-1 tabular-nums" x-text="heading"></span>
                                </h3>
                                <button type="button" @click="step(1)" :aria-label="stepLabels[1]" class="{{ $pickerNavButton }}">
                                    <x-icon name="chevron-right" class="h-4 w-4" />
                                </button>
                            </div>
                            {{-- The grid's label, announced politely as it changes
                            (paging months, zooming out or in). --}}
                            <span class="sr-only" aria-live="polite" x-text="gridLabel"></span>

                            {{-- Days --}}
                            <table x-show="view === 'days'" role="grid" :aria-label="gridLabel" class="mt-2 w-full table-fixed border-collapse">
                                <thead>
                                    <tr>
                                        @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday)
                                            <th scope="col" abbr="{{ $weekday }}" class="pb-1 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                <span aria-hidden="true">{{ substr($weekday, 0, 2) }}</span>
                                                <span class="sr-only">{{ $weekday }}</span>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody @mouseleave="anchor && (preview = focused)">
                                    <template x-for="week in weeks" :key="week.key">
                                        <tr>
                                            <template x-for="(cell, col) in week.days" :key="col">
                                                <td
                                                    class="group relative h-10 p-0 text-center focus:outline-none"
                                                    :class="cell && 'cursor-pointer'"
                                                    :data-date="cell ? cell.iso : null"
                                                    :tabindex="cell ? (cell.iso === focused ? 0 : -1) : null"
                                                    :aria-selected="cell ? String(isSelected(cell.iso)) : null"
                                                    :aria-current="cell && cell.iso === today ? 'date' : null"
                                                    :aria-label="cell ? label(cell.iso) : null"
                                                    @click="cell && pick(cell.iso)"
                                                    @mouseenter="cell && anchor && (preview = cell.iso)"
                                                    @focus="cell && (focused = cell.iso)"
                                                >
                                                    <template x-if="cell">
                                                        <div aria-hidden="true">
                                                            <span class="pointer-events-none absolute inset-y-0.5 bg-primary-50 dark:bg-primary-900/30" :class="bandClass(cell)"></span>
                                                            <span
                                                                class="relative mx-auto flex h-9 w-9 items-center justify-center rounded-lg text-sm tabular-nums transition group-focus-visible:ring-2 group-focus-visible:ring-primary-500 dark:group-focus-visible:ring-primary-400"
                                                                :class="{
                                                                    'date-range-endpoint bg-primary-50 font-semibold text-primary-700 ring-1 ring-inset ring-primary-600 dark:text-primary-200 dark:ring-primary-500': isStart(cell.iso) || isEnd(cell.iso),
                                                                    'font-medium text-primary-700 dark:text-primary-200': inRange(cell.iso) && ! isStart(cell.iso) && ! isEnd(cell.iso),
                                                                    'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800': ! inRange(cell.iso),
                                                                }"
                                                            >
                                                                <span
                                                                    :class="cell.iso === today && 'inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary-600 font-bold text-white dark:bg-primary-400 dark:text-slate-900'"
                                                                    x-text="cell.day"
                                                                ></span>
                                                            </span>
                                                        </div>
                                                    </template>
                                                </td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            {{-- Months --}}
                            <table x-show="view === 'months'" x-cloak role="grid" :aria-label="gridLabel" class="mt-2 w-full table-fixed border-separate border-spacing-1">
                                <tbody>
                                    <template x-for="row in monthRows" :key="row.key">
                                        <tr>
                                            <template x-for="cell in row.cells" :key="cell.key">
                                                <td
                                                    class="group cursor-pointer p-0 focus:outline-none"
                                                    :data-month="cell.key"
                                                    :tabindex="cell.key === focusedMonthKey ? 0 : -1"
                                                    :aria-selected="String(cell.key === focusedMonthKey)"
                                                    :aria-current="cell.key === today.slice(0, 7) ? 'date' : null"
                                                    :aria-label="cell.name"
                                                    @click="pickMonth(cell.key)"
                                                    @focus="focused = sameDayInMonth(cell.key)"
                                                >
                                                    <span
                                                        aria-hidden="true"
                                                        class="{{ $pickerZoomCell }}"
                                                        :class="{
                                                            'date-range-endpoint bg-primary-50 font-semibold text-primary-700 ring-1 ring-inset ring-primary-600 dark:text-primary-200 dark:ring-primary-500': cell.key === focusedMonthKey,
                                                            'bg-primary-50 font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-200': cell.key !== focusedMonthKey && touchesRange(cell),
                                                            'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800': cell.key !== focusedMonthKey && ! touchesRange(cell) && ! (cell.key === today.slice(0, 7)),
                                                            'font-semibold text-primary-700 hover:bg-slate-100 dark:text-primary-300 dark:hover:bg-slate-800': cell.key !== focusedMonthKey && ! touchesRange(cell) && cell.key === today.slice(0, 7),
                                                        }"
                                                        x-text="cell.label"
                                                    ></span>
                                                </td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            {{-- Years --}}
                            <table x-show="view === 'years'" x-cloak role="grid" :aria-label="gridLabel" class="mt-2 w-full table-fixed border-separate border-spacing-1">
                                <tbody>
                                    <template x-for="row in yearRows" :key="row.key">
                                        <tr>
                                            <template x-for="cell in row.cells" :key="cell.year">
                                                <td
                                                    class="group cursor-pointer p-0 focus:outline-none"
                                                    :data-year="cell.year"
                                                    :tabindex="cell.year === focusedYear ? 0 : -1"
                                                    :aria-selected="String(cell.year === focusedYear)"
                                                    :aria-current="String(cell.year) === today.slice(0, 4) ? 'date' : null"
                                                    :aria-label="String(cell.year)"
                                                    @click="pickYear(cell.year)"
                                                    @focus="focused = sameDayInYear(cell.year)"
                                                >
                                                    <span
                                                        aria-hidden="true"
                                                        class="{{ $pickerZoomCell }} tabular-nums"
                                                        :class="{
                                                            'date-range-endpoint bg-primary-50 font-semibold text-primary-700 ring-1 ring-inset ring-primary-600 dark:text-primary-200 dark:ring-primary-500': cell.year === focusedYear,
                                                            'bg-primary-50 font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-200': cell.year !== focusedYear && touchesRange(cell),
                                                            'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800': cell.year !== focusedYear && ! touchesRange(cell) && ! (String(cell.year) === today.slice(0, 4)),
                                                            'font-semibold text-primary-700 hover:bg-slate-100 dark:text-primary-300 dark:hover:bg-slate-800': cell.year !== focusedYear && ! touchesRange(cell) && String(cell.year) === today.slice(0, 4),
                                                        }"
                                                        x-text="cell.year"
                                                    ></span>
                                                </td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400" x-text="hint"></p>
                        </div>

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
                                    class="block w-full min-w-0 rounded-lg border-slate-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100"
                                >
                            </div>
                            <div class="min-w-0">
                                <x-input-label for="attendance-to" value="To" class="!mb-1 !text-xs" />
                                <input
                                    id="attendance-to"
                                    type="date"
                                    wire:model.live="toDate"
                                    class="block w-full min-w-0 rounded-lg border-slate-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100"
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
                    class="block w-full rounded-lg border-slate-300 bg-white pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500"
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
            $chipBase = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-offset-slate-900';
            $chipSelected = 'font-semibold bg-primary-50 text-primary-700 ring-primary-600 hover:bg-primary-100 dark:bg-primary-900/30 dark:text-primary-200 dark:ring-primary-500 dark:hover:bg-primary-900/50';
            $chipUnselected = 'font-medium bg-white text-slate-600 ring-slate-300 hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-700 dark:hover:text-white dark:active:bg-slate-600';
            $workingStatuses = collect($allStatuses)->reject(fn ($status) => $status === \App\Enums\AttendanceStatus::Off);
            $allWorkingSelected = $workingStatuses->every(fn ($status) => in_array($status->value, $statuses, true));
            $showsOffDays = in_array(\App\Enums\AttendanceStatus::Off->value, $statuses, true);
        @endphp
        <div class="grid gap-4 border-t border-slate-200/60 px-5 py-4 dark:border-slate-800/60 sm:px-6 xl:grid-cols-[minmax(0,1fr)_auto]">
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
            <div class="border-t border-slate-200/60 px-6 py-10 text-center dark:border-slate-800/60">
                <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                    <x-icon name="clock" class="h-5 w-5" />
                </span>
                <h3 class="mt-3 text-sm font-medium text-slate-900 dark:text-slate-100">No attendance records</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Try widening the date range or adjusting the filters above.</p>
            </div>
        @else
            <div class="border-t border-slate-200/60 dark:border-slate-800/60">
                {{-- The table's natural width is ~1119px; it first fits the card
                at a 1440px viewport (1134px card), so the cue shows below that —
                including 1024–1439, where it used to be hidden while the table
                still scrolled. --}}
                <div class="flex items-center justify-end gap-1.5 px-5 py-2 text-xs text-slate-500 dark:text-slate-400 min-[1440px]:hidden" aria-hidden="true">
                    <span>Scroll to view all columns</span>
                    <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                </div>

                {{-- Below xl the Status and chevron columns are pinned to the
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
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 z-[2] h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
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
                            <tr wire:key="daily-attendance-{{ $attendance->id }}" class="group relative hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums {{ $dateCellClass }}">
                                    {{ $workDateLabel }}
                                    {{-- The row divider lives in the first cell (positioned
                                    against the row) and sits above the pinned cells, so it
                                    runs unbroken beneath them. --}}
                                    @unless ($loop->last)
                                        <span class="pointer-events-none absolute inset-x-6 bottom-0 z-[2] h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
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
                                            <span class="text-slate-400 dark:text-slate-500">(+1)</span>
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
                                            class="-my-1 ml-auto inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-300 dark:group-hover:text-primary-400"
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
                <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                    {{ $attendances->links() }}
                </div>
            @endif
        @endif
    </x-card>
    @endif
</div>
