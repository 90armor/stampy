<div>
@if ($showModal)
    <x-modal name="overtime-request-modal" :show="true" entangle="showModal" backdrop="bg-slate-900/50" maxWidth="md" panelClass="mt-6 sm:mt-16">
        <div class="mx-4 border-b border-slate-divider py-4 sm:mx-6 sm:py-5">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                @if ($step === 'review')
                    Review {{ $forOthers ? 'overtime' : 'your request' }}
                @else
                    {{ $forOthers ? 'File overtime for an employee' : 'Request overtime' }}
                @endif
            </h3>
        </div>

        @if ($step === 'form')
            <form id="overtime-request-form" wire:submit="review" class="space-y-4 px-4 py-4 sm:px-6">
                @if ($forOthers)
                    @include('livewire.partials.employee-picker', ['id' => 'overtime_employee_search'])
                @endif

                <div>
                    <x-input-label for="overtime_date" value="Date" />
                    <x-date-picker id="overtime_date" model="date" label="Date" :min="$minDate" :max="$maxDate" />
                    <x-input-error :messages="$errors->get('date')" class="mt-1" />
                </div>

                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label id="overtime_start-label" for="overtime_start" value="From" />
                            <x-time-input id="overtime_start" model="start_time" label="From" />
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <x-input-label id="overtime_end-label" for="overtime_end" value="To" />
                                {{-- An end earlier than the start is on the next day. --}}
                                <span x-data x-show="$wire.start_time && $wire.end_time && $wire.end_time <= $wire.start_time" x-cloak class="mb-1 text-xs text-slate-500 dark:text-slate-400">(+1) next day</span>
                            </div>
                            <x-time-input id="overtime_end" model="end_time" label="To" />
                        </div>
                    </div>
                    {{-- The window's errors, and the day's limits, full width under the row. --}}
                    <x-input-error :messages="array_merge($errors->get('start_time'), $errors->get('end_time'), $errors->get('starts_at'), $errors->get('ends_at'), $errors->get('overtime'))" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="overtime_compensation" value="Compensation" />
                    @if ($timeOffOffered)
                        <x-select id="overtime_compensation" wire:model="compensation">
                            <option value="pay">Pay</option>
                            <option value="time_off">Time off in lieu</option>
                        </x-select>
                    @else
                        {{-- No TOIL type set (Policies → Overtime): pay is the only choice. --}}
                        <x-select id="overtime_compensation" wire:model="compensation">
                            <option value="pay">Pay</option>
                        </x-select>
                    @endif
                    <x-input-error :messages="$errors->get('compensation')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="overtime_reason" value="Reason (optional)" />
                    <x-textarea id="overtime_reason" rows="2" maxlength="1000" wire:model="reason">{{ $reason }}</x-textarea>
                    <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                </div>
            </form>

            <div class="mx-4 flex items-center justify-end gap-3 border-t border-slate-divider py-4 sm:mx-6">
                {{-- "Close", not "Cancel": on the Overtime page, cancel means cancelling a request. --}}
                <x-button type="button" variant="secondary" wire:click="close">Close</x-button>
                <x-button type="submit" form="overtime-request-form" variant="primary" wire:loading.attr="disabled" wire:target="review">
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
                    <p class="text-base font-semibold text-slate-900 dark:text-slate-100">Overtime · {{ $summary['total'] }} · {{ $summary['compensation'] }}</p>
                    <p class="mt-0.5 text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $summary['when'] }}</p>
                </div>

                <div class="space-y-1.5 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <p>{{ $summary['kind'] }}</p>
                    @if ($summary['counts'])<p>{{ $summary['counts'] }}</p>@endif
                    <p class="tabular-nums">{{ $summary['split'] }}</p>
                    @if ($summary['toil'])<p>{{ $summary['toil'] }}</p>@endif
                    @if ($summary['punches'])<p>{{ $summary['punches'] }}</p>@endif
                </div>

                @if ($summary['limitProblems'] !== [])
                    {{-- An admin over a daily limit: the problem, and the reason that lets it through. --}}
                    <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30">
                        <p class="font-medium">Over the daily limit</p>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($summary['limitProblems'] as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <x-input-label for="overtime_override" value="Reason to go over the limit" />
                        <x-textarea id="overtime_override" rows="2" maxlength="1000" wire:model="override_reason">{{ $override_reason }}</x-textarea>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Required — it's kept on the request.</p>
                        <x-input-error :messages="$errors->get('limit_override_reason')" class="mt-1" />
                    </div>
                @endif
                <x-input-error :messages="$errors->get('overtime')" class="mt-1" />

                <p class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                    @if ($summary['approvedOnSubmit'])
                        <span>Filed by you as an admin, this overtime is approved immediately — there are no approval steps.</span>
                    @else
                        <span>{{ $summary['reviewers'] }}</span>
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
