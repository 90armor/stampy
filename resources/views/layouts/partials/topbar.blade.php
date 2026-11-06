<header class="sticky top-0 z-30 flex h-16 shrink-0 print:!hidden items-center gap-x-4 border-b border-slate-border bg-white/70 backdrop-blur-xl px-4 sm:px-6 dark:bg-slate-800/60">
    @php $waiting = \App\Support\ApprovalBadge::count(auth()->user()); @endphp
    {{-- Below lg the sidebar is a drawer, so its Approvals badge is hidden until
    opened: a dot on the menu button says something is waiting (Phase 3e). --}}
    {{-- The Approvals page refreshes the count after every decision (approval-badge). --}}
    <button type="button" class="relative rounded-lg p-2 max-sm:p-2.5 text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 lg:hidden dark:text-slate-400" @click="openSidebar()"
        x-data="{ waiting: {{ $waiting }} }" x-on:approval-badge.window="waiting = $event.detail.count">
        <span class="sr-only" x-text="'Open sidebar' + (waiting > 0 ? ' — ' + waiting + (waiting === 1 ? ' approval' : ' approvals') + ' waiting' : '')">Open sidebar{{ $waiting > 0 ? ' — '.$waiting.' '.Str::plural('approval', $waiting).' waiting' : '' }}</span>
        <x-icon name="bars-3" class="w-6 h-6" />
        <span x-show="waiting > 0" @if ($waiting === 0) style="display: none" @endif class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-primary-600 dark:bg-primary-400" aria-hidden="true"></span>
    </button>

    <div class="flex flex-1 items-center justify-between">
        <div class="min-w-0 flex items-center gap-x-2 text-sm text-slate-500 dark:text-slate-400">
            @unless (request()->routeIs('dashboard'))
                @if ($breadcrumbs ?? null)
                    {{-- A detail page's way back (Phase 6i, docs/DESIGN_SYSTEM.md,
                    Navigation): the parent crumb links to the list as the user
                    left it (App\Support\ListUrl), and only when they may open it
                    (a crumb without a route is plain text). Below 640px the title
                    is that link alone, "‹ Attendance", 44px tall; the page's own
                    h1 names the record. --}}
                    @php $parent = count($breadcrumbs) > 1 ? $breadcrumbs[count($breadcrumbs) - 2] : null; @endphp
                    @if ($parent && ($parent['route'] ?? null))
                        <a href="{{ $parent['route'] }}" wire:navigate data-back-link class="-my-1.5 -ml-1 inline-flex min-h-11 items-center gap-x-0.5 rounded-lg pr-2 font-medium text-slate-700 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:hidden dark:text-slate-200 dark:hover:text-slate-100">
                            <x-icon name="chevron-left" class="h-5 w-5" />
                            {{ $parent['label'] }}
                        </a>
                    @endif
                    @foreach ($breadcrumbs as $crumb)
                        @unless ($loop->first)
                            <x-icon name="chevron-right" class="hidden sm:inline w-5 h-5 text-slate-300 dark:text-slate-600" />
                        @endunless
                        @if (! $loop->last && ($crumb['route'] ?? null))
                            <a href="{{ $crumb['route'] }}" wire:navigate class="hidden rounded text-slate-600 underline decoration-slate-300 decoration-1 underline-offset-2 hover:text-slate-900 hover:decoration-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:inline dark:text-slate-400 dark:decoration-slate-600 dark:hover:text-slate-100 dark:hover:decoration-slate-400">
                                {{ $crumb['label'] }}
                            </a>
                        @elseif (! $loop->last)
                            <span class="hidden sm:inline">{{ $crumb['label'] }}</span>
                        @else
                            <span @class(['font-semibold text-slate-900 dark:text-slate-100', 'max-sm:hidden' => $parent && ($parent['route'] ?? null)])>{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                @else
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                    <x-icon name="chevron-right" class="hidden sm:inline w-5 h-5 text-slate-300 dark:text-slate-600" />
                    <span class="font-semibold text-slate-900 dark:text-slate-100">
                        {{ $header ?? '' }}
                    </span>
                @endif
            @endunless
        </div>

        <div class="flex items-center gap-x-3">
            <button
                type="button"
                x-data="{ isDark: document.documentElement.classList.contains('dark') }"
                x-on:livewire:navigated.window="isDark = document.documentElement.classList.contains('dark')"
                @click="document.documentElement.classList.toggle('dark'); isDark = document.documentElement.classList.contains('dark'); localStorage.setItem('theme', isDark ? 'dark' : 'light')"
                class="rounded-lg p-2 max-sm:p-3 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-600/30 dark:hover:text-slate-200"
                :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            >
                <x-icon name="sun" class="w-5 h-5 hidden dark:block" />
                <x-icon name="moon" class="w-5 h-5 dark:hidden" />
            </button>

            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="flex items-center gap-x-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-200 dark:hover:bg-slate-600/30"
                        aria-haspopup="true"
                        :aria-expanded="open.toString()"
                    >
                        <x-avatar :name="auth()->user()->name" class="h-8 w-8 font-medium" />
                        {{-- The avatar is decorative, so below sm, where the name is hidden,
                        this names the button (Phase 6h). --}}
                        <span class="sr-only sm:hidden">{{ auth()->user()->name }}</span>
                        <span class="hidden sm:flex sm:flex-col sm:items-start sm:leading-tight">
                            <span class="font-medium text-slate-900 dark:text-slate-100">{{ auth()->user()->name }}</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                {{ auth()->user()->getRoleNames()->first() ? ucfirst(auth()->user()->getRoleNames()->first()) : 'No role' }}
                            </span>
                        </span>
                        <x-icon name="chevron-down" class="w-5 h-5 shrink-0 text-slate-400" />
                    </button>
                </x-slot>

                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')" wire:navigate>
                        {{ __('Profile') }}
                    </x-dropdown-link>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')"
                                onclick="event.preventDefault(); this.closest('form').submit();">
                            {{ __('Log out') }}
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </div>
</header>
