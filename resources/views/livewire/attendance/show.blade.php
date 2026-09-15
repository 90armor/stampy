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
                    <tbody>
                        @foreach ($days as $day)
                            @php $record = $day['record']; @endphp
                            <tr wire:key="attendance-day-{{ $day['date']->format('Y-m-d') }}" class="relative {{ $day['date']->isToday() ? 'bg-primary-50/40 dark:bg-primary-900/10' : '' }}">
                                <td class="px-6 py-4 text-sm text-slate-700 dark:text-slate-300">
                                    {{ $day['date']->format('D j M') }}
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
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</div>
