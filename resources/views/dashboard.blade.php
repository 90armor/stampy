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

    @if ($stats)
        {{-- KPI cards --}}
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card icon="users" label="Total employees" :value="$stats['total_employees']">
                <x-slot name="subtext">
                    <x-trend direction="up">+{{ $stats['new_this_month'] }} this month</x-trend>
                </x-slot>
            </x-stat-card>

            <x-stat-card
                icon="check"
                label="Present today"
                :value="$attendance['today']['present']['count']"
                icon-class="bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"
            >
                <x-slot name="subtext">{{ $attendance['today']['present']['percent'] }}% of workforce</x-slot>
            </x-stat-card>

            <x-stat-card
                icon="clock"
                label="Late today"
                :value="$attendance['today']['late']['count']"
                icon-class="bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400"
            >
                <x-slot name="subtext">
                    {{ $attendance['today']['late']['count'] === 0 ? 'No late arrivals' : $attendance['today']['late']['percent'].'% of workforce' }}
                </x-slot>
            </x-stat-card>

            <x-stat-card
                icon="calendar-days"
                label="On leave"
                :value="$attendance['today']['leave']['count']"
                icon-class="bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
            >
                <x-slot name="subtext">
                    {{ $attendance['today']['leave']['count'] === 0 ? 'No one on leave' : $attendance['today']['leave']['percent'].'% of workforce' }}
                </x-slot>
            </x-stat-card>
        </div>

        {{-- Today's Attendance + Quick Actions --}}
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-card class="lg:col-span-2 self-start">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Today</p>
                <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Today's Attendance</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Employee attendance status for today</p>

                @php
                    $breakdown = [
                        ['label' => 'Present', 'dot' => 'bg-primary-500', 'data' => $attendance['today']['present']],
                        ['label' => 'Late', 'dot' => 'bg-amber-400', 'data' => $attendance['today']['late']],
                        ['label' => 'On leave', 'dot' => 'bg-blue-400', 'data' => $attendance['today']['leave']],
                        ['label' => 'Absent', 'dot' => 'bg-red-400', 'data' => $attendance['today']['absent']],
                    ];
                @endphp

                <div
                    class="mt-5 flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                    role="img"
                    aria-label="Present {{ $attendance['today']['present']['percent'] }}%, late {{ $attendance['today']['late']['percent'] }}%, on leave {{ $attendance['today']['leave']['percent'] }}%, absent {{ $attendance['today']['absent']['percent'] }}%"
                >
                    @foreach ($breakdown as $segment)
                        <div class="{{ $segment['dot'] }}" style="width: {{ $segment['data']['percent'] }}%"></div>
                    @endforeach
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ($breakdown as $segment)
                        <div>
                            <dt class="flex items-center gap-x-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                                <span class="h-2 w-2 rounded-full {{ $segment['dot'] }}"></span>
                                {{ $segment['label'] }}
                            </dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $segment['data']['count'] }}</dd>
                            <dd class="text-xs text-slate-400 dark:text-slate-500">{{ $segment['data']['percent'] }}%</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-6 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Needs attention</p>

                    @if (count($attendance['needsAttention']))
                        <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($attendance['needsAttention'] as $person)
                                @php
                                    $isAbsent = $person['status'] === 'absent';
                                @endphp
                                <li class="flex items-center gap-x-3 py-2.5 first:pt-0 last:pb-0">
                                    <span @class([
                                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                        'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400' => $isAbsent,
                                        'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400' => ! $isAbsent,
                                    ])>
                                        {{ strtoupper(substr($person['name'], 0, 1)) }}
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-700 dark:text-slate-200">
                                        {{ $person['name'] }}
                                    </span>
                                    <x-badge :color="$isAbsent ? 'red' : 'amber'">
                                        {{ ucfirst($person['status']) }}
                                    </x-badge>
                                    @if ($person['time'])
                                        <span class="shrink-0 text-xs text-slate-400 dark:text-slate-500">{{ $person['time'] }}</span>
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
                <x-card>
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Shortcuts</p>
                    <h2 class="mt-1 text-base font-semibold text-slate-900 dark:text-slate-100">Quick Actions</h2>

                    <div class="mt-4 space-y-1.5">
                        @foreach ([
                            ['label' => 'Add employee', 'route' => 'employees.index', 'icon' => 'plus', 'accent' => true],
                            ['label' => 'Add department', 'route' => 'organization.index', 'icon' => 'plus', 'accent' => true],
                            ['label' => 'View employees', 'route' => 'employees.index', 'icon' => 'users', 'accent' => false],
                            ['label' => 'Organization settings', 'route' => 'organization.index', 'icon' => 'building-office', 'accent' => false],
                        ] as $action)
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
                    <div class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($attendance['recent'] as $activity)
                            @php
                                $tone = match ($activity['tone']) {
                                    'present' => ['bg' => 'bg-primary-50 dark:bg-primary-900/40', 'text' => 'text-primary-700 dark:text-primary-300', 'icon' => 'check'],
                                    'late' => ['bg' => 'bg-amber-50 dark:bg-amber-900/30', 'text' => 'text-amber-600 dark:text-amber-400', 'icon' => 'clock'],
                                    'leave' => ['bg' => 'bg-blue-50 dark:bg-blue-900/30', 'text' => 'text-blue-600 dark:text-blue-400', 'icon' => 'calendar-days'],
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
