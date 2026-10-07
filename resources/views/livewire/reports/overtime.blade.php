@php
    use App\Support\Duration;

    $th = 'px-3 py-3 text-right font-medium';
    $td = 'px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300';
    $minutes = fn (int $value) => $value > 0 ? Duration::format($value) : '—';
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Overtime report</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Credited overtime per employee for a month, for payroll.</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-44">
                <x-input-label for="report-month" value="Month" />
                <x-select id="report-month" wire:model.live="month">
                    @foreach ($months as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
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
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $monthLabel }}</h2>
                <p class="shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ count($report['rows']) }} {{ Str::plural('employee', count($report['rows'])) }}</p>
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Only approved, credited minutes count. Pay columns are minutes by category; time off is earned as leave, not paid. Pay-equivalent hours = Σ (minutes × rate) ÷ 60 at today's rates — payroll multiplies it by the hourly wage.</p>
        </div>

        @if ($report['rows'] === [])
            <x-empty-state icon="document-text" title="No credited overtime" description="Nothing was credited for this month." />
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead>
                        <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <th scope="col" class="px-6 py-3 font-medium">Employee</th>
                            @foreach ($categories as $key => [$label, $rateColumn])
                                <th scope="col" class="{{ $th }}">Pay · {{ $label }}<span class="block font-normal normal-case tracking-normal">{{ $rates->{$rateColumn} }}%</span></th>
                            @endforeach
                            <th scope="col" class="{{ $th }}">Time off</th>
                            <th scope="col" class="px-6 py-3 text-right font-medium">Pay-equiv. hours<span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr class="relative">
                                <td class="px-6 py-4">
                                    <span class="font-medium text-slate-900 dark:text-slate-100">{{ $row['name'] }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $row['code'] }}{{ $row['department'] ? ' · '.$row['department'] : '' }}</span>
                                </td>
                                @foreach (array_keys($categories) as $key)
                                    <td class="{{ $td }}">{{ $minutes($row['pay'][$key]) }}</td>
                                @endforeach
                                <td class="{{ $td }}">{{ $minutes($row['time_off']) }}</td>
                                <td class="px-6 py-4 text-right font-semibold tabular-nums text-slate-900 dark:text-slate-100">
                                    {{ number_format($row['pay_equivalent_hours'], 2) }}
                                    <span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>
                                </td>
                            </tr>
                        @endforeach
                        <tr class="relative font-semibold">
                            <td class="px-6 py-4 text-slate-900 dark:text-slate-100">Total</td>
                            @foreach (array_keys($categories) as $key)
                                <td class="px-3 py-4 text-right tabular-nums text-slate-900 dark:text-slate-100">{{ $minutes($report['totals']['pay'][$key]) }}</td>
                            @endforeach
                            <td class="px-3 py-4 text-right tabular-nums text-slate-900 dark:text-slate-100">{{ $minutes($report['totals']['time_off']) }}</td>
                            <td class="px-6 py-4 text-right tabular-nums text-slate-900 dark:text-slate-100">{{ number_format($report['totals']['pay_equivalent_hours'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</div>
