@php
    $statusStyles = [
        'present' => ['icon' => 'check', 'badge' => 'green', 'iconClass' => 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'],
        'late' => ['icon' => 'clock', 'badge' => 'amber', 'iconClass' => 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400'],
        'incomplete' => ['icon' => 'exclamation-triangle', 'badge' => 'amber', 'iconClass' => 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400'],
        'absent' => ['icon' => 'user-x', 'badge' => 'red', 'iconClass' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400'],
        'off' => ['icon' => 'calendar-days', 'badge' => 'slate', 'iconClass' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'],
        'holiday' => ['icon' => 'calendar-days', 'badge' => 'slate', 'iconClass' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'],
        'leave' => ['icon' => 'calendar-days', 'badge' => 'slate', 'iconClass' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'],
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

    {{-- Always exactly these 4 (Present/Late/Absent/Incomplete), 0 shown
    plainly when a status has no rows — not appear/disappear based on
    whether data exists — same as Employees' Total/Active/Inactive.
    Off/Holiday/Leave are passive/expected states, not KPIs an admin needs
    to monitor, so they're left out here (still filterable as chips below,
    and still shown as a badge on individual table rows). --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ($summary as $statusValue => $count)
            @php $style = $statusStyles[$statusValue]; @endphp
            <x-stat-card
                :icon="$style['icon']"
                :label="\App\Enums\AttendanceStatus::from($statusValue)->label()"
                :value="$count"
                :icon-class="$style['iconClass']"
            />
        @endforeach
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

        <div class="flex flex-wrap gap-2 px-6 pb-6">
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
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3">Employee</th>
                            <th class="px-6 py-3">Department</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right">Early leave</th>
                            <th class="px-6 py-3">
                                Status
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attendances as $attendance)
                            @php $style = $statusStyles[$attendance->status->value] ?? $statusStyles['off']; @endphp
                            <tr wire:key="daily-attendance-{{ $attendance->id }}" class="relative hover:bg-slate-50 dark:hover:bg-slate-800/60">
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">{{ $attendance->work_date->format('D j M') }}</td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-slate-900 dark:text-slate-100">{{ $attendance->employee->full_name }}</div>
                                    <div class="text-sm text-slate-500 dark:text-slate-400">{{ $attendance->employee->employee_code }}</div>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">{{ $attendance->employee->department->name }}</td>
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    {{ $attendance->first_in?->format('H:i') ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    @if ($attendance->last_out)
                                        {{ $attendance->last_out->format('H:i') }}
                                        @if ($attendance->isOvernightOut())
                                            <span class="text-slate-400 dark:text-slate-500">(+1)</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $attendance->formattedWorkedMinutes() ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $attendance->late_minutes > 0 ? $attendance->late_minutes.'m' : '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $attendance->early_leave_minutes > 0 ? $attendance->early_leave_minutes.'m' : '—' }}</td>
                                <td class="px-6 py-4">
                                    <x-badge :color="$style['badge']">{{ $attendance->status->label() }}</x-badge>
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
</div>
