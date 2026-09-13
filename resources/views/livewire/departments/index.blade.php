<div class="space-y-6">
    <div class="flex items-center justify-end">
        <x-button variant="primary" wire:click="create">
            <x-icon name="plus" class="w-4 h-4" />
            New Department
        </x-button>
    </div>

    @error('delete')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
            {{ $message }}
        </div>
    @enderror

    <x-card :padding="false">
        @if ($departments->isEmpty())
            <x-empty-state
                icon="building-office"
                title="No departments yet"
                description="Create a department to start assigning employees to it."
            >
                <x-slot name="action">
                    <x-button variant="primary" wire:click="create">Add Department</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div>
                @foreach ($departments as $department)
                    <div class="flex items-center justify-between gap-4 px-6 py-4 border-t first:border-t-0 border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-4 min-w-0">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-100 text-sm font-bold text-accent-800 dark:bg-accent-900/40 dark:text-accent-300">
                                {{ strtoupper(substr($department->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900 dark:text-slate-100">{{ $department->name }}</p>
                                @if ($department->description)
                                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $department->description }}</p>
                                @endif
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    {{ $department->employees_count }} {{ $department->employees_count === 1 ? 'employee' : 'employees' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            <button
                                type="button"
                                wire:click="edit({{ $department->id }})"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                title="Edit"
                            >
                                <x-icon name="pencil" class="w-4 h-4" />
                            </button>
                            <button
                                type="button"
                                @click="$dispatch('confirm-dialog-departments', {
                                    title: 'Delete department',
                                    message: @js('Delete '.$department->name.'? This action cannot be undone.'),
                                    confirmText: 'Delete',
                                    method: 'delete',
                                    args: [{{ $department->id }}],
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

            <div class="border-t border-slate-100 px-6 py-4 dark:border-slate-800">
                {{ $departments->links() }}
            </div>
        @endif
    </x-card>

    @include('livewire.departments.partials.modal')

    <x-confirm-dialog event="confirm-dialog-departments" />
</div>
