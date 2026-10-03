<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Positions</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manage the organization-wide job titles assigned to employees.</p>
        </div>
        <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create" class="self-start sm:self-auto">
            <x-icon name="plus" class="w-5 h-5" />
            New Position
        </x-button>
    </div>

    @error('delete')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
            {{ $message }}
        </div>
    @enderror

    <x-card :padding="false">
        @if ($positions->isEmpty())
            <x-empty-state
                icon="briefcase"
                title="No positions yet"
                description="Create a position to start assigning employees to it."
            >
                <x-slot name="action">
                    <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">New Position</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div role="list" aria-label="Positions">
                @foreach ($positions as $position)
                    <div wire:key="position-{{ $position->id }}" role="listitem" class="group relative flex items-center justify-between gap-4 px-5 py-3.5 transition hover:bg-slate-50 dark:hover:bg-slate-750/60 sm:px-6">
                        @unless ($loop->last)
                            <span class="pointer-events-none absolute inset-x-5 bottom-0 h-px bg-slate-divider sm:inset-x-6"></span>
                        @endunless

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <p class="break-words font-semibold text-slate-900 dark:text-slate-100">{{ $position->name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $position->employees_count }} {{ $position->employees_count === 1 ? 'employee' : 'employees' }}
                                </p>
                            </div>
                            @if ($position->description)
                                <p class="mt-0.5 line-clamp-2 break-words text-sm text-slate-500 dark:text-slate-400">{{ $position->description }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <span class="group/action relative inline-flex">
                                <button
                                    type="button"
                                    wire:click="edit({{ $position->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="edit({{ $position->id }})"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 active:bg-primary-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-400 dark:active:bg-primary-900/50"
                                    aria-label="Edit {{ $position->name }} position"
                                >
                                    <x-icon name="pencil" class="w-5 h-5" />
                                </button>
                                <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Edit position</span>
                            </span>
                            <span class="group/action relative inline-flex">
                                <button
                                    type="button"
                                    @click="$dispatch('confirm-dialog-positions', {
                                        title: 'Delete position',
                                        message: @js('Delete '.$position->name.'? This action cannot be undone.'),
                                        confirmText: 'Delete',
                                        method: 'delete',
                                        args: [{{ $position->id }}],
                                    })"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-50 hover:text-red-600 active:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400 dark:active:bg-red-900/50"
                                    aria-label="Delete {{ $position->name }} position"
                                >
                                    <x-icon name="trash" class="w-5 h-5" />
                                </button>
                                <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Delete position</span>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- No footer (and no empty divider band) when everything fits on one page. --}}
            @if ($positions->hasPages())
                <div class="border-t border-slate-divider px-5 py-4 sm:px-6">
                    {{ $positions->links() }}
                </div>
            @endif
        @endif
    </x-card>

    @include('livewire.positions.partials.modal')

    <x-confirm-dialog event="confirm-dialog-positions" />
</div>
