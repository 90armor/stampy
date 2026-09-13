<x-guest-layout>
    <p class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Welcome back</p>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Sign in to your workspace</h2>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Manage attendance, leave, and overtime for your team.</p>

    <!-- Session Status -->
    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5" x-data="{ showPassword: false }">
        @csrf

        @if ($errors->has('auth'))
            <div
                class="flex items-start gap-x-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-400"
                role="alert"
            >
                <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>{{ $errors->first('auth') }}</span>
            </div>
        @endif

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Work email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="you@company.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <div class="relative mt-1">
                <x-text-input id="password" class="block w-full pr-10"
                                type="password"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                name="password"
                                required autocomplete="current-password" placeholder="Enter your password" />
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    aria-label="Toggle password visibility"
                >
                    <x-icon name="eye" class="w-[18px] h-[18px]" x-show="!showPassword" />
                    <x-icon name="eye-off" class="w-[18px] h-[18px]" x-show="showPassword" x-cloak />
                </button>
            </div>

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center gap-x-2 text-sm text-slate-600 dark:text-slate-400">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-primary-600 shadow-sm checked:border-primary-600 checked:bg-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800 dark:checked:border-primary-600 dark:checked:bg-primary-600" name="remember">
                {{ __('Remember me') }}
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm font-semibold text-primary-700 hover:text-primary-800 dark:text-accent-300 dark:hover:text-accent-200" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-button type="submit" variant="primary" class="w-full">
            {{ __('Sign in') }}
            <x-icon name="chevron-right" class="w-4 h-4" />
        </x-button>

        <p class="text-center text-xs text-slate-400 dark:text-slate-500">
            {{ __("Don't have an account? Contact your administrator.") }}
        </p>
    </form>
</x-guest-layout>
