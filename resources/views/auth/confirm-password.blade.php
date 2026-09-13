<x-guest-layout>
    <p class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Security check</p>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Confirm your password</h2>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        {{ __('This is a secure area. Please confirm your password before continuing.') }}
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-8 space-y-5">
        @csrf

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <x-button type="submit" variant="primary" class="w-full">
            {{ __('Confirm') }}
        </x-button>
    </form>
</x-guest-layout>
