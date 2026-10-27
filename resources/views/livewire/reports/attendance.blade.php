@php
    use App\Support\AttendanceReport;
    use App\Support\DisplayDate;
    use App\Support\Duration;
    use App\Support\LeaveDays;

    // Narrow cell padding and short headers (the full name in an <abbr>, as
    // the attendance table's Early) so the whole table fits from 1366; a long
    // name or department wraps inside the fixed Employee column.
    $th = 'px-1.5 py-3 text-right font-medium';
    $td = 'px-1.5 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300';
    $total = 'px-1.5 py-4 text-right tabular-nums text-slate-900 dark:text-slate-100';
    $abbr = fn (string $short, string $full) => '<abbr title="'.e($full).'" class="no-underline">'.e($short).'</abbr>';
    $divider = '<span class="pointer-events-none absolute inset-x-4 bottom-0 z-[2] h-px bg-slate-divider sm:inset-x-6"></span>';
@endphp

<div class="space-y-6">
    {{-- Three controls: beside the title only from xl, where they fit on one line. --}}
    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Attendance report</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Each employee's month in counts, from the calculated attendance.</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-input-label for="attendance-report-month" value="Month" />
                <x-select id="attendance-report-month" wire:model.live="month">
                    @foreach ($months as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-52">
                <x-input-label for="attendance-report-department" value="Department" />
                <x-select id="attendance-report-department" wire:model.live="department">
                    <option value="">All departments</option>
                    @foreach ($report['departments'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-select>
            </div>
            <x-button type="button" variant="secondary" wire:click="download">
                <x-icon name="document-text" class="h-5 w-5" />
                Download CSV
            </x-button>
        </div>
    </div>

    @if ($report['open'])
        <div class="flex items-start gap-2.5 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30" role="status">
            <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0" />
            <span>This month is still open: figures can change until it ends.</span>
        </div>
    @endif

    <x-card :padding="false">
        <div class="px-4 pt-5 sm:px-6">
            <div class="flex items-baseline justify-between gap-4">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $monthLabel }}</h3>
                <p class="shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ count($report['rows']) }} {{ Str::plural('employee', count($report['rows'])) }}</p>
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The same counts as each employee's attendance summary. Workdays are scheduled workdays only; a day worked on a day off or holiday counts under Days off worked. Leave taken says how many of its days were worked anyway. Late and early leave are total time over the number of days. Not calculated counts past workdays with no finished attendance.</p>
        </div>

        @if ($report['rows'] === [])
            @if ($report['department'] !== null)
                <x-empty-state icon="users" title="No employees in this department" :description="'Nobody in '.$report['departments'][$report['department']].' was employed in '.$monthLabel.'.'" />
            @else
                <x-empty-state icon="users" title="No employees in this month" :description="'Nobody you can report on was employed in '.$monthLabel.'.'" />
            @endif
        @elseif (! $report['calculated'])
            <x-empty-state icon="document-text" title="No attendance calculated for this month" :description="'Nothing has been calculated for '.$monthLabel.' yet, for anyone here.'" />
        @else
            {{-- Wider than any screen with the sidebar: the Employee column stays
            pinned while the figures scroll under it, and the end edge fades
            while more columns lie beyond. No column is hidden. The chevron is
            each row's only link. --}}
            <div x-data="scrollEdges" @scroll.passive="measure()" class="scroll-fade-end mt-3 overflow-x-auto rounded-b-xl">
                <table class="w-full min-w-[64rem] text-sm">
                    <thead>
                        <tr class="relative text-left align-bottom text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="table-pin-lead min-w-[8.5rem] px-4 py-3 font-medium sm:w-44 sm:min-w-44 sm:pl-6 sm:pr-3">Employee</th>
                            <th scope="col" class="{{ $th }}">{!! $abbr('Workdays', 'Calculated workdays') !!}</th>
                            <th scope="col" class="{{ $th }}">Present</th>
                            <th scope="col" class="{{ $th }}">Absent</th>
                            <th scope="col" class="{{ $th }}">Incomplete</th>
                            <th scope="col" class="{{ $th }}">On leave</th>
                            <th scope="col" class="{{ $th }}">Leave taken<span class="block font-normal normal-case tracking-normal">days</span></th>
                            <th scope="col" class="{{ $th }}">Days off<span class="block font-normal normal-case tracking-normal">worked</span></th>
                            <th scope="col" class="{{ $th }}">Late</th>
                            <th scope="col" class="{{ $th }}">{!! $abbr('Early', 'Early leave') !!}</th>
                            <th scope="col" class="{{ $th }}">Worked</th>
                            <th scope="col" class="{{ $th }}">Not calculated</th>
                            <th scope="col" class="w-12 px-1 py-3"><span class="sr-only">Attendance</span>{!! $divider !!}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([...array_map(fn ($row) => [...$row, 'total' => false], $report['rows']), ['summary' => $report['totals'], 'total' => true]] as $row)
                            @php
                                $s = $row['summary'];
                                $lateDays = AttendanceReport::lateDays($s);
                                $cell = $row['total'] ? $total : $td;
                            @endphp
                            <tr @class(['relative', 'font-semibold' => $row['total']])>
                                <td class="table-pin-lead px-4 py-4 sm:pl-6 sm:pr-3">
                                    @if ($row['total'])
                                        <span class="text-slate-900 dark:text-slate-100">Total</span>
                                    @else
                                        @php $employee = $row['employee']; @endphp
                                        {{-- A fixed width at every size: a long name or department
                                        wraps instead of widening the pinned column, so the
                                        table still fits from 1366 and leaves a phone room
                                        for the figures. --}}
                                        <span class="block max-w-[8.75rem] sm:max-w-none font-medium text-slate-900 dark:text-slate-100">{{ $employee->full_name }}</span>
                                        <span class="block max-w-[8.75rem] sm:max-w-none text-xs text-slate-500 dark:text-slate-400">{{ $employee->employee_code }}{!! $employee->department ? '&nbsp;· '.e($employee->department->name) : '' !!}</span>
                                        @if ($row['joined'] || $row['left'])
                                            <span class="block max-w-[8.75rem] sm:max-w-none text-xs text-slate-500 dark:text-slate-400">{{ collect([$row['joined'] ? 'Joined '.DisplayDate::compact($row['joined']) : null, $row['left'] ? 'Left '.DisplayDate::compact($row['left']) : null])->filter()->implode(' · ') }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="{{ $cell }}">{{ $s['workdays'] }}</td>
                                <td class="{{ $cell }}">{{ $s['present'] }}</td>
                                <td class="{{ $cell }}">{{ $s['absent'] }}</td>
                                <td class="{{ $cell }}">{{ $s['incomplete'] }}</td>
                                <td class="{{ $cell }}">{{ $s['leave'] }}</td>
                                <td class="{{ $cell }}">
                                    {{ $s['leave_tenths'] > 0 ? LeaveDays::format($s['leave_tenths']) : '—' }}
                                    {{-- Days of that leave the employee worked anyway
                                    (DailyAttendance::workedOnLeave()): still charged,
                                    so HR paying from this can see them. --}}
                                    @if ($s['worked_on_leave'] > 0)
                                        <span class="block whitespace-nowrap text-xs font-normal text-slate-500 dark:text-slate-400">{{ $s['worked_on_leave'] }} worked</span>
                                    @endif
                                </td>
                                <td class="{{ $cell }}">{{ $s['days_off_worked'] }}</td>
                                {{-- The total over the day count: two lines, so the whole
                                table fits without scrolling. --}}
                                @foreach ([[$lateDays, $s['late_minutes']], [$s['early_leave_days'], $s['early_leave_minutes']]] as [$days, $minutes])
                                    <td class="{{ $cell }} whitespace-nowrap">
                                        @if ($days > 0)
                                            <span class="block font-medium text-amber-700 dark:text-amber-300">{{ Duration::format($minutes) }}</span>
                                            <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $days }} {{ Str::plural('day', $days) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endforeach
                                <td class="{{ $cell }} whitespace-nowrap">{{ $s['total_worked_minutes'] > 0 ? Duration::format($s['total_worked_minutes']) : '—' }}</td>
                                <td class="{{ $cell }}">{{ $s['not_calculated'] }}</td>
                                <td class="px-1 py-2 text-right">
                                    @unless ($row['total'])
                                        <a
                                            href="{{ route('attendance.show', ['employee' => $employee, 'month' => $month]) }}"
                                            wire:navigate
                                            aria-label="View attendance for {{ $employee->full_name }}, {{ $monthLabel }}"
                                            class="-my-1 ml-auto inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-primary-50 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-primary-600/35 dark:hover:text-primary-300"
                                        >
                                            <x-icon name="chevron-right" class="h-5 w-5" />
                                        </a>
                                        {!! $divider !!}
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</div>
