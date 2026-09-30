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
        $fromDate === $toDate => \Illuminate\Support\Carbon::parse($fromDate)->format('M j, Y'),
        default => \Illuminate\Support\Carbon::parse($fromDate)->format('M j').' – '.\Illuminate\Support\Carbon::parse($toDate)->format('M j, Y'),
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
        $from = \Illuminate\Support\Carbon::parse($fromDate);
        $to = \Illuminate\Support\Carbon::parse($toDate);
        $summaryRange = match (true) {
            $from->isSameDay($to) => $from->format('D, M j'),
            $from->isSameMonth($to) => $from->format('M j').'–'.$to->format('j'),
            $from->isSameYear($to) => $from->format('M j').' – '.$to->format('M j'),
            default => $from->format('M j, Y').' – '.$to->format('M j, Y'),
        };
        $summaryScope = $summaryRange.' · '.($employeeFilter !== '' || $departmentFilter !== '' ? 'all employees, all statuses' : 'all statuses');
    @endphp
    <x-card :padding="false">
        {{-- Card header pattern: the scope is right-aligned muted meta on a
        header row, inset to the cells' own padding. --}}
        <div class="flex items-baseline justify-end gap-4 px-3 pt-3 sm:px-4">
            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ $summaryScope }}</p>
        </div>
        <dl class="grid grid-cols-3 divide-x divide-slate-200/60 dark:divide-slate-800/60">
            <x-stat-card icon="check" label="Present" :value="$summary['present']">
                @if ($timingParts)
                    <x-slot:subtext>{{ implode(' · ', $timingParts) }}</x-slot:subtext>
                @endif
            </x-stat-card>
            <x-stat-card icon="user-x" label="Absent" :value="$summary['absent']" />
            <x-stat-card icon="exclamation-triangle" label="Incomplete" :value="$summary['incomplete']" />
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
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button
                    type="button"
                    @click="open = !open"
                    :aria-expanded="open"
                    aria-controls="attendance-date-panel"
                    class="inline-flex w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 lg:w-auto"
                >
                    <span class="inline-flex items-center gap-2"><x-icon name="calendar-days" class="h-4 w-4 text-slate-400 dark:text-slate-500" />{{ $rangeLabel }}</span>
                    <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                </button>

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
                    x-show="open"
                    x-cloak
                    class="absolute left-0 z-20 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl bg-white p-4 shadow-xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Quick ranges</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'thisMonth' => 'This month'] as $preset => $label)
                            <button type="button" wire:click="setRange('{{ $preset }}')" @click="open = false" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-300 dark:hover:bg-slate-800">{{ $label }}</button>
                        @endforeach
                    </div>

                    {{-- From/To stacked, not side-by-side: a native date
                    input's intrinsic width (digits + picker icon, ~150-180px
                    depending on browser/OS/locale) doesn't reliably fit two
                    across this panel's ~288px content width (w-80 minus p-4
                    padding, minus the gap between columns) — that's exactly
                    what the reported overflow was. Stacked, each input gets
                    the full content width, well clear of any browser's
                    intrinsic minimum. min-w-0 on top of that so a flex/grid
                    child can never be held back above w-full by content
                    size in the first place. --}}
                    <div class="mt-4 border-t border-slate-200/60 pt-4 dark:border-slate-800/60">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Custom</p>
                        <div class="mt-2 space-y-3">
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
        a primary-50 tint with primary text, a primary border and a check;
        unselected is a neutral outline; never a solid fill. The status chips
        narrow rather than enumerate — while every working status is in the
        set (the default), none of them reads as selected, so the default
        state looks like what it is: no filter. Off is its own "Show off
        days" toggle. See Index::toggleStatus() for the mapping. --}}
        @php
            $chipBase = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-offset-slate-900';
            $chipSelected = 'bg-primary-50 text-primary-700 ring-primary-600 hover:bg-primary-100 dark:bg-primary-900/30 dark:text-primary-200 dark:ring-primary-500 dark:hover:bg-primary-900/50';
            $chipUnselected = 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-700 dark:hover:text-white dark:active:bg-slate-600';
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
                    Attendance has only been calculated up to <strong>{{ \Illuminate\Support\Carbon::parse($maxBuiltDate)->format('M j, Y') }}</strong>.
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
                <div class="flex items-center justify-end gap-1.5 px-5 py-2 text-xs text-slate-500 dark:text-slate-400 lg:hidden" aria-hidden="true">
                    <span>Scroll to view all columns</span>
                    <x-icon name="chevron-right" class="h-3.5 w-3.5" />
                </div>

                <div class="overflow-x-auto transition-opacity" wire:loading.class="opacity-60">
                    {{-- Column order puts identity and status first (Employee, Status,
                    then Date), then the times, then Department, so the columns that
                    answer "who, and did they attend" are the leftmost and visible
                    without scrolling at narrow widths. No column is ever hidden
                    (docs/ATTENDANCE_UI.md). --}}
                    <table class="min-w-[64rem] w-full">
                    <thead>
                        <tr class="relative whitespace-nowrap text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th class="min-w-[11rem] px-6 py-3">Employee</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right"><abbr title="Early leave" class="no-underline">Early</abbr></th>
                            <th class="px-6 py-3">Department</th>
                            <th class="py-3 pl-2 pr-6">
                                <span class="sr-only">Open detail</span>
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
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
                                $workDateLabel = $attendance->work_date->format('D j M');
                            @endphp
                            <tr wire:key="daily-attendance-{{ $attendance->id }}" class="group relative hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                {{-- Plain text, not a link: the row's one navigation target is
                                the chevron at the end, so the identity column reads as data
                                and every cell stays selectable. --}}
                                <td class="px-6 py-2">
                                    <div class="whitespace-nowrap text-sm font-medium text-slate-900 dark:text-slate-100">{{ $attendance->employee->full_name }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ $attendance->employee->employee_code }}</div>
                                </td>
                                <td class="px-6 py-2">
                                    {{-- Status only — the Late/Early columns already show the
                                    timing (aligned, scannable, amber); a "Late 21m" chip here
                                    repeated the same fact and bloated row height. Colour comes
                                    from displayVariant(), which is status-only: a late Present
                                    day is a green "Present". --}}
                                    <x-badge :color="$style['badge']">{{ $attendance->status->label() }}</x-badge>
                                </td>
                                <td class="whitespace-nowrap px-6 py-2 text-sm tabular-nums {{ $dateCellClass }}">{{ $workDateLabel }}</td>
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
                                <td class="whitespace-nowrap px-6 py-2 text-sm text-slate-700 dark:text-slate-300">{{ $attendance->employee->department->name }}</td>
                                <td class="py-2 pl-2 pr-6 text-right">
                                    {{-- The row's only link: visible at rest, a 40px target
                                    (negative margin keeps it from growing the row), no
                                    whole-row click handler. --}}
                                    <a
                                        href="{{ route('attendance.show', $attendance->employee) }}?month={{ $attendance->work_date->format('Y-m') }}"
                                        wire:navigate
                                        aria-label="View attendance for {{ $attendance->employee->full_name }}, {{ $workDateLabel }}"
                                        class="-my-1 ml-auto inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-300 dark:group-hover:text-primary-400"
                                    >
                                        <x-icon name="chevron-right" class="h-4 w-4" />
                                    </a>
                                    @unless ($loop->last)
                                        <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                    @endunless
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
