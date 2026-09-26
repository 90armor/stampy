<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100 sm:text-[28px]">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Welcome back, {{ auth()->user()->name }}. <span class="whitespace-nowrap">{{ now()->format('l, F j, Y') }}</span>
            </p>
        </div>
        <x-button :href="$stats ? route('attendance.index') : route('attendance.mine')" variant="secondary" wire:navigate class="self-start sm:self-auto">
            <x-icon name="calendar-days" class="h-4 w-4" />
            {{ $stats ? 'View attendance' : 'View my attendance' }}
        </x-button>
    </header>

    @if ($stats)
        @php
            $segments = collect($attendance['today']['segments']);
            $primarySegments = $segments->take(4);
            $attentionAvatarClass = [
                'red' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
                'violet' => 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300',
                'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
            ];
            $setupSteps = collect([
                ['label' => 'Departments', 'done' => $attendance['onboarding']['departments']],
                ['label' => 'Positions', 'done' => $attendance['onboarding']['positions']],
                ['label' => 'Employee records', 'done' => $attendance['onboarding']['employees']],
            ]);
            $showSetup = $setupSteps->contains(fn ($step) => ! $step['done']);
            $quickActions = collect([
                ['label' => 'Add employee', 'route' => 'employees.index', 'icon' => 'plus', 'accent' => true, 'ability' => ['create', \App\Models\Employee::class]],
                ['label' => 'Add department', 'route' => 'organization.index', 'icon' => 'plus', 'accent' => true, 'ability' => ['create', \App\Models\Department::class]],
                ['label' => 'View employees', 'route' => 'employees.index', 'icon' => 'users', 'accent' => false, 'ability' => ['viewAny', \App\Models\Employee::class]],
                ['label' => 'Organization settings', 'route' => 'organization.index', 'icon' => 'building-office', 'accent' => false, 'ability' => ['viewAny', \App\Models\Department::class]],
            ])->filter(fn ($action) => auth()->user()->can(...$action['ability']))->values();
            $showQuickActions = $quickActions->isNotEmpty()
                && ! ($quickActions->count() === 1 && $quickActions->first()['route'] === 'employees.index');
        @endphp

        <div class="grid gap-6 lg:grid-cols-12 lg:items-start">
            <section class="contents" aria-label="Today's attendance overview">
                <x-card class="order-1 lg:col-span-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Today</p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Attendance status</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                {{ $attendance['today']['total'] }} active {{ $attendance['today']['total'] === 1 ? 'employee' : 'employees' }} in scope
                            </p>
                        </div>
                        @unless ($attendance['today']['builtToday'])
                            <x-badge color="amber">Not calculated yet</x-badge>
                        @endunless
                    </div>

                    <dl @class([
                        'mt-6 grid w-full grid-cols-1 gap-3',
                        'max-w-sm' => $primarySegments->count() === 1,
                        'sm:grid-cols-2' => $primarySegments->count() === 2,
                        'sm:grid-cols-3' => $primarySegments->count() === 3,
                        'sm:grid-cols-4' => $primarySegments->count() >= 4,
                    ])>
                        @foreach ($primarySegments as $segment)
                            <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                <dt class="flex items-center gap-x-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    <span class="h-2 w-2 rounded-full {{ $segment['dot'] }}"></span>{{ $segment['label'] }}
                                </dt>
                                <dd class="mt-2 text-2xl font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $segment['count'] }}</dd>
                                <dd class="text-xs tabular-nums text-slate-400 dark:text-slate-500">{{ $segment['percent'] }}%</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($segments->count() > 4)
                        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                            @foreach ($segments->slice(4) as $segment)
                                <p class="flex items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="h-2 w-2 rounded-full {{ $segment['dot'] }}"></span>{{ $segment['label'] }}
                                    <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ $segment['count'] }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif
                    @if ($attendance['today']['late']['count'] > 0 || $attendance['today']['earlyLeave']['count'] > 0)
                        <p class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            Of those present,
                            @if ($attendance['today']['late']['count'] > 0)<span class="font-medium text-amber-700 dark:text-amber-400">{{ $attendance['today']['late']['count'] }} arrived late</span>@endif
                            @if ($attendance['today']['late']['count'] > 0 && $attendance['today']['earlyLeave']['count'] > 0) and @endif
                            @if ($attendance['today']['earlyLeave']['count'] > 0)<span class="font-medium text-amber-700 dark:text-amber-400">{{ $attendance['today']['earlyLeave']['count'] }} left early</span>@endif.
                        </p>
                    @endif
                </x-card>

                <x-card class="order-2 lg:col-span-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Action required</p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Needs attention</h2>
                        </div>
                        @if (count($attendance['needsAttention']))<x-badge color="amber">{{ count($attendance['needsAttention']) }}</x-badge>@endif
                    </div>
                    @if (count($attendance['needsAttention']))
                        <ul class="mt-5 divide-y divide-slate-200/70 dark:divide-slate-800">
                            @foreach ($attendance['needsAttention'] as $person)
                                <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $attentionAvatarClass[$person['badge']] }}">{{ strtoupper(substr($person['name'], 0, 1)) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $person['name'] }}</p>
                                        @if ($person['detail'])<p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $person['detail'] }}</p>@endif
                                    </div>
                                    <x-badge :color="$person['badge']">{{ $person['label'] }}</x-badge>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="mt-8 flex flex-col items-center pb-3 text-center">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400"><x-icon name="check" class="h-5 w-5" /></span>
                            <p class="mt-3 text-sm font-medium text-slate-800 dark:text-slate-200">Nothing needs attention</p>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">There are no attendance exceptions to review.</p>
                        </div>
                    @endif
                </x-card>
            </section>

            <section class="contents" aria-label="Attendance trends and workforce summary">
                <x-card class="order-3 min-w-0 lg:col-span-8">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Last seven days</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Attendance trend</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Daily attendance across active employees in your scope.</p>
                    @if (count($attendance['trend']))
                        <div class="relative mt-5 h-64 min-w-0 w-full overflow-hidden" role="img" aria-label="Weekly attendance: {{ collect($attendance['trend'])->map(fn ($day) => $day['label'].' '.$day['value'].'%')->implode(', ') }}">
                            <canvas class="!h-full !w-full max-w-full" id="attendance-trend-chart" data-labels="{{ json_encode(collect($attendance['trend'])->pluck('label')) }}" data-values="{{ json_encode(collect($attendance['trend'])->pluck('value')) }}" aria-hidden="true"></canvas>
                        </div>
                    @else
                        <x-empty-state icon="clock" title="No weekly attendance yet" description="Trend data will appear when active employees have attendance records." />
                    @endif
                </x-card>
                <div class="contents lg:order-4 lg:col-span-4 lg:flex lg:flex-col lg:gap-6">
                    <x-card class="order-4 min-w-0 w-full">
                        <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Workforce</p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Employee summary</h2>
                        <dl class="mt-5 divide-y divide-slate-200/70 dark:divide-slate-800">
                            <div class="flex items-end justify-between gap-4 pb-4"><dt class="text-sm font-medium text-slate-700 dark:text-slate-300">Total employees</dt><dd class="text-3xl font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $stats['total_employees'] }}</dd></div>
                            <div class="flex items-end justify-between gap-4 pt-4"><dt class="text-xs text-slate-500 dark:text-slate-400">Added this month</dt><dd class="text-sm font-medium tabular-nums text-slate-600 dark:text-slate-300">{{ $stats['new_this_month'] }}</dd></div>
                        </dl>
                    </x-card>

                    @if ($showQuickActions)
                        <x-card class="order-7">
                            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Shortcuts</p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Quick Actions</h2>
                            <div class="mt-4 space-y-1.5">
                                @foreach ($quickActions as $action)
                                    <a href="{{ route($action['route']) }}" wire:navigate class="group flex items-center gap-x-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100/70 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-300 dark:hover:bg-slate-800/60 dark:hover:text-white">
                                        <span @class(['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', 'bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' => $action['accent'], 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => ! $action['accent']])><x-icon :name="$action['icon']" class="h-4 w-4" /></span>
                                        {{ $action['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </x-card>
                    @endif

                    @if ($showSetup)
                        <x-card class="order-8">
                            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Setup</p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Complete your organization</h2>
                            <ul class="mt-4 space-y-3">
                                @foreach ($setupSteps as $step)
                                    <li class="flex items-center gap-x-2.5 text-sm">
                                        @if ($step['done'])<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-500 text-white"><x-icon name="check" class="h-3 w-3" /></span>@else<span class="h-5 w-5 shrink-0 rounded-full border-2 border-slate-300 dark:border-slate-700"></span>@endif
                                        <span class="text-slate-600 dark:text-slate-300">{{ $step['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-card>
                    @endif
                </div>
            </section>

            <section class="contents" aria-label="Department attendance and recent activity">
                <x-card class="order-5 lg:col-span-8">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">By team</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Department attendance</h2>
                    @if (count($attendance['departments']))
                        <div class="mt-5 space-y-4">
                            @foreach ($attendance['departments'] as $department)
                                <div>
                                    <div class="flex items-baseline justify-between gap-2"><p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $department['name'] }}</p><p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $department['attendance'] }}%</p></div>
                                    <p class="text-xs text-slate-400 dark:text-slate-500">{{ $department['employees'] }} {{ $department['employees'] === 1 ? 'employee' : 'employees' }}</p>
                                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-primary-500" style="width: {{ $department['attendance'] }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-empty-state icon="building-office" title="No departments yet" description="Add a department to see attendance by team." />
                    @endif
                </x-card>

                <x-card class="order-6 lg:col-span-4">
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Latest</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">Recent activity</h2>
                    @if (count($attendance['recent']))
                        <div class="mt-5 divide-y divide-slate-200/70 dark:divide-slate-800">
                            @foreach ($attendance['recent'] as $activity)
                                @php
                                    $tone = match ($activity['tone']) {
                                        'present' => ['bg' => 'bg-primary-50 dark:bg-primary-900/40', 'text' => 'text-primary-700 dark:text-primary-300', 'icon' => 'check'],
                                        'late' => ['bg' => 'bg-amber-50 dark:bg-amber-900/30', 'text' => 'text-amber-600 dark:text-amber-400', 'icon' => 'clock'],
                                        'out' => ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'icon' => 'logout'],
                                        default => ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'icon' => 'user-circle'],
                                    };
                                @endphp
                                <div class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $tone['bg'] }} {{ $tone['text'] }}">{{ strtoupper(substr($activity['name'], 0, 1)) }}</span>
                                    <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $activity['name'] }}</p><p class="mt-0.5 flex items-center gap-x-1 text-xs text-slate-400 dark:text-slate-500"><x-icon :name="$tone['icon']" class="h-3 w-3" />{{ $activity['action'] }}</p></div>
                                    <span class="shrink-0 text-xs text-slate-400 dark:text-slate-500">{{ $activity['time'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-empty-state icon="inbox" title="No recent activity" description="Clock events will appear here as they are recorded." />
                    @endif
                </x-card>
            </section>

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
