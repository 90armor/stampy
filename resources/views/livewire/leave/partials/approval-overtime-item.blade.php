{{-- One overtime request awaiting a decision (Phase 4d) — the overtime twin of
approval-item. $item comes from Leave\Approvals::describeOvertime(). --}}
@php
    $request = $item['request'];
    $steps = $request->approvalSteps;
@endphp
<li wire:key="approval-overtime-{{ $request->id }}" class="border-t border-slate-divider px-4 py-4 first:border-t-0 sm:px-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    {{ $request->employee->full_name }}
                    @if ($item['department'])<span class="font-normal text-slate-500 dark:text-slate-400"> · {{ $item['department'] }}</span>@endif
                </p>
                @if ($item['stuck'])
                    <x-badge color="amber">Stuck</x-badge>
                @endif
            </div>
            <p class="text-sm tabular-nums text-slate-700 dark:text-slate-200">Overtime · {{ $request->displayDateAndWindow() }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $request->kind->label() }} · {{ $request->compensation->label() }}</p>
            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([$item['submitted'], $item['when']])) }}</p>
            @if ($item['awayReason'])
                <p class="text-xs text-slate-600 dark:text-slate-300">{{ $item['awayReason'] }}.</p>
            @endif
            @if ($request->reason)
                <p class="text-sm text-slate-600 dark:text-slate-300">“{{ $request->reason }}”</p>
            @endif
            @if ($request->limit_override_reason)
                <p class="text-xs text-slate-600 dark:text-slate-300"><span class="font-semibold">Over the daily limit:</span> “{{ $request->limit_override_reason }}”</p>
            @endif
            {{-- A claim, or a planned date that has passed: did they actually stay? --}}
            @if ($item['worked'])
                <p class="text-sm tabular-nums text-slate-700 dark:text-slate-200">{{ $item['worked'] }}</p>
                @if ($item['workedHint'])
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item['workedHint'] }}</p>
                @endif
            @endif
            @if ($steps->isNotEmpty())
                <ul class="space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                    @foreach ($steps as $step)
                        <li>{{ 'Step '.$step->step.': '.strtolower($step->outcome->label()).($step->decidedBy ? ' by '.$step->decidedBy->name : '').' · '.\App\Support\DisplayDate::compact($step->decided_at).($step->note ? ' — “'.$step->note.'”' : '') }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="flex shrink-0 gap-3">
            <x-button type="button" variant="secondary" wire:click="openDecision({{ $request->id }}, 'reject', 'overtime')">Reject</x-button>
            <x-button type="button" variant="primary" wire:click="openDecision({{ $request->id }}, 'approve', 'overtime')">Approve</x-button>
        </div>
    </div>
</li>
