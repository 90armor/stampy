{{-- An employee's own pending requests, with whose decision they wait for,
then the next approved leave. On the employee dashboard (Phase 3e) and, after
the team cards, a manager's or linked admin's (Phase 6g, F11). $mine is
EmployeeDashboard::for(); $class places the card; the card styles are the
including dashboard's. --}}
<x-card :class="$class ?? ''">
    <div class="{{ $cardHeader }}">
        <h2 class="{{ $cardTitle }}">Upcoming</h2>
        @php $waiting = count($mine['pending']) + collect($mine['overtime'])->where('status', \App\Enums\OvertimeStatus::Pending)->count(); @endphp
        @if ($waiting)<p class="{{ $cardMeta }}">{{ $waiting }} waiting</p>@endif
    </div>
    @if (count($mine['pending']) || $mine['next'] || count($mine['overtime']))
        <ul class="mt-4">
            @foreach ($mine['pending'] as $item)
                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $item['leave']->leaveType->name }} · <span class="tabular-nums">{{ $item['leave']->displayDates() }}</span></p>
                        <x-badge :color="$item['leave']->status->badgeColor()">{{ $item['leave']->status->label() }}</x-badge>
                    </div>
                    <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ \App\Support\LeaveDays::label($item['days']).' · '.$item['leave']->waitingLabel() }}</p>
                </li>
            @endforeach
            {{-- Overtime: waiting for a decision, then approved and still ahead. --}}
            @foreach ($mine['overtime'] as $request)
                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Overtime · <span class="tabular-nums">{{ $request->displayDateAndWindow() }}</span></p>
                        <x-badge :color="$request->status->badgeColor()">{{ $request->status->label() }}</x-badge>
                    </div>
                    <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([$request->compensation->label(), $request->waitingLabel() ?? $request->startsLabel()])) }}</p>
                </li>
            @endforeach
            @if ($mine['next'])
                @php $next = $mine['next']['leave']; @endphp
                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $next->leaveType->name }} · <span class="tabular-nums">{{ $next->displayDates() }}</span></p>
                        <x-badge :color="$next->status->badgeColor()">{{ $next->status->label() }}</x-badge>
                    </div>
                    <p class="mt-0.5 text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([\App\Support\LeaveDays::label($mine['next']['days']), $next->startsLabel()])) }}</p>
                </li>
            @endif
        </ul>
        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2">
            <a href="{{ route('time-off.index') }}" wire:navigate class="{{ $link }} max-sm:-my-3 max-sm:inline-flex max-sm:min-h-11 max-sm:items-center">See all requests on Time off</a>
            @if (count($mine['overtime']))
                <a href="{{ route('overtime.index') }}" wire:navigate class="{{ $link }}">See overtime</a>
            @endif
        </div>
    @else
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No leave requested or coming up.</p>
    @endif
</x-card>
