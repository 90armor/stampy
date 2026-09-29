<div class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-x-4 border-b border-slate-200/60 bg-white/70 backdrop-blur-xl px-4 sm:px-6 dark:border-slate-800/70 dark:bg-slate-900/60">
    <button type="button" class="rounded-lg p-2 text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 lg:hidden dark:text-slate-400" @click="openSidebar()">
        <span class="sr-only">Open sidebar</span>
        <x-icon name="bars-3" class="w-6 h-6" />
    </button>

    <div class="flex flex-1 items-center justify-between">
        <div class="min-w-0 flex items-center gap-x-2 text-sm text-slate-500 dark:text-slate-400">
            @unless (request()->routeIs('dashboard'))
                @if ($breadcrumbs ?? null)
                    @foreach ($breadcrumbs as $crumb)
                        @unless ($loop->first)
                            <x-icon name="chevron-right" class="hidden sm:inline w-4 h-4 text-slate-300 dark:text-slate-600" />
                        @endunless
                        @if (! $loop->last)
                            <a href="{{ $crumb['route'] }}" wire:navigate class="hidden rounded hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:inline dark:hover:text-slate-100">
                                {{ $crumb['label'] }}
                            </a>
                        @else
                            <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $crumb['label'] }}</span>
                        @endif
                    @endforeach
                @else
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                    <x-icon name="chevron-right" class="hidden sm:inline w-4 h-4 text-slate-300 dark:text-slate-600" />
                    <span class="font-semibold text-slate-900 dark:text-slate-100">
                        {{ $header ?? '' }}
                    </span>
                @endif
            @endunless
        </div>

        <div class="flex items-center gap-x-3">
            <button
                type="button"
                x-data="{}"
                @click="document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light')"
                class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                aria-label="Toggle dark mode"
                title="Toggle dark mode"
            >
                <x-icon name="sun" class="w-5 h-5 hidden dark:block" />
                <x-icon name="moon" class="w-5 h-5 dark:hidden" />
            </button>

            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="flex items-center gap-x-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-200 dark:hover:bg-slate-800"
                        aria-haspopup="true"
                        :aria-expanded="open.toString()"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-700 font-medium dark:bg-primary-800 dark:text-primary-100">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </span>
                        <span class="hidden sm:flex sm:flex-col sm:items-start sm:leading-tight">
                            <span class="font-medium text-slate-900 dark:text-slate-100">{{ auth()->user()->name }}</span>
                            <span class="text-xs text-slate-400 dark:text-slate-500">
                                {{ auth()->user()->getRoleNames()->first() ? ucfirst(auth()->user()->getRoleNames()->first()) : 'No role' }}
                            </span>
                        </span>
                        <x-icon name="chevron-down" class="w-4 h-4 shrink-0 text-slate-400" />
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
                            {{ __('Log Out') }}
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </div>
</div>
