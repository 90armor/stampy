@php
    $dayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-end gap-4">
        <x-button variant="primary" wire:click="create">
            <x-icon name="plus" class="w-4 h-4" />
            New Schedule
        </x-button>
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
                    <x-button variant="primary" wire:click="create">Add Schedule</x-button>
                </x-slot>
            </x-empty-state>
        @else
            <div>
                @foreach ($schedules as $schedule)
                    <div class="group mx-6 flex items-center justify-between gap-4 border-t first:border-t-0 border-slate-200/60 py-4 dark:border-slate-800/60">
                        <div class="flex items-center gap-4 min-w-0">
                            <span class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">
                                <x-icon name="clock" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-semibold text-slate-900 dark:text-slate-100">{{ $schedule->name }}</p>
                                    @if ($schedule->is_default)
                                        <x-badge color="primary">Default</x-badge>
                                    @endif
                                </div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">
                                    <x-time :time="\Carbon\Carbon::parse($schedule->start_time)" />&nbsp;&ndash;&nbsp;<x-time :time="\Carbon\Carbon::parse($schedule->end_time)" />
                                    &middot;
                                    {{ collect($schedule->workdays)->sort()->map(fn ($day) => $dayLabels[$day])->implode(', ') }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    {{ $schedule->grace_minutes }}m grace &middot; {{ $schedule->break_minutes }}m break &middot;
                                    {{ $assignedCounts[$schedule->id] ?? 0 }} {{ Str::plural('employee', $assignedCounts[$schedule->id] ?? 0) }} currently assigned
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-1 shrink-0 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                            @unless ($schedule->is_default)
                                <button
                                    type="button"
                                    wire:click="setDefault({{ $schedule->id }})"
                                    class="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-medium text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                    title="Make default"
                                >
                                    Make default
                                </button>
                            @endunless
                            <button
                                type="button"
                                wire:click="edit({{ $schedule->id }})"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-primary-50 hover:text-primary-600 dark:text-slate-400 dark:hover:bg-primary-900/30 dark:hover:text-primary-400"
                                title="Edit"
                            >
                                <x-icon name="pencil" class="w-4 h-4" />
                            </button>
                            <button
                                type="button"
                                @click="$dispatch('confirm-dialog-schedules', {
                                    title: 'Delete schedule',
                                    message: @js('Delete '.$schedule->name.'? This cannot be undone.'),
                                    confirmText: 'Delete',
                                    method: 'delete',
                                    args: [{{ $schedule->id }}],
                                })"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600 dark:text-slate-400 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                title="Delete"
                            >
                                <x-icon name="trash" class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mx-6 border-t border-slate-200/60 py-4 dark:border-slate-800/60">
                {{ $schedules->links() }}
            </div>
        @endif
    </x-card>

    @include('livewire.schedules.partials.modal')

    <x-confirm-dialog event="confirm-dialog-schedules" />
</div>
