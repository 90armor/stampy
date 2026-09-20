@php
    // Colour comes from DailyAttendance::displayVariant() everywhere on this
    // page — "did they attend" (status) and "was the timing off" (late/early
    // minutes) are independent facts (see AttendanceStatus's doc comment),
    // so no lookup here keys off late_minutes/early_leave_minutes/status
    // directly. 'timing' is the variant for a Present day with a late
    // arrival and/or early leave — needed here for the per-row Status badge
    // (a row can resolve to it), but NOT for the Present stat card below:
    // that tile aggregates every present row, on-time or not, so it stays
    // green/check like Present itself — only its subtext breaks out how
    // many of those were late/early, it doesn't recolour the whole tile.
    $variantStyles = [
        'present' => ['icon' => 'check', 'badge' => 'green', 'iconClass' => 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'],
        'timing' => ['icon' => 'clock', 'badge' => 'amber', 'iconClass' => 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400'],
        // violet, not amber — matches Attendance\Show's calendar/day-modal/
        // table (see CLAUDE.md's "Status colors" note): Incomplete is a
        // device defect (a punch never recorded), a late/early timing
        // exception is normal employee behavior, and the two used to be
        // visually indistinguishable here.
        'incomplete' => ['icon' => 'exclamation-triangle', 'badge' => 'violet', 'iconClass' => 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300'],
        'absent' => ['icon' => 'user-x', 'badge' => 'red', 'iconClass' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400'],
        'off' => ['icon' => 'calendar-days', 'badge' => 'slate', 'iconClass' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'],
        // blue/fuchsia — see Attendance\Show's calendar (same "Status colors"
        // reasoning: in_progress must not read as red/amber ("not yet", not
        // a failure), and holiday must not reuse primary/accent's own green
        // family, which would repeat present's hue.
        'in_progress' => ['icon' => 'clock', 'badge' => 'blue', 'iconClass' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
        'holiday' => ['icon' => 'flag', 'badge' => 'fuchsia', 'iconClass' => 'bg-fuchsia-50 text-fuchsia-700 dark:bg-fuchsia-900/30 dark:text-fuchsia-300'],
        // Accent + briefcase, matching Attendance\Show's calendar — a day off that used leave balance must not look like a weekend (off).
        'leave' => ['icon' => 'briefcase', 'badge' => 'accent', 'iconClass' => 'bg-accent-50 text-accent-700 dark:bg-accent-900/30 dark:text-accent-300'],
    ];

    $isToday = $fromDate === $toDate && $fromDate === today()->format('Y-m-d');
    $rangeLabel = match (true) {
        $isToday => 'Today',
        $fromDate === $toDate => \Illuminate\Support\Carbon::parse($fromDate)->format('M j, Y'),
        default => \Illuminate\Support\Carbon::parse($fromDate)->format('M j').' – '.\Illuminate\Support\Carbon::parse($toDate)->format('M j, Y'),
    };
@endphp

<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Attendance</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Daily attendance</h1>
    </div>

    @if ($scopeHasNoEmployeeRecord)
        {{-- A manager-role account with no linked employees row has no
        position in the org tree, so scopedEmployeeIds() is deliberately
        empty rather than "all employees" — filters/stat cards would be
        meaningless over zero rows, so this replaces them entirely with an
        explanation instead of a bare "no results" table. --}}
        <x-card>
            <x-empty-state
                icon="user-x"
                title="Your account isn't linked to an employee record"
                description="Attendance can't be scoped to you until an admin links this login to an employee profile. Contact an admin to get this set up."
            />
        </x-card>
    @else
    {{-- Always exactly these 3 (Present/Absent/Incomplete), 0 shown plainly
    when a status has no rows — not appear/disappear based on whether data
    exists — same as Employees' Total/Active/Inactive. Off/Holiday/Leave are
    passive/expected states, not KPIs an admin needs to monitor, so they're
    left out here (still filterable as chips below, and still shown as a
    badge on individual table rows).

    Late/Early leave are NOT peer tiles here, even though they used to be:
    a late or early day is already one of the Present rows counted above,
    not an additional one (see CLAUDE.md's "Status vs. timing" note) — a
    separate "Late" tile next to "Present" implied they were disjoint and
    summed to a total, which was true back when Late was its own status but
    is wrong now. Shown as a sub-line under Present instead ("of which..."),
    which is the containment made visible with the least structural change —
    the alternative (nesting Present/Late/Early into one grouped card) would
    need a new component for something this page is the only user of. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card
            :icon="$variantStyles['present']['icon']"
            label="Present"
            :value="$summary['present']"
            :icon-class="$variantStyles['present']['iconClass']"
        >
            @if ($summary['late'] > 0 || $summary['early'] > 0)
                <x-slot name="subtext">
                    of which
                    @if ($summary['late'] > 0)
                        {{ $summary['late'] }} late
                    @endif
                    @if ($summary['late'] > 0 && $summary['early'] > 0)
                        &middot;
                    @endif
                    @if ($summary['early'] > 0)
                        {{ $summary['early'] }} left early
                    @endif
                </x-slot>
            @endif
        </x-stat-card>
        <x-stat-card
            :icon="$variantStyles['absent']['icon']"
            label="Absent"
            :value="$summary['absent']"
            :icon-class="$variantStyles['absent']['iconClass']"
        />
        <x-stat-card
            :icon="$variantStyles['incomplete']['icon']"
            label="Incomplete"
            :value="$summary['incomplete']"
            :icon-class="$variantStyles['incomplete']['iconClass']"
        />
    </div>

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-4 p-6 pb-0">
            <h2 class="flex items-baseline gap-x-2 text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                All attendance
                <span class="text-xs font-medium text-slate-400 dark:text-slate-500">{{ $attendances->total() }} shown</span>
            </h2>

            <button
                type="button"
                wire:click="resetFilters"
                class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-primary-600 dark:text-slate-400 dark:hover:text-primary-400"
            >
                <x-icon name="x-mark" class="h-3.5 w-3.5" />
                Reset filters
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-3 p-6">
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button
                    type="button"
                    @click="open = !open"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white/80 backdrop-blur-sm px-3 py-2 text-sm text-slate-700 shadow-sm hover:bg-white focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    <x-icon name="calendar-days" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                    {{ $rangeLabel }}
                </button>

                {{-- Deliberately opaque, not glass: this overlays the solid
                status chips and table rows right below it, and even a 95%
                translucent + blurred surface let their color bleed through
                enough to hurt legibility. The topbar's dropdown (x-dropdown)
                gets away with glass because it never sits over saturated
                content — this one does, so it's a narrow exception.

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
                    x-show="open"
                    x-cloak
                    class="absolute left-0 z-20 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-lg bg-white p-4 shadow-xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Quick ranges</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="setRange('today')" @click="open = false" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Today</button>
                        <button type="button" wire:click="setRange('yesterday')" @click="open = false" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Yesterday</button>
                        <button type="button" wire:click="setRange('last7')" @click="open = false" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Last 7 days</button>
                        <button type="button" wire:click="setRange('last30')" @click="open = false" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Last 30 days</button>
                        <button type="button" wire:click="setRange('thisMonth')" @click="open = false" class="rounded-lg px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">This month</button>
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
                                    class="block w-full min-w-0 rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100"
                                >
                            </div>
                            <div class="min-w-0">
                                <x-input-label for="attendance-to" value="To" class="!mb-1 !text-xs" />
                                <input
                                    id="attendance-to"
                                    type="date"
                                    wire:model.live="toDate"
                                    class="block w-full min-w-0 rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative min-w-[200px] flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 w-4 h-4 text-slate-400 dark:text-slate-500" />
                <input
                    id="attendance-search"
                    type="text"
                    wire:model.live.debounce.300ms="employeeFilter"
                    placeholder="Name or employee code…"
                    class="block w-full rounded-lg border-slate-300 bg-white/80 backdrop-blur-sm pl-9 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500"
                >
            </div>

            <div>
                <x-select id="attendance-department" wire:model.live="departmentFilter">
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
        themselves signals that grouping. --}}
        <p class="px-6 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Status</p>
        <div class="flex flex-wrap gap-2 px-6 pb-4">
            @foreach ($allStatuses as $status)
                @php $selected = in_array($status->value, $statuses, true); @endphp
                <button
                    type="button"
                    wire:click="toggleStatus('{{ $status->value }}')"
                    @class([
                        'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium transition',
                        'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $selected,
                        'bg-transparent text-slate-500 ring-1 ring-inset ring-slate-300 hover:border-slate-400 hover:text-slate-700 dark:text-slate-400 dark:ring-slate-700 dark:hover:text-slate-200' => ! $selected,
                    ])
                >
                    {{ $status->label() }}
                </button>
            @endforeach
        </div>

        {{-- Timing (late arrival / early departure) is independent of status
        (see AttendanceStatus's doc comment) — filtering for it used to be
        impossible since "late" wasn't a filterable status any more than
        "early leave" ever was. Same chip styling and OR-across-selected
        semantics as the status chips above, just a separate #[Url]-bound
        property so the two filters combine independently. --}}
        <p class="px-6 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Timing</p>
        <div class="flex flex-wrap gap-2 px-6 pb-6">
            @foreach (['late' => 'Late arrival', 'early' => 'Early departure'] as $value => $label)
                @php $selected = in_array($value, $timingFilters, true); @endphp
                <button
                    type="button"
                    wire:click="toggleTimingFilter('{{ $value }}')"
                    @class([
                        'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium transition',
                        'bg-amber-600 text-white shadow-sm dark:bg-amber-500' => $selected,
                        'bg-transparent text-slate-500 ring-1 ring-inset ring-slate-300 hover:border-slate-400 hover:text-slate-700 dark:text-slate-400 dark:ring-slate-700 dark:hover:text-slate-200' => ! $selected,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
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
            <x-empty-state
                icon="clock"
                title="No attendance records"
                description="Try widening the date range or adjusting the filters above."
            />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th class="whitespace-nowrap px-6 py-3">Date</th>
                            <th class="min-w-[11rem] px-6 py-3">Employee</th>
                            <th class="px-6 py-3">Department</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right">Early leave</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="py-3 pl-2 pr-6">
                                <span class="sr-only">Open detail</span>
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attendances as $attendance)
                            @php
                                $style = $variantStyles[$attendance->displayVariant()];

                                // Marked times (see CLAUDE.md's "Marked times" note) — the
                                // Status badge shows the real attendance status ("Present"),
                                // so the In/Out cells are where the specific late-arrival/
                                // early-leave discrepancy is pointed out, matching the
                                // calendar's convention, instead of it only living in the
                                // Late/Early leave columns.
                                $markedLate = $attendance->isLate();
                                $markedEarly = $attendance->leftEarly();
                                $markedTimeClass = 'text-red-700 underline decoration-red-600 decoration-2 underline-offset-2 dark:text-red-300 dark:decoration-red-400';
                            @endphp
                            <tr wire:key="daily-attendance-{{ $attendance->id }}" class="group relative hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-700 dark:text-slate-300">{{ $attendance->work_date->format('D j M') }}</td>
                                <td class="px-6 py-4">
                                    {{-- Resting-state accent color (not just on hover) + underline-on-hover
                                    + a visible focus ring is the app's new "this is a link" convention —
                                    see CLAUDE.md's Design system → Links. Hover alone isn't enough on
                                    touch devices, which never trigger it. --}}
                                    <a
                                        href="{{ route('attendance.show', $attendance->employee) }}"
                                        wire:navigate
                                        class="inline-block rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                                    >
                                        <div class="whitespace-nowrap font-medium text-primary-700 underline decoration-1 underline-offset-2 decoration-primary-300 transition hover:decoration-primary-600 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400">{{ $attendance->employee->full_name }}</div>
                                        <div class="text-sm text-slate-500 dark:text-slate-400">{{ $attendance->employee->employee_code }}</div>
                                    </a>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">{{ $attendance->employee->department->name }}</td>
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    @if ($attendance->first_in)
                                        @if ($markedLate)
                                            <x-time :time="$attendance->first_in" class="{{ $markedTimeClass }}" aria-label="Arrived {{ $attendance->late_minutes }} minute{{ $attendance->late_minutes === 1 ? '' : 's' }} late" />
                                        @else
                                            <x-time :time="$attendance->first_in" />
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    @if ($attendance->last_out)
                                        @if ($markedEarly)
                                            <x-time :time="$attendance->last_out" class="{{ $markedTimeClass }}" aria-label="Left {{ $attendance->early_leave_minutes }} minute{{ $attendance->early_leave_minutes === 1 ? '' : 's' }} early" />
                                        @else
                                            <x-time :time="$attendance->last_out" />
                                        @endif
                                        @if ($attendance->isOvernightOut())
                                            <span class="text-slate-400 dark:text-slate-500">(+1)</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $attendance->formattedWorkedMinutes() ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $attendance->isLate() ? $attendance->late_minutes.'m' : '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $attendance->leftEarly() ? $attendance->early_leave_minutes.'m' : '—' }}</td>
                                <td class="px-6 py-4">
                                    {{-- Status only — the adjacent Late/Early leave columns
                                    already show the minutes (aligned, scannable), and the
                                    marked In/Out times already point at which one; a third
                                    "Late 21m" chip here repeated the same fact and bloated
                                    row height. Colour still comes from displayVariant(), so
                                    a timing exception still reads amber, not green. --}}
                                    <x-badge :color="$style['badge']">{{ $attendance->status->label() }}</x-badge>
                                </td>
                                <td class="py-4 pl-2 pr-6 text-right">
                                    <x-icon name="chevron-right" class="ml-auto h-4 w-4 text-slate-400 transition group-hover:text-primary-600 dark:text-slate-500 dark:group-hover:text-primary-400" />
                                    @unless ($loop->last)
                                        <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                {{ $attendances->links() }}
            </div>
        @endif
    </x-card>
    @endif
</div>
