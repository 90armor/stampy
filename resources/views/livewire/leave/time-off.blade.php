@php
    use App\Enums\LeaveStatus;
    use App\Support\DisplayDate;
    use App\Support\LeaveDays;

    $cardHeader = 'flex items-baseline justify-between gap-4';
    $cardMeta = 'shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400';
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Time off</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Your leave balances and requests.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($canFileForOthers)
                <x-button type="button" variant="secondary" wire:click="$dispatch('file-leave')">
                    <x-icon name="users" class="h-5 w-5" />
                    File for an employee
                </x-button>
            @endif
            @if ($employee)
                <x-button type="button" variant="primary" wire:click="$dispatch('request-leave')">
                    <x-icon name="plus" class="h-5 w-5" />
                    Request leave
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
                <p class="text-sm text-slate-600 dark:text-slate-300">Your account isn't linked to an employee record, so there's no leave balance of your own here. You can still file leave for an employee.</p>
            </div>
        </x-card>
    @else
        <x-card :padding="false">
            <div class="{{ $cardHeader }} px-4 pt-5 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Balances</h2>
                @if (count($years) > 1)
                    <div class="w-28">
                        <label for="balance-year" class="sr-only">Leave year</label>
                        <x-select id="balance-year" wire:model.live="year">
                            @foreach ($years as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </x-select>
                    </div>
                @else
                    <p class="{{ $cardMeta }}">{{ $year }}</p>
                @endif
            </div>

            @include('livewire.leave.partials.balances', ['balanceRows' => $balanceRows])

            @if ($otherTypes->isNotEmpty())
                <p class="border-t border-slate-divider px-4 py-4 text-sm text-slate-500 dark:text-slate-400 sm:px-6">
                    Also available: {{ $otherTypes->map(fn ($type) => $type->name.($type->deductsFrom ? ' (deducted from '.$type->deductsFrom->name.')' : ''))->implode(', ') }}.
                </p>
            @endif
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
                <x-empty-state icon="calendar-days" title="No leave requests yet" description="Requests you make appear here with their status.">
                    <x-slot name="action">
                        <x-button type="button" variant="secondary" wire:click="$dispatch('request-leave')">
                            <x-icon name="plus" class="h-5 w-5" />
                            Request leave
                        </x-button>
                    </x-slot>
                </x-empty-state>
            @else
                {{-- Phone: one card per request. --}}
                <ul class="mt-3 sm:hidden">
                    @foreach ($requests as $item)
                        @php
                            $leave = $item['leave'];
                            $note = \App\Support\LeaveDecisions::note($leave);
                            $step = $leave->waitingLabel();
                        @endphp
                        <li wire:key="leave-card-{{ $leave->id }}" class="border-t border-slate-divider px-4 py-4 first:border-t-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $leave->leaveType->name }}</p>
                                <x-badge :color="$leave->status->badgeColor()">{{ $leave->status->label() }}</x-badge>
                                @if (in_array($leave->id, $newIds, true))<x-badge color="primary">New</x-badge>@endif
                            </div>
                            <p class="mt-1 text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $leave->displayDates() }} · {{ LeaveDays::label($item['days']) }}</p>
                            @if ($step)<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $step }}</p>@endif
                            @if ($note)<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">“{{ $note->note }}” — {{ $note->decidedBy?->name ?? 'an approver' }}</p>@endif
                            @can('cancel', $leave)
                                <div class="mt-3">@include('livewire.leave.partials.cancel-button', ['leave' => $leave, 'label' => $leave->displayDates()])</div>
                            @endcan
                        </li>
                    @endforeach
                </ul>

                {{-- From sm: a table. --}}
                <div class="mt-3 hidden sm:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="relative text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <th scope="col" class="px-6 py-3 font-medium">Type</th>
                                <th scope="col" class="px-3 py-3 font-medium">Dates</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Days</th>
                                <th scope="col" class="px-3 py-3 font-medium">Status</th>
                                <th scope="col" class="px-6 py-3 font-medium"><span class="sr-only">Actions</span><span class="pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-divider"></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $item)
                                @php
                                    $leave = $item['leave'];
                                    $note = \App\Support\LeaveDecisions::note($leave);
                                    $step = $leave->waitingLabel();
                                @endphp
                                <tr wire:key="leave-row-{{ $leave->id }}" class="relative align-top">
                                    <td class="px-6 py-4 font-medium text-slate-900 dark:text-slate-100">
                                        {{ $leave->leaveType->name }}
                                        @if (in_array($leave->id, $newIds, true))<x-badge color="primary" class="ml-1.5">New</x-badge>@endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 tabular-nums text-slate-700 dark:text-slate-300">{{ $leave->displayDates() }}</td>
                                    <td class="px-3 py-4 text-right tabular-nums text-slate-700 dark:text-slate-300">{{ LeaveDays::format($item['days']) }}</td>
                                    <td class="px-3 py-4">
                                        <x-badge :color="$leave->status->badgeColor()">{{ $leave->status->label() }}</x-badge>
                                        @if ($step)<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $step }}</p>@endif
                                        @if ($note)<p class="mt-1 max-w-xs text-xs text-slate-500 dark:text-slate-400">“{{ $note->note }}” — {{ $note->decidedBy?->name ?? 'an approver' }}</p>@endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        @can('cancel', $leave)
                                            @include('livewire.leave.partials.cancel-button', ['leave' => $leave, 'label' => $leave->displayDates()])
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

    <livewire:leave.request-modal />
    <x-confirm-dialog event="confirm-dialog-time-off" />
</div>
