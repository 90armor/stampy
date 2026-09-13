<x-guest-layout>
    <p class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Get started</p>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Create your account</h2>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Set up access to your workspace.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between pt-2">
            <a class="text-sm font-semibold text-primary-700 hover:text-primary-800 dark:text-accent-300 dark:hover:text-accent-200" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-button type="submit" variant="primary">
                {{ __('Register') }}
            </x-button>
        </div>
    </form>
</x-guest-layout>
