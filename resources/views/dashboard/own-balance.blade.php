{{-- An employee's own leave balances for the year. On the employee dashboard
(Phase 3e) and, after the team cards, a manager's or linked admin's (Phase 6g,
F11). $mine is EmployeeDashboard::for(); $class places the card; the card
styles are the including dashboard's. --}}
<x-card :class="$class ?? ''">
    <div class="{{ $cardHeader }}">
        <h2 class="{{ $cardTitle }}">Leave balance</h2>
        <p class="{{ $cardMeta }}">{{ today()->year }}</p>
    </div>
    @if (count($mine['balances']))
        <dl class="mt-4">
            @foreach ($mine['balances'] as $balance)
                <div class="{{ $row }}">
                    <dt class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $balance['name'] }}</dt>
                    @if ($balance['usableFrom'] === null)
                        <dd class="text-right text-sm tabular-nums text-slate-500 dark:text-slate-400">
                            <span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ \App\Support\LeaveDays::format($balance['available']) }}</span> available{{ $balance['fullYear'] !== null ? ' at last day' : '' }}
                            @if ($balance['fullYear'] !== null)
                                <span class="block text-xs">Full-year balance: {{ \App\Support\LeaveDays::label($balance['fullYear']) }}</span>
                            @endif
                            @if (($balance['toilRemainder'] ?? 0) > 0)
                                <span class="block text-xs">{{ \App\Support\Duration::format($balance['toilRemainder']) }} toward the next half day</span>
                            @endif
                        </dd>
                    @else
                        <dd class="text-right text-sm text-slate-500 dark:text-slate-400">{{ $balance['usableLine'] }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    @else
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No leave balances yet.</p>
    @endif
    <div class="mt-4"><a href="{{ route('time-off.index') }}" wire:navigate class="{{ $link }} max-sm:-my-3 max-sm:inline-flex max-sm:min-h-11 max-sm:items-center">Request leave on Time off</a></div>
</x-card>
