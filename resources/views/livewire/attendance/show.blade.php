@php
    $statusStyles = [
        'present' => ['badge' => 'green'],
        'late' => ['badge' => 'amber'],
        'incomplete' => ['badge' => 'amber'],
        'absent' => ['badge' => 'red'],
        'off' => ['badge' => 'slate'],
        'holiday' => ['badge' => 'slate'],
        'leave' => ['badge' => 'slate'],
    ];
@endphp

<div class="space-y-6">
    @if ($noEmployeeRecord)
        <x-card>
            <x-empty-state
                icon="user-x"
                title="Your account isn't linked to an employee record"
                description="There's nothing to show here yet — contact an admin to get this login linked to an employee profile."
            />
        </x-card>
    @else
        <div>
            <a href="{{ route('attendance.index') }}" wire:navigate class="inline-flex items-center gap-x-1 text-sm font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">
                <x-icon name="chevron-left" class="h-4 w-4" />
                Back to attendance
            </a>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-x-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-100 text-lg font-semibold text-primary-700 dark:bg-primary-800 dark:text-primary-100">
                    {{ strtoupper(substr($employee->full_name, 0, 1)) }}
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $employee->employee_code }} &middot; {{ $employee->department->name }} &middot; {{ $employee->position->name }}</p>
                </div>
            </div>
        </div>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="previousMonth"
                        class="rounded-lg border border-slate-300 bg-white/80 p-1.5 text-slate-500 shadow-sm hover:bg-white hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:bg-slate-800"
                        aria-label="Previous month"
                    >
                        <x-icon name="chevron-left" class="h-4 w-4" />
                    </button>
                    <span class="min-w-[9rem] text-center text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $monthLabel }}</span>
                    <button
                        type="button"
                        wire:click="nextMonth"
                        class="rounded-lg border border-slate-300 bg-white/80 p-1.5 text-slate-500 shadow-sm hover:bg-white hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-400 dark:hover:bg-slate-800"
                        aria-label="Next month"
                    >
                        <x-icon name="chevron-right" class="h-4 w-4" />
                    </button>
                    @unless ($isCurrentMonth)
                        <button type="button" wire:click="$set('month', '{{ today()->format('Y-m') }}')" class="ml-1 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
                            Jump to this month
                        </button>
                    @endunless
                </div>

                {{-- Counts only — no derived/payroll-adjacent figures. Total
                worked is a plain sum of worked_minutes, not anything
                interpreted (e.g. no "deductible days" or hours owed). --}}
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
                    <span class="text-slate-500 dark:text-slate-400">Workdays <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['workdays'] }}</strong></span>
                    <span class="text-slate-500 dark:text-slate-400">Present <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['present'] }}</strong></span>
                    <span class="text-slate-500 dark:text-slate-400">Late <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['late'] }}</strong></span>
                    <span class="text-slate-500 dark:text-slate-400">Early leave <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['early_leave_days'] }}</strong></span>
                    <span class="text-slate-500 dark:text-slate-400">Absent <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['absent'] }}</strong></span>
                    <span class="text-slate-500 dark:text-slate-400">Incomplete <strong class="font-semibold text-slate-900 dark:text-slate-100">{{ $summary['incomplete'] }}</strong></span>
                </div>
            </div>

            @if ($summary['total_worked_minutes'] > 0)
                <p class="mt-3 border-t border-slate-200/60 pt-3 text-xs text-slate-400 dark:border-slate-800/60 dark:text-slate-500">
                    Total worked this month: {{ sprintf('%dh %02dm', intdiv($summary['total_worked_minutes'], 60), $summary['total_worked_minutes'] % 60) }}
                </p>
            @endif
        </x-card>

        <x-card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3">In</th>
                            <th class="px-6 py-3">Out</th>
                            <th class="px-6 py-3 text-right">Worked</th>
                            <th class="px-6 py-3 text-right">Late</th>
                            <th class="px-6 py-3 text-right">Early leave</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">
                                Note
                                <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                            </th>
                        </tr>
                    </thead>
                        @foreach ($days as $day)
                            @php $record = $day['record']; $dayKey = $day['date']->format('Y-m-d'); @endphp
                            {{-- A per-day <tbody> (valid HTML — a <table> may contain several),
                            not a single <tbody> for the month: the expand toggle and its detail
                            row below need to share one Alpine x-data scope, and sibling <tr>s
                            don't share scope unless a common ancestor carries it. --}}
                            <tbody wire:key="attendance-day-tbody-{{ $dayKey }}" x-data="{ open: false }">
                            <tr
                                class="relative {{ $day['date']->isToday() ? 'bg-primary-50/40 dark:bg-primary-900/10' : '' }}"
                            >
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    <div class="flex items-center gap-2">
                                        @can('update', $employee)
                                            <button
                                                type="button"
                                                @click="open = !open"
                                                :aria-expanded="open.toString()"
                                                aria-label="Show raw punches"
                                                class="shrink-0 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
                                            >
                                                <x-icon name="chevron-right" class="h-3.5 w-3.5 transition" x-bind:class="open ? 'rotate-90' : ''" />
                                            </button>
                                        @endcan
                                        <span>{{ $day['date']->format('D j M') }}</span>
                                    </div>
                                </td>
                                @if ($record)
                                    <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                        {{ $record->first_in?->format('H:i') ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                        @if ($record->last_out)
                                            {{ $record->last_out->format('H:i') }}
                                            @if ($record->isOvernightOut())
                                                <span class="text-slate-400 dark:text-slate-500">(+1)</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $record->formattedWorkedMinutes() ?? '—' }}</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $record->late_minutes > 0 ? $record->late_minutes.'m' : '—' }}</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-700 dark:text-slate-300">{{ $record->early_leave_minutes > 0 ? $record->early_leave_minutes.'m' : '—' }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="$statusStyles[$record->status->value]['badge'] ?? 'slate'">{{ $record->status->label() }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-500 dark:text-slate-400">
                                        {{ $record->note ?? '—' }}
                                        @unless ($loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                        @endunless
                                    </td>
                                @else
                                    {{-- No row at all — the builder hasn't reached this date yet.
                                    Deliberately distinct from "absent": absent means the builder
                                    ran and found no punches on a scheduled workday; this means
                                    it hasn't run at all, so nothing here should read as a
                                    judgement about attendance. --}}
                                    <td class="px-6 py-4 text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4 text-right text-sm text-slate-400 dark:text-slate-600">—</td>
                                    <td class="px-6 py-4">
                                        <x-badge color="slate">Not calculated</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-slate-400 dark:text-slate-600">
                                        —
                                        @unless ($loop->last)
                                            <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60"></span>
                                        @endunless
                                    </td>
                                @endif
                            </tr>
                            @can('update', $employee)
                                <tr x-show="open" x-cloak>
                                    <td colspan="8" class="bg-slate-50/60 px-6 py-4 dark:bg-slate-800/30">
                                        @php $punches = $punchesByDate->get($dayKey, collect()); @endphp

                                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Raw punches</p>

                                        @if ($punches->isEmpty())
                                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No punches recorded on this date.</p>
                                        @else
                                            <ul class="mt-2 space-y-1.5">
                                                @foreach ($punches as $punch)
                                                    <li class="flex flex-wrap items-center justify-between gap-3 text-sm">
                                                        <span class="flex flex-wrap items-center gap-2 {{ $punch->voided_at ? 'text-slate-400 line-through dark:text-slate-600' : 'text-slate-700 dark:text-slate-300' }}">
                                                            {{ $punch->punched_at->format('H:i:s') }}
                                                            <x-badge :color="$punch->punch_type->value === 'in' ? 'green' : 'slate'">{{ $punch->punch_type->label() }}</x-badge>
                                                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $punch->source->label() }}</span>
                                                            @if ($punch->source->value === 'manual' && $punch->createdBy)
                                                                <span class="text-xs text-slate-400 dark:text-slate-500">by {{ $punch->createdBy->name }}</span>
                                                            @endif
                                                            @if ($punch->voided_at)
                                                                <span class="text-xs text-slate-400 dark:text-slate-500">voided by {{ $punch->voidedBy?->name ?? 'unknown' }}, {{ $punch->voided_at->format('M j, H:i') }}</span>
                                                            @endif
                                                        </span>
                                                        @unless ($punch->voided_at)
                                                            <button
                                                                type="button"
                                                                @click="$dispatch('confirm-dialog-attendance-show', {
                                                                    title: 'Void punch',
                                                                    message: @js('Void the '.$punch->punch_type->label().' punch at '.$punch->punched_at->format('H:i:s').'? This cannot be undone.'),
                                                                    confirmText: 'Void',
                                                                    method: 'voidPunch',
                                                                    args: [{{ $punch->id }}],
                                                                })"
                                                                class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400"
                                                            >
                                                                Void
                                                            </button>
                                                        @endunless
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

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
                                    </td>
                                </tr>
                            @endcan
                            </tbody>
                        @endforeach
                </table>
            </div>
        </x-card>

        <x-confirm-dialog event="confirm-dialog-attendance-show" />
    @endif
</div>
