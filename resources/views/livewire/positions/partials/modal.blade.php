@if ($showModal)
    <x-modal
        name="position-form-modal"
        :show="true"
        entangle="showModal"
        surface="solid"
        backdrop="bg-slate-900/50"
        maxWidth="md"
        panelClass="mt-16"
    >
        <div class="mx-6 border-b border-slate-200/60 py-5 dark:border-slate-800/60">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ $editing ? 'Edit — '.$editing->name : 'Add Position' }}
            </h3>
        </div>

        <form id="position-form" wire:submit="save" class="px-6 py-2">
            <div class="divide-y divide-slate-200/60 dark:divide-slate-800/60">
                <div class="grid grid-cols-1 gap-2 py-4 first:pt-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="pos_name" value="Name" class="!mb-0" />
                    </div>
                    <div class="min-w-0">
                        <x-text-input id="pos_name" type="text" surface="solid" wire:model="name" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="pos_description" value="Description" class="!mb-0" />
                        <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Optional.</p>
                    </div>
                    <div class="min-w-0">
                        <x-textarea id="pos_description" rows="3" surface="solid" wire:model="description">{{ $description }}</x-textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>
                </div>
            </div>
        </form>

        <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
            <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
            <x-button type="submit" form="position-form" variant="primary">Save</x-button>
        </div>
    </x-modal>
@endif
