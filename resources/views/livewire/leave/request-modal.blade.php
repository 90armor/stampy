<div>
@if ($showModal)
    @php
        // Types that allow a half day: the Morning/Afternoon choice only shows for
        // one of these, and only for a single date (Alpine reads the deferred values).
        $halfTypes = $types->where('allows_half_day', true)->pluck('id')->values();
        $fieldError = 'mt-1';
    @endphp
    <x-modal name="leave-request-modal" :show="true" entangle="showModal" backdrop="bg-slate-900/50" maxWidth="md" panelClass="mt-6 sm:mt-16">
        <div class="mx-4 border-b border-slate-divider py-4 sm:mx-6 sm:py-5">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                @if ($step === 'review')
                    Review {{ $forOthers ? 'leave' : 'your request' }}
                @else
                    {{ $forOthers ? 'File leave for an employee' : 'Request leave' }}
                @endif
            </h3>
        </div>

        @if ($step === 'form')
            <form id="leave-request-form" wire:submit="review" class="space-y-4 px-4 py-4 sm:px-6">
                @if ($forOthers)
                    <div>
                        <x-input-label for="leave_employee_search" value="Employee" />
                        @if ($employee)
                            <div class="flex h-control items-center justify-between gap-3 rounded-lg border border-slate-border bg-slate-50 px-3 text-sm dark:bg-slate-800">
                                <span class="truncate text-slate-900 dark:text-slate-100">{{ $employee->full_name }} <span class="text-slate-500 dark:text-slate-400">· {{ $employee->employee_code }}</span></span>
                                @unless ($employeeLocked)
                                    <button type="button" wire:click="clearEmployee" class="shrink-0 rounded text-sm font-medium text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400">Change</button>
                                @endunless
                            </div>
                        @else
                            <x-text-input id="leave_employee_search" type="search" wire:model.live.debounce.300ms="employeeSearch" placeholder="Search by name or employee code" autocomplete="off" autofocus />
                            @if ($matches->isNotEmpty())
                                <ul class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-border bg-white dark:bg-slate-750" role="listbox" aria-label="Matching employees">
                                    @foreach ($matches as $match)
                                        <li>
                                            <button type="button" wire:click="selectEmployee({{ $match->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-slate-900 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none dark:text-slate-100 dark:hover:bg-slate-600/30 dark:focus:bg-slate-600/30">
                                                <span class="truncate">{{ $match->full_name }}</span>
                                                <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ $match->employee_code }}{{ $match->status === 'inactive' ? ' · inactive' : '' }}{{ $match->user_id === null ? ' · no login' : '' }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (trim($employeeSearch) !== '')
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No employee matches “{{ $employeeSearch }}”.</p>
                            @endif
                        @endif
                        <x-input-error :messages="$errors->get('employee')" class="{{ $fieldError }}" />
                    </div>
                @endif

                <div>
                    <x-input-label for="leave_type" value="Type of leave" />
                    <x-select id="leave_type" wire:model="leave_type_id">
                        <option value="">Choose a type</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('leave_type_id')" class="{{ $fieldError }}" />
                </div>

                <div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="leave_start" value="From" />
                            <x-date-picker id="leave_start" model="start_date" label="First day" :min="$minDate" :max="$maxDate" />
                        </div>
                        <div>
                            <x-input-label for="leave_end" value="To" />
                            <x-date-picker id="leave_end" model="end_date" label="Last day" :min="$minDate" :max="$maxDate" placeholder="Same day" clearable />
                        </div>
                    </div>
                    {{-- The dates' errors, and the request-as-a-whole ones (balance), full
                    width under the row: an overlap or a shortfall is about the dates
                    together, and a long message wrapped badly inside one column. --}}
                    <x-input-error :messages="array_merge($errors->get('start_date'), $errors->get('end_date'), $errors->get('leave'))" class="{{ $fieldError }}" />
                </div>

                <div
                    x-data="{ halfTypes: @js($halfTypes) }"
                    x-show="halfTypes.includes(Number($wire.leave_type_id)) && $wire.start_date && (! $wire.end_date || $wire.end_date === $wire.start_date)"
                    x-effect="if (! (halfTypes.includes(Number($wire.leave_type_id)) && $wire.start_date && (! $wire.end_date || $wire.end_date === $wire.start_date))) { $wire.half = '' }"
                    x-cloak
                >
                    <x-input-label for="leave_half" value="How much of the day" />
                    <x-select id="leave_half" wire:model="half">
                        <option value="">Full day</option>
                        <option value="am">Morning (AM)</option>
                        <option value="pm">Afternoon (PM)</option>
                    </x-select>
                    <x-input-error :messages="$errors->get('half')" class="{{ $fieldError }}" />
                </div>

                <div>
                    <x-input-label for="leave_reason" value="Reason (optional)" />
                    <x-textarea id="leave_reason" rows="2" maxlength="1000" wire:model="reason">{{ $reason }}</x-textarea>
                    <x-input-error :messages="$errors->get('reason')" class="{{ $fieldError }}" />
                </div>
            </form>

            <div class="mx-4 flex items-center justify-end gap-3 border-t border-slate-divider py-4 sm:mx-6">
                {{-- "Close", not "Cancel": on Time off, cancel means cancelling leave. --}}
                <x-button type="button" variant="secondary" wire:click="close">Close</x-button>
                <x-button type="submit" form="leave-request-form" variant="primary" wire:loading.attr="disabled" wire:target="review">
                    <span wire:loading.remove wire:target="review">Review</span>
                    <span wire:loading wire:target="review">Checking&hellip;</span>
                </x-button>
            </div>
        @else
            <div class="space-y-4 px-4 py-4 sm:px-6">
                @if ($changed)
                    <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900" role="status">{{ $changed }}</div>
                @endif

                <div>
                    @if ($forOthers)
                        <p class="text-sm text-slate-500 dark:text-slate-400">For {{ $summary['employee'] }}</p>
                    @endif
                    <p class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $summary['type'] }} · {{ $summary['total'] }}</p>
                    <p class="mt-0.5 text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $summary['dates'] }}</p>
                </div>

                <div class="rounded-lg bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800">
                    @if (! $summary['hasBalance'])
                        <p class="text-slate-700 dark:text-slate-200">{{ $summary['type'] }} leave has no balance to draw from.</p>
                    @elseif (count($summary['years']) === 1)
                        @php $line = $summary['years'][0]; @endphp
                        <p class="text-slate-700 dark:text-slate-200">Uses <span class="font-semibold">{{ $line['cost'] }}</span> of {{ $summary['charges'] }}</p>
                        <p class="mt-1 tabular-nums text-slate-600 dark:text-slate-300">Available {{ $line['before'] }} → <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $line['after'] }}</span></p>
                    @else
                        <p class="text-slate-700 dark:text-slate-200">Crosses New Year — each year's {{ $summary['charges'] }} balance pays for its own days:</p>
                        <ul class="mt-2 space-y-1 tabular-nums">
                            @foreach ($summary['years'] as $line)
                                <li class="text-slate-600 dark:text-slate-300">{{ $line['year'] }}: uses <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $line['cost'] }}</span> · available {{ $line['before'] }} → <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $line['after'] }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($summary['notCharged'] !== [])
                    <p class="text-sm text-slate-500 dark:text-slate-400">Not charged: {{ implode(', ', $summary['notCharged']) }}.</p>
                @endif

                <p class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                    @if ($summary['approvedOnSubmit'])
                        <span>Filed by you as an admin, this leave is approved immediately — there are no approval steps.</span>
                    @else
                        <span>Your manager reviews it first, then an admin. You can cancel it until it starts.</span>
                    @endif
                </p>
            </div>

            <div class="mx-4 flex items-center justify-end gap-3 border-t border-slate-divider py-4 sm:mx-6">
                <x-button type="button" variant="secondary" wire:click="back">Back</x-button>
                <x-button type="button" variant="primary" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">{{ $summary['approvedOnSubmit'] ? 'File and approve' : 'Submit request' }}</span>
                    <span wire:loading wire:target="submit">Submitting&hellip;</span>
                </x-button>
            </div>
        @endif
    </x-modal>
@endif
</div>
