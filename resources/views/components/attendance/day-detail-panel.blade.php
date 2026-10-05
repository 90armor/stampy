@props([
    'employee',
    'date',
    'punches',
    'addingPunchFor',
    'newPunchDate',
    'newPunchTime',
    'newPunchType',
    // Overnight shifts (Attendance\Show::overnightPunches()): the (+1)
    // out-punch that closed this day's shift, and which shift each punch
    // recorded on this day belongs to, when it isn't this day's.
    'overnightOut' => null,
    'overnightShifts' => null,
])

{{-- Shared by the table view's expandable row and the calendar view's day
modal (Attendance\Show) — the raw-punches list plus add/void controls,
including their wire:click/wire:model bindings, which resolve against
whichever Livewire component this ends up rendered inside (a plain Blade
component has no wire root of its own). Extracted specifically so the
void/rebuild logic only exists once: two copies of this would drift, and a
divergence here would be a real correctness bug, not just a style one. --}}
@php
    $dayKey = $date->format('Y-m-d');
    $overnightShifts ??= collect();
    // This day's punches, then the next-day out that closed its shift.
    $rows = $punches->values()->when($overnightOut, fn ($rows) => $rows->push($overnightOut));
    $canEdit = auth()->user()?->can('update', $employee);
    $isAdding = $addingPunchFor === $dayKey;
@endphp

<div>
    {{-- Title left, action right (the card-header pattern). --}}
    <div class="flex min-h-[2.375rem] items-center justify-between gap-3">
        <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
            Raw punches
            @if ($rows->isNotEmpty())
                <span class="ml-1 font-normal text-slate-500 dark:text-slate-400">{{ $rows->count() }}</span>
            @endif
        </h4>
        @if ($canEdit && ! $isAdding)
            <x-button type="button" variant="secondary" wire:click="startAddingPunch('{{ $dayKey }}')">
                <x-icon name="plus" class="h-5 w-5" />
                Add punch
            </x-button>
        @endif
    </div>

    @if ($rows->isEmpty())
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No punches recorded on this date.</p>
    @else
        <ul class="mt-2">
            @foreach ($rows as $punch)
                @php
                    $isNextDay = $punch->punched_at->format('Y-m-d') !== $dayKey;
                    $shiftDate = $overnightShifts->get($punch->id);
                    $belongsElsewhere = ! $isNextDay && $shiftDate !== null && $shiftDate->format('Y-m-d') !== $dayKey;
                    $voided = $punch->voided_at !== null;
                @endphp
                <li class="flex min-h-12 items-center gap-3 border-t border-slate-divider py-2 first:border-t-0">
                    <div @class(['flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-0.5 text-sm', 'text-slate-500 dark:text-slate-400' => $voided, 'text-slate-900 dark:text-slate-100' => ! $voided])>
                        <span @class(['w-24 shrink-0 font-medium tabular-nums', 'line-through' => $voided])>
                            <x-time :time="$punch->punched_at" />
                            @if ($isNextDay)
                                <span class="font-normal text-slate-500 dark:text-slate-400">(+1)</span>
                            @endif
                        </span>
                        {{-- Both directions neutral: green means Present, and an in-punch is not a status. --}}
                        <x-badge color="slate">{{ $punch->punch_type->label() }}</x-badge>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $punch->source->label() }}
                            @if ($punch->source->value === 'manual' && $punch->createdBy)
                                · by {{ $punch->createdBy->name }}
                            @endif
                            @if ($isNextDay)
                                · recorded {{ \App\Support\DisplayDate::compact($punch->punched_at) }}
                            @elseif ($belongsElsewhere)
                                · ends {{ \App\Support\DisplayDate::compact($shiftDate) }}'s shift
                            @endif
                            @if ($voided)
                                · voided by {{ $punch->voidedBy?->name ?? 'unknown' }}, {{ \App\Support\DisplayDate::compact($punch->voided_at) }} {{ \App\Support\AttendanceTime::format($punch->voided_at) }}
                            @endif
                        </span>
                    </div>
                    @if ($canEdit && ! $voided)
                        <button
                            type="button"
                            @click="$dispatch('confirm-dialog-attendance-show', {
                                title: 'Void punch',
                                message: @js('Void the '.$punch->punch_type->label().' punch at '.\App\Support\AttendanceTime::format($punch->punched_at).' on '.\App\Support\DisplayDate::compact($punch->punched_at).'? This cannot be undone.'),
                                confirmText: 'Void',
                                method: 'voidPunch',
                                args: [{{ $punch->id }}],
                            })"
                            aria-label="Void the {{ $punch->punch_type->label() }} punch at {{ \App\Support\AttendanceTime::format($punch->punched_at) }}"
                            class="inline-flex h-8 shrink-0 items-center rounded-lg px-2.5 text-xs font-medium text-red-600 transition hover:bg-red-50 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-red-400 dark:hover:bg-red-950/40 dark:hover:text-red-300"
                        >
                            Void
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit && $isAdding)
            {{-- A well, so the form reads as one new punch, not another row. --}}
            {{-- Date, time and type on one row; the actions under them, right-aligned. --}}
            <form wire:submit="addPunch" class="mt-3 rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-divider dark:bg-slate-800">
                <div class="grid grid-cols-1 gap-3 sm:max-w-xl sm:grid-cols-[minmax(10.5rem,1fr)_minmax(10rem,1fr)_5rem]">
                <div>
                    <x-input-label for="new_punch_date_{{ $dayKey }}" value="Date" class="!mb-1 !text-xs" />
                    {{-- min/max mirror addPunch()'s rule: from the join date to today. --}}
                    <x-date-picker id="new_punch_date_{{ $dayKey }}" model="newPunchDate" label="Date" :min="$employee->join_date->format('Y-m-d')" :max="($employee->left_on !== null && $employee->left_on->lt(today()) ? $employee->left_on : today())->format('Y-m-d')" />
                </div>
                <div>
                    <x-input-label id="new_punch_time_{{ $dayKey }}-label" for="new_punch_time_{{ $dayKey }}" value="Time" class="!mb-1 !text-xs" />
                    {{-- No later than now while the punch date is today, as addPunch() rules. --}}
                    <x-time-input id="new_punch_time_{{ $dayKey }}" model="newPunchTime" label="Time" cap-at-now-when="newPunchDate" />
                </div>
                <div>
                    <x-input-label for="new_punch_type_{{ $dayKey }}" value="Type" class="!mb-1 !text-xs" />
                    <x-select id="new_punch_type_{{ $dayKey }}" wire:model="newPunchType" >
                        <option value="in">In</option>
                        <option value="out">Out</option>
                    </x-select>
                </div>
                </div>
                <div class="mt-4 flex justify-end gap-3 sm:max-w-xl">
                    <x-button type="button" variant="secondary" wire:click="cancelAddingPunch">Cancel</x-button>
                    <x-button type="submit" variant="primary">Save punch</x-button>
                </div>
            </form>
            <x-input-error :messages="$errors->get('newPunchDate')" class="mt-1" />
            <x-input-error :messages="$errors->get('newPunchTime')" class="mt-1" />
            <x-input-error :messages="$errors->get('newPunchType')" class="mt-1" />
    @endif
</div>
