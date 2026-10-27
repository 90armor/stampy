@php
    use App\Support\DisplayDate;
    use App\Support\LeaveDays;

    $th = 'px-3 py-3 text-right font-medium';
    $td = 'px-3 py-4 text-right tabular-nums whitespace-nowrap text-slate-700 dark:text-slate-300';
    $total = 'px-3 py-4 text-right tabular-nums whitespace-nowrap text-slate-900 dark:text-slate-100';
    $divider = '<span class="pointer-events-none absolute inset-x-4 bottom-0 z-[2] h-px bg-slate-divider sm:inset-x-6"></span>';
    // An amount with its unit; a zero is an em dash, except what's available.
    $days = fn (int $tenths) => $tenths === 0 ? '—' : LeaveDays::label($tenths);
@endphp

<div class="space-y-6">
    {{-- Four controls: beside the title only from xl, where they fit on one line. --}}
    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Leave report</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Each employee's balance of one leave type for a year, as Time off shows it.</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-28">
                <x-input-label for="leave-report-year" value="Year" />
                <x-select id="leave-report-year" wire:model.live="year">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-48">
                <x-input-label for="leave-report-type" value="Leave type" />
                <x-select id="leave-report-type" wire:model.live="type">
                    @foreach ($types as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}{{ $option->is_active ? '' : ' (inactive)' }}</option>
                    @endforeach
                </x-select>
            </div>
            @if ($report)
                <div class="w-52">
                    <x-input-label for="leave-report-department" value="Department" />
                    <x-select id="leave-report-department" wire:model.live="department">
                        <option value="">All departments</option>
                        @foreach ($report['departments'] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-select>
                </div>
            @endif
            <x-button type="button" variant="secondary" wire:click="download">
                <x-icon name="document-text" class="h-5 w-5" />
                Download CSV
            </x-button>
        </div>
    </div>

    <x-card :padding="false">
        <div class="px-4 pt-5 sm:px-6">
            <div class="flex items-baseline justify-between gap-4">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $selectedType?->name ?? 'Leave' }} · {{ $selectedYear }}</h3>
                @if ($report)
                    <p class="shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ count($report['rows']) }} {{ Str::plural('employee', count($report['rows'])) }}</p>
                @endif
            </div>
            @if ($report)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    @if ($report['hasBalance'])
                        The same balance as each employee's Time off: available = entitled + carried in + adjustments − used − pending.
                    @else
                        {{ $selectedType->name }} has no balance: the days each employee took.
                    @endif
                    @if ($report['current'])
                        Pending is as of now.
                    @endif
                </p>
            @endif
        </div>

        @if ($report === null)
            <x-empty-state icon="calendar-days" title="No leave types" description="No leave type is set up yet." />
        @elseif ($report['rows'] === [])
            @if ($report['department'] !== null)
                <x-empty-state icon="users" title="No employees in this department" :description="'Nobody in '.$report['departments'][$report['department']].' had '.$selectedType->name.' in '.$selectedYear.'.'" />
            @elseif ($selectedType->balance_source->isEarned())
                <x-empty-state icon="calendar-days" :title="'Nobody has '.$selectedType->name.' in '.$selectedYear" description="It appears for an employee once their overtime has earned some." />
            @else
                <x-empty-state icon="users" title="No employees in this year" :description="'Nobody you can report on was employed in '.$selectedYear.'.'" />
            @endif
        @else
            @php
                $hasBalance = $report['hasBalance'];
                $earned = $selectedType->balance_source->isEarned();
            @endphp
            {{-- Wider than a phone: the Employee column stays pinned while the
            figures scroll under it, and the end edge fades while more columns
            lie beyond. The chevron is each row's only link, to the profile's
            Leave card. --}}
            <div x-data="scrollEdges" @scroll.passive="measure()" class="scroll-fade-end mt-3 overflow-x-auto rounded-b-xl">
                <table @class(['w-full text-sm', 'min-w-[46rem]' => $hasBalance])>
                    <thead>
                        <tr class="relative text-left align-bottom text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="table-pin-lead min-w-[8.5rem] px-4 py-3 font-medium sm:w-56 sm:pl-6 sm:pr-3">Employee</th>
                            @if ($hasBalance)
                                <th scope="col" class="{{ $th }}">Entitled{!! $earned ? '<span class="block font-normal normal-case tracking-normal">from overtime</span>' : '' !!}</th>
                                <th scope="col" class="{{ $th }}">Carried in</th>
                                <th scope="col" class="{{ $th }}">Adjustments</th>
                                <th scope="col" class="{{ $th }}">Used</th>
                                <th scope="col" class="{{ $th }}">Pending</th>
                                <th scope="col" class="{{ $th }}">Available</th>
                            @else
                                <th scope="col" class="{{ $th }}">Used</th>
                            @endif
                            <th scope="col" class="w-12 px-1 py-3"><span class="sr-only">Leave</span>{!! $divider !!}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([...array_map(fn ($row) => [...$row, 'total' => false], $report['rows']), ['total' => true]] as $row)
                            @php
                                $balance = $row['balance'] ?? null;
                                $cell = $row['total'] ? $total : $td;
                                $figures = $row['total'] ? $report['totals'] : [
                                    'entitled' => $balance->entitled, 'carriedIn' => $balance->carriedIn, 'adjustments' => $balance->adjustments,
                                    'used' => $balance->used, 'pending' => $balance->pending, 'available' => $hasBalance ? $balance->available() : 0,
                                ];
                                $notUsableYet = ! $row['total'] && $hasBalance && $balance->usableFrom !== null;
                            @endphp
                            <tr @class(['relative', 'font-semibold' => $row['total']])>
                                <td class="table-pin-lead px-4 py-4 sm:pl-6 sm:pr-3">
                                    @if ($row['total'])
                                        <span class="text-slate-900 dark:text-slate-100">Total</span>
                                    @else
                                        @php $employee = $row['employee']; @endphp
                                        {{-- A long name or department wraps inside the column. --}}
                                        <span class="block max-w-[8.75rem] font-medium text-slate-900 sm:max-w-none dark:text-slate-100">{{ $employee->full_name }}</span>
                                        <span class="block max-w-[8.75rem] text-xs text-slate-500 sm:max-w-none dark:text-slate-400">{{ $employee->employee_code }}{!! $employee->department ? '&nbsp;· '.e($employee->department->name) : '' !!}</span>
                                        @foreach (array_filter([
                                            $row['joined'] ? 'Joined '.DisplayDate::compact($row['joined']) : null,
                                            $row['left'] ? 'Left '.DisplayDate::compact($row['left']) : null,
                                            $balance->earnedToLastDay !== null ? 'Earned to last day: '.LeaveDays::label($balance->earnedToLastDay) : null,
                                        ]) as $line)
                                            <span class="block max-w-[8.75rem] text-xs text-slate-500 sm:max-w-none dark:text-slate-400">{{ $line }}</span>
                                        @endforeach
                                    @endif
                                </td>
                                @if ($notUsableYet)
                                    {{-- What Time off says, never a bare 0 that reads as "used up". --}}
                                    <td colspan="6" class="px-3 py-4 text-right text-slate-500 dark:text-slate-400">
                                        Usable from {{ DisplayDate::compact($balance->usableFrom) }}{{ $row['earnedSoFar'] !== null ? ' · '.LeaveDays::label($row['earnedSoFar']).' earned so far' : '' }}
                                    </td>
                                @elseif ($hasBalance)
                                    <td class="{{ $cell }}">{{ $days($figures['entitled']) }}</td>
                                    <td class="{{ $cell }}">{{ $days($figures['carriedIn']) }}</td>
                                    <td class="{{ $cell }}">{{ $days($figures['adjustments']) }}</td>
                                    <td class="{{ $cell }}">{{ $days($figures['used']) }}</td>
                                    <td class="{{ $cell }} {{ $row['total'] ? '' : 'text-slate-500 dark:text-slate-400' }}">{{ $days($figures['pending']) }}</td>
                                    {{-- Bold; a negative keeps the text colour and its minus
                                    sign — colour is for attendance status, not balances. --}}
                                    <td class="px-3 py-4 text-right font-semibold tabular-nums whitespace-nowrap text-slate-900 dark:text-slate-100">{{ LeaveDays::label($figures['available']) }}</td>
                                @else
                                    <td class="{{ $cell }}">{{ $days($figures['used']) }}</td>
                                @endif
                                <td class="px-1 py-2 text-right">
                                    @unless ($row['total'])
                                        <a
                                            href="{{ route('employees.show', $employee) }}#leave"
                                            wire:navigate
                                            aria-label="View leave for {{ $employee->full_name }}"
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
