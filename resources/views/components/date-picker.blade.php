{{-- A single-date field: the date picker (datePicker in resources/js/app.js)
in single mode, bound to one Livewire property and written deferred, like the
plain wire:model it replaces. Rules: docs/ATTENDANCE_UI.md, Date picker.

  <x-input-label for="holiday_date" value="Date" />
  <x-date-picker id="holiday_date" model="date" label="Date" />

- id: the trigger's id, so the field's <x-input-label for> still points at it.
- model: the Livewire property ('YYYY-MM-DD').
- label: the field's name, for the trigger's and the native input's
  accessible names (a label's text alone would hide the chosen date).
- min / max: optional 'YYYY-MM-DD' bounds that mirror the field's server-side
  rule; days outside them are muted and can't be picked. The server rule stays
  the authority.
- clearable: only for a field whose rule is nullable — adds Clear to the
  footer. A required field gets none: picking again already replaces it.
- The footer's Today shows only when today is inside min/max.
- Other attributes (wire:loading.attr, autofocus, a width) go on the trigger.

From 640px the trigger opens the shared calendar in a popover; below 640px a
native date input, bound to the same property, takes its place — the same
split as the Daily Attendance range. --}}
@props(['id', 'model', 'label', 'min' => null, 'max' => null, 'placeholder' => 'Select a date', 'clearable' => false])

@php
    $today = today()->format('Y-m-d');
    $todayAllowed = ($min === null || $today >= $min) && ($max === null || $today <= $max);
    $footerButton = 'rounded-lg px-2 py-1 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500';
@endphp

<div>
    <div
        class="relative hidden sm:block"
        wire:ignore
        x-data="datePicker({ mode: 'single', model: @js($model), today: @js(today()->format('Y-m-d')), min: @js($min), max: @js($max) })"
        @click.outside="closePanel(false)"
        {{-- Only while open: Escape then closes the picker, not the modal
        around it; with the picker closed, Escape reaches the modal as usual. --}}
        @keydown.escape="if (panelOpen) { $event.stopPropagation(); closePanel(); }"
    >
        <button
            type="button"
            id="{{ $id }}"
            x-ref="trigger"
            @click="togglePanel()"
            :aria-expanded="panelOpen"
            aria-haspopup="dialog"
            aria-controls="{{ $id }}-panel"
            :aria-label="@js($label) + ', ' + (displayLong || 'no date selected')"
            {{ $attributes->merge(['class' => 'inline-flex w-full items-center justify-between gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-sm leading-5 text-slate-900 shadow-sm transition hover:bg-slate-50 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-600 dark:bg-slate-750 dark:text-slate-100 dark:hover:bg-slate-600/30 dark:disabled:bg-slate-800 dark:disabled:text-slate-600']) }}
        >
            <span class="inline-flex min-w-0 items-center gap-2">
                <x-icon name="calendar-days" class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" />
                <span class="truncate tabular-nums" :class="display ? '' : 'text-slate-500 dark:text-slate-400'" x-text="display || @js($placeholder)"></span>
            </span>
            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" />
        </button>
        <p class="sr-only" aria-live="polite" x-text="announcement"></p>

        {{-- Fixed and placed against the trigger (datePicker's place()), so
        a modal's scrolling body can't clip it; it stays inside the modal's
        DOM, so the modal's focus trap still includes it. --}}
        <div
            id="{{ $id }}-panel"
            x-ref="panel"
            x-show="panelOpen"
            x-cloak
            role="dialog"
            aria-label="Choose a date: {{ $label }}"
            class="fixed z-50 w-80 max-w-[calc(100vw-2rem)] overflow-y-auto overscroll-contain rounded-xl bg-white p-4 pt-2 shadow-xl ring-1 ring-slate-200 dark:bg-slate-750 dark:ring-slate-600/40"
        >
            <x-date-picker.calendar />

            @if ($clearable || $todayAllowed)
                {{-- Footer: Clear on the left (nullable fields only), Today
                on the right (only when today is pickable). --}}
                <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-200/60 pt-3 dark:border-slate-600/15">
                    @if ($clearable)
                        <button type="button" @click="clear()" class="{{ $footerButton }} text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-600/30">Clear</button>
                    @else
                        <span></span>
                    @endif
                    @if ($todayAllowed)
                        <button type="button" @click="pickToday()" class="{{ $footerButton }} text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-600/35">Today</button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <input
        type="date"
        id="{{ $id }}-native"
        aria-label="{{ $label }}"
        wire:model="{{ $model }}"
        @if ($min) min="{{ $min }}" @endif
        @if ($max) max="{{ $max }}" @endif
        class="block w-full min-w-0 rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-750 dark:text-slate-100 sm:hidden"
    >
</div>
