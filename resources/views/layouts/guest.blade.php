<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($pageTitle) ? $pageTitle.' – '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|dm-serif-display:400&display=swap" rel="stylesheet" />

        <!-- Dark mode: applied before paint to avoid a flash of the wrong theme. Also
             re-applied on 'livewire:navigated' — see layouts/app.blade.php for why. -->
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
    <body class="font-sans text-slate-900 antialiased dark:text-slate-100">
        <div class="min-h-screen min-h-dvh grid grid-cols-1 lg:grid-cols-[46%_54%] bg-slate-50 dark:bg-slate-950 bg-shell">
            <!-- Hero panel -->
            <section class="hidden lg:flex flex-col bg-primary-700 text-white px-16 py-12">
                <x-logo-lockup size="32" variant="dark" />

                <div class="flex flex-1 items-center">
                    <div class="max-w-md">
                        <p class="mb-2 text-xs font-bold uppercase tracking-widest text-accent-300">People operations, clarified</p>
                        <h1 class="text-5xl font-semibold leading-[1.05] tracking-tight">
                            Make every workday<br>
                            <em class="font-serif font-normal not-italic text-accent-300">count.</em>
                        </h1>
                        <p class="mt-6 text-primary-100 leading-relaxed">
                            One calm, connected place for your team's attendance, leave, and overtime.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Form panel -->
            <section class="relative flex items-start justify-center px-6 py-20 sm:px-12 sm:py-24 lg:items-center lg:py-10">
                <button
                    type="button"
                    x-data="{ isDark: document.documentElement.classList.contains('dark') }"
                    @click="document.documentElement.classList.toggle('dark'); isDark = document.documentElement.classList.contains('dark'); localStorage.setItem('theme', isDark ? 'dark' : 'light')"
                    class="absolute right-4 top-4 flex h-11 w-11 items-center justify-center rounded-lg text-slate-600 transition hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 sm:right-6 sm:top-6 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100 dark:focus:ring-offset-slate-950"
                    :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    :aria-pressed="isDark.toString()"
                >
                    <x-icon name="sun" class="w-5 h-5 hidden dark:block" />
                    <x-icon name="moon" class="w-5 h-5 dark:hidden" />
                </button>

                <div class="w-full max-w-[26rem]">
                    <div class="mb-8 flex flex-col items-center text-center sm:mb-10 lg:hidden">
                        <x-logo-lockup size="40" />
                        <p class="mt-2 text-sm text-slate-500">attendance, stamped in seconds</p>
                    </div>

                    {{ $slot }}
                </div>
            </section>
        </div>

        @livewireScripts
    </body>
</html>
