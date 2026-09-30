<x-guest-layout>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Choose a permanent password</h2>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        An admin reset your password. Pick a new one to continue — you won't be able to use the rest of the app until you do.
    </p>

    <form method="POST" action="{{ route('password.change.update') }}" class="mt-8 space-y-5">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="password" value="New password" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Confirm new password" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-button type="submit" variant="primary" class="w-full">
            Set new password
        </x-button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="w-full text-center text-sm font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
            Log out instead
        </button>
    </form>
</x-guest-layout>
