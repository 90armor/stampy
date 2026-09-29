@php
    $dayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Schedules</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Define the working hours and workdays used for employee attendance.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-button
                type="button"
                variant="secondary"
                wire:click="openBulkReassign"
                wire:loading.attr="disabled"
                wire:target="openBulkReassign"
                :disabled="$allSchedules->count() < 2"
            >
                Bulk Reassign
            </x-button>
            <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                <x-icon name="plus" class="w-4 h-4" />
                New Schedule
            </x-button>
        </div>
    </div>

    @error('delete')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900">
            {{ $message }}
        </div>
    @enderror

    <x-card :padding="false">
        @if ($schedules->isEmpty())
            <x-empty-state
                icon="clock"
                title="No work schedules yet"
                description="Add a schedule to define the hours new employees are assigned by default."
            >
                <x-slot name="action">
                    <x-button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">New Schedule</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div role="list" aria-label="Work schedules">
                @foreach ($schedules as $schedule)
                    <div wire:key="schedule-{{ $schedule->id }}" role="listitem" class="group relative grid grid-cols-1 gap-x-4 px-5 py-4 transition hover:bg-slate-50 dark:hover:bg-slate-800/60 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-6">
                        @unless ($loop->last)
                            <span class="pointer-events-none absolute inset-x-5 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60 sm:inset-x-6"></span>
                        @endunless

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <p class="break-words font-semibold text-slate-900 dark:text-slate-100">{{ $schedule->name }}</p>
                                @if ($schedule->is_default)
                                    <x-badge color="primary">Default</x-badge>
                                @endif
                            </div>

                            <div class="mt-1.5 flex flex-col gap-0.5 text-sm text-slate-700 dark:text-slate-300 sm:flex-row sm:flex-wrap sm:gap-x-4">
                                <p class="font-medium tabular-nums">
                                    <x-time :time="\Carbon\Carbon::parse($schedule->start_time)" />&nbsp;&ndash;&nbsp;<x-time :time="\Carbon\Carbon::parse($schedule->end_time)" />
                                </p>
                                <p>{{ collect($schedule->workdays)->sort()->map(fn ($day) => $dayLabels[$day])->implode(', ') }}</p>
                            </div>

                            <p class="mt-1.5 text-xs leading-5 text-slate-500 dark:text-slate-400">
                                Grace {{ $schedule->grace_minutes }} min
                                <span aria-hidden="true">&middot;</span>
                                Break {{ $schedule->break_minutes }} min
                                <span aria-hidden="true">&middot;</span>
                                {{ $assignedCounts[$schedule->id] ?? 0 }} {{ Str::plural('employee', $assignedCounts[$schedule->id] ?? 0) }} currently assigned
                            </p>
                        </div>

                        <div class="mt-3 flex shrink-0 flex-wrap items-center justify-between gap-3 self-start sm:col-start-2 sm:row-start-1 sm:mt-0 sm:justify-end">
                            @unless ($schedule->is_default)
                                <button
                                    type="button"
                                    wire:click="setDefault({{ $schedule->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="setDefault({{ $schedule->id }})"
                                    class="inline-flex h-9 items-center rounded-lg px-2.5 text-xs font-medium text-slate-600 transition hover:bg-primary-50 hover:text-primary-700 active:bg-primary-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50 dark:text-slate-300 dark:hover:bg-primary-900/30 dark:hover:text-primary-300 dark:active:bg-primary-900/50"
                                    aria-label="Make {{ $schedule->name }} the default schedule"
                                >
                                    Make default
                                </button>
                            @endunless
                            <div class="flex items-center gap-1" aria-label="Schedule actions">
                                <span class="group/action relative inline-flex">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $schedule->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="edit({{ $schedule->id }})"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-600 active:bg-primary-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-50 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400 dark:active:bg-primary-900/50"
                                        aria-label="Edit {{ $schedule->name }} schedule"
                                    >
                                        <x-icon name="pencil" class="w-4 h-4" />
                                    </button>
                                    <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Edit schedule</span>
                                </span>
                                <span class="group/action relative inline-flex">
                                    <button
                                        type="button"
                                        @click="$dispatch('confirm-dialog-schedules', {
                                            title: 'Delete schedule',
                                            message: @js('Delete '.$schedule->name.'? This cannot be undone.'),
                                            confirmText: 'Delete',
                                            method: 'delete',
                                            args: [{{ $schedule->id }}],
                                        })"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-red-50 hover:text-red-600 active:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400 dark:active:bg-red-900/50"
                                        aria-label="Delete {{ $schedule->name }} schedule"
                                    >
                                        <x-icon name="trash" class="w-4 h-4" />
                                    </button>
                                    <span role="tooltip" class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-sm transition-opacity group-hover/action:opacity-100 group-focus-within/action:opacity-100 dark:bg-slate-100 dark:text-slate-900">Delete schedule</span>
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="border-t border-slate-200/60 px-5 py-4 dark:border-slate-800/60 sm:px-6">
                {{ $schedules->links() }}
            </div>
        @endif
    </x-card>

    @include('livewire.schedules.partials.modal')
    @include('livewire.schedules.partials.bulk-reassign-modal')

    <x-confirm-dialog event="confirm-dialog-schedules" />
</div>
