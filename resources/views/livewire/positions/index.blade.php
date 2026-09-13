<div class="space-y-6">
    <div class="flex items-center justify-end">
        <x-button variant="primary" wire:click="create">
            <x-icon name="plus" class="w-4 h-4" />
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
                    <x-button variant="primary" wire:click="create">Add Position</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div>
                @foreach ($positions as $position)
                    <div class="group mx-6 flex items-center justify-between gap-4 border-t first:border-t-0 border-slate-200/60 py-4 dark:border-slate-800/60">
                        <div class="flex items-center gap-4 min-w-0">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-100 text-sm font-bold text-accent-800 dark:bg-accent-900/40 dark:text-accent-300">
                                {{ strtoupper(substr($position->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900 dark:text-slate-100">{{ $position->name }}</p>
                                @if ($position->description)
                                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $position->description }}</p>
                                @endif
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    {{ $position->employees_count }} {{ $position->employees_count === 1 ? 'employee' : 'employees' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-1 shrink-0 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                            <button
                                type="button"
                                wire:click="edit({{ $position->id }})"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                title="Edit"
                            >
                                <x-icon name="pencil" class="w-4 h-4" />
                            </button>
                            <button
                                type="button"
                                @click="$dispatch('confirm-dialog-positions', {
                                    title: 'Delete position',
                                    message: @js('Delete '.$position->name.'? This action cannot be undone.'),
                                    confirmText: 'Delete',
                                    method: 'delete',
                                    args: [{{ $position->id }}],
                                })"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                title="Delete"
                            >
                                <x-icon name="trash" class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                {{ $positions->links() }}
            </div>
        @endif
    </x-card>

    @include('livewire.positions.partials.modal')

    <x-confirm-dialog event="confirm-dialog-positions" />
</div>
