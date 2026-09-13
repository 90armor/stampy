@php
$navItems = [
    ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'visible' => true, 'enabled' => true],
    ['label' => 'Employees', 'route' => 'employees.index', 'icon' => 'users', 'visible' => auth()->user()->hasAnyRole(['admin', 'manager']), 'enabled' => true],
    ['label' => 'Organization', 'route' => 'organization.index', 'icon' => 'building-office', 'visible' => auth()->user()->hasRole('admin'), 'enabled' => true],
    ['label' => 'Attendance', 'icon' => 'clock', 'visible' => true, 'enabled' => false],
    ['label' => 'Time off', 'icon' => 'calendar-days', 'visible' => true, 'enabled' => false],
    ['label' => 'Reports', 'icon' => 'document-text', 'visible' => auth()->user()->hasAnyRole(['admin', 'manager']), 'enabled' => false],
];
@endphp

<div class="flex h-full w-[242px] flex-col bg-white/70 backdrop-blur-xl border-r border-slate-200/60 dark:bg-slate-900/60 dark:border-slate-800/70">
    <div class="flex h-16 shrink-0 items-center gap-x-2.5 px-6">
        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-accent-400 text-primary-900">
            <x-icon name="bolt" class="w-4 h-4" />
        </span>
        <a href="{{ route('dashboard') }}" wire:navigate class="text-lg font-semibold tracking-tight text-slate-900 dark:text-white">
            {{ config('app.name') }}
        </a>
    </div>

    <nav class="flex-1 space-y-1 px-3 py-4">
        @foreach ($navItems as $item)
            @continue(! $item['visible'])
            @if ($item['label'] === 'Attendance')
                <div class="my-2 border-t border-slate-200/70 dark:border-slate-800/70"></div>
            @endif
            @if ($item['enabled'])
                {{-- Match on the route-name prefix (e.g. 'employees*'), not the exact
                     route, so nested pages like employees.show keep this item active. --}}
                @php $active = request()->routeIs(explode('.', $item['route'])[0].'*'); @endphp
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    class="group relative flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm transition
                        {{ $active ? 'bg-primary-100/80 font-semibold text-primary-700 dark:bg-primary-900/50 dark:text-primary-300' : 'font-medium text-slate-500 hover:bg-slate-100/70 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-white' }}"
                >
                    @if ($active)
                        <span class="absolute inset-y-1.5 left-0 w-[3px] rounded-full bg-primary-600 dark:bg-primary-400"></span>
                    @endif
                    <x-icon :name="$item['icon']" class="w-5 h-5 shrink-0 {{ $active ? 'text-primary-700 dark:text-primary-300' : 'text-slate-400 group-hover:text-slate-700 dark:group-hover:text-slate-200' }}" />
                    {{ $item['label'] }}
                </a>
            @else
                <div
                    class="flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-400 dark:text-slate-600 cursor-not-allowed"
                    title="Coming in a later phase"
                >
                    <x-icon :name="$item['icon']" class="w-5 h-5 shrink-0 text-slate-400 dark:text-slate-600" />
                    {{ $item['label'] }}
                    <span class="ml-auto rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Soon</span>
                </div>
            @endif
        @endforeach
    </nav>
</div>
