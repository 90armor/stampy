{{-- The balance table for one employee and year — Time off and the
employee profile's Leave card (Phase 3e). $balanceRows: list of
['type' => LeaveType, 'balance' => Balance, 'earnedSoFar' => ?int,
'toilRemainder' => ?int (Time off in lieu's saved minutes, Phase 4d)]. --}}
@php
    use App\Support\DisplayDate;
    use App\Support\LeaveDays;

    // Adjustments only appear when someone has one this year.
    $showAdjustments = collect($balanceRows)->contains(fn ($row) => $row['balance']->adjustments !== 0);
    // A type not usable yet: when it becomes usable, and what's been earned meanwhile.
    $usableLine = fn ($balance, $row) => 'Usable from '.DisplayDate::compact($balance->usableFrom)
        .($row['earnedSoFar'] !== null ? ' · '.LeaveDays::label($row['earnedSoFar']).' earned so far' : '');
    // A leaver's last year: the allowance pro-rated to their last day (EntitlementCalculator::earnedToLastDay()).
    $earnedLine = fn ($balance) => $balance->earnedToLastDay !== null ? 'Earned to last day: '.LeaveDays::label($balance->earnedToLastDay) : null;
    // Time off in lieu: the overtime saved below a full half day (TimeOffInLieuReconciler::remainderMinutes()).
    $toilLine = fn ($row) => ($row['toilRemainder'] ?? 0) > 0 ? \App\Support\Duration::format($row['toilRemainder']).' toward the next half day' : null;
@endphp

@if ($balanceRows === [])
    <p class="px-4 pb-5 pt-3 text-sm text-slate-500 dark:text-slate-400 sm:px-6">No leave types with a yearly balance are set up.</p>
@else
    {{-- Phone: one block per type. --}}
    <ul class="mt-3 sm:hidden">
        @foreach ($balanceRows as $row)
            @php $balance = $row['balance']; @endphp
            <li class="border-t border-slate-divider px-4 py-4 first:border-t-0">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $row['type']->name }}</p>
                    @if ($balance->usableFrom === null)
                        <p class="text-sm tabular-nums text-slate-500 dark:text-slate-400"><span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ LeaveDays::format($balance->available()) }}</span> available</p>
                    @endif
                </div>
                @if ($earnedLine($balance))
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $earnedLine($balance) }}</p>
                @endif
                @if ($toilLine($row))
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $toilLine($row) }}</p>
                @endif
                @if ($balance->usableFrom !== null)
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $usableLine($balance, $row) }}</p>
                @else
                    <dl class="mt-2 grid grid-cols-4 gap-x-3 gap-y-2 text-xs">
                        @foreach (array_filter(['Entitled' => $balance->entitled, 'Carried' => $balance->carriedIn, 'Adjustments' => $showAdjustments ? $balance->adjustments : null, 'Used' => $balance->used, 'Pending' => $balance->pending], fn ($value) => $value !== null) as $label => $value)
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                <dd class="tabular-nums text-sm text-slate-900 dark:text-slate-100">{{ LeaveDays::format($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </li>
        @endforeach
    </ul>

    {{-- From sm: a table. --}}
    <div class="mt-3 hidden sm:block">
        <table class="w-full text-sm">
            <thead>
                <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <th scope="col" class="px-6 py-3 font-medium">Type</th>
                    <th scope="col" class="px-3 py-3 text-right font-medium">Entitled</th>
                    <th scope="col" class="px-3 py-3 text-right font-medium">Carried</th>
                    @if ($showAdjustments)<th scope="col" class="px-3 py-3 text-right font-medium">Adjustments</th>@endif
                    <th scope="col" class="px-3 py-3 text-right font-medium">Used</th>
                    <th scope="col" class="px-3 py-3 text-right font-medium">Pending</th>
                    <th scope="col" class="px-6 py-3 text-right font-medium">Available<span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($balanceRows as $row)
                    @php $balance = $row['balance']; @endphp
                    <tr class="relative">
                        <td class="px-6 py-4 font-medium text-slate-900 dark:text-slate-100">
                            {{ $row['type']->name }}
                            @if ($earnedLine($balance))
                                <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $earnedLine($balance) }}</span>
                            @endif
                            @if ($toilLine($row))
                                <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $toilLine($row) }}</span>
                            @endif
                        </td>
                        @if ($balance->usableFrom !== null)
                            <td colspan="{{ $showAdjustments ? 6 : 5 }}" class="px-6 py-4 text-right text-slate-500 dark:text-slate-400">
                                {{ $usableLine($balance, $row) }}
                                @unless ($loop->last)<span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>@endunless
                            </td>
                        @else
                            <td class="px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ LeaveDays::format($balance->entitled) }}</td>
                            <td class="px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ LeaveDays::format($balance->carriedIn) }}</td>
                            @if ($showAdjustments)<td class="px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ LeaveDays::format($balance->adjustments) }}</td>@endif
                            <td class="px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ LeaveDays::format($balance->used) }}</td>
                            <td class="px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ LeaveDays::format($balance->pending) }}</td>
                            <td class="px-6 py-4 text-right font-semibold tabular-nums text-slate-900 dark:text-slate-100">
                                {{ LeaveDays::format($balance->available()) }}
                                @unless ($loop->last)<span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>@endunless
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

