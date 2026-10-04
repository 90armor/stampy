<section>
    <header>
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
            {{ __('Profile information') }}
        </h2>

        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Your name and email address.') }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            @if ($user->employee)
                {{-- HR data, admin-owned (see ProfileController::update(), which enforces
                this server-side — the readonly attribute here is presentation only). --}}
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full cursor-not-allowed !bg-slate-50 !text-slate-500 dark:!bg-slate-900 dark:!text-slate-600" :value="$user->employee->full_name" readonly aria-readonly="true" />
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Your name is managed by an admin and set from your employee record.') }}</p>
            @else
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            @endif
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            {{-- A Blade @if/@endif embedded directly inside a self-closing
            <x-component /> tag's attribute list breaks Blade's component-tag
            compiler entirely (it only recognizes a fixed set of attribute
            token shapes there) — the whole tag then passes through
            uncompiled, which browsers silently drop as an unrecognized
            custom element. That's what made this input disappear. Branch
            outside the tag instead, the same way the Name field above does. --}}
            @if ($user->employee)
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" autocomplete="username" autofocus />
            @else
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" autocomplete="username" />
            @endif
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-slate-500 dark:text-slate-400"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
