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
        <div class="min-h-screen grid grid-cols-1 lg:grid-cols-[46%_54%] bg-slate-50 dark:bg-slate-950 bg-shell">
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
            <section class="flex items-center justify-center relative px-6 py-10 sm:px-12">
                <button
                    type="button"
                    x-data="{}"
                    @click="document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light')"
                    class="absolute top-6 right-6 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                    aria-label="Toggle theme"
                >
                    <x-icon name="sun" class="w-5 h-5 hidden dark:block" />
                    <x-icon name="moon" class="w-5 h-5 dark:hidden" />
                </button>

                <div class="w-full max-w-sm">
                    <div class="mb-10 flex flex-col items-center text-center lg:hidden">
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
