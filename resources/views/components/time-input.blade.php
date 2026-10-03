{{-- A time field: typed hour / minute / AM-PM segments plus a popover of
columns (timeInput in resources/js/app.js). Bound to one Livewire property as
'HH:MM' (24-hour), written deferred like the plain wire:model it replaces;
shows the app's time format (8:02 AM). Rules: docs/DESIGN_SYSTEM.md, Time input.

  <x-input-label id="schedule_start_time-label" for="schedule_start_time" value="Start time" />
  <x-time-input id="schedule_start_time" model="start_time" label="Start time" />

- id: the popover button's id, so the field's <x-input-label for> opens the
  picker; give that label id="{id}-label" — the segments are labelled by it.
- model: the Livewire property ('HH:MM').
- label: the field's name, for the native input's accessible name.
- min / max: optional 'HH:MM' bounds mirroring the server rule.
- after: the property this time must be later than (end after start).
- capAtNowWhen: a date property; while it is today, no later than now (a
  manual punch can't be in the future).
- disabled: a locked field — shown, not editable.

From 640px the segmented field; below 640px a native time input on the same
property. The server rule stays the authority. --}}
@props(['id', 'model', 'label', 'min' => null, 'max' => null, 'after' => null, 'capAtNowWhen' => null, 'disabled' => false])

@php
    $segment = 'rounded px-0.5 tabular-nums outline-none focus:bg-primary-100 focus:text-primary-900 dark:focus:bg-primary-600/35 dark:focus:text-primary-100';
    $option = 'flex h-9 w-full items-center justify-center rounded-lg text-sm tabular-nums transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-primary-400 dark:focus-visible:ring-offset-slate-750';
    $optionChosen = 'bg-primary-600 font-semibold text-white dark:bg-primary-500';
    $optionPlain = 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-600/30';
    $optionDisabled = 'cursor-not-allowed text-slate-300 dark:text-slate-600';
@endphp

<div {{ $attributes }}>
    <div
        class="relative hidden sm:block"
        wire:ignore
        x-data="timeInput({ model: @js($model), min: @js($min), max: @js($max), after: @js($after), capDate: @js($capAtNowWhen), nowCap: @js(now()->format('H:i')), today: @js(today()->format('Y-m-d')), disabled: @js((bool) $disabled) })"
        @click.outside="closePopover(false)"
        @keydown.escape="if (popoverOpen) { $event.stopPropagation(); closePopover(); }"
    >
        <div
            x-ref="field"
            class="flex h-control w-full items-center gap-2 rounded-lg border bg-white px-3 text-sm leading-5 text-slate-900 shadow-sm focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500 dark:bg-slate-750 dark:text-slate-100"
            :class="[invalid ? 'border-red-500' : 'border-slate-border', disabled ? 'bg-slate-50 dark:bg-slate-800' : '']"
        >
            <x-icon name="clock" class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" />
            <div
                x-ref="segments"
                role="group"
                aria-labelledby="{{ $id }}-label"
                :aria-invalid="String(invalid)"
                :aria-description="boundsText || null"
                class="flex min-w-0 flex-1 items-center whitespace-nowrap"
            >
                <span
                    role="spinbutton"
                    :tabindex="disabled ? -1 : 0"
                    aria-label="Hour"
                    aria-valuemin="1"
                    aria-valuemax="12"
                    :aria-valuenow="hour"
                    :aria-valuetext="hour === null ? 'Empty' : String(hour)"
                    :aria-disabled="String(disabled)"
                    @keydown="onSegmentKeydown($event, 'hour')"
                    @focus="buffer = ''"
                    class="{{ $segment }}"
                    :class="hour === null && 'text-slate-500 dark:text-slate-400'"
                    x-text="hour ?? '--'"
                ></span>
                <span aria-hidden="true" class="px-px text-slate-500 dark:text-slate-400">:</span>
                <span
                    role="spinbutton"
                    :tabindex="disabled ? -1 : 0"
                    aria-label="Minutes"
                    aria-valuemin="0"
                    aria-valuemax="59"
                    :aria-valuenow="minute"
                    :aria-valuetext="minute === null ? 'Empty' : String(minute).padStart(2, '0')"
                    :aria-disabled="String(disabled)"
                    @keydown="onSegmentKeydown($event, 'minute')"
                    @focus="buffer = ''"
                    class="{{ $segment }}"
                    :class="minute === null && 'text-slate-500 dark:text-slate-400'"
                    x-text="minute === null ? '--' : String(minute).padStart(2, '0')"
                ></span>
                <span
                    role="spinbutton"
                    :tabindex="disabled ? -1 : 0"
                    aria-label="AM/PM"
                    :aria-valuetext="meridiem ?? 'Empty'"
                    :aria-disabled="String(disabled)"
                    @keydown="onSegmentKeydown($event, 'meridiem')"
                    class="{{ $segment }} ml-1.5"
                    :class="meridiem === null && 'text-slate-500 dark:text-slate-400'"
                    x-text="meridiem ?? '--'"
                ></span>
            </div>
            <button
                type="button"
                id="{{ $id }}"
                x-ref="toggle"
                @click="togglePopover()"
                :disabled="disabled"
                :aria-expanded="popoverOpen"
                aria-haspopup="dialog"
                aria-controls="{{ $id }}-popover"
                :aria-label="'Choose a time' + (display ? ', ' + display : '')"
                class="-my-1 -mr-1.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-not-allowed dark:text-slate-500 dark:hover:bg-slate-600/30 dark:hover:text-slate-300"
            >
                <x-icon name="chevron-down" class="h-4 w-4" />
            </button>
        </div>

        {{-- Fixed and placed against the field (like the date picker), inside
        the modal's DOM so its focus trap includes it. --}}
        <div
            id="{{ $id }}-popover"
            x-ref="popover"
            x-show="popoverOpen"
            x-cloak
            role="dialog"
            aria-label="Choose a time: {{ $label }}"
            class="fixed z-50 w-64 rounded-xl bg-white p-3 shadow-xl ring-1 ring-slate-border dark:bg-slate-750"
        >
            <div class="grid grid-cols-3 gap-2">
                @foreach (['hour' => 'Hour', 'minute' => 'Minute', 'meridiem' => 'AM/PM'] as $column => $heading)
                    <div class="min-w-0">
                        <p class="pb-1 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" id="{{ $id }}-{{ $column }}-heading">{{ $heading }}</p>
                        <div role="listbox" aria-labelledby="{{ $id }}-{{ $column }}-heading" class="max-h-56 space-y-1 overflow-y-auto overscroll-contain p-1">
                            <template x-for="option in {{ $column === 'hour' ? 'hours' : ($column === 'minute' ? 'minutes' : "['AM', 'PM']") }}" :key="option">
                                <div
                                    role="option"
                                    :tabindex="{{ $column }} === option ? 0 : -1"
                                    :aria-selected="String({{ $column }} === option)"
                                    :aria-disabled="String(! optionAllowed('{{ $column }}', option))"
                                    @click="choose('{{ $column }}', option)"
                                    @keydown="onOptionKeydown($event, '{{ $column }}', option)"
                                    class="{{ $option }}"
                                    :class="{{ $column }} === option ? '{{ $optionChosen }}' : (optionAllowed('{{ $column }}', option) ? '{{ $optionPlain }} cursor-pointer' : '{{ $optionDisabled }}')"
                                    x-text="{{ $column === 'minute' ? "String(option).padStart(2, '0')" : 'option' }}"
                                ></div>
                            </template>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Type exact minutes in the field.</p>
        </div>
    </div>

    <input
        type="time"
        id="{{ $id }}-native"
        aria-label="{{ $label }}"
        wire:model="{{ $model }}"
        @if ($min) min="{{ $min }}" @endif
        @if ($max) max="{{ $max }}" @endif
        @disabled($disabled)
        class="block h-control w-full min-w-0 rounded-lg border-slate-border bg-white text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:bg-slate-50 disabled:text-slate-500 dark:bg-slate-750 dark:text-slate-100 sm:hidden"
    >
</div>
