<x-guest-layout>
    <x-slot:pageTitle>Sign in</x-slot:pageTitle>
    <p class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-600 dark:text-slate-400">Welcome back</p>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Sign in to your workspace</h2>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Enter your credentials to continue.</p>

    <!-- Session Status -->
    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form
        method="POST"
        action="{{ route('login') }}"
        class="mt-8 space-y-5"
        x-data="{ showPassword: false, submitting: false, capsLock: false }"
        @submit="submitting = true"
    >
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

        <!-- Email or username -->
        <div>
            <x-input-label for="email" :value="__('Email or username')" />
            <x-text-input
                id="email"
                class="mt-1 min-h-11 {{ $errors->has('email') ? '!border-red-500 focus:!border-red-500 focus:!ring-red-500' : '' }}"
                type="text"
                name="email"
                :value="old('email')"
                required
                autofocus
                autocomplete="username"
                placeholder="you@company.com or username"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                :aria-describedby="$errors->has('email') ? 'email-error' : null"
            />
            <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <div class="relative mt-1">
                <x-text-input id="password" class="min-h-11 pr-12 {{ $errors->has('password') ? '!border-red-500 focus:!border-red-500 focus:!ring-red-500' : '' }}"
                                type="password"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                name="password"
                                required autocomplete="current-password" placeholder="Enter your password"
                                aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                                :aria-describedby="$errors->has('password') ? 'password-error' : null"
                                @keydown="capsLock = $event.getModifierState('CapsLock')"
                                @keyup="capsLock = $event.getModifierState('CapsLock')" />
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute inset-y-0 right-0 flex min-h-11 w-11 items-center justify-center rounded-r-lg text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500 dark:text-slate-400 dark:hover:text-slate-200"
                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                    :aria-pressed="showPassword.toString()"
                >
                    <x-icon name="eye" class="w-[18px] h-[18px]" x-show="!showPassword" />
                    <x-icon name="eye-off" class="w-[18px] h-[18px]" x-show="showPassword" x-cloak />
                </button>
            </div>

            <p x-cloak x-show="capsLock" class="mt-2 text-sm font-medium text-amber-700 dark:text-amber-300" role="status">
                Caps Lock is on.
            </p>
            <x-input-error id="password-error" :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="flex flex-col items-stretch gap-1 min-[360px]:flex-row min-[360px]:items-center min-[360px]:justify-between">
            <label for="remember_me" class="inline-flex min-h-11 cursor-pointer items-center gap-x-2 text-sm text-slate-700 dark:text-slate-300">
                <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-primary-600 shadow-sm checked:border-primary-600 checked:bg-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800 dark:checked:border-primary-600 dark:checked:bg-primary-600" name="remember">
                {{ __('Keep me signed in on this device') }}
            </label>

            @if (Route::has('password.request'))
                <a class="inline-flex min-h-11 items-center text-sm font-semibold text-primary-700 hover:text-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:text-accent-300 dark:hover:text-accent-200 dark:focus:ring-offset-slate-950" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-button type="submit" variant="primary" class="min-h-11 w-full" x-bind:disabled="submitting">
            <svg x-cloak x-show="submitting" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z" />
            </svg>
            <span x-text="submitting ? 'Signing in…' : 'Sign in'">{{ __('Sign in') }}</span>
            <x-icon name="chevron-right" class="h-4 w-4" x-show="!submitting" />
        </x-button>

        <p class="text-center text-xs text-slate-600 dark:text-slate-400">
            {{ __("Don't have an account? Contact your administrator.") }}
        </p>
    </form>
</x-guest-layout>
