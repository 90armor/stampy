<div>
@if ($showModal && $employee)
    {{-- The confirm dialog's look (warning icon, title, message, Cancel and a
    danger action), on <x-modal> so the server can answer before it closes:
    a last-day error or the rebuild warning stays on screen. --}}
    <x-modal
        name="employee-status-modal"
        :show="true"
        entangle="showModal"
        backdrop="bg-slate-900/50"
        maxWidth="sm"
        panelClass="mt-24"
    >
        <form wire:submit="confirm" class="p-6">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                    <x-icon name="exclamation-triangle" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1 pt-1.5">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                        {{ $action === 'deactivate' ? 'Deactivate employee' : 'Reactivate employee' }}
                    </h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        @if ($action === 'deactivate')
                            Deactivate {{ $employee->full_name }}? They will no longer appear in active lists. Attendance history up to their last day is preserved.
                        @else
                            Reactivate {{ $employee->full_name }}? They will appear in active lists again, and the days since {{ \App\Support\DisplayDate::compact($employee->left_on) }} count as workdays again. Leave cancelled or shortened when they were deactivated isn't restored.
                        @endif
                    </p>
                </div>
            </div>

            @if ($action === 'deactivate' && $rebuildError === null)
                <div class="mt-5">
                    <x-input-label for="status_left_on" value="Last day" />
                    <x-date-picker id="status_left_on" model="left_on" label="Last day" :min="$employee->join_date->format('Y-m-d')" :max="today()->format('Y-m-d')" />
                    <x-input-error :messages="$errors->get('left_on')" class="mt-1" />
                </div>

                {{-- The first Deactivate lists what it will do to leave after the last day;
                the second writes it (Employees\StatusModal). --}}
                @if ($affectedLeaves !== [])
                    <div class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30">
                        <p class="font-medium">Leave after their last day will change:</p>
                        <ul class="mt-2 space-y-1">
                            @foreach ($affectedLeaves as $affected)
                                @php
                                    $change = $affected['action'] === 'cancel'
                                        ? 'cancelled'
                                        : 'shortened to end '.\App\Support\DisplayDate::compact(\Carbon\Carbon::parse($affected['end']));
                                @endphp
                                <li>{{ $affected['type'] }} · {{ $affected['dates'] }} ({{ $affected['status'] }}) — {{ $change }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif

            {{-- The status change already happened whenever this shows — only
            the attendance rebuild after it failed partway — so it's a warning
            (amber), not a failure (red). --}}
            @if ($rebuildError)
                <div class="mt-5 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900">
                    {{ $rebuildError }}
                </div>
            @endif

            <div class="mt-5 flex items-center justify-end gap-3">
                @if ($rebuildError)
                    <x-button type="button" variant="secondary" wire:click="close">Close</x-button>
                @else
                    <x-button type="button" variant="secondary" wire:click="close" wire:loading.attr="disabled" wire:target="confirm">Cancel</x-button>
                    <x-button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="confirm">
                        @if ($action === 'reactivate')
                            Reactivate
                        @elseif ($affectedLeaves !== [])
                            Deactivate and adjust {{ count($affectedLeaves) }} {{ Str::plural('leave', count($affectedLeaves)) }}
                        @else
                            Deactivate
                        @endif
                    </x-button>
                @endif
            </div>
        </form>
    </x-modal>
@endif
</div>
