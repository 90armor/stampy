@php
    use App\Support\DisplayDate;
    use App\Support\Duration;
    use App\Support\OvertimeSummary;

    $sectionTitle = 'text-sm font-semibold text-slate-900 dark:text-slate-100';
    $muted = 'text-xs text-slate-500 dark:text-slate-400';
    $stepLine = fn ($step) => 'Step '.$step->step.': '.strtolower($step->outcome->label())
        .($step->decidedBy ? ' by '.$step->decidedBy->name : '').' · '.DisplayDate::compact($step->decided_at)
        .($step->note ? ' — “'.$step->note.'”' : '');
@endphp

<div>
    <div class="flex flex-col gap-3 px-4 pt-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Overtime</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Credited overtime and requests.</p>
        </div>
        @if ($canFile)
            {{-- The request modal, opened with this employee preselected and locked. --}}
            <x-button type="button" variant="secondary" wire:click="$dispatch('file-overtime', { employeeId: {{ $employee->id }}, lock: true })">
                <x-icon name="plus" class="h-5 w-5" />
                File overtime
            </x-button>
        @endif
    </div>

    @if ($notice)
        <div class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30 sm:mx-6" role="status">{{ $notice }}</div>
    @endif
    @if ($warning)
        <div class="mx-4 mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900 sm:mx-6">{{ $warning }}</div>
    @endif

    {{-- This month and last: pay by category with its rates, time off as a plain total (1:1). --}}
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2">
        @foreach ($months as $month)
            @php $summary = $month['summary']; @endphp
            <section class="border-t border-slate-divider px-4 py-4 sm:px-6 sm:[&:nth-child(2)]:border-l">
                <div class="flex items-baseline justify-between gap-4">
                    <h3 class="{{ $sectionTitle }}">{{ $month['label'] }}</h3>
                    <p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $summary['total'] > 0 ? Duration::format($summary['total']) : 'None' }}</p>
                </div>
                @if (array_sum($summary['pay']) > 0)
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">Paid {{ Duration::format(array_sum($summary['pay'])) }}</p>
                    <p class="{{ $muted }} tabular-nums">{{ OvertimeSummary::categoryLine($summary['pay']) }}</p>
                @endif
                @if (array_sum($summary['time_off']) > 0)
                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">As time off {{ Duration::format(array_sum($summary['time_off'])) }}</p>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Requests: every one, with what it credited and its step history. --}}
    <section class="border-t border-slate-divider px-4 py-5 sm:px-6">
        <div class="flex items-baseline justify-between gap-4">
            <h3 class="{{ $sectionTitle }}">Requests</h3>
            @if ($requests->isNotEmpty())<p class="{{ $muted }} tabular-nums">{{ $requests->count() }}</p>@endif
        </div>
        @if ($requests->isEmpty())
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No overtime requested yet.</p>
        @else
            <ul class="mt-2">
                @foreach ($requests as $item)
                    @php $request = $item['request']; @endphp
                    <li wire:key="overtime-card-request-{{ $request->id }}" class="border-t border-slate-divider py-3 first:border-t-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium tabular-nums text-slate-900 dark:text-slate-100">{{ $request->displayDateAndWindow() }}</p>
                            <x-badge :color="$request->status->badgeColor()">{{ $request->status->label() }}</x-badge>
                        </div>
                        <p class="mt-0.5 {{ $muted }}">{{ implode(' · ', array_filter([$request->kind->label(), $request->compensation->label(), $request->waitingLabel()])) }}</p>
                        @if ($item['result'])<p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $item['result'] }}</p>@endif
                        @if ($request->reason)<p class="mt-1 text-sm text-slate-600 dark:text-slate-300">“{{ $request->reason }}”</p>@endif
                        @if ($request->limit_override_reason)<p class="mt-1 {{ $muted }}">Over the daily limit: “{{ $request->limit_override_reason }}”</p>@endif
                        @if ($request->approvalSteps->isNotEmpty() || $request->cancelled_at)
                            <ul class="mt-1 space-y-0.5 {{ $muted }}">
                                @foreach ($request->approvalSteps as $step)
                                    <li>{{ $stepLine($step) }}</li>
                                @endforeach
                                @if ($request->cancelled_at)
                                    <li>{{ 'Cancelled'.($request->cancelledBy ? ' by '.$request->cancelledBy->name : '').' · '.DisplayDate::compact($request->cancelled_at) }}</li>
                                @endif
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Time off in lieu posted: the system's reconciliation, each linked to the request that set it off. --}}
    @if ($posted->isNotEmpty())
        <section class="border-t border-slate-divider px-4 py-5 sm:px-6">
            <h3 class="{{ $sectionTitle }}">Time off in lieu posted</h3>
            <ul class="mt-2">
                @foreach ($posted as $adjustment)
                    <li wire:key="overtime-card-posted-{{ $adjustment->id }}" class="border-t border-slate-divider py-2.5 first:border-t-0">
                        <p class="text-sm text-slate-900 dark:text-slate-100"><span class="font-medium tabular-nums">{{ \App\Livewire\Employees\LeaveCard::signed($adjustment->days) }}</span> {{ $adjustment->leaveType->name }} {{ $adjustment->year }}</p>
                        <p class="mt-0.5 {{ $muted }}">{{ 'Triggered by overtime on '.DisplayDate::compact($adjustment->overtimeRequest->date).' · '.DisplayDate::compact($adjustment->created_at) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canFile)
        <livewire:overtime.request-modal :key="'overtime-card-request-modal-'.$employee->id" />
    @endif
</div>
