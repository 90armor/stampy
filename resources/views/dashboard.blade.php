<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Welcome back, {{ auth()->user()->name }}. <span class="whitespace-nowrap">{{ \App\Support\DisplayDate::long(now()) }}</span>
            </p>
        </div>
        <x-button :href="$stats ? route('attendance.index') : route('attendance.mine')" variant="secondary" wire:navigate class="self-start sm:self-auto">
            <x-icon name="calendar-days" class="h-5 w-5" />
            {{ $stats ? 'View attendance' : 'View my attendance' }}
        </x-button>
    </header>

    @if ($stats)
        @php
            $segments = collect($attendance['today']['segments']);
            $setupSteps = collect([
                ['label' => 'Departments', 'done' => $attendance['onboarding']['departments']],
                ['label' => 'Positions', 'done' => $attendance['onboarding']['positions']],
                ['label' => 'Employee records', 'done' => $attendance['onboarding']['employees']],
            ]);
            $showSetup = $setupSteps->contains(fn ($step) => ! $step['done']);
            $quickActions = collect([
                ['label' => 'Add employee', 'route' => 'employees.index', 'icon' => 'plus', 'ability' => ['create', \App\Models\Employee::class]],
                ['label' => 'Add department', 'route' => 'organization.index', 'icon' => 'plus', 'ability' => ['create', \App\Models\Department::class]],
                ['label' => 'View employees', 'route' => 'employees.index', 'icon' => 'users', 'ability' => ['viewAny', \App\Models\Employee::class]],
                ['label' => 'Organization settings', 'route' => 'organization.index', 'icon' => 'building-office', 'ability' => ['viewAny', \App\Models\Department::class]],
            ])->filter(fn ($action) => auth()->user()->can(...$action['ability']))->values();
            $showQuickActions = $quickActions->isNotEmpty()
                && ! ($quickActions->count() === 1 && $quickActions->first()['route'] === 'employees.index');
        @endphp

        @php
            // The shared stat strip in its live "who is here now" form for
            // today (docs/ATTENDANCE_UI.md), then headcount. Below sm the cells
            // stack full-width with horizontal dividers (see x-stat-card).
            $live = $attendance['live'];
            // No separate headcount cell: the total is already the strip's meta
            // ("35 active employees"), and the three cells sum to it.
            $stripCells = collect(\App\Support\DashboardAttendance::liveTodayCells($live));
        @endphp
        <x-card :padding="false" class="mb-6">
            <div class="flex items-baseline justify-end gap-4 px-6 pt-4">
                <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">
                    Today, {{ \App\Support\DisplayDate::compact(today()) }} · {{ $attendance['today']['total'] }} active {{ $attendance['today']['total'] === 1 ? 'employee' : 'employees' }}
                </p>
            </div>
            <dl class="grid grid-cols-3 divide-x divide-slate-divider">
                @foreach ($stripCells as $cell)
                    <x-stat-card
                        :icon="$cell['icon']"
                        :label="$cell['label']"
                        :value="$cell['value']"
                    >
                        @if ($cell['subtext'] !== null)
                            <x-slot:subtext>{{ $cell['subtext'] }}</x-slot:subtext>
                        @endif
                    </x-stat-card>
                @endforeach
            </dl>
        </x-card>

        {{-- Two independent column stacks, not a row-based grid: each column
        flows at its own height, so a short card never leaves a hole beside a
        tall one. Two columns only from xl, with the side column at least 21rem:
        at lg its 4-of-12 share was about 224px and cut Needs attention's names
        to a few characters. Below xl the wrappers dissolve (`contents`) and the
        cards interleave by `order-*` into one reading order, needs-attention
        first.

        Card header pattern (docs/DESIGN_SYSTEM.md): title on the left,
        optional right-aligned muted meta on the right, no eyebrows. Time
        scope goes in the meta as real dates. --}}
        @php
            $trend = collect($attendance['trend']);
            $trendMeta = $trend->isNotEmpty()
                ? \App\Support\DisplayDate::range(\Illuminate\Support\Carbon::parse($trend->first()['date']), \Illuminate\Support\Carbon::parse($trend->last()['date']))
                : null;
            $todayMeta = \App\Support\DisplayDate::compact(today());
            $cardHeader = 'flex items-baseline justify-between gap-4';
            $cardTitle = 'text-lg font-semibold text-slate-900 dark:text-slate-100';
            $cardMeta = 'shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400';
            $avatar = 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 dark:bg-slate-750 dark:text-slate-300';
        @endphp
        <div class="flex flex-col gap-6 xl:grid xl:grid-cols-[minmax(0,2fr)_minmax(21rem,1fr)] xl:items-start">
            <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-6">
                <x-card class="order-2 min-w-0">
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">Attendance trend</h2>
                        @if ($trendMeta)<p class="{{ $cardMeta }}">{{ $trendMeta }}</p>@endif
                    </div>
                    @if ($trend->isNotEmpty())
                        <div class="relative mt-5 h-64 min-w-0 w-full overflow-hidden" role="img" aria-label="Attendance rate (present and incomplete) per day: {{ $trend->map(fn ($day) => $day['label'].' '.($day['marker'] ?? $day['value'].'%'))->implode(', ') }}">
                            <canvas
                                class="!h-full !w-full max-w-full"
                                id="attendance-trend-chart"
                                data-labels="{{ json_encode($trend->pluck('label')) }}"
                                data-values="{{ json_encode($trend->pluck('value')) }}"
                                data-markers="{{ json_encode($trend->pluck('marker')) }}"
                                data-pending="{{ json_encode($trend->pluck('pending')) }}"
                                aria-hidden="true"
                            ></canvas>
                        </div>
                    @else
                        <x-empty-state icon="clock" title="No weekly attendance yet" description="Trend data will appear when active employees have attendance records." />
                    @endif
                </x-card>

                <x-card class="order-3">
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">Department attendance</h2>
                        <p class="{{ $cardMeta }}">{{ $todayMeta }}</p>
                    </div>
                    @if (count($attendance['departments']))
                        <div class="mt-5 space-y-4">
                            @foreach ($attendance['departments'] as $department)
                                <div>
                                    {{-- Counts, never a bare percentage (Phase 2.7): "Checked in
                                    N / M" (any punch today, so the departments total the strip's
                                    At work + Left) with a lighter provisional bar while today is pending,
                                    "Attended N / M" (present + incomplete) once it has closed. --}}
                                    @php
                                        $departmentCount = $department['pending'] ? $department['checkedIn'] : $department['attended'];
                                        // M leaves out anyone on full-day approved leave today (Phase 3d).
                                        $departmentShare = $department['expected'] > 0 ? round($departmentCount / $department['expected'] * 100, 1) : 0;
                                        $allOnLeave = $department['expected'] <= 0 && $department['employees'] > 0;
                                    @endphp
                                    <div class="flex items-baseline justify-between gap-2">
                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $department['name'] }}</p>
                                        @if ($allOnLeave)
                                            <p class="text-sm text-slate-500 dark:text-slate-400">On leave</p>
                                        @else
                                            <p class="text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $department['pending'] ? 'Checked in' : 'Attended' }} <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $departmentCount }}</span> / {{ $department['expected'] }}</p>
                                        @endif
                                    </div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $department['employees'] }} {{ $department['employees'] === 1 ? 'employee' : 'employees' }}</p>
                                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-750"><div @class(['h-full rounded-full', 'bg-primary-200 dark:bg-primary-800' => $department['pending'], 'bg-primary-500' => ! $department['pending']]) style="width: {{ $departmentShare }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-empty-state icon="building-office" title="No departments yet" description="Add a department to see attendance by team." />
                    @endif
                </x-card>
            </div>

            <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-6">
                {{-- Approvers (Phase 3e): gated on the Approvals page's own
                ability through ApprovalBadge, which is 0 without it — and
                hidden at 0. First below xl too: on a phone it's the one
                notification there is. --}}
                @if (($waitingApprovals = \App\Support\ApprovalBadge::count(auth()->user())) > 0)
                    <x-card class="order-first">
                        <h2 class="{{ $cardTitle }}">Pending approvals</h2>
                        <p class="mt-2 flex items-baseline gap-x-2">
                            <span class="text-3xl font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $waitingApprovals }}</span> <span class="text-sm text-slate-500 dark:text-slate-400">leave {{ $waitingApprovals === 1 ? 'request' : 'requests' }} waiting on you</span>
                        </p>
                        <x-button :href="route('approvals.index')" variant="secondary" wire:navigate class="mt-4">
                            Review requests
                            <x-icon name="chevron-right" class="h-5 w-5" />
                        </x-button>
                    </x-card>
                @endif

                <x-card class="order-1">
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">Needs attention</h2>
                        {{-- The real total; the list shows the most urgent 8. --}}
                        @if ($attendance['needsAttentionTotal'] > 0)<p class="{{ $cardMeta }}">{{ $attendance['needsAttentionTotal'] }} today</p>@endif
                    </div>
                    @if (count($attendance['needsAttention']))
                        <ul class="mt-4 divide-y divide-slate-divider">
                            @foreach ($attendance['needsAttention'] as $person)
                                <li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                    <span class="{{ $avatar }}" aria-hidden="true">{{ strtoupper(substr($person['name'], 0, 1)) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $person['name'] }}</p>
                                        @if (in_array($person['kind'], ['not_in_yet', 'worked_on_leave'], true))
                                            {{-- Not in yet and worked on leave are derived facts, not a
                                            status or an absence: muted text, under the name so a
                                            narrow column doesn't truncate the name. --}}
                                            <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ $person['detail'] }}</p>
                                        @endif
                                    </div>
                                    @if ($person['badge'])
                                        <x-badge :color="$person['badge']">{{ $person['label'] }}</x-badge>
                                    @elseif ($person['kind'] === 'late')
                                        {{-- Late is timing, not a status: the amber duration alone. --}}
                                        <span class="shrink-0 text-sm font-medium tabular-nums text-amber-700 dark:text-amber-300">{{ $person['detail'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Nothing needs attention today.</p>
                    @endif
                </x-card>

                <x-card class="order-4">
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">Recent activity</h2>
                    </div>
                    @if (count($attendance['recent']))
                        <div class="mt-4 divide-y divide-slate-divider">
                            @foreach ($attendance['recent'] as $activity)
                                <div class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                    <span class="{{ $avatar }}" aria-hidden="true">{{ strtoupper(substr($activity['name'], 0, 1)) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $activity['name'] }}</p>
                                        {{-- A log, so neutral: the late fact is shown once, in Needs attention. --}}
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $activity['action'] }}</p>
                                    </div>
                                    <span class="shrink-0 text-right text-xs tabular-nums text-slate-500 dark:text-slate-400">
                                        @if ($activity['date'])<span class="block">{{ $activity['date'] }}</span>@endif
                                        {{ $activity['time'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-empty-state icon="inbox" title="No recent activity" description="Clock events will appear here as they are recorded." />
                    @endif
                </x-card>

                @if ($showQuickActions)
                    <x-card class="order-5">
                        <h2 class="{{ $cardTitle }}">Quick actions</h2>
                        <div class="mt-3 space-y-1">
                            @foreach ($quickActions as $action)
                                <a href="{{ route($action['route']) }}" wire:navigate class="group -mx-3 flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-300 dark:hover:bg-slate-600/30 dark:hover:text-white">
                                    <x-icon :name="$action['icon']" class="h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                                    {{ $action['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </x-card>
                @endif

                @if ($showSetup)
                    <x-card class="order-6">
                        <h2 class="{{ $cardTitle }}">Complete your organization</h2>
                        <ul class="mt-4 space-y-3">
                            @foreach ($setupSteps as $step)
                                <li class="flex items-center gap-x-2.5 text-sm">
                                    @if ($step['done'])<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-500 text-white"><x-icon name="check" class="h-5 w-5" /></span>@else<span class="h-5 w-5 shrink-0 rounded-full border-2 border-slate-border"></span>@endif
                                    <span class="text-slate-600 dark:text-slate-300">{{ $step['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                @endif
            </div>
        </div>
    @elseif ($mine)
        @php
            $cardHeader = 'flex items-baseline justify-between gap-4';
            $cardTitle = 'text-lg font-semibold text-slate-900 dark:text-slate-100';
            $cardMeta = 'shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400';
            $row = 'flex items-baseline justify-between gap-4 border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0';
            $link = 'rounded text-sm font-medium text-primary-700 underline decoration-primary-300 decoration-1 underline-offset-2 hover:decoration-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:decoration-primary-700 dark:hover:decoration-primary-400';
            $month = $mine['month'];
            // The same "of which" breakdowns as My attendance's summary, built
            // as one string (Blade keeps the whitespace between directives).
            $presentNote = implode(' · ', array_filter([
                $month['late'] > 0 ? $month['late'].' late' : null,
                $month['early_leave_days'] > 0 ? $month['early_leave_days'].' left early' : null,
            ]));
            $monthRows = array_filter([
                'Present' => [$month['present'], $presentNote !== '' ? 'of which '.$presentNote : null],
                'Absent' => [$month['absent'], null],
                'Incomplete' => [$month['incomplete'], $month['incomplete_late'] > 0 ? $month['incomplete_late'].' late' : null],
                'On leave' => $month['leave'] > 0 ? [$month['leave'], null] : null,
            ]);
        @endphp

        {{-- The employee dashboard (Phase 3e): decisions and balance on the left;
        upcoming leave and the month on the right from lg; one column below, in
        reading order — at most four cards on a phone. Two independent stacks, so a
        short card never leaves a hole. --}}
        <div class="grid gap-6 lg:grid-cols-2 lg:items-start">
            <div class="flex min-w-0 flex-col gap-6">
                {{-- Decided since they last opened Time off (LeaveDecisions), newest
                first, at most the last 30 days — the same "New" state Time off
                shows, which the title already says, so no per-item badge. Without
                email, this is where an employee finds out; a rejection's note is
                the reason, so it shows. --}}
                @if (count($mine['decided']))
                    <x-card>
                        <div class="{{ $cardHeader }}">
                            <h2 class="{{ $cardTitle }}">Decided since your last visit</h2>
                            <p class="{{ $cardMeta }}">{{ count($mine['decided']) }} new</p>
                        </div>
                        <ul class="mt-4">
                            @foreach ($mine['decided'] as $leave)
                                @php $decision = \App\Support\LeaveDecisions::note($leave); @endphp
                                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $leave->leaveType->name }} · <span class="tabular-nums">{{ $leave->displayDates() }}</span></p>
                                        <x-badge :color="$leave->status->badgeColor()">{{ $leave->status->label() }}</x-badge>
                                    </div>
                                    @if ($decision)
                                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">“{{ $decision->note }}” <span class="text-slate-500 dark:text-slate-400">— {{ $decision->decidedBy?->name ?? 'an approver' }}</span></p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-4"><a href="{{ route('time-off.index') }}" wire:navigate class="{{ $link }}">See them on Time off</a></div>
                    </x-card>
                @endif

                <x-card>
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">Leave balance</h2>
                        <p class="{{ $cardMeta }}">{{ today()->year }}</p>
                    </div>
                    @if (count($mine['balances']))
                        <dl class="mt-4">
                            @foreach ($mine['balances'] as $balance)
                                <div class="{{ $row }}">
                                    <dt class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $balance['name'] }}</dt>
                                    @if ($balance['usableFrom'] === null)
                                        <dd class="text-sm tabular-nums text-slate-500 dark:text-slate-400"><span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ \App\Support\LeaveDays::format($balance['available']) }}</span> available</dd>
                                    @else
                                        <dd class="text-right text-sm text-slate-500 dark:text-slate-400">{{ 'Usable from '.\App\Support\DisplayDate::compact($balance['usableFrom']).($balance['earnedSoFar'] !== null ? ' · '.\App\Support\LeaveDays::label($balance['earnedSoFar']).' earned so far' : '') }}</dd>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No leave balances yet.</p>
                    @endif
                    <div class="mt-4"><a href="{{ route('time-off.index') }}" wire:navigate class="{{ $link }}">Request leave on Time off</a></div>
                </x-card>
            </div>

            <div class="flex min-w-0 flex-col gap-6">
                {{-- Upcoming: pending requests with whose decision they wait for,
                then the next approved leave. --}}
                <x-card>
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">Upcoming</h2>
                        @if (count($mine['pending']))<p class="{{ $cardMeta }}">{{ count($mine['pending']) }} waiting</p>@endif
                    </div>
                    @if (count($mine['pending']) || $mine['next'])
                        <ul class="mt-4">
                            @foreach ($mine['pending'] as $item)
                                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $item['leave']->leaveType->name }} · <span class="tabular-nums">{{ $item['leave']->displayDates() }}</span></p>
                                        <x-badge :color="$item['leave']->status->badgeColor()">{{ $item['leave']->status->label() }}</x-badge>
                                    </div>
                                    <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ \App\Support\LeaveDays::label($item['days']).' · '.$item['leave']->waitingLabel() }}</p>
                                </li>
                            @endforeach
                            @if ($mine['next'])
                                @php $next = $mine['next']['leave']; @endphp
                                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $next->leaveType->name }} · <span class="tabular-nums">{{ $next->displayDates() }}</span></p>
                                        <x-badge :color="$next->status->badgeColor()">{{ $next->status->label() }}</x-badge>
                                    </div>
                                    <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([\App\Support\LeaveDays::label($mine['next']['days']), $next->startsLabel()])) }}</p>
                                </li>
                            @endif
                        </ul>
                        <div class="mt-4"><a href="{{ route('time-off.index') }}" wire:navigate class="{{ $link }}">See all requests on Time off</a></div>
                    @else
                        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No leave requested or coming up.</p>
                    @endif
                </x-card>

                <x-card>
                    <div class="{{ $cardHeader }}">
                        <h2 class="{{ $cardTitle }}">This month</h2>
                        <p class="{{ $cardMeta }}">{{ \App\Support\DisplayDate::month(today()) }}</p>
                    </div>
                    @if ($month['workdays'] > 0)
                        {{-- AttendanceSummary, the same counts as My attendance:
                        the status rows always add up to Calculated workdays. --}}
                        <dl class="mt-4">
                            <div class="{{ $row }}">
                                <dt class="text-sm text-slate-500 dark:text-slate-400">Calculated workdays</dt>
                                <dd class="text-base font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $month['workdays'] }}</dd>
                            </div>
                            @foreach ($monthRows as $label => [$count, $note])
                                <div class="{{ $row }}">
                                    <dt class="text-sm text-slate-500 dark:text-slate-400">
                                        {{ $label }}
                                        @if ($note)<span class="block text-xs">{{ $note }}</span>@endif
                                    </dt>
                                    <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $count }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($month['leave_tenths'] > 0 || $month['total_worked_minutes'] > 0)
                            <p class="mt-4 border-t border-slate-divider pt-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ implode(' · ', array_filter([
                                    $month['leave_tenths'] > 0 ? 'Leave taken: '.\App\Support\LeaveDays::label($month['leave_tenths']) : null,
                                    $month['total_worked_minutes'] > 0 ? 'Worked: '.\App\Support\Duration::format($month['total_worked_minutes']) : null,
                                ])) }}
                            </p>
                        @endif
                    @else
                        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No workdays calculated this month yet.</p>
                    @endif
                </x-card>
            </div>
        </div>
    @else
        <x-card class="max-w-2xl">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"><x-icon name="calendar-days" class="h-5 w-5" /></div>
            <h2 class="mt-5 text-lg font-semibold text-slate-900 dark:text-slate-100">Your attendance workspace</h2>
            <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500 dark:text-slate-400">Review your attendance history and daily clock records from My attendance.</p>
            <div class="mt-5"><x-button :href="route('attendance.mine')" wire:navigate>View my attendance</x-button></div>
        </x-card>
    @endif

    @if ($stats && count($attendance['trend']))
        @vite('resources/js/dashboard-chart.js')
    @endif
</x-app-layout>
