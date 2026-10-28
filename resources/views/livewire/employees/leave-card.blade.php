@php
    use App\Support\DisplayDate;
    use App\Support\LeaveDays;

    $sectionTitle = 'text-sm font-semibold text-slate-900 dark:text-slate-100';
    $muted = 'text-xs text-slate-500 dark:text-slate-400';
    // A request's step history, one string per step (no directive between the parts).
    $stepLine = fn ($step) => 'Step '.$step->step.': '.strtolower($step->outcome->label())
        .($step->decidedBy ? ' by '.$step->decidedBy->name : '').' · '.DisplayDate::compact($step->decided_at)
        .($step->note ? ' — “'.$step->note.'”' : '');
@endphp

<div>
    <div class="flex flex-col gap-3 px-4 pt-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Leave</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Balances, requests and adjustments.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if (count($years) > 1)
                <div class="w-28">
                    <label for="leave-card-year" class="sr-only">Leave year</label>
                    <x-select id="leave-card-year" wire:model.live="year">
                        @foreach ($years as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-select>
                </div>
            @else
                <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ $year }}</p>
            @endif
            @if ($canFile)
                {{-- The request modal, opened with this employee preselected and locked. --}}
                <x-button type="button" variant="secondary" wire:click="$dispatch('file-leave', { employeeId: {{ $employee->id }}, lock: true })">
                    <x-icon name="plus" class="h-5 w-5" />
                    File leave
                </x-button>
            @endif
        </div>
    </div>

    @if ($notice)
        <div class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30 sm:mx-6" role="status">{{ $notice }}</div>
    @endif
    @if ($warning)
        <div class="mx-4 mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900 sm:mx-6">{{ $warning }}</div>
    @endif

    @include('livewire.leave.partials.balances', ['balanceRows' => $balanceRows])

    {{-- Requests: every one, with its step history. --}}
    <section class="border-t border-slate-divider px-4 py-5 sm:px-6">
        <div class="flex items-baseline justify-between gap-4">
            <h3 class="{{ $sectionTitle }}">Requests</h3>
            @if ($requests->isNotEmpty())<p class="{{ $muted }} tabular-nums">{{ $requests->count() }}</p>@endif
        </div>
        @if ($requests->isEmpty())
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No leave requested yet.</p>
        @else
            <ul class="mt-2">
                @foreach ($requests as $item)
                    @php $leave = $item['leave']; @endphp
                    <li wire:key="leave-card-request-{{ $leave->id }}" class="border-t border-slate-divider py-3 first:border-t-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $leave->leaveType->name }} · <span class="tabular-nums">{{ $leave->displayDates() }}</span> · {{ LeaveDays::label($item['days']) }}</p>
                            <x-badge :color="$leave->status->badgeColor()">{{ $leave->status->label() }}</x-badge>
                        </div>
                        @if ($leave->waitingLabel())<p class="mt-0.5 {{ $muted }}">{{ $leave->waitingLabel() }}</p>@endif
                        @if ($leave->reason)<p class="mt-1 text-sm text-slate-600 dark:text-slate-300">“{{ $leave->reason }}”</p>@endif
                        @if ($leave->approvalSteps->isNotEmpty() || $leave->cancelled_at)
                            <ul class="mt-1 space-y-0.5 {{ $muted }}">
                                @foreach ($leave->approvalSteps as $step)
                                    <li>{{ $stepLine($step) }}</li>
                                @endforeach
                                @if ($leave->cancelled_at)
                                    <li>{{ 'Cancelled'.($leave->cancelledBy ? ' by '.$leave->cancelledBy->name : '').' · '.DisplayDate::compact($leave->cancelled_at) }}</li>
                                @endif
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Adjustments: append-only corrections (LeaveAdjustment). --}}
    <section class="border-t border-slate-divider px-4 py-5 sm:px-6">
        <div class="flex items-center justify-between gap-4">
            <h3 class="{{ $sectionTitle }}">Adjustments</h3>
            @if ($canAdjust)
                <x-button type="button" variant="secondary" wire:click="openAdjustment">Add adjustment</x-button>
            @endif
        </div>
        @if ($adjustments->isEmpty())
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No adjustments.</p>
        @else
            <ul class="mt-2">
                @foreach ($adjustments as $adjustment)
                    <li wire:key="leave-adjustment-{{ $adjustment->id }}" class="border-t border-slate-divider py-3 first:border-t-0">
                        <p class="text-sm text-slate-900 dark:text-slate-100"><span class="font-medium tabular-nums">{{ \App\Livewire\Employees\LeaveCard::signed($adjustment->days) }}</span> {{ $adjustment->leaveType->name }} {{ $adjustment->year }}</p>
                        <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">“{{ $adjustment->note }}”</p>
                        <p class="mt-0.5 {{ $muted }}">{{ ($adjustment->createdBy?->name ?? 'Unknown').' · '.DisplayDate::compact($adjustment->created_at) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($canAdjust && $showAdjustment)
        <x-modal name="leave-adjustment-modal" :show="true" entangle="showAdjustment" backdrop="bg-slate-900/50" maxWidth="md" panelClass="mt-6 sm:mt-16">
            <div class="mx-4 border-b border-slate-divider py-4 sm:mx-6 sm:py-5">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">Adjust {{ $employee->full_name }}'s balance</h3>
            </div>
            <form id="leave-adjustment-form" wire:submit="saveAdjustment" class="space-y-4 px-4 py-4 sm:px-6">
                <p class="text-sm text-slate-600 dark:text-slate-300">Adjustments can't be edited or deleted: fix a mistake with a reversing entry. Opening balances — leave taken or carried before go-live — go in here too.</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="adj_type" value="Balance" />
                        <x-select id="adj_type" wire:model="adj_leave_type_id">
                            <option value="">Choose a type</option>
                            @foreach ($balanceTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('adj_leave_type_id')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="adj_year" value="Year" />
                        <x-select id="adj_year" wire:model="adj_year">
                            @foreach ($years as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('adj_year')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <x-input-label for="adj_days" value="Days" />
                    <x-text-input id="adj_days" type="text" inputmode="decimal" wire:model="adj_days" placeholder="2 to add, -1.5 to take away" />
                    <x-input-error :messages="$errors->get('adj_days')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="adj_note" value="Note" />
                    <x-text-input id="adj_note" type="text" maxlength="255" wire:model="adj_note" placeholder="e.g. Opening balance: 4 days taken before go-live" />
                    <x-input-error :messages="$errors->get('adj_note')" class="mt-1" />
                </div>
            </form>
            <div class="mx-4 flex items-center justify-end gap-3 border-t border-slate-divider py-4 sm:mx-6">
                <x-button type="button" variant="secondary" wire:click="$set('showAdjustment', false)">Close</x-button>
                <x-button type="submit" form="leave-adjustment-form" variant="primary" wire:loading.attr="disabled" wire:target="saveAdjustment">Add adjustment</x-button>
            </div>
        </x-modal>
    @endif

    @if ($canFile)
        <livewire:leave.request-modal :key="'leave-card-request-modal-'.$employee->id" />
    @endif
</div>
