@if ($showBulkModal)
    <x-modal
        name="schedule-bulk-reassign-modal"
        :show="true"
        entangle="showBulkModal"
        surface="solid"
        backdrop="bg-slate-900/50"
        maxWidth="sm"
        panelClass="mt-16"
    >
        <div class="mx-6 border-b border-slate-200/60 py-5 dark:border-slate-800/60">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Bulk Reassign</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Move everyone currently on one schedule to another.</p>
        </div>

        @if ($bulkResult)
            <div class="mx-6 my-4 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700 ring-1 ring-inset ring-primary-200 dark:bg-primary-900/20 dark:text-primary-300 dark:ring-primary-900">
                Reassigned {{ $bulkResult['employees'] }} {{ Str::plural('employee', $bulkResult['employees']) }}, rebuilding {{ $bulkResult['days'] }} {{ Str::plural('day', $bulkResult['days']) }} of attendance.
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

        <form wire:submit="bulkReassign" class="px-6 py-2 space-y-4">
            @error('form')
                <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
                    {{ $message }}
                </div>
            @enderror

            <div>
                <x-input-label for="bulk_from" value="Move everyone currently on" />
                <x-select id="bulk_from" surface="solid" wire:model="bulk_from_id" class="mt-1 block w-full">
                    <option value="">Select a schedule&hellip;</option>
                    @foreach ($allSchedules as $schedule)
                        <option value="{{ $schedule->id }}">{{ $schedule->name }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('bulk_from_id')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="bulk_to" value="Onto" />
                <x-select id="bulk_to" surface="solid" wire:model="bulk_to_id" class="mt-1 block w-full">
                    <option value="">Select a schedule&hellip;</option>
                    @foreach ($allSchedules as $schedule)
                        <option value="{{ $schedule->id }}">{{ $schedule->name }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('bulk_to_id')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="bulk_effective_from" value="Effective from" />
                <x-text-input id="bulk_effective_from" type="date" surface="solid" wire:model="bulk_effective_from" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('bulk_effective_from')" class="mt-1" />
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200/60 pt-4 dark:border-slate-800/60">
                <x-button type="button" variant="secondary" wire:click="$set('showBulkModal', false)">Close</x-button>
                <x-button type="submit" variant="primary">Reassign</x-button>
            </div>
        </form>
    </x-modal>
@endif
