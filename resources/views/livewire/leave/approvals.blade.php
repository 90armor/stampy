@php
    $cardHeader = 'flex items-baseline justify-between gap-4 px-4 pt-5 sm:px-6';
    $cardMeta = 'shrink-0 text-xs tabular-nums text-slate-500 dark:text-slate-400';
    $overrideCount = count($stuckOverrides) + count($otherOverrides);
    $nothing = $typeCounts['all'] === 0;
    $nothingShown = $stepOne === [] && $stepTwo === [] && $overrideCount === 0;
    // A filter that leaves nothing: say what the other type still has.
    $otherType = $typeFilter === 'leave' ? 'overtime' : 'leave';
    $filterEmptyTitle = $typeFilter === 'leave' ? 'No leave requests waiting' : 'No overtime requests waiting';
    $filterEmptyDescription = $typeCounts[$otherType].' '.$otherType.' '.($typeCounts[$otherType] === 1 ? 'request is' : 'requests are').' waiting.';
    // Single-select chips: the shared selected state, the same fixed size either way.
    $chipBase = 'inline-flex h-7 items-center gap-1.5 rounded-lg px-3 text-xs ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900';
    $chipSelected = 'font-semibold bg-primary-50 text-primary-700 ring-primary-600 hover:bg-primary-100 dark:bg-primary-600/35 dark:text-primary-200 dark:ring-primary-500 dark:hover:bg-primary-600/45';
    $chipUnselected = 'font-medium bg-white text-slate-600 ring-slate-border hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 dark:bg-slate-750 dark:text-slate-300 dark:hover:bg-slate-600 dark:hover:text-white dark:active:bg-slate-500';
    $chipLabel = fn (string $text) => new \Illuminate\Support\HtmlString('<span class="inline-grid"><span class="[grid-area:1/1]">'.e($text).'</span><span class="invisible [grid-area:1/1] font-semibold" aria-hidden="true">'.e($text).'</span></span>');
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Approvals</h1>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Leave and overtime requests waiting for a decision.</p>
        </div>
        @unless ($nothing)
            <div class="flex flex-wrap gap-2" role="group" aria-label="Request type">
                @foreach (['all' => 'All', 'leave' => 'Leave', 'overtime' => 'Overtime'] as $value => $label)
                    @php $selected = $typeFilter === $value; @endphp
                    <button type="button" wire:click="filterBy('{{ $value }}')" aria-pressed="{{ $selected ? 'true' : 'false' }}" @class([$chipBase, $chipSelected => $selected, $chipUnselected => ! $selected])>
                        {{ $chipLabel($label.' '.$typeCounts[$value]) }}
                    </button>
                @endforeach
            </div>
        @endunless
    </div>

    @if ($notice)
        <div tabindex="-1" data-focus-notice class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-500/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" role="status">{{ $notice }}</div>
    @endif
    @if ($stale)
        {{-- Phase 6b: the request moved on while the page was open — calm, not an error.
        The item clicked may be far down the list, so the notice scrolls into view;
        keyed by its text, so a second notice is a new element and scrolls again. --}}
        <div wire:key="stale-{{ md5($stale) }}" x-data x-init="$el.scrollIntoView({ block: 'center' })" tabindex="-1" data-focus-notice class="flex items-start gap-x-2 rounded-lg bg-white px-4 py-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-border dark:bg-slate-800 dark:text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" role="status">
            <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
            <span>{{ $stale }}</span>
        </div>
    @endif
    @if ($problem)
        <div tabindex="-1" data-focus-notice class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:ring-amber-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500" role="status">{{ $problem }}</div>
    @endif

    @if ($nothing)
        <x-card>
            <x-empty-state icon="inbox" title="Nothing waiting for you" description="Leave and overtime requests that need your decision appear here." heading="h2" />
        </x-card>
    @elseif ($nothingShown)
        <x-card>
            <x-empty-state icon="inbox" :title="$filterEmptyTitle" :description="$filterEmptyDescription" heading="h2">
                <x-slot:action>
                    <x-button type="button" variant="secondary" wire:click="filterBy('all')">Show all</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        @if ($stepOne !== [] || ! $isAdmin)
            <x-card :padding="false">
                <div class="{{ $cardHeader }}">
                    <h2 tabindex="-1" data-section-heading class="text-lg font-semibold text-slate-900 dark:text-slate-100 focus:outline-none">Waiting for you</h2>
                    <p class="{{ $cardMeta }}">{{ count($stepOne) }}</p>
                </div>
                @if ($stepOne === [])
                    <p class="px-4 pb-5 pt-3 text-sm text-slate-500 dark:text-slate-400 sm:px-6">Nothing from your team right now.</p>
                @else
                    <ul class="mt-3">
                        @foreach ($stepOne as $item)
                            @include('livewire.leave.partials.approval-row', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </x-card>
        @endif

        @if ($isAdmin)
            <x-card :padding="false">
                <div class="{{ $cardHeader }}">
                    <h2 tabindex="-1" data-section-heading class="text-lg font-semibold text-slate-900 dark:text-slate-100 focus:outline-none">Waiting for admin</h2>
                    <p class="{{ $cardMeta }}">{{ count($stepTwo) }}</p>
                </div>
                @if ($stepTwo === [])
                    <p class="px-4 pb-5 pt-3 text-sm text-slate-500 dark:text-slate-400 sm:px-6">No request has reached the admin step.</p>
                @else
                    <ul class="mt-3">
                        @foreach ($stepTwo as $item)
                            @include('livewire.leave.partials.approval-row', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </x-card>

            @if ($overrideCount > 0)
                {{-- Open by default only when something in it is stuck: those are
                listed first, the rest behind "{n} more waiting for managers". --}}
                <x-card :padding="false">
                    <div class="{{ $cardHeader }}">
                        <div>
                            <h2 tabindex="-1" data-section-heading class="text-lg font-semibold text-slate-900 dark:text-slate-100 focus:outline-none">You can override</h2>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Waiting for a manager. Deciding one completes both steps.</p>
                        </div>
                        <p class="{{ $cardMeta }}">{{ $stuckOverrides !== [] ? count($stuckOverrides).' stuck · '.$overrideCount.' in all' : $overrideCount }}</p>
                    </div>
                    @if ($stuckOverrides !== [])
                        <ul class="mt-3">
                            @foreach ($stuckOverrides as $item)
                                @include('livewire.leave.partials.approval-row', ['item' => $item])
                            @endforeach
                        </ul>
                    @endif
                    @if ($otherOverrides !== [])
                        @if ($showOverrides)
                            <ul @class(['border-t border-slate-divider' => $stuckOverrides !== [], 'mt-3' => $stuckOverrides === []])>
                                @foreach ($otherOverrides as $item)
                                    @include('livewire.leave.partials.approval-row', ['item' => $item])
                                @endforeach
                            </ul>
                        @endif
                        <div @class(['px-4 pb-4 sm:px-6', 'border-t border-slate-divider pt-3' => $stuckOverrides !== [] || $showOverrides, 'pt-3' => $stuckOverrides === [] && ! $showOverrides])>
                            <button type="button" wire:click="$toggle('showOverrides')" aria-expanded="{{ $showOverrides ? 'true' : 'false' }}" class="-mx-2 inline-flex items-center gap-x-1 rounded-lg px-2 py-1 text-sm font-medium text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 max-sm:min-h-11 dark:text-primary-400">
                                {{ $showOverrides ? 'Hide the '.count($otherOverrides).' waiting for managers' : ($stuckOverrides !== [] ? count($otherOverrides).' more' : 'Show '.count($otherOverrides)).' waiting for managers' }}
                                <x-icon name="chevron-down" @class(['h-5 w-5', 'rotate-180' => $showOverrides]) />
                            </button>
                        </div>
                    @else
                        <div class="pb-1"></div>
                    @endif
                </x-card>
            @endif
        @endif
    @endif

    @if ($showDecision && $deciding)
        <x-modal name="leave-decision-modal" :show="true" entangle="showDecision" backdrop="bg-slate-900/50" maxWidth="md" panelClass="mt-6 sm:mt-16">
            <form wire:submit="decide">
                <div class="mx-4 border-b border-slate-divider py-4 sm:mx-6 sm:py-5">
                    <x-modal.title>
                        {{ $decision === 'reject' ? 'Reject' : 'Approve' }} {{ $deciding->employee->full_name }}'s
                        {{ $deciding instanceof \App\Models\OvertimeRequest ? 'overtime on '.$deciding->displayDateAndWindow() : $deciding->leaveType->name.' leave' }}
                    </x-modal.title>
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
                                        <li wire:key="limit-problem-{{ $loop->index }}">{{ $problem }}</li>
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
