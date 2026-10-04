@if ($showBulkModal)
    <x-modal
        name="schedule-bulk-reassign-modal"
        :show="true"
        entangle="showBulkModal"
        backdrop="bg-slate-900/50"
        maxWidth="sm"
        panelClass="mb-6"
    >
        <div class="mx-6 border-b border-slate-divider py-5">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Bulk Reassign</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Move everyone currently on one schedule to another.</p>
        </div>

        @if ($bulkResult)
            <div class="mx-6 my-4 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-inset ring-primary-200 dark:bg-primary-900/20 dark:text-primary-300 dark:ring-primary-900">
                {{-- $bulkResult['days'] sums each moved employee's own
                     rebuilt range (EmployeeScheduleAssigner::bulkReassign())
                     — an employee-day count, not a count of calendar dates.
                     "rebuilding 34 days of attendance" read as 34 dates;
                     "employee-days" (a standard unit, like "person-days") is
                     what actually disambiguates it. --}}
                Reassigned {{ $bulkResult['employees'] }} {{ Str::plural('employee', $bulkResult['employees']) }} — {{ $bulkResult['days'] }} employee-{{ Str::plural('day', $bulkResult['days']) }} of attendance recalculated.
            </div>

            {{-- The reassignment itself already succeeded whenever this shows
                 — every employee was moved — only the rebuild after it failed
                 partway, so this is a warning (amber), not a failure (red). --}}
            @if ($bulkResult['rebuildError'])
                <div class="mx-6 mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900">
                    {{ $bulkResult['rebuildError'] }}
                </div>
            @endif
        @endif

        <form wire:submit="bulkReassign" class="space-y-5 px-6 py-4">
            @error('form')
                <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
                    {{ $message }}
                </div>
            @enderror

            <div class="space-y-4">
                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">From</p>
                    <x-input-label for="bulk_from" value="Current schedule" class="mt-2" />
                {{-- .live: without it wire:model only syncs on submit
                (Livewire 3's default), so the preview below couldn't react
                to picking a schedule until the admin had already confirmed
                — too late to be a preview. --}}
                    <x-select id="bulk_from" wire:model.live="bulk_from_id" wire:loading.attr="disabled" wire:target="bulkReassign" class="mt-1 block w-full">
                        <option value="">Select a schedule&hellip;</option>
                        @foreach ($allSchedules as $schedule)
                            <option value="{{ $schedule->id }}">{{ $schedule->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('bulk_from_id')" class="mt-1" />

                </div>

                <div class="flex items-center gap-3" aria-hidden="true">
                    <span class="h-px flex-1 bg-slate-divider"></span>
                    <x-icon name="chevron-down" class="h-5 w-5 text-slate-400 dark:text-slate-500" />
                    <span class="h-px flex-1 bg-slate-divider"></span>
                </div>

                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">To</p>
                    <x-input-label for="bulk_to" value="New schedule" class="mt-2" />
                    <x-select id="bulk_to" wire:model="bulk_to_id" wire:loading.attr="disabled" wire:target="bulkReassign" class="mt-1 block w-full">
                        <option value="">Select a schedule&hellip;</option>
                        @foreach ($allSchedules as $schedule)
                            <option value="{{ $schedule->id }}">{{ $schedule->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('bulk_to_id')" class="mt-1" />
                </div>
            </div>

            <div>
                <x-input-label for="bulk_effective_from" value="Effective from" />
                {{-- min mirrors the rule: at most MAX_BULK_LOOKBACK_DAYS back. --}}
                <x-date-picker id="bulk_effective_from" model="bulk_effective_from" label="Effective from" :min="today()->subDays(\App\Services\Attendance\EmployeeScheduleAssigner::MAX_BULK_LOOKBACK_DAYS)->format('Y-m-d')" wire:loading.attr="disabled" wire:target="bulkReassign" />
                <x-input-error :messages="$errors->get('bulk_effective_from')" class="mt-1" />
                <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">A new assignment starts on this date. Attendance from then through today is recalculated; a future date does not change past attendance.</p>
            </div>

            {{-- Who this would actually move, before the admin confirms
            — $bulkFromEmployees is resolved by the exact same query
            bulkReassign() itself uses (EmployeeScheduleAssigner::
            employeesCurrentlyOn()), so this can never show a different
            set of people than the ones who actually get moved. --}}
            @if ($bulkFromEmployees !== null)
                <div class="rounded-lg border border-slate-border px-3 py-2.5 text-xs">
                    @if ($bulkFromEmployees->isEmpty())
                        <p class="text-slate-500 dark:text-slate-400">No active employees are currently on this schedule — nothing to move.</p>
                    @else
                        <p class="font-medium text-slate-700 dark:text-slate-300">
                            {{ $bulkFromEmployees->count() }} {{ Str::plural('employee', $bulkFromEmployees->count()) }} will move
                        </p>
                    {{-- One expression, not an @if tacked onto the end —
                    same stray-space lesson as the "of which" summary:
                    raw HTML between directives collapses to a stray
                    space on render ("...Win , and 27 more"). --}}
                        <p class="mt-1 break-words text-slate-500 dark:text-slate-400">{{ $bulkFromEmployees->count() > 8
                            ? $bulkFromEmployees->take(8)->pluck('full_name')->implode(', ').', and '.($bulkFromEmployees->count() - 8).' more'
                            : $bulkFromEmployees->pluck('full_name')->implode(', ') }}</p>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-3 border-t border-slate-divider pt-4">
                <x-button type="button" variant="secondary" wire:click="$set('showBulkModal', false)" wire:loading.attr="disabled" wire:target="bulkReassign">Close</x-button>
                <x-button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="bulkReassign" :disabled="$bulkFromEmployees?->isEmpty() ?? false">
                    <span wire:loading.remove wire:target="bulkReassign">Reassign</span>
                    <span wire:loading wire:target="bulkReassign">Reassigning&hellip;</span>
                </x-button>
            </div>
        </form>
    </x-modal>
@endif
