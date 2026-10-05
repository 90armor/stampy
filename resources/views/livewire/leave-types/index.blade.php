@php
    use App\Support\LeaveDays;

    $days = fn (?string $decimal) => LeaveDays::label(LeaveDays::fromDecimal($decimal));
    // One line on the allowance, one on how requests work — built as strings,
    // so no directive sits between the separators.
    $allowance = fn ($type) => $type->days_per_year === null
        ? ($type->deductsFrom ? 'Deducted from '.$type->deductsFrom->name : 'No yearly balance')
        : implode(' · ', array_filter([
            $days($type->days_per_year).' a year',
            $type->seniority_bonus ? '+1 day per 3 years of service' : null,
            $type->carry_over_cap !== null ? 'carry over up to '.$days($type->carry_over_cap) : 'no carry-over',
            $type->min_service_months ? 'usable after '.$type->min_service_months.' '.Str::plural('month', $type->min_service_months).' of service' : null,
        ]));
    $rules = fn ($type) => implode(' · ', array_filter([
        'Counts '.Str::lower($type->counts->label()),
        $type->allows_half_day ? 'half days allowed' : 'whole days only',
        $type->max_days_per_request !== null ? 'at most '.$days($type->max_days_per_request).' per request' : null,
        $type->is_paid ? 'paid' : 'unpaid',
    ]));
    $referenced = fn ($type) => $type->leaves_count + $type->entitlements_count + $type->adjustments_count + $type->deducted_by_count > 0;
    $actionButton = 'inline-flex h-9 items-center rounded-lg px-2.5 text-xs font-medium text-slate-600 transition hover:bg-primary-50 hover:text-primary-700 active:bg-primary-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-300 dark:hover:bg-primary-600/35 dark:hover:text-primary-300 dark:active:bg-primary-900/50';
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Leave types</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">What each kind of leave grants, and how requests for it are counted.</p>
        </div>
        <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create" class="self-start sm:self-auto">
            <x-icon name="plus" class="h-5 w-5" />
            New leave type
        </x-button>
    </div>

    @if ($notice)
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30" role="status">{{ $notice }}</div>
    @endif
    @error('delete')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">{{ $message }}</div>
    @enderror

    <x-card :padding="false">
        @if ($types->isEmpty())
            <x-empty-state icon="calendar-days" title="No leave types yet" description="Add the kinds of leave employees can take.">
                <x-slot name="action">
                    <x-button variant="primary" wire:click="create">New leave type</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div role="list" aria-label="Leave types">
                @foreach ($types as $type)
                    <div wire:key="leave-type-{{ $type->id }}" role="listitem" class="relative grid grid-cols-1 gap-x-4 px-5 py-4 transition hover:bg-slate-50 dark:hover:bg-slate-750/60 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-6">
                        @unless ($loop->last)
                            <span class="pointer-events-none absolute inset-x-5 bottom-0 h-px bg-slate-divider sm:inset-x-6"></span>
                        @endunless

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <p @class(['font-semibold', 'text-slate-900 dark:text-slate-100' => $type->is_active, 'text-slate-500 dark:text-slate-400' => ! $type->is_active])>{{ $type->name }}</p>
                                @unless ($type->is_active)
                                    <x-badge color="slate">Inactive</x-badge>
                                @endunless
                            </div>
                            <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">{{ $allowance($type) }}</p>
                            <p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $rules($type) }}</p>
                            @if ($type->pending_count > 0)
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $type->pending_count }} pending {{ Str::plural('request', $type->pending_count) }}</p>
                            @endif
                        </div>

                        <div class="mt-3 flex shrink-0 flex-wrap items-center gap-1 self-start sm:col-start-2 sm:row-start-1 sm:mt-0 sm:justify-end">
                            @if ($type->is_active)
                                @if ($referenced($type))
                                    {{-- In use: can't be deleted, so it's retired instead. --}}
                                    <button
                                        type="button"
                                        @click="$dispatch('confirm-dialog-leave-types', {
                                            title: @js('Deactivate '.$type->name.'?'),
                                            message: @js('No new '.$type->name.' requests can be made and no new grants are given. Balances and history stay.'.($type->pending_count > 0 ? ' '.$type->pending_count.' pending '.Str::plural('request', $type->pending_count).' stay decidable.' : '')),
                                            confirmText: 'Deactivate',
                                            cancelText: 'Keep active',
                                            method: 'setActive',
                                            args: [{{ $type->id }}, false],
                                        })"
                                        class="{{ $actionButton }}"
                                        aria-label="Deactivate {{ $type->name }}"
                                    >Deactivate</button>
                                @endif
                            @else
                                <button type="button" wire:click="setActive({{ $type->id }}, true)" class="{{ $actionButton }}" aria-label="Reactivate {{ $type->name }}">Reactivate</button>
                            @endif
                            <button
                                type="button"
                                wire:click="edit({{ $type->id }})"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-400"
                                aria-label="Edit {{ $type->name }}"
                            >
                                <x-icon name="pencil" class="h-5 w-5" />
                            </button>
                            @unless ($referenced($type))
                                {{-- Delete only while nothing refers to the type (LeaveType::isReferenced()). --}}
                                <button
                                    type="button"
                                    @click="$dispatch('confirm-dialog-leave-types', {
                                        title: @js('Delete '.$type->name.'?'),
                                        message: 'Nothing refers to this type yet, so it can be deleted. This cannot be undone.',
                                        confirmText: 'Delete',
                                        method: 'delete',
                                        args: [{{ $type->id }}],
                                    })"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                    aria-label="Delete {{ $type->name }}"
                                >
                                    <x-icon name="trash" class="h-5 w-5" />
                                </button>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    @include('livewire.leave-types.partials.modal')

    <x-confirm-dialog event="confirm-dialog-leave-types" />
</div>
