@php
    $cardHeader = 'flex items-baseline justify-between gap-4 px-4 pt-5 sm:px-6';
    $cardMeta = 'shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400';
    $nothing = $stepOne === [] && $stepTwo === [] && $overrides === [];
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Approvals</h1>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Leave and overtime requests waiting for a decision.</p>
    </div>

    @if ($notice)
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30" role="status">{{ $notice }}</div>
    @endif
    @if ($problem)
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900" role="status">{{ $problem }}</div>
    @endif

    @if ($nothing)
        <x-card>
            <x-empty-state icon="inbox" title="Nothing waiting for you" description="Leave and overtime requests that need your decision appear here." />
        </x-card>
    @else
        @if ($stepOne !== [] || ! $isAdmin)
            <x-card :padding="false">
                <div class="{{ $cardHeader }}">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Waiting for you</h2>
                    <p class="{{ $cardMeta }}">{{ count($stepOne) }}</p>
                </div>
                @if ($stepOne === [])
                    <p class="px-4 pb-5 pt-3 text-sm text-slate-500 dark:text-slate-400 sm:px-6">Nothing from your team right now.</p>
                @else
                    <ul class="mt-3">
                        @foreach ($stepOne as $item)
                            @include($item['type'] === 'overtime' ? 'livewire.leave.partials.approval-overtime-item' : 'livewire.leave.partials.approval-item', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </x-card>
        @endif

        @if ($isAdmin)
            <x-card :padding="false">
                <div class="{{ $cardHeader }}">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Waiting for admin</h2>
                    <p class="{{ $cardMeta }}">{{ count($stepTwo) }}</p>
                </div>
                @if ($stepTwo === [])
                    <p class="px-4 pb-5 pt-3 text-sm text-slate-500 dark:text-slate-400 sm:px-6">No request has reached the admin step.</p>
                @else
                    <ul class="mt-3">
                        @foreach ($stepTwo as $item)
                            @include($item['type'] === 'overtime' ? 'livewire.leave.partials.approval-overtime-item' : 'livewire.leave.partials.approval-item', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </x-card>

            @if ($overrides !== [])
                <x-card :padding="false">
                    <div class="{{ $cardHeader }} pb-5">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">You can override</h2>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Waiting for a manager. Deciding one completes both steps.</p>
                        </div>
                        <button type="button" wire:click="$toggle('showOverrides')" class="shrink-0 rounded-lg px-2 py-1 text-sm font-medium text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400" aria-expanded="{{ $showOverrides ? 'true' : 'false' }}">
                            {{ $showOverrides ? 'Hide' : 'Show '.count($overrides) }}
                        </button>
                    </div>
                    @if ($showOverrides)
                        <ul class="border-t border-slate-divider">
                            @foreach ($overrides as $item)
                                @include($item['type'] === 'overtime' ? 'livewire.leave.partials.approval-overtime-item' : 'livewire.leave.partials.approval-item', ['item' => $item])
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            @endif
        @endif
    @endif

    @if ($showDecision && $deciding)
        <x-modal name="leave-decision-modal" :show="true" entangle="showDecision" backdrop="bg-slate-900/50" maxWidth="md" panelClass="mt-6 sm:mt-16">
            <form wire:submit="decide">
                <div class="mx-4 border-b border-slate-divider py-4 sm:mx-6 sm:py-5">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                        {{ $decision === 'reject' ? 'Reject' : 'Approve' }} {{ $deciding->employee->full_name }}'s
                        {{ $deciding instanceof \App\Models\OvertimeRequest ? 'overtime on '.$deciding->displayDateAndWindow() : $deciding->leaveType->name.' leave' }}
                    </h3>
                </div>
                <div class="space-y-4 px-4 py-4 sm:px-6">
                    @if ($decision === 'approve' && $decidesBoth)
                        <p class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                            <span>As an admin acting at the manager's step, approving completes both steps — the request is approved straight away.</span>
                        </p>
                    @endif
                    @if ($deciding instanceof \App\Models\OvertimeRequest && $decision === 'approve')
                        <div>
                            <x-input-label for="decision_compensation" value="Compensation" />
                            <x-select id="decision_compensation" wire:model="compensation">
                                <option value="pay">Pay</option>
                                @if ($timeOffOffered || $compensation === 'time_off')
                                    <option value="time_off">Time off in lieu</option>
                                @endif
                            </x-select>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Requested as {{ strtolower($deciding->compensation->label()) }}. A change is noted on your decision.</p>
                            <x-input-error :messages="$errors->get('compensation')" class="mt-1" />
                        </div>
                        @if ($limitProblems !== [])
                            <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-500/30">
                                <p class="font-medium">Over the daily limit</p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($limitProblems as $problem)
                                        <li>{{ $problem }}</li>
                                    @endforeach
                                </ul>
                                @unless ($needsOverride)
                                    <p class="mt-1">You can approve it; the admin decides at the next step.</p>
                                @endunless
                            </div>
                            @if ($needsOverride)
                                <div>
                                    <x-input-label for="decision_override" value="Reason to go over the limit" />
                                    <x-textarea id="decision_override" rows="2" maxlength="1000" wire:model="override_reason">{{ $override_reason }}</x-textarea>
                                    <x-input-error :messages="array_merge($errors->get('limit_override_reason'), $errors->get('overtime'))" class="mt-1" />
                                </div>
                            @endif
                        @endif
                    @endif
                    <div>
                        <x-input-label for="decision_note" :value="$decision === 'reject' ? 'Reason for rejecting' : 'Note (optional)'" />
                        <x-textarea id="decision_note" rows="3" maxlength="1000" wire:model="note" autofocus>{{ $note }}</x-textarea>
                        @if ($decision === 'reject')
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Required — it's the only feedback {{ $deciding->employee->full_name }} gets.</p>
                        @endif
                        <x-input-error :messages="$errors->get('note')" class="mt-1" />
                    </div>
                </div>
                <div class="mx-4 flex items-center justify-end gap-3 border-t border-slate-divider py-4 sm:mx-6">
                    <x-button type="button" variant="secondary" wire:click="$set('showDecision', false)">Close</x-button>
                    <x-button type="submit" :variant="$decision === 'reject' ? 'danger' : 'primary'" wire:loading.attr="disabled" wire:target="decide">
                        {{ $decision === 'reject' ? 'Reject' : 'Approve' }}
                    </x-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
