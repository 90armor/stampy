<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Holidays</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Configure company-wide dates that affect attendance.</p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="w-32">
                <x-input-label for="holiday_year" value="Year" />
                <x-select id="holiday_year" wire:model.live="yearFilter">
                    @foreach ($years as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </x-select>
            </div>

            <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                <x-icon name="plus" class="w-4 h-4" />
                New Holiday
            </x-button>
        </div>
    </div>

    @error('delete')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
            {{ $message }}
        </div>
    @enderror

    <x-card :padding="false">
        @if ($holidays->isEmpty())
            <x-empty-state
                icon="flag"
                title="No holidays for {{ $yearFilter }}"
                description="Add a holiday to mark it company-wide on everyone's calendar."
            >
                <x-slot name="action">
                    <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">New Holiday</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div role="list" aria-label="Holidays for {{ $yearFilter }}">
                @foreach ($holidays as $holiday)
                    <div wire:key="holiday-{{ $holiday->id }}" role="listitem" class="group relative grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 px-5 py-3.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/60 sm:grid-cols-[9.5rem_minmax(0,1fr)_auto] sm:items-start sm:px-6 sm:py-4">
                        @unless ($loop->last)
                            <span class="pointer-events-none absolute inset-x-5 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60 sm:inset-x-6"></span>
                        @endunless

                        <time datetime="{{ $holiday->date->format('Y-m-d') }}" class="col-start-1 text-sm font-medium tabular-nums text-slate-700 dark:text-slate-300">
                            {{ \App\Support\DisplayDate::compact($holiday->date) }}
                        </time>

                        <div class="col-span-2 col-start-1 mt-1.5 min-w-0 sm:col-span-1 sm:col-start-2 sm:mt-0">
                            <p class="break-words font-semibold text-slate-900 dark:text-slate-100">{{ $holiday->name }}</p>
                            @if ($holiday->note)
                                <p class="mt-0.5 break-words text-sm text-slate-500 dark:text-slate-400">{{ $holiday->note }}</p>
                            @endif
                        </div>

                        <div class="col-start-2 row-start-1 flex shrink-0 items-center gap-1 sm:col-start-3">
                            <span class="group/action relative inline-flex">
                                <button
                                    type="button"
                                    wire:click="edit({{ $holiday->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="edit({{ $holiday->id }})"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 active:bg-primary-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400 dark:active:bg-primary-900/50"
                                    aria-label="Edit {{ $holiday->name }} holiday on {{ \App\Support\DisplayDate::long($holiday->date) }}"
                                >
                                    <x-icon name="pencil" class="w-4 h-4" />
                                </button>
                                <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Edit holiday</span>
                            </span>
                            <span class="group/action relative inline-flex">
                                <button
                                    type="button"
                                    @click="$dispatch('confirm-dialog-holidays', {
                                        title: 'Delete holiday',
                                        message: @js('Delete '.$holiday->name.' ('.\App\Support\DisplayDate::compact($holiday->date).')? Attendance for active employees on this date will be recalculated. This action cannot be undone.'),
                                        confirmText: 'Delete',
                                        method: 'delete',
                                        args: [{{ $holiday->id }}],
                                    })"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-50 hover:text-red-600 active:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400 dark:active:bg-red-900/50"
                                    aria-label="Delete {{ $holiday->name }} holiday on {{ \App\Support\DisplayDate::long($holiday->date) }}"
                                >
                                    <x-icon name="trash" class="w-4 h-4" />
                                </button>
                                <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Delete holiday</span>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- No footer (and no empty divider band) when everything fits on one page. --}}
            @if ($holidays->hasPages())
                <div class="border-t border-slate-200/60 px-5 py-4 dark:border-slate-800/60 sm:px-6">
                    {{ $holidays->links() }}
                </div>
            @endif
        @endif
    </x-card>

    @include('livewire.holidays.partials.modal')

    <x-confirm-dialog event="confirm-dialog-holidays" />
</div>
