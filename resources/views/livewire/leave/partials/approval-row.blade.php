{{-- One request awaiting a decision (Phase 6j): who, when, how long, any
problem and where it stands, at a glance; the rest behind "Show details".
$item comes from Leave\Approvals::describe() / describeOvertime().

≥1280px one row of aligned columns (the same template on every row, so the
columns line up); 640–1279px two lines (the sidebar takes 242px from 1024);
below 640px a card, the reason line and details above Reject / Approve. The
reason line and the details sit in rows of their own under the who column
(row-start, not an area, so closed details add no gap). --}}
@php
    $request = $item['request'];
    $key = $item['type'].'-'.$request->id;
    $step = $request->current_step ?? 'null';
@endphp
<li wire:key="approval-{{ $key }}" x-data="{ open: false }" class="border-t border-slate-divider px-4 py-4 first:border-t-0 sm:px-6">
    <div class="grid grid-cols-1 gap-y-2 sm:grid-cols-[4.75rem_minmax(0,1fr)_minmax(0,1fr)_11rem] sm:gap-x-4 sm:[grid-template-areas:'type_who_when_actions'_'._signals_signals_step'] xl:grid-cols-[4.75rem_minmax(0,0.85fr)_minmax(0,1.05fr)_minmax(0,1.25fr)_minmax(0,0.75fr)_11rem] xl:items-start xl:[grid-template-areas:'type_who_when_signals_step_actions']">
        {{-- Type and who: one line on a phone. --}}
        <div class="flex items-start gap-x-2.5 sm:contents">
            <div class="shrink-0 sm:[grid-area:type] sm:pt-0.5">
                <x-badge color="slate">{{ $item['type'] === 'overtime' ? 'Overtime' : 'Leave' }}</x-badge>
            </div>
            <div class="min-w-0 sm:[grid-area:who]">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $item['name'] }}</p>
                @if ($item['department'])
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item['department'] }}</p>
                @endif
            </div>
        </div>

        <div class="min-w-0 sm:[grid-area:when]">
            <p class="text-base font-medium tabular-nums text-slate-900 sm:text-sm dark:text-slate-100">{{ $item['when'] }}@if ($item['whenTime']) · <span class="whitespace-nowrap">{{ $item['whenTime'] }}</span>@endif</p>
            <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ $item['whenSub'] }}</p>
        </div>

        @if ($item['signals'] !== [])
            <ul class="flex min-w-0 flex-wrap content-start gap-1.5 sm:[grid-area:signals]" aria-label="Signals">
                @foreach ($item['signals'] as $signal)
                    <li wire:key="signal-{{ $key }}-{{ $loop->index }}">
                        @if ($signal['tone'] === 'attention')
                            <x-badge color="amber" class="gap-x-1 !px-2"><x-icon :name="$signal['icon']" class="h-3.5 w-3.5 shrink-0" />{{ $signal['text'] }}</x-badge>
                        @else
                            <x-badge color="slate" class="!px-2">{{ $signal['text'] }}</x-badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($item['step']['text'] !== null)
            <p class="min-w-0 text-xs text-slate-500 sm:[grid-area:step] sm:text-right xl:pt-0.5 xl:text-left dark:text-slate-400">
                @if ($item['step']['done'])
                    {{ $item['step']['text'] }} <span class="text-green-700 dark:text-green-400" aria-hidden="true">✓</span><span class="sr-only">approved by</span>
                    {{ $item['step']['by'] }}
                @else
                    {{ $item['step']['text'] }}
                @endif
            </p>
        @endif

        {{-- The reason, one line, and the details toggle. --}}
        <div class="flex min-w-0 items-center gap-x-3 sm:col-start-2 sm:col-end-[-1] sm:row-start-3 xl:row-start-2">
            <p class="min-w-0 flex-1 truncate text-xs text-slate-500 dark:text-slate-400">
                @if ($item['reason'])“{{ $item['reason'] }}”@else<span class="italic">No reason given</span>@endif
            </p>
            <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-expanded="false" aria-controls="details-{{ $key }}" data-details-toggle
                class="-my-1.5 inline-flex shrink-0 items-center gap-x-1 rounded-lg px-1.5 py-1.5 text-xs font-medium text-primary-700 hover:text-primary-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 max-sm:-my-3 max-sm:min-h-11 dark:text-primary-400 dark:hover:text-primary-300">
                <span x-text="open ? 'Hide details' : 'Show details'">Show details</span>
            </button>
        </div>

        <div id="details-{{ $key }}" x-show="open" x-cloak class="min-w-0 sm:col-start-2 sm:col-end-[-1] sm:row-start-4 xl:row-start-3">
            <dl class="grid gap-x-6 gap-y-2 rounded-lg bg-slate-50 px-4 py-3 text-xs sm:grid-cols-[8rem_minmax(0,1fr)] dark:bg-slate-750/60">
                @foreach ($item['details'] as $detail)
                    <dt wire:key="detail-label-{{ $key }}-{{ $loop->index }}" class="font-medium text-slate-700 dark:text-slate-300">{{ $detail['label'] }}</dt>
                    <dd wire:key="detail-value-{{ $key }}-{{ $loop->index }}" class="space-y-0.5 text-slate-600 max-sm:mb-1 dark:text-slate-300">
                        @foreach ($detail['lines'] as $line)
                            <p wire:key="detail-line-{{ $key }}-{{ $loop->parent->index }}-{{ $loop->index }}">{{ $line }}</p>
                        @endforeach
                    </dd>
                @endforeach
            </dl>
        </div>

        <div class="flex gap-3 max-sm:mt-1 sm:[grid-area:actions] sm:justify-end">
            <x-button type="button" variant="secondary" class="max-sm:flex-1" wire:click="openDecision({{ $request->id }}, 'reject', '{{ $item['type'] }}', {{ $step }})">Reject</x-button>
            <x-button type="button" variant="primary" class="max-sm:flex-1" wire:click="openDecision({{ $request->id }}, 'approve', '{{ $item['type'] }}', {{ $step }})">Approve</x-button>
        </div>
    </div>
</li>
