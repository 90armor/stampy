{{-- One request awaiting a decision: what the approver needs, then the actions.
$item comes from Leave\Approvals::describe(). --}}
@php
    $leave = $item['leave'];
    $steps = $leave->approvalSteps;
@endphp
<li wire:key="approval-{{ $leave->id }}" class="border-t border-slate-divider px-4 py-4 first:border-t-0 sm:px-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    {{ $leave->employee->full_name }}
                    @if ($item['department'])<span class="font-normal text-slate-500 dark:text-slate-400"> · {{ $item['department'] }}</span>@endif
                </p>
                {{-- Overrides only: waiting 2+ working days, or starting within 2. --}}
                @if ($item['stuck'])
                    <x-badge color="amber">Stuck</x-badge>
                @endif
            </div>
            <p class="text-sm tabular-nums text-slate-700 dark:text-slate-200">{{ $leave->leaveType->name }} · {{ $item['dates'] }} · {{ $item['days'] }}</p>
            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([$item['submitted'], $item['when']])) }}</p>
            @if ($leave->reason)
                <p class="text-sm text-slate-600 dark:text-slate-300">“{{ $leave->reason }}”</p>
            @endif
            <p class="text-xs text-slate-500 dark:text-slate-400">
                @if ($item['after'] === [])
                    {{ $leave->leaveType->name }} has no balance to draw from.
                @else
                    {{ $item['balanceType'].($item['deducted'] ? ' (deducted from)' : '').' available after this request: '.collect($item['after'])->map(fn ($line) => (count($item['after']) > 1 ? $line['year'].': ' : '').$line['available'])->implode(' · ') }}
                @endif
            </p>
            @if ($steps->isNotEmpty())
                <ul class="space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                    @foreach ($steps as $step)
                        <li>{{ 'Step '.$step->step.': '.strtolower($step->outcome->label()).($step->decidedBy ? ' by '.$step->decidedBy->name : '').' · '.\App\Support\DisplayDate::compact($step->decided_at).($step->note ? ' — “'.$step->note.'”' : '') }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($item['alsoOff'] !== [])
                {{-- Muted, not amber: amber text is the timing annotation's colour. --}}
                <p class="text-xs text-slate-600 dark:text-slate-300">
                    <span class="font-semibold">Also off:</span> {{ count($item['alsoOff']) }} {{ count($item['alsoOff']) === 1 ? 'other' : 'others' }} in {{ $item['department'] }} during these dates — {{ implode('; ', $item['alsoOff']) }}.
                </p>
            @endif
        </div>
        <div class="flex shrink-0 gap-3">
            <x-button type="button" variant="secondary" wire:click="openDecision({{ $leave->id }}, 'reject')">Reject</x-button>
            <x-button type="button" variant="primary" wire:click="openDecision({{ $leave->id }}, 'approve')">Approve</x-button>
        </div>
    </div>
</li>
