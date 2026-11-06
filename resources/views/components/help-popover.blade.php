{{-- A question-mark button that explains something in a small popover
(Phase 6i follow-up, docs/DESIGN_SYSTEM.md, Overlays → Help popover). Never
hover-only: it opens on a click or tap and closes on a second one, on Escape
(focus back on the button) and on a click outside. The popover is a live
region, empty while closed, so its text is announced as it appears; the
button names it with aria-controls and says whether it's open with
aria-expanded. 20px icon, 28px button, 44px below 640px. `label` names the
button; the slot is the popover's text. --}}
@props(['label'])

@php $panelId = 'help-'.substr(md5($label), 0, 8); @endphp

<span
    x-data="{ open: false }"
    x-on:keydown.escape="if (open) { open = false; $refs.button.focus(); $event.stopPropagation(); }"
    x-on:click.outside="open = false"
    {{ $attributes->class('relative inline-flex') }}
>
    <button
        type="button"
        x-ref="button"
        x-on:click="open = ! open"
        aria-label="{{ $label }}"
        aria-controls="{{ $panelId }}"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 max-sm:-m-2 max-sm:h-11 max-sm:w-11 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-200"
    >
        <x-icon name="question-mark-circle" class="h-5 w-5" />
    </button>
    <span id="{{ $panelId }}" role="status" class="absolute left-0 top-full z-20 mt-2">
        <template x-if="open">
            <span data-help-popover class="block w-64 max-w-[calc(100vw-4rem)] rounded-xl bg-white p-3 text-sm font-normal text-slate-700 shadow-lg ring-1 ring-slate-border dark:bg-slate-750 dark:text-slate-200">{{ $slot }}</span>
        </template>
    </span>
</span>
