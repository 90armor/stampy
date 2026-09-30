@props([
    'employee',
    'date',
    'punches',
    'addingPunchFor',
    'newPunchDate',
    'newPunchTime',
    'newPunchType',
])

{{-- Shared by the table view's expandable row and the calendar view's day
modal (Attendance\Show) — the raw-punches list plus add/void controls,
including their wire:click/wire:model bindings, which resolve against
whichever Livewire component this ends up rendered inside (a plain Blade
component has no wire root of its own). Extracted specifically so the
void/rebuild logic only exists once: two copies of this would drift, and a
divergence here would be a real correctness bug, not just a style one. --}}
@php $dayKey = $date->format('Y-m-d'); @endphp

<div>
    <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Raw punches</p>

    @if ($punches->isEmpty())
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No punches recorded on this date.</p>
    @else
        <ul class="mt-2 space-y-1.5">
            @foreach ($punches as $punch)
                <li class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span class="flex flex-wrap items-center gap-2 {{ $punch->voided_at ? 'text-slate-400 line-through dark:text-slate-600' : 'text-slate-700 dark:text-slate-300' }}">
                        <x-time :time="$punch->punched_at" />
                        {{-- Both directions neutral: green means Present, and an in-punch is not a status. --}}
                        <x-badge color="slate">{{ $punch->punch_type->label() }}</x-badge>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $punch->source->label() }}</span>
                        @if ($punch->source->value === 'manual' && $punch->createdBy)
                            <span class="text-xs text-slate-400 dark:text-slate-500">by {{ $punch->createdBy->name }}</span>
                        @endif
                        @if ($punch->voided_at)
                            <span class="text-xs text-slate-400 dark:text-slate-500">voided by {{ $punch->voidedBy?->name ?? 'unknown' }}, {{ $punch->voided_at->format('M j') }} {{ \App\Support\AttendanceTime::format($punch->voided_at) }}</span>
                        @endif
                    </span>
                    @can('update', $employee)
                        @unless ($punch->voided_at)
                            <button
                                type="button"
                                @click="$dispatch('confirm-dialog-attendance-show', {
                                    title: 'Void punch',
                                    message: @js('Void the '.$punch->punch_type->label().' punch at '.\App\Support\AttendanceTime::format($punch->punched_at).' on '.\App\Support\DisplayDate::compact($punch->punched_at).'? This cannot be undone.'),
                                    confirmText: 'Void',
                                    method: 'voidPunch',
                                    args: [{{ $punch->id }}],
                                })"
                                class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400"
                            >
                                Void
                            </button>
                        @endunless
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @can('update', $employee)
        @if ($addingPunchFor === $dayKey)
            <form wire:submit="addPunch" class="mt-3 flex flex-wrap items-end gap-3 border-t border-slate-200/60 pt-3 dark:border-slate-800/60">
                <div>
                    <x-input-label for="new_punch_date_{{ $dayKey }}" value="Date" class="!mb-1 !text-xs" />
                    <x-text-input id="new_punch_date_{{ $dayKey }}" type="date" wire:model="newPunchDate" class="!w-auto" />
                </div>
                <div>
                    <x-input-label for="new_punch_time_{{ $dayKey }}" value="Time" class="!mb-1 !text-xs" />
                    <x-text-input id="new_punch_time_{{ $dayKey }}" type="time" wire:model="newPunchTime" class="!w-auto" />
                </div>
                <div>
                    <x-input-label for="new_punch_type_{{ $dayKey }}" value="Type" class="!mb-1 !text-xs" />
                    <x-select id="new_punch_type_{{ $dayKey }}" wire:model="newPunchType" class="!w-auto">
                        <option value="in">In</option>
                        <option value="out">Out</option>
                    </x-select>
                </div>
                <x-button type="submit" variant="primary">Save punch</x-button>
                <x-button type="button" variant="secondary" wire:click="cancelAddingPunch">Cancel</x-button>
            </form>
            <x-input-error :messages="$errors->get('newPunchDate')" class="mt-1" />
            <x-input-error :messages="$errors->get('newPunchTime')" class="mt-1" />
            <x-input-error :messages="$errors->get('newPunchType')" class="mt-1" />
        @else
            <button type="button" wire:click="startAddingPunch('{{ $dayKey }}')" class="mt-3 text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
                + Add punch
            </button>
        @endif
    @endcan
</div>
