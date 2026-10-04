<x-app-layout>
    <x-slot name="header">Profile</x-slot>

    <div class="max-w-2xl space-y-6">
        {{-- A user with no linked employee record (not yet onboarded to
             self-service, or a plain login-only account) sees only the
             account sections below — there's no employee record to
             summarize. --}}
        @if ($user->employee)
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Employee record</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ $user->employee->employee_code }}
                </p>
            </div>

            <x-employee-details-card :employee="$user->employee" />

            <x-card>
                <livewire:employees.schedule-assignments :employee="$user->employee" :key="'profile-schedule-assignments-'.$user->employee->id" />
            </x-card>
        @endif

        <x-card>
            @include('profile.partials.update-profile-information-form')
        </x-card>

        <x-card>
            @include('profile.partials.update-password-form')
        </x-card>
    </div>
</x-app-layout>
