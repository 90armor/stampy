@if ($showModal)
    <x-modal
        name="holiday-form-modal"
        :show="true"
        entangle="showModal"
        backdrop="bg-slate-900/50"
        maxWidth="md"
        panelClass="mt-16"
    >
        <div class="mx-6 border-b border-slate-divider py-5">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ $editing ? 'Edit — '.$editing->name : 'Add Holiday' }}
            </h3>
        </div>

        <form id="holiday-form" wire:submit="save" class="px-6 py-2">
            <div class="divide-y divide-slate-divider">
                <div class="grid grid-cols-1 gap-2 py-4 first:pt-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="holiday_date" value="Date" class="!mb-0" />
                    </div>
                    <div class="min-w-0">
                        <x-date-picker id="holiday_date" model="date" label="Date" autofocus />
                        <x-input-error :messages="$errors->get('date')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="holiday_name" value="Name" class="!mb-0" />
                    </div>
                    <div class="min-w-0">
                        <x-text-input id="holiday_name" type="text" wire:model="name" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="holiday_note" value="Note" class="!mb-0" />
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Optional.</p>
                    </div>
                    <div class="min-w-0">
                        <x-textarea id="holiday_note" rows="2" maxlength="255" wire:model="note">{{ $note }}</x-textarea>
                        <x-input-error :messages="$errors->get('note')" class="mt-1" />
                    </div>
                </div>
            </div>

            <p class="border-t border-slate-divider py-4 text-sm text-slate-500 dark:text-slate-400">
                Saving changes recalculates attendance for the employees active on the affected date or dates.
            </p>
        </form>

        <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-divider py-4">
            <x-button type="button" variant="secondary" wire:click="$set('showModal', false)" wire:loading.attr="disabled" wire:target="save">Cancel</x-button>
            <x-button type="submit" form="holiday-form" variant="primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </x-button>
        </div>
    </x-modal>
@endif
