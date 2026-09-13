@if ($showModal)
    @php
        $fieldClass = 'block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:bg-slate-800/70 dark:border-slate-700 dark:text-slate-100 dark:placeholder-slate-500';
    @endphp

    <div class="fixed inset-0 z-50 overflow-y-auto overscroll-contain px-4 py-6">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="$set('showModal', false)"></div>

        <div class="relative mx-auto mt-16 max-w-md">
            <div class="overflow-hidden rounded-lg bg-white ring-1 ring-slate-200 shadow-lg dark:bg-slate-900 dark:ring-slate-800/70">
                <div class="mx-6 border-b border-slate-200/60 py-5 dark:border-slate-800/60">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                        {{ $editing ? 'Edit — '.$editing->name : 'Add Department' }}
                    </h3>
                </div>

                <form id="department-form" wire:submit="save" class="px-6 py-2">
                    <div class="divide-y divide-slate-200/60 dark:divide-slate-800/60">
                        <div class="grid grid-cols-1 gap-2 py-4 first:pt-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                            <div>
                                <x-input-label for="dept_name" value="Name" class="!mb-0" />
                            </div>
                            <div class="min-w-0">
                                <input id="dept_name" type="text" class="{{ $fieldClass }}" wire:model="name" autofocus>
                                <x-input-error :messages="$errors->get('name')" class="mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                            <div>
                                <x-input-label for="dept_description" value="Description" class="!mb-0" />
                                <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Optional.</p>
                            </div>
                            <div class="min-w-0">
                                <textarea id="dept_description" rows="3" wire:model="description" class="{{ $fieldClass }}">{{ $description }}</textarea>
                                <x-input-error :messages="$errors->get('description')" class="mt-1" />
                            </div>
                        </div>
                    </div>
                </form>

                <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                    <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
                    <x-button type="submit" form="department-form" variant="primary">Save</x-button>
                </div>
            </div>
        </div>
    </div>
@endif
