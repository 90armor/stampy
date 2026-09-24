<div>
    <div class="flex items-center justify-between gap-4">
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Schedule</p>
        @can('update', $employee)
            <button
                type="button"
                wire:click="create"
                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
            >
                <x-icon name="plus" class="h-4 w-4" />
                Assign schedule
            </button>
        @endcan
    </div>

    {{-- Only for whoever can actually reach it (WorkSchedulePolicy::create,
    the same ability Schedules\Index::openBulkReassign() itself checks) —
    a manager sees the read-only history above with no hint of a page they'd
    403 on, same reasoning as CLAUDE.md's "gate by the ability the
    destination checks" note. --}}
    @can('create', \App\Models\WorkSchedule::class)
        <a
            href="{{ route('organization.index') }}?tab=schedules"
            wire:navigate
            class="mt-1 inline-block text-xs text-slate-400 underline decoration-slate-300 decoration-1 underline-offset-2 hover:text-primary-600 hover:decoration-primary-400 dark:text-slate-500 dark:decoration-slate-700 dark:hover:text-primary-400"
        >
            Moving more than one employee? Bulk reassign on the Schedules tab
        </a>
    @endcan

    @error('delete')
        <div class="mt-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
            {{ $message }}
        </div>
    @enderror

    {{-- The assignment/deletion itself already succeeded whenever this shows
         — only its attendance rebuild failed partway — so it's a warning
         (amber), not a failure (red). --}}
    @error('rebuild')
        <div class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900">
            {{ $message }}
        </div>
    @enderror

    <ul class="mt-3 space-y-2">
        @foreach ($assignments as $assignment)
            <li class="group flex items-center justify-between gap-3 rounded-lg border border-slate-200/60 px-3 py-2 dark:border-slate-800/60 {{ $loop->last ? 'ring-1 ring-inset ring-primary-200/60 dark:ring-primary-800/60' : '' }}">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
                        {{ $assignment->workSchedule->name }}
                        @if ($loop->last)
                            <span class="ml-1 text-xs font-normal text-primary-600 dark:text-primary-400">(current)</span>
                        @endif
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        From {{ $assignment->effective_from->format('M j, Y') }}
                        @unless ($loop->last)
                            &mdash; previously {{ $assignments[$loop->index + 1]->workSchedule->name ?? '' }}
                        @endunless
                    </p>
                </div>

                @can('update', $employee)
                    @if ($assignments->count() > 1)
                        <button
                            type="button"
                            @click="$dispatch('confirm-dialog-schedule-assignments', {
                                title: 'Delete assignment',
                                message: @js('Delete the '.$assignment->workSchedule->name.' assignment effective '.$assignment->effective_from->format('M j, Y').'? This rebuilds this employee\'s attendance from that date.'),
                                confirmText: 'Delete',
                                method: 'deleteAssignment',
                                args: [{{ $assignment->id }}],
                            })"
                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-600 dark:text-slate-500 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                            title="Delete assignment"
                        >
                            <x-icon name="trash" class="h-3.5 w-3.5" />
                        </button>
                    @endif
                @endcan
            </li>
        @endforeach
    </ul>

    @if ($showModal)
        <x-modal
            name="schedule-assignment-modal"
            :show="true"
            entangle="showModal"
            surface="solid"
            backdrop="bg-slate-900/50"
            maxWidth="sm"
            panelClass="mt-16"
        >
            <div class="mx-6 border-b border-slate-200/60 py-5 dark:border-slate-800/60">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Assign Schedule</h3>
            </div>

            <form wire:submit="assign" class="px-6 py-4 space-y-4">
                <div>
                    <x-input-label for="assign_schedule" value="Schedule" />
                    <x-select id="assign_schedule" surface="solid" wire:model="work_schedule_id" class="mt-1 block w-full">
                        <option value="">Select a schedule&hellip;</option>
                        @foreach ($schedules as $schedule)
                            <option value="{{ $schedule->id }}">{{ $schedule->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('work_schedule_id')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="assign_effective_from" value="Effective from" />
                    <x-text-input id="assign_effective_from" type="date" surface="solid" wire:model="effective_from" class="mt-1 block w-full" />
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Past dates correct a wrong assignment; future dates schedule a change ahead. Rebuilds this employee's attendance from this date through today — never a future date.</p>
                    <x-input-error :messages="$errors->get('effective_from')" class="mt-1" />
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-slate-200/60 pt-4 dark:border-slate-800/60">
                    <x-button type="button" variant="secondary" wire:click="$set('showModal', false)">Cancel</x-button>
                    <x-button type="submit" variant="primary">Assign</x-button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-confirm-dialog event="confirm-dialog-schedule-assignments" />
</div>
