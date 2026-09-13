<x-guest-layout>
    <p class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">One more step</p>
    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Verify your email</h2>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        {{ __('Before getting started, please verify your email address by clicking the link we emailed to you. If you didn\'t receive it, we can send another.') }}
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mt-6 flex gap-x-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-900/20">
            <x-icon name="check" class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400" />
            <p class="text-sm text-green-700 dark:text-green-400">
                {{ __('A new verification link has been sent to the email address you provided.') }}
            </p>
        </div>
    @endif

    <div class="mt-8 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-button type="submit" variant="primary">
                {{ __('Resend verification email') }}
            </x-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
