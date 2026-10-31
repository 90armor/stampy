<x-guest-layout>
    <x-slot:pageTitle>Reset your password</x-slot:pageTitle>

    <a href="{{ route('login') }}" wire:navigate class="mb-6 inline-flex items-center gap-x-1 text-sm font-semibold text-primary-700 hover:text-primary-800 dark:text-accent-300 dark:hover:text-accent-200">
        <x-icon name="chevron-right" class="w-5 h-5 rotate-180" />
        Back to sign in
    </a>

    <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Reset your password</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
        Enter your username or email. If your account has an email on file, we'll send a link to choose a new password.
    </p>

    @if (session('status'))
        {{-- Phase 6b: one message for every outcome (PasswordResetLinkController::SENT). --}}
        <div class="mt-6 flex gap-x-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-900/20" role="status">
            <x-icon name="check" class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400" />
            <p class="text-sm text-green-800 dark:text-green-300">{{ session('status') }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
            @csrf

            <!-- Username or email -->
            <div>
                <x-input-label for="email" :value="__('Username or email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="text" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="Your username or email" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <x-button type="submit" variant="primary" class="w-full">
                {{ __('Send reset link') }}
                <x-icon name="chevron-right" class="w-5 h-5" />
            </x-button>
        </form>
    @endif
</x-guest-layout>
