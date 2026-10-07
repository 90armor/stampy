@if ($showModal)
    @php
        // Locked values stay readable, as on the Schedules form.
        $lockedControl = 'disabled:!text-slate-700 dark:disabled:!text-slate-300';
        $hint = 'mt-1 text-xs text-slate-500 dark:text-slate-400';
        $daysSuffix = 'pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-slate-500 dark:text-slate-400';
    @endphp
    <x-modal name="leave-type-form-modal" :show="true" entangle="showModal" backdrop="bg-slate-900/50" maxWidth="lg" panelClass="mb-6">
        <div class="mx-4 border-b border-slate-divider py-4 sm:mx-6 sm:py-5">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $editing ? 'Edit — '.$editing->name : 'New leave type' }}</h3>
        </div>

        <form id="leave-type-form" wire:submit="save" class="px-4 py-2 sm:px-6">
            @error('form')
                <div class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">{{ $message }}</div>
            @enderror

            @if ($locked)
                <div id="leave-type-lock-help" class="mt-4 flex items-start gap-2.5 rounded-lg bg-amber-50 p-3 text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30">
                    <x-icon name="lock-closed" class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="text-sm font-semibold">Locked: leave has been taken with this type</p>
                        <p class="mt-0.5 text-xs leading-[1.125rem]">How days are counted, whether half days are allowed and which balance it draws from decide what existing requests cost, so they can't change. Everything else can.</p>
                    </div>
                </div>
            @endif

            <div class="divide-y divide-slate-divider">
                <section class="py-4">
                    <x-input-label for="leave_type_name" value="Name" />
                    <x-text-input id="leave_type_name" type="text" wire:model="name" autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </section>

                <section class="py-4">
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Yearly balance</h4>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="leave_type_days" value="Days per year" />
                            <div class="relative">
                                <x-text-input id="leave_type_days" type="text" inputmode="decimal" wire:model="days_per_year" placeholder="No balance" class="pr-12" />
                                <span class="{{ $daysSuffix }}">Days</span>
                            </div>
                            <p class="{{ $hint }}">
                                @if ($editing)
                                    A change applies from the next grant; grants already made keep their days.
                                @else
                                    Leave empty for leave with no balance, like Unpaid.
                                @endif
                            </p>
                            <x-input-error :messages="$errors->get('days_per_year')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="leave_type_service" value="Usable after" />
                            <div class="relative">
                                <x-text-input id="leave_type_service" type="number" min="0" max="120" wire:model="min_service_months" placeholder="From joining" class="pr-16" />
                                <span class="{{ $daysSuffix }}">Months</span>
                            </div>
                            <p class="{{ $hint }}">
                                @if ($editing)
                                    Months of service before it can be used. A change affects grants not yet made; grants already made and approved leave are untouched.
                                @else
                                    Months of service before it can be used.
                                @endif
                            </p>
                            <x-input-error :messages="$errors->get('min_service_months')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="leave_type_carry" value="Carry over up to" />
                            <div class="relative">
                                <x-text-input id="leave_type_carry" type="text" inputmode="decimal" wire:model="carry_over_cap" placeholder="No carry-over" class="pr-12" />
                                <span class="{{ $daysSuffix }}">Days</span>
                            </div>
                            <x-input-error :messages="$errors->get('carry_over_cap')" class="mt-1" />
                        </div>
                        <label class="flex items-start gap-2.5 sm:pt-7">
                            <input type="checkbox" wire:model="seniority_bonus" class="mt-0.5">
                            <span class="text-sm text-slate-700 dark:text-slate-300">Seniority bonus <span class="block text-xs text-slate-500 dark:text-slate-400">+1 day per 3 completed years of service</span></span>
                        </label>
                    </div>
                </section>

                <section class="py-4" @if ($locked) aria-describedby="leave-type-lock-help" @endif>
                    <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Requests</h4>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="leave_type_counts" value="Counts" />
                            <x-select id="leave_type_counts" wire:model="counts" :disabled="$locked" class="{{ $lockedControl }}">
                                @foreach (\App\Enums\LeaveCounting::cases() as $counting)
                                    <option value="{{ $counting->value }}">{{ $counting->label() }}</option>
                                @endforeach
                            </x-select>
                            <p class="{{ $hint }}">Workdays skip days off and holidays; calendar days count every date.</p>
                            <x-input-error :messages="$errors->get('counts')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="leave_type_deducts" value="Draws from" />
                            <x-select id="leave_type_deducts" wire:model="deducts_from_leave_type_id" :disabled="$locked" class="{{ $lockedControl }}">
                                <option value="">Its own balance</option>
                                @foreach ($deductTargets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </x-select>
                            <p class="{{ $hint }}">Special leave, for example, is deducted from Annual.</p>
                            <x-input-error :messages="$errors->get('deducts_from_leave_type_id')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="leave_type_max" value="At most per request" />
                            <div class="relative">
                                <x-text-input id="leave_type_max" type="text" inputmode="decimal" wire:model="max_days_per_request" placeholder="No limit" class="pr-12" />
                                <span class="{{ $daysSuffix }}">Days</span>
                            </div>
                            <x-input-error :messages="$errors->get('max_days_per_request')" class="mt-1" />
                        </div>
                        <div class="space-y-3 sm:pt-7">
                            <label class="flex items-center gap-2.5">
                                <input type="checkbox" wire:model="allows_half_day" @disabled($locked)>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Half days allowed</span>
                            </label>
                            <label class="flex items-center gap-2.5">
                                <input type="checkbox" wire:model="is_paid">
                                <span class="text-sm text-slate-700 dark:text-slate-300">Paid</span>
                            </label>
                        </div>
                    </div>
                </section>
            </div>
        </form>

        <div class="mx-4 flex items-center justify-end gap-3 border-t border-slate-divider py-4 sm:mx-6">
            <x-button type="button" variant="secondary" wire:click="close">Close</x-button>
            <x-button type="submit" form="leave-type-form" variant="primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </x-button>
        </div>
    </x-modal>
@endif
