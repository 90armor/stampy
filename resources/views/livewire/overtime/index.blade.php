@php
    use App\Support\Duration;
    use App\Support\LeaveDays;
    use App\Support\OvertimeSummary;
    use App\Support\RequestDecisions;

    $cardHeader = 'flex items-baseline justify-between gap-4';
    $cardMeta = 'shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400';
    $row = 'flex items-baseline justify-between gap-4 border-t border-slate-divider py-2.5 first:border-t-0 first:pt-0 last:pb-0';
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Overtime</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Your overtime requests and what they've credited.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($canFileForOthers)
                <x-button type="button" variant="secondary" wire:click="$dispatch('file-overtime')">
                    <x-icon name="users" class="h-5 w-5" />
                    File for an employee
                </x-button>
            @endif
            @if ($employee)
                <x-button type="button" variant="primary" wire:click="$dispatch('request-overtime')">
                    <x-icon name="plus" class="h-5 w-5" />
                    Request overtime
                </x-button>
            @endif
        </div>
    </div>

    @if ($notice)
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30" role="status">{{ $notice }}</div>
    @endif
    @if ($warning)
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900">{{ $warning }}</div>
    @endif

    @if (! $employee)
        <x-card>
            <div class="flex items-start gap-3">
                <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                <p class="text-sm text-slate-600 dark:text-slate-300">Your account isn't linked to an employee record, so there's no overtime of your own here. You can still file overtime for an employee.</p>
            </div>
        </x-card>
    @else
        {{-- This month: the builder's credited minutes (OvertimeSummary), by
        category with the current rates, paid and time off apart. --}}
        <x-card>
            <div class="{{ $cardHeader }}">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">This month</h2>
                <p class="{{ $cardMeta }}">{{ \App\Support\DisplayDate::month(today()) }}</p>
            </div>
            <dl class="mt-4">
                <div class="{{ $row }}">
                    <dt class="text-sm text-slate-500 dark:text-slate-400">Credited</dt>
                    <dd class="text-base font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $month['total'] > 0 ? Duration::format($month['total']) : 'None yet' }}</dd>
                </div>
                @foreach (['pay' => 'Paid', 'time_off' => 'As time off'] as $key => $label)
                    @if (array_sum($month[$key]) > 0)
                        <div class="{{ $row }}">
                            <dt class="text-sm text-slate-500 dark:text-slate-400">
                                {{ $label }}
                                <span class="block text-xs">{{ OvertimeSummary::categoryLine($month[$key]) }}</span>
                            </dt>
                            <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ Duration::format(array_sum($month[$key])) }}</dd>
                        </div>
                    @endif
                @endforeach
                @if ($month['pending'] > 0)
                    <div class="{{ $row }}">
                        <dt class="text-sm text-slate-500 dark:text-slate-400">Waiting for a decision</dt>
                        <dd class="text-sm font-semibold tabular-nums text-slate-900 dark:text-slate-100">{{ $month['pending'] }} {{ Str::plural('request', $month['pending']) }}</dd>
                    </div>
                @endif
                @if ($toil)
                    <div class="{{ $row }}">
                        <dt class="text-sm text-slate-500 dark:text-slate-400">
                            {{ $toil['name'] }}
                            @if ($toil['saved'] > 0)<span class="block text-xs">{{ Duration::format($toil['saved']) }} toward the next half day</span>@endif
                        </dt>
                        <dd class="text-sm tabular-nums text-slate-500 dark:text-slate-400"><span class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ LeaveDays::format($toil['available']) }}</span> available</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        <x-card :padding="false">
            <div class="{{ $cardHeader }} px-4 pt-5 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">My requests</h2>
                @if ($requests->isNotEmpty())<p class="{{ $cardMeta }}">{{ $requests->count() }} {{ Str::plural('request', $requests->count()) }}</p>@endif
            </div>

            @error('requests')
                <div class="mx-4 mt-3 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-900 sm:mx-6">{{ $message }}</div>
            @enderror

            @if ($requests->isEmpty())
                <x-empty-state icon="bolt" title="No overtime requests yet" description="Plan overtime before you work it, or claim it after. Requests appear here with what they've credited.">
                    <x-slot name="action">
                        <x-button type="button" variant="secondary" wire:click="$dispatch('request-overtime')">
                            <x-icon name="plus" class="h-5 w-5" />
                            Request overtime
                        </x-button>
                    </x-slot>
                </x-empty-state>
            @else
                {{-- Phone: one card per request. --}}
                <ul class="mt-3 sm:hidden">
                    @foreach ($requests as $item)
                        @php
                            $request = $item['request'];
                            $note = RequestDecisions::note($request);
                            $step = $request->waitingLabel();
                        @endphp
                        <li wire:key="overtime-card-{{ $request->id }}" class="border-t border-slate-divider px-4 py-4 first:border-t-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium tabular-nums text-slate-900 dark:text-slate-100">{{ \App\Support\DisplayDate::compact($request->date) }}</p>
                                <x-badge :color="$request->status->badgeColor()">{{ $request->status->label() }}</x-badge>
                                @if (in_array($request->id, $newIds, true))<x-badge color="primary">New</x-badge>@endif
                            </div>
                            <p class="mt-1 text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $request->displayWindow() }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $request->kind->label() }} · {{ $request->compensation->label() }}{{ $step ? ' · '.$step : '' }}</p>
                            @if ($item['result'])<p class="mt-1 text-sm text-slate-700 dark:text-slate-200">{{ $item['result'] }}</p>@endif
                            @if ($note)<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">“{{ $note->note }}” — {{ $note->decidedBy?->name ?? 'an approver' }}</p>@endif
                            @can('cancel', $request)
                                <div class="mt-3">@include('livewire.overtime.partials.cancel-button', ['request' => $request])</div>
                            @endcan
                        </li>
                    @endforeach
                </ul>

                {{-- From sm: a table. --}}
                <div class="mt-3 hidden sm:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-6 py-3 font-medium">Date</th>
                                <th scope="col" class="px-3 py-3 font-medium">Request</th>
                                <th scope="col" class="px-3 py-3 font-medium">Status</th>
                                <th scope="col" class="px-3 py-3 font-medium">Credited</th>
                                <th scope="col" class="px-6 py-3 font-medium"><span class="sr-only">Actions</span><span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $item)
                                @php
                                    $request = $item['request'];
                                    $note = RequestDecisions::note($request);
                                    $step = $request->waitingLabel();
                                @endphp
                                <tr wire:key="overtime-row-{{ $request->id }}" class="relative align-top">
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <span class="font-medium tabular-nums text-slate-900 dark:text-slate-100">{{ \App\Support\DisplayDate::compact($request->date) }}</span>
                                        @if (in_array($request->id, $newIds, true))<x-badge color="primary" class="ml-1.5">New</x-badge>@endif
                                        <span class="block tabular-nums text-slate-600 dark:text-slate-300">{{ $request->displayWindow() }}</span>
                                    </td>
                                    <td class="px-3 py-4 text-slate-700 dark:text-slate-300">
                                        {{ $request->kind->label() }}
                                        <span class="block text-slate-500 dark:text-slate-400">{{ $request->compensation->label() }}</span>
                                    </td>
                                    <td class="px-3 py-4">
                                        <x-badge :color="$request->status->badgeColor()">{{ $request->status->label() }}</x-badge>
                                        @if ($step)<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $step }}</p>@endif
                                        @if ($note)<p class="mt-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">“{{ $note->note }}” — {{ $note->decidedBy?->name ?? 'an approver' }}</p>@endif
                                    </td>
                                    <td class="px-3 py-4 text-slate-700 dark:text-slate-300">{{ $item['result'] ?? '—' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        @can('cancel', $request)
                                            @include('livewire.overtime.partials.cancel-button', ['request' => $request])
                                        @endcan
                                        @unless ($loop->last)<span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span>@endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @endif

    <livewire:overtime.request-modal />
    <x-confirm-dialog event="confirm-dialog-overtime" />
</div>
