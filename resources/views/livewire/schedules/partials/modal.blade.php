@if ($showModal)
    <x-modal
        name="schedule-form-modal"
        :show="true"
        entangle="showModal"
        backdrop="bg-slate-900/50"
        maxWidth="lg"
        panelClass="mt-16"
    >
        <div class="mx-6 border-b border-slate-200/60 py-5 dark:border-slate-800/60">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ $editing ? 'Edit — '.$editing->name : 'Add Schedule' }}
            </h3>
        </div>

        <form id="schedule-form" wire:submit="save" class="px-6 py-2">
            @error('form')
                <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
                    {{ $message }}
                </div>
            @enderror

            @if ($editingIsLocked)
                <div class="mb-4 flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-500/30">
                    <x-icon name="info" class="h-4 w-4 shrink-0" />
                    <span>This schedule is already assigned to an employee or has been used to calculate attendance, so its hours can't be changed — daily attendance must always be recomputable exactly as it was. The name can still be edited. To change hours, create a new schedule and reassign the affected employees to it.</span>
                </div>
            @endif

            <div class="divide-y divide-slate-200/60 dark:divide-slate-800/60">
                <div class="grid grid-cols-1 gap-2 py-4 first:pt-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="schedule_name" value="Name" class="!mb-0" />
                    </div>
                    <div class="min-w-0">
                        <x-text-input id="schedule_name" type="text" wire:model="name" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label value="Hours" class="!mb-0" />
                    </div>
                    <div class="min-w-0 flex items-center gap-3">
                        <x-text-input type="time" wire:model="start_time" :disabled="$editingIsLocked" />
                        <span class="text-slate-400">&ndash;</span>
                        <x-text-input type="time" wire:model="end_time" :disabled="$editingIsLocked" />
                    </div>
                    <div></div>
                    <div class="min-w-0">
                        <x-input-error :messages="$errors->get('start_time')" class="mt-1" />
                        <x-input-error :messages="$errors->get('end_time')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="schedule_grace" value="Grace" class="!mb-0" />
                        <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Minutes.</p>
                    </div>
                    <div class="min-w-0">
                        <x-text-input id="schedule_grace" type="number" min="0" wire:model="grace_minutes" :disabled="$editingIsLocked" />
                        <x-input-error :messages="$errors->get('grace_minutes')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label for="schedule_break" value="Break" class="!mb-0" />
                        <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Minutes.</p>
                    </div>
                    <div class="min-w-0">
                        <x-text-input id="schedule_break" type="number" min="0" wire:model="break_minutes" :disabled="$editingIsLocked" />
                        <x-input-error :messages="$errors->get('break_minutes')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2 py-4 sm:grid-cols-[110px_1fr] sm:items-start sm:gap-6">
                    <div>
                        <x-input-label value="Workdays" class="!mb-0" />
                    </div>
                    <div class="min-w-0 flex flex-wrap gap-3">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $iso => $label)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    wire:model="workdays"
                                    value="{{ $iso }}"
                                    @disabled($editingIsLocked)
                                    class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800"
                                >
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div></div>
                    <div class="min-w-0">
                        <x-input-error :messages="$errors->get('workdays')" class="mt-1" />
                    </div>
                </div>

                <div class="py-4">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="is_default" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-800">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Make this the default schedule for new employees</span>
                    </label>
                </div>
            </div>
        </form>

        <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
            <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
            <x-button type="submit" form="schedule-form" variant="primary">Save</x-button>
        </div>
    </x-modal>
@endif
