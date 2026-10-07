@php
    $hint = 'mt-1 text-xs text-slate-500 dark:text-slate-400';
    $suffix = 'pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-slate-500 dark:text-slate-400';
    $lockedControl = 'disabled:!text-slate-700 dark:disabled:!text-slate-300';
    $sectionTitle = 'text-sm font-semibold text-slate-900 dark:text-slate-100';
@endphp

<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Overtime</h2>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">How overtime is paid, limited and taken as time off. The defaults follow the Labour Law until HR confirms the company's policy.</p>
    </div>

    @if ($notice)
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30" role="status">{{ $notice }}</div>
    @endif
    @error('form')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">{{ $message }}</div>
    @enderror

    <x-card :padding="false">
        <form id="overtime-settings-form" wire:submit="save" class="divide-y divide-slate-divider">
            <section class="px-4 py-5 sm:px-6">
                <h3 class="{{ $sectionTitle }}">Rates</h3>
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach (['workday_rate_percent' => 'Workday', 'night_rate_percent' => 'Night', 'rest_day_rate_percent' => 'Rest day', 'holiday_rate_percent' => 'Holiday'] as $field => $label)
                        <div>
                            <x-input-label for="ot_{{ $field }}" :value="$label" />
                            <div class="relative">
                                <x-text-input id="ot_{{ $field }}" type="number" min="100" wire:model="{{ $field }}" class="pr-8" />
                                <span class="{{ $suffix }}">%</span>
                            </div>
                            <x-input-error :messages="$errors->get($field)" class="mt-1" />
                        </div>
                    @endforeach
                </div>
                <p class="{{ $hint }}">Each overtime minute counts in one category — holiday, then rest day, then night, then workday — so the rates must run holiday ≥ rest day ≥ night ≥ workday ≥ 100%. A rate only changes the monthly report's arithmetic: the minutes already credited stay as they are.</p>
                <x-input-error :messages="$errors->get('rates')" class="mt-1" />
            </section>

            <section class="px-4 py-5 sm:px-6">
                <h3 class="{{ $sectionTitle }}">When</h3>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <x-input-label id="ot_night_starts-label" for="ot_night_starts" value="Night starts" />
                        <x-time-input id="ot_night_starts" model="night_starts" label="Night starts" />
                        <x-input-error :messages="$errors->get('night_starts')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label id="ot_night_ends-label" for="ot_night_ends" value="Night ends" />
                        <x-time-input id="ot_night_ends" model="night_ends" label="Night ends" />
                        <x-input-error :messages="$errors->get('night_ends')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ot_weekly_rest_day" value="Weekly rest day" />
                        <x-select id="ot_weekly_rest_day" wire:model="weekly_rest_day">
                            @foreach ($weekdays as $number => $name)
                                <option value="{{ $number }}">{{ $name }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('weekly_rest_day')" class="mt-1" />
                    </div>
                </div>
                <p class="{{ $hint }}">Night hours earn the night rate; the weekly rest day earns the rest-day rate (any other day off counts as a workday). A change applies to days built from now on — past days keep their categories until rebuilt: <code class="text-[11px]">php artisan attendance:build-daily --from=YYYY-MM-DD --to=YYYY-MM-DD</code>.</p>
            </section>

            <section class="px-4 py-5 sm:px-6">
                <h3 class="{{ $sectionTitle }}">Limits</h3>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="ot_max_overtime" value="Overtime a day, at most" />
                        <div class="relative">
                            <x-text-input id="ot_max_overtime" type="number" min="1" wire:model="max_overtime_minutes_per_day" class="pr-16" />
                            <span class="{{ $suffix }}">minutes</span>
                        </div>
                        <p class="{{ $hint }}">On a day with scheduled hours. A day off or a holiday has only the total below.</p>
                        <x-input-error :messages="$errors->get('max_overtime_minutes_per_day')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ot_max_work" value="Work a day, at most" />
                        <div class="relative">
                            <x-text-input id="ot_max_work" type="number" min="1" wire:model="max_work_minutes_per_day" class="pr-16" />
                            <span class="{{ $suffix }}">minutes</span>
                        </div>
                        <p class="{{ $hint }}">Scheduled hours plus overtime. An admin can go over either limit with a reason.</p>
                        <x-input-error :messages="$errors->get('max_work_minutes_per_day')" class="mt-1" />
                    </div>
                </div>
            </section>

            <section class="px-4 py-5 sm:px-6">
                <h3 class="{{ $sectionTitle }}">Requests</h3>
                <div class="mt-3 sm:max-w-xs">
                    <x-input-label for="ot_claim_window" value="Claims, at most" />
                    <div class="relative">
                        <x-text-input id="ot_claim_window" type="number" min="1" wire:model="claim_window_days" class="pr-20" />
                        <span class="{{ $suffix }}">days back</span>
                    </div>
                    <p class="{{ $hint }}">How far back an employee can claim overtime they already worked. An admin can file further back.</p>
                    <x-input-error :messages="$errors->get('claim_window_days')" class="mt-1" />
                </div>
            </section>

            <section class="px-4 py-5 sm:px-6" @if ($toilLocked) aria-describedby="ot-toil-lock" @endif>
                <h3 class="{{ $sectionTitle }}">Time off in lieu</h3>
                @if ($toilLocked)
                    <div id="ot-toil-lock" class="mt-3 flex items-start gap-2.5 rounded-lg bg-amber-50 p-3 text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30">
                        <x-icon name="lock-closed" class="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p class="text-sm font-semibold">Locked: time off in lieu has been credited</p>
                            <p class="mt-0.5 text-xs leading-[1.125rem]">Time off in lieu is worked out from every overtime hour ever credited, so changing the ratio or block would re-value all of them, and a new leave type would strand what was posted to this one. Changing them is a deliberate data operation, not a settings edit.</p>
                        </div>
                    </div>
                @endif
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <x-input-label for="ot_toil_ratio" value="Ratio" />
                        <div class="relative">
                            <x-text-input id="ot_toil_ratio" type="number" min="1" wire:model="toil_ratio_percent" :disabled="$toilLocked" class="pr-8 {{ $lockedControl }}" />
                            <span class="{{ $suffix }}">%</span>
                        </div>
                        <p class="{{ $hint }}">100% is one hour off per overtime hour, whatever its rate.</p>
                        <x-input-error :messages="$errors->get('toil_ratio_percent')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ot_toil_block" value="Half a day for every" />
                        <div class="relative">
                            <x-text-input id="ot_toil_block" type="number" min="30" step="30" wire:model="toil_block_minutes" :disabled="$toilLocked" class="pr-16 {{ $lockedControl }}" />
                            <span class="{{ $suffix }}">minutes</span>
                        </div>
                        <p class="{{ $hint }}">Whole half hours. Time below a block carries on toward the next.</p>
                        <x-input-error :messages="$errors->get('toil_block_minutes')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ot_toil_type" value="Credited to" />
                        <x-select id="ot_toil_type" wire:model="toil_leave_type_id" :disabled="$toilLocked" class="{{ $lockedControl }}">
                            <option value="">No time off in lieu</option>
                            @foreach ($earnedTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </x-select>
                        <p class="{{ $hint }}">A leave type earned from overtime (Leave types). Without one, overtime can only be paid.</p>
                        <x-input-error :messages="array_merge($errors->get('toil_leave_type_id'), $errors->get('toil'))" class="mt-1" />
                    </div>
                </div>
            </section>
        </form>

        <div class="flex justify-end border-t border-slate-divider px-4 py-4 sm:px-6">
            <x-button type="submit" form="overtime-settings-form" variant="primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving&hellip;</span>
            </x-button>
        </div>
    </x-card>
</div>
