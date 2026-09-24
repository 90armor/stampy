<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <div class="mb-8">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Overview</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100 sm:text-[28px]">
            Welcome back, {{ auth()->user()->name }}
        </h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ now()->format('l, F j, Y') }}
        </p>
    </div>

    @php
        // Same displayVariant()-derived palette as Attendance\Index, so
        // "Present"/"Absent"/"Incomplete" mean the same colour everywhere in
        // the app — see that file's own comment for why timing (late/early)
        // is a Present subtext, not a peer tile or its own colour here.
        $variantStyles = [
            'present' => ['icon' => 'check', 'iconClass' => 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'],
            'absent' => ['icon' => 'user-x', 'iconClass' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400'],
            'incomplete' => ['icon' => 'exclamation-triangle', 'iconClass' => 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300'],
        ];
    @endphp

    @if ($stats)
        {{-- KPI cards --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card icon="users" label="Total employees" :value="$stats['total_employees']">
                <x-slot name="subtext">
                    <x-trend direction="up">+{{ $stats['new_this_month'] }} this month</x-trend>
                </x-slot>
            </x-stat-card>

            <x-stat-card
                :icon="$variantStyles['present']['icon']"
                label="Present today"
                :value="$attendance['today']['present']['count']"
                :icon-class="$variantStyles['present']['iconClass']"
            >
                @if ($attendance['today']['late']['count'] > 0 || $attendance['today']['earlyLeave']['count'] > 0)
                    <x-slot name="subtext">
                        of which
                        @if ($attendance['today']['late']['count'] > 0)
                            {{ $attendance['today']['late']['count'] }} late
                        @endif
                        @if ($attendance['today']['late']['count'] > 0 && $attendance['today']['earlyLeave']['count'] > 0)
                            &middot;
                        @endif
                        @if ($attendance['today']['earlyLeave']['count'] > 0)
                            {{ $attendance['today']['earlyLeave']['count'] }} left early
                        @endif
                    </x-slot>
                @else
                    <x-slot name="subtext">{{ $attendance['today']['present']['percent'] }}% of workforce</x-slot>
                @endif
            </x-stat-card>

            <x-stat-card
                :icon="$variantStyles['absent']['icon']"
                label="Absent today"
                :value="collect($attendance['today']['segments'])->firstWhere('key', 'absent')['count'] ?? 0"
                :icon-class="$variantStyles['absent']['iconClass']"
            />

            <x-stat-card
                :icon="$variantStyles['incomplete']['icon']"
                label="Incomplete today"
                :value="collect($attendance['today']['segments'])->firstWhere('key', 'incomplete')['count'] ?? 0"
                :icon-class="$variantStyles['incomplete']['iconClass']"
            />
        </div>

        {{-- Today's Attendance + Quick Actions --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-card class="lg:col-span-2 self-start">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Today</p>
                <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Today's Attendance</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Employee attendance status for today</p>

                @unless ($attendance['today']['builtToday'])
                    {{-- No scheduled job runs attendance:build-daily (see
                    CLAUDE.md's Local environment note) — today's rows may
                    simply not exist yet at whatever moment this loads. --}}
                    <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">
                        Today's attendance hasn't been calculated yet.
                    </p>
                @endunless

                <div
                    class="mt-5 flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                    role="img"
                    aria-label="{{ collect($attendance['today']['segments'])->map(fn ($segment) => $segment['label'].' '.$segment['percent'].'%')->implode(', ') }}"
                >
                    @foreach ($attendance['today']['segments'] as $segment)
                        <div class="{{ $segment['dot'] }}" style="width: {{ $segment['percent'] }}%"></div>
                    @endforeach
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ($attendance['today']['segments'] as $segment)
                        <div>
                            <dt class="flex items-center gap-x-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                                <span class="h-2 w-2 rounded-full {{ $segment['dot'] }}"></span>
                                {{ $segment['label'] }}
                            </dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $segment['count'] }}</dd>
                            <dd class="text-xs text-slate-400 dark:text-slate-500">{{ $segment['percent'] }}%</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-6 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Needs attention</p>

                    @if (count($attendance['needsAttention']))
                        @php
                            $attentionAvatarClass = [
                                'red' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
                                'violet' => 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300',
                                'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
                            ];
                        @endphp
                        <ul class="mt-3 divide-y divide-slate-200/60 dark:divide-slate-800/60">
                            @foreach ($attendance['needsAttention'] as $person)
                                <li class="flex items-center gap-x-3 py-2.5 first:pt-0 last:pb-0">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $attentionAvatarClass[$person['badge']] }}">
                                        {{ strtoupper(substr($person['name'], 0, 1)) }}
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700 dark:text-slate-200">
                                        {{ $person['name'] }}
                                    </span>
                                    <x-badge :color="$person['badge']">
                                        {{ $person['label'] }}
                                    </x-badge>
                                    @if ($person['detail'])
                                        <span class="shrink-0 text-xs text-slate-400 dark:text-slate-500">{{ $person['detail'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Everyone is present today 🎉</p>
                    @endif
                </div>
            </x-card>

            <div class="flex flex-col gap-4">
                @php
                    // Gated by the exact ability its destination enforces —
                    // not a role list — so this can never drift the way it
                    // did before: this card used to hardcode all four links
                    // behind "$stats is set", which silently came to mean
                    // "admin or manager" (routes/web.php) rather than
                    // "admin", so a manager saw "Add department" and
                    // "Organization settings" (organization.index is
                    // role:admin only) and got a 403 on either one.
                    $quickActions = collect([
                        ['label' => 'Add employee', 'route' => 'employees.index', 'icon' => 'plus', 'accent' => true, 'ability' => ['create', \App\Models\Employee::class]],
                        ['label' => 'Add department', 'route' => 'organization.index', 'icon' => 'plus', 'accent' => true, 'ability' => ['create', \App\Models\Department::class]],
                        ['label' => 'View employees', 'route' => 'employees.index', 'icon' => 'users', 'accent' => false, 'ability' => ['viewAny', \App\Models\Employee::class]],
                        ['label' => 'Organization settings', 'route' => 'organization.index', 'icon' => 'building-office', 'accent' => false, 'ability' => ['viewAny', \App\Models\Department::class]],
                    ])->filter(fn ($action) => auth()->user()->can(...$action['ability']))->values();

                    // "View employees" duplicates the sidebar's own
                    // "Employees" link one-for-one (same viewAny ability,
                    // same route, always visible to the same audience) — if
                    // ability filtering above leaves exactly that one
                    // action, the whole card would just be a worse copy of
                    // something already one click away, so skip the card
                    // rather than show it with a single redundant link.
                    // Written as a redundancy check on what survived
                    // filtering, not a role check, so it stays correct if a
                    // future ability change ever leaves someone else in the
                    // same spot — today that's every manager, since create()
                    // on both Employee and Department is admin-only.
                    $showQuickActions = $quickActions->isNotEmpty()
                        && ! ($quickActions->count() === 1 && $quickActions->first()['route'] === 'employees.index');
                @endphp

                @if ($showQuickActions)
                    <x-card>
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Shortcuts</p>
                        <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Quick Actions</h2>

                        <div class="mt-4 space-y-1.5">
                            @foreach ($quickActions as $action)
                                <a
                                    href="{{ route($action['route']) }}"
                                    wire:navigate
                                    class="group flex items-center gap-x-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100/70 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-white"
                                >
                                    <span @class([
                                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                        'bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' => $action['accent'],
                                        'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => ! $action['accent'],
                                    ])>
                                        <x-icon :name="$action['icon']" class="h-4 w-4" />
                                    </span>
                                    {{ $action['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </x-card>
                @endif

                <x-card>
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Getting started</p>
                    <h2 class="mt-1 text-sm font-semibold text-slate-900 dark:text-slate-100">Complete your organization setup</h2>

                    <ul class="mt-3 space-y-2">
                        @foreach ([
                            ['label' => 'Departments', 'done' => $attendance['onboarding']['departments']],
                            ['label' => 'Positions', 'done' => $attendance['onboarding']['positions']],
                            ['label' => 'Employee records', 'done' => $attendance['onboarding']['employees']],
                            ['label' => 'Attendance device integration', 'done' => false],
                        ] as $step)
                            <li class="flex items-center gap-x-2.5 text-sm">
                                @if ($step['done'])
                                    <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-primary-500 text-white">
                                        <x-icon name="check" class="h-2.5 w-2.5" />
                                    </span>
                                    <span class="text-slate-600 dark:text-slate-300">{{ $step['label'] }}</span>
                                @else
                                    <span class="h-4 w-4 shrink-0 rounded-full border-2 border-slate-300 dark:border-slate-700"></span>
                                    <span class="text-slate-400 dark:text-slate-500">{{ $step['label'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            </div>
        </div>

        {{-- Attendance Trend --}}
        <x-card class="mb-6">
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">This week</p>
            <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Attendance Trend</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Attendance overview for the current week</p>

            <div
                class="mt-5 h-[240px]"
                role="img"
                aria-label="Weekly attendance: {{ collect($attendance['trend'])->map(fn ($day) => $day['label'].' '.$day['value'].'%')->implode(', ') }}"
            >
                <canvas
                    id="attendance-trend-chart"
                    data-labels="{{ json_encode(collect($attendance['trend'])->pluck('label')) }}"
                    data-values="{{ json_encode(collect($attendance['trend'])->pluck('value')) }}"
                    aria-hidden="true"
                ></canvas>
            </div>
        </x-card>

        {{-- Department Attendance + Recent Activity --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-card>
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">By team</p>
                <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Department Attendance</h2>

                @if (count($attendance['departments']))
                    <div class="mt-5 space-y-4">
                        @foreach ($attendance['departments'] as $department)
                            <div>
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $department['name'] }}</p>
                                    <p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $department['attendance'] }}%</p>
                                </div>
                                <p class="text-xs text-slate-400 dark:text-slate-500">
                                    {{ $department['employees'] }} {{ $department['employees'] === 1 ? 'employee' : 'employees' }}
                                </p>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div class="h-full rounded-full bg-primary-500" style="width: {{ $department['attendance'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-empty-state icon="building-office" title="No departments yet" description="Add a department to see attendance by team." />
                @endif
            </x-card>

            <x-card>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Latest</p>
                        <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Recent Activity</h2>
                    </div>
                    <span
                        class="mt-1 shrink-0 cursor-not-allowed text-xs font-medium text-slate-300 dark:text-slate-600"
                        title="Coming with Reports"
                    >
                        View all
                    </span>
                </div>

                @if (count($attendance['recent']))
                    <div class="mt-4 divide-y divide-slate-200/60 dark:divide-slate-800/60">
                        @foreach ($attendance['recent'] as $activity)
                            @php
                                $tone = match ($activity['tone']) {
                                    'present' => ['bg' => 'bg-primary-50 dark:bg-primary-900/40', 'text' => 'text-primary-700 dark:text-primary-300', 'icon' => 'check'],
                                    'late' => ['bg' => 'bg-amber-50 dark:bg-amber-900/30', 'text' => 'text-amber-600 dark:text-amber-400', 'icon' => 'clock'],
                                    'out' => ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'icon' => 'logout'],
                                    default => ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'icon' => 'user-circle'],
                                };
                            @endphp
                            <div class="flex items-center gap-x-3 py-3 first:pt-0 last:pb-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $tone['bg'] }} {{ $tone['text'] }}">
                                    {{ strtoupper(substr($activity['name'], 0, 1)) }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $activity['name'] }}</p>
                                    <p class="flex items-center gap-x-1 text-xs text-slate-400 dark:text-slate-500">
                                        <x-icon :name="$tone['icon']" class="h-3 w-3" />
                                        {{ $activity['action'] }}
                                    </p>
                                </div>
                                <span class="shrink-0 text-xs text-slate-400 dark:text-slate-500">{{ $activity['time'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-empty-state icon="inbox" title="No recent activity" />
                @endif
            </x-card>
        </div>
    @endif

    @vite('resources/js/dashboard-chart.js')
</x-app-layout>
