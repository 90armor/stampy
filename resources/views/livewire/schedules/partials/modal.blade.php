@if ($showModal)
    <x-modal
        name="schedule-form-modal"
        :show="true"
        entangle="showModal"
        backdrop="bg-slate-900/50"
        maxWidth="lg"
        panelClass="mb-6"
    >
        <div class="mx-6 border-b border-slate-divider py-5">
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
                <div id="schedule-lock-help" class="mt-4 flex items-start gap-2.5 rounded-lg bg-amber-50 p-3 text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30">
                    <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="text-sm font-semibold">Schedule configuration is locked</p>
                        <p class="mt-0.5 text-xs leading-[1.125rem]">This schedule has assignment or attendance history. Hours, attendance rules, and workdays are locked; name and default remain editable. To change locked settings, create a replacement schedule and reassign employees.</p>
                    </div>
                </div>
            @endif

            <div class="divide-y divide-slate-divider">
                <section class="py-4">
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Identity</h4>
                    <div class="mt-3">
                        <x-input-label for="schedule_name" value="Name" />
                        <x-text-input id="schedule_name" type="text" wire:model="name" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                </section>

                <section class="py-4" @if ($editingIsLocked) aria-describedby="schedule-lock-help" @endif>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Working hours</h4>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label id="schedule_start_time-label" for="schedule_start_time" value="Start time" />
                            <x-time-input id="schedule_start_time" model="start_time" label="Start time" :disabled="$editingIsLocked" />
                            <x-input-error :messages="$errors->get('start_time')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label id="schedule_end_time-label" for="schedule_end_time" value="End time" />
                            {{-- after: the end must be later than the start (WorkSchedule's own rule). --}}
                            <x-time-input id="schedule_end_time" model="end_time" label="End time" after="start_time" :disabled="$editingIsLocked" />
                            <x-input-error :messages="$errors->get('end_time')" class="mt-1" />
                        </div>
                    </div>
                </section>

                <section class="py-4" @if ($editingIsLocked) aria-describedby="schedule-lock-help" @endif>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Attendance rules</h4>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="schedule_grace" value="Grace period" />
                            <div class="relative">
                                <x-text-input id="schedule_grace" type="number" min="0" max="65535" wire:model="grace_minutes" :disabled="$editingIsLocked" class="pr-16 disabled:!text-slate-700 dark:disabled:!text-slate-300" />
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-slate-500 dark:text-slate-400">Minutes</span>
                            </div>
                            <x-input-error :messages="$errors->get('grace_minutes')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="schedule_break" value="Break duration" />
                            <div class="relative">
                                <x-text-input id="schedule_break" type="number" min="0" wire:model="break_minutes" :disabled="$editingIsLocked" class="pr-16 disabled:!text-slate-700 dark:disabled:!text-slate-300" />
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-slate-500 dark:text-slate-400">Minutes</span>
                            </div>
                            <x-input-error :messages="$errors->get('break_minutes')" class="mt-1" />
                        </div>
                    </div>
                </section>

                <fieldset class="py-4" @disabled($editingIsLocked) @if ($editingIsLocked) aria-describedby="schedule-lock-help" @endif>
                    <legend class="text-sm font-semibold text-slate-900 dark:text-slate-100">Workdays</legend>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-3">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $iso => $label)
                            <label class="inline-flex min-h-9 items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    wire:model="workdays"
                                    value="{{ $iso }}"
                                >
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('workdays')" class="mt-1" />
                </fieldset>

                <section class="py-4">
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Default behavior</h4>
                    <label class="mt-3 flex items-start gap-2.5">
                        <input type="checkbox" wire:model="is_default" @disabled($editing?->is_default) class="mt-0.5">
                        <span>
                            <span class="block text-sm font-medium text-slate-700 dark:text-slate-300">Use for newly created employees</span>
                            <span class="mt-0.5 block text-xs leading-5 text-slate-500 dark:text-slate-400">
                                @if ($editing?->is_default)
                                    This is the current default. Make another schedule the default to replace it.
                                @else
                                    Selecting this replaces the current default; existing employee assignments do not change.
                                @endif
                            </span>
                        </span>
                    </label>
                </section>
            </div>
        </form>

        <div class="mx-6 flex items-center justify-end gap-3 border-t border-slate-divider py-4">
            <x-button type="button" variant="secondary" wire:click="$set('showModal', false)" wire:loading.attr="disabled" wire:target="save">Cancel</x-button>
            <x-button type="submit" form="schedule-form" variant="primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </x-button>
        </div>
    </x-modal>
@endif
