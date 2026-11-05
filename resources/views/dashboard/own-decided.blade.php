{{-- An employee's own requests decided since they last opened Time off
(LeaveDecisions), newest first, at most the last 30 days — the same "New" state
Time off shows, which the title already says, so no per-item badge. Without
email, this is where an employee finds out; a rejection's note is the reason,
so it shows. On the employee dashboard (Phase 3e) and, after the team cards, a
manager's or linked admin's (Phase 6g, F11). $mine is EmployeeDashboard::for();
$class places the card; the card styles are the including dashboard's. --}}
@if (count($mine['decided']))
    <x-card :class="$class ?? ''">
        <div class="{{ $cardHeader }}">
            <h2 class="{{ $cardTitle }}">Decided since your last visit</h2>
            <p class="{{ $cardMeta }}">{{ count($mine['decided']) }} new</p>
        </div>
        <ul class="mt-4">
            @foreach ($mine['decided'] as $decided)
                @php $decision = \App\Support\RequestDecisions::note($decided); @endphp
                <li class="border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($decided instanceof \App\Models\OvertimeRequest)
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Overtime · <span class="tabular-nums">{{ $decided->displayDateAndWindow() }}</span></p>
                        @else
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $decided->leaveType->name }} · <span class="tabular-nums">{{ $decided->displayDates() }}</span></p>
                        @endif
                        <x-badge :color="$decided->status->badgeColor()">{{ $decided->status->label() }}</x-badge>
                    </div>
                    @if ($decision)
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">“{{ $decision->note }}” <span class="text-slate-500 dark:text-slate-400">— {{ $decision->decidedBy?->name ?? 'an approver' }}</span></p>
                    @endif
                </li>
            @endforeach
        </ul>
        @php $decidedOvertime = collect($mine['decided'])->contains(fn ($decided) => $decided instanceof \App\Models\OvertimeRequest); @endphp
        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2">
            @if (collect($mine['decided'])->contains(fn ($decided) => $decided instanceof \App\Models\Leave))
                <a href="{{ route('time-off.index') }}" wire:navigate class="{{ $link }}">See them on Time off</a>
            @endif
            @if ($decidedOvertime)
                <a href="{{ route('overtime.index') }}" wire:navigate class="{{ $link }}">See overtime</a>
            @endif
        </div>
    </x-card>
@endif
