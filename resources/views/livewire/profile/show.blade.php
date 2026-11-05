{{-- The signed-in user's own profile (Phase 6i, docs/DESIGN_SYSTEM.md, Profile):
read-only, every change in a modal. --}}
<div class="max-w-3xl space-y-6">
    <header class="flex min-w-0 items-center gap-x-4">
        <x-avatar :name="$employee?->full_name ?? $user->name" class="h-14 w-14 text-lg font-semibold" />
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $employee?->full_name ?? $user->name }}</h1>
            @if ($employee)
                {{-- Only the parts that exist; the code and the join date never break inside. --}}
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ collect([$employee->position?->name, $employee->department?->name])->filter()->map(fn ($part) => $part.' · ')->implode('') }}<span class="whitespace-nowrap">{{ $employee->employee_code }}</span></p>
                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">@if ($employee->manager)Manager: {{ $employee->manager->full_name }} · @endif<span class="whitespace-nowrap">Joined {{ \App\Support\DisplayDate::compact($employee->join_date) }}</span> <span class="whitespace-nowrap">({{ $tenure }})</span></p>
            @else
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $user->getRoleNames()->map(fn ($role) => ucfirst($role))->implode(', ') ?: 'No role' }}</p>
                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">Not linked to an employee record.</p>
            @endif
        </div>
    </header>

    @if ($notice)
        <div wire:key="profile-notice-{{ md5($notice) }}" tabindex="-1" data-focus-notice class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" role="status">{{ $notice }}</div>
    @endif

    @if ($employee)
        <x-card>
            <h2 tabindex="-1" data-section-heading class="text-base font-semibold text-slate-900 dark:text-slate-100 focus:outline-none">Work schedule</h2>
            @if ($schedule)
                <p class="mt-2 text-sm text-slate-900 dark:text-slate-100">{{ $schedule['today'] }}</p>
                @if ($schedule['next'])
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">From {{ \App\Support\DisplayDate::compact($schedule['next']['from']) }}: {{ $schedule['next']['text'] }}</p>
                @endif
            @else
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No schedule assigned yet. Ask HR.</p>
            @endif
        </x-card>
    @endif

    <x-card>
        <div class="flex items-center justify-between gap-4">
            <h2 tabindex="-1" data-section-heading class="text-base font-semibold text-slate-900 dark:text-slate-100 focus:outline-none">Account</h2>
            @if ($canEditAccount)
                <x-button variant="secondary" wire:click="openAccount" wire:loading.attr="disabled" wire:target="openAccount" aria-label="Edit account">
                    <x-icon name="pencil-square" class="h-5 w-5" />
                    Edit
                </x-button>
            @endif
        </div>

        <dl class="mt-2">
            <div class="grid grid-cols-1 gap-1 border-t border-slate-divider py-3 first:border-t-0 sm:grid-cols-[8rem_minmax(0,1fr)] sm:gap-4">
                <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Username</dt>
                <dd class="min-w-0 text-sm">
                    <span class="break-all text-slate-900 dark:text-slate-100">{{ $user->username }}</span>
                    @unless ($canEditAccount)
                        <span class="block text-slate-500 dark:text-slate-400 sm:ml-2 sm:inline">Ask HR to change</span>
                    @endunless
                </dd>
            </div>
            <div class="grid grid-cols-1 gap-1 border-t border-slate-divider py-3 sm:grid-cols-[8rem_minmax(0,1fr)] sm:gap-4">
                <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Email</dt>
                <dd class="min-w-0 text-sm">
                    @if ($user->email)
                        <span class="break-all text-slate-900 dark:text-slate-100">{{ $user->email }}</span>
                    @else
                        <span class="text-slate-900 dark:text-slate-100">None</span>
                    @endif
                    @unless ($canEditAccount)
                        <span class="block text-slate-500 dark:text-slate-400 sm:ml-2 sm:inline">Ask HR to add or change your email</span>
                    @endunless
                </dd>
            </div>
            <div class="grid grid-cols-1 gap-1 border-t border-slate-divider py-3 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-center sm:gap-4">
                <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Password</dt>
                <dd class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                    <span class="text-slate-900 dark:text-slate-100">
                        @if ($user->password_changed_at)
                            Changed <time datetime="{{ $user->password_changed_at->toIso8601String() }}">{{ $user->password_changed_at->diffForHumans() }}</time>
                        @else
                            &mdash;
                        @endif
                    </span>
                    <x-button variant="secondary" wire:click="openPassword" wire:loading.attr="disabled" wire:target="openPassword" aria-label="Change password">Change</x-button>
                </dd>
            </div>
        </dl>
    </x-card>

    @if ($employee)
        <x-card>
            <h2 tabindex="-1" data-section-heading class="text-base font-semibold text-slate-900 dark:text-slate-100 focus:outline-none">Fingerprint</h2>
            @if ($employee->device_user_id)
                <p class="mt-2 text-sm text-slate-900 dark:text-slate-100">Device user ID <span class="font-medium tabular-nums">{{ $employee->device_user_id }}</span> <span class="text-slate-500 dark:text-slate-400">— tell HR if your punches don't show up.</span></p>
            @else
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Not set yet. HR adds it when you're enrolled on the device.</p>
            @endif
        </x-card>
    @endif

    @if ($showPasswordModal)
        <x-modal name="change-password-modal" :show="true" entangle="showPasswordModal" maxWidth="md" panelClass="mt-16">
            <div class="mx-6 border-b border-slate-divider py-5">
                <x-modal.title>Change password</x-modal.title>
            </div>

            <form id="change-password-form" wire:submit="changePassword" class="space-y-4 px-6 py-5">
                <div>
                    <x-input-label for="current_password" value="Current password" />
                    <x-password-input id="current_password" wire:model="current_password" autocomplete="current-password" autofocus />
                    <x-input-error :messages="$errors->get('current_password')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="new_password" value="New password" />
                    <x-password-input id="new_password" wire:model="password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="new_password_confirmation" value="Confirm new password" />
                    <x-password-input id="new_password_confirmation" wire:model="password_confirmation" />
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1" />
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400">You'll stay signed in here and be signed out on your other devices.</p>
            </form>

            <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-divider py-4">
                <x-button type="button" variant="secondary" wire:click="$set('showPasswordModal', false)" wire:loading.attr="disabled" wire:target="changePassword">Cancel</x-button>
                <x-button type="submit" form="change-password-form" variant="primary" wire:loading.attr="disabled" wire:target="changePassword">
                    <span wire:loading.remove wire:target="changePassword">Change password</span>
                    <span wire:loading wire:target="changePassword">Changing&hellip;</span>
                </x-button>
            </div>
        </x-modal>
    @endif

    @if ($showAccountModal)
        <x-modal name="edit-account-modal" :show="true" entangle="showAccountModal" maxWidth="md" panelClass="mt-16">
            <div class="mx-6 border-b border-slate-divider py-5">
                <x-modal.title>Edit account</x-modal.title>
            </div>

            <form id="edit-account-form" wire:submit="saveAccount" class="space-y-4 px-6 py-5">
                @if ($employee)
                    <div>
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Name</p>
                        <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</p>
                        <a href="{{ route('employees.show', $employee) }}" wire:navigate class="mt-1 inline-flex items-center rounded text-sm text-primary-700 underline decoration-primary-300 decoration-1 underline-offset-2 hover:decoration-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 max-sm:-my-3 max-sm:min-h-11 dark:text-primary-300 dark:decoration-primary-600 dark:hover:decoration-primary-300">Edit on your employee record</a>
                    </div>
                @else
                    <div>
                        <x-input-label for="account_name" value="Name" />
                        <x-text-input id="account_name" type="text" wire:model="name" autocomplete="name" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                @endif
                <div>
                    <x-input-label for="account_email" value="Email" />
                    @if ($employee)
                        <x-text-input id="account_email" type="email" wire:model="email" autocomplete="email" autofocus />
                    @else
                        <x-text-input id="account_email" type="email" wire:model="email" autocomplete="email" />
                    @endif
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Optional. You can also sign in with it.</p>
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
            </form>

            <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-divider py-4">
                <x-button type="button" variant="secondary" wire:click="$set('showAccountModal', false)" wire:loading.attr="disabled" wire:target="saveAccount">Cancel</x-button>
                <x-button type="submit" form="edit-account-form" variant="primary" wire:loading.attr="disabled" wire:target="saveAccount">
                    <span wire:loading.remove wire:target="saveAccount">Save</span>
                    <span wire:loading wire:target="saveAccount">Saving&hellip;</span>
                </x-button>
            </div>
        </x-modal>
    @endif
</div>
