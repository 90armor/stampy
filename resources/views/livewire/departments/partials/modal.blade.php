@if ($showModal)
    <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="$set('showModal', false)"></div>

        <div class="relative mx-auto mt-16 max-w-md">
            <x-card>
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                    {{ $editing ? 'Edit Department' : 'New Department' }}
                </h3>

                <form wire:submit="save" class="mt-4 space-y-4">
                    <div>
                        <x-input-label for="dept_name" value="Name" />
                        <x-text-input id="dept_name" type="text" class="w-full" wire:model="name" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="dept_description" value="Description (optional)" />
                        <x-textarea id="dept_description" class="w-full" rows="3" wire:model="description">{{ $description }}</x-textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-1" />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
                        <x-button type="submit" variant="primary">Save</x-button>
                    </div>
                </form>
            </x-card>
        </div>
    </div>
@endif
