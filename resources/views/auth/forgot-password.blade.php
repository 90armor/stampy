<x-guest-layout>
    <a href="{{ route('login') }}" class="mb-6 inline-flex items-center gap-x-1 text-sm font-semibold text-primary-700 hover:text-primary-800 dark:text-accent-300 dark:hover:text-accent-200">
        <x-icon name="chevron-right" class="w-4 h-4 rotate-180" />
        Back to sign in
    </a>

    <p class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Account recovery</p>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Reset your password</h2>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Enter your work email and we\'ll email you a secure link to choose a new one.') }}
    </p>

    <!-- Session Status -->
    <x-auth-session-status class="mt-6" :status="session('status')" />

    @if (session('status'))
        <div class="mt-6 flex gap-x-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-900/20">
            <x-icon name="check" class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400" />
            <div>
                <p class="text-sm font-semibold text-green-800 dark:text-green-300">Check your inbox</p>
                <p class="mt-1 text-sm text-green-700 dark:text-green-400">A password reset link is on its way, if an account exists for that address.</p>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
            @csrf

            <!-- Email Address -->
            <div>
                <x-input-label for="email" :value="__('Work email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus placeholder="you@company.com" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-button type="submit" variant="primary" class="w-full">
                {{ __('Send reset link') }}
                <x-icon name="chevron-right" class="w-4 h-4" />
            </x-button>
        </form>
    @endif
</x-guest-layout>
