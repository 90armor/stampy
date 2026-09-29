<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Dark mode: applied before paint to avoid a flash of the wrong theme. Also
             re-applied on 'livewire:navigated' because wire:navigate morphs <html> against
             the freshly-fetched page (which has no 'dark' class baked in) and skips
             re-running this identical inline script, so without the listener the class
             gets silently dropped — and the theme "resets" — on every SPA navigation. -->
        <script>
            function applyStoredTheme() {
                var stored = localStorage.getItem('theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', stored === 'dark' || (!stored && prefersDark));
            }

            applyStoredTheme();
            document.addEventListener('livewire:navigated', applyStoredTheme);
        </script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 bg-shell">
        <div
            x-data="{
                sidebarOpen: false,
                sidebarTrigger: null,
                openSidebar() {
                    this.sidebarTrigger = document.activeElement;
                    this.sidebarOpen = true;
                    this.$nextTick(() => this.$refs.sidebarClose.focus());
                },
                closeSidebar() {
                    this.sidebarOpen = false;

                    const trigger = this.sidebarTrigger;
                    this.sidebarTrigger = null;

                    this.$nextTick(() => {
                        if (trigger && document.body.contains(trigger) && typeof trigger.focus === 'function') {
                            trigger.focus();
                        }
                    });
                },
                sidebarFocusables() {
                    return [...this.$refs.sidebarPanel.querySelectorAll('a, button, [tabindex]:not([tabindex=\'-1\'])')]
                        .filter((element) => ! element.hasAttribute('disabled'));
                },
                trapSidebarTab(event) {
                    const focusable = this.sidebarFocusables();
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];

                    if (! first || ! last) return;

                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },
            }"
            class="h-screen flex overflow-hidden"
        >
            <!-- Desktop sidebar -->
            <div class="hidden lg:flex lg:shrink-0">
                @include('layouts.partials.sidebar')
            </div>

            <!-- Mobile sidebar drawer -->
            <div x-show="sidebarOpen" x-cloak class="relative z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation" @keydown.escape.window="if (sidebarOpen) closeSidebar()">
                <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-900/50" @click="closeSidebar()"></div>

                <div x-ref="sidebarPanel" x-show="sidebarOpen" x-transition:enter="transition ease-in-out duration-200 transform" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in-out duration-200 transform" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" @keydown.tab="trapSidebarTab($event)" class="fixed inset-y-0 left-0 flex w-[242px]">
                    @include('layouts.partials.sidebar')
                    <button x-ref="sidebarClose" type="button" class="absolute top-3 -right-12 inline-flex h-10 w-10 items-center justify-center rounded-lg text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400" @click="closeSidebar()">
                        <span class="sr-only">Close navigation</span>
                        <x-icon name="x-mark" class="w-6 h-6" />
                    </button>
                </div>
            </div>

            <!-- Main column -->
            <div class="flex flex-1 flex-col overflow-hidden">
                @include('layouts.partials.topbar', ['header' => $header ?? null, 'breadcrumbs' => $breadcrumbs ?? null])

                <main class="flex-1 overflow-y-auto">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
