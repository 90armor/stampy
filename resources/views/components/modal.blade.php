@props([
    'name' => null,
    'show' => false,
    'maxWidth' => '2xl',
    'entangle' => null,
    'surface' => 'glass',
    'backdrop' => 'bg-gray-500 opacity-75',
    'panelClass' => 'mb-6',
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    // Named rather than built from the raw pixel value: Tailwind's class
    // scanner only picks up arbitrary-value utilities that appear as a
    // literal token in source, so an interpolated "max-w-[{$px}]" would
    // silently produce no CSS. Add a name here for any one-off width.
    'employee-form' => 'sm:max-w-[620px]',
][$maxWidth];

$surfaceClass = match ($surface) {
    'solid' => 'bg-white ring-1 ring-slate-200 shadow-lg dark:bg-slate-900 dark:ring-slate-800/70',
    default => 'bg-white/80 backdrop-blur-xl dark:bg-slate-900/80 shadow-xl',
};

$showJs = $entangle ? "\$wire.entangle('{$entangle}').live" : \Illuminate\Support\Js::from($show);
@endphp

{{--
    x-teleport moves this element to be a direct child of <body> at runtime.
    Without it, a modal embedded inside anything with backdrop-blur/backdrop-
    filter (every x-card, and this app's cards are glass by design) gets
    clipped: per the CSS spec, a non-none backdrop-filter establishes a
    containing block for its fixed-position descendants, the same way
    transform/filter do — so this modal's "fixed inset-0" would resolve
    against that small card box instead of the viewport. Confirmed by
    reproducing it in isolation (a bare backdrop-filter div with a
    position:fixed child renders confined to the div, not the viewport) and
    confirming the teleported version doesn't. Employees\ScheduleAssignments'
    "Assign schedule" panel — nested inside x-card on both Employees\Show and
    the profile page — hit exactly this; every other modal in the app
    happened to dodge it only because its include sits beside its card, not
    inside one, which was luck, not something to keep relying on.
--}}
<template x-teleport="body">
<div
    x-data="{
        show: {{ $showJs }},
        triggerEl: null,
        focusables() {
            // All focusable element types...
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)]
                // All non-disabled elements...
                .filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="
        if (show) { triggerEl = document.activeElement; }
        $watch('show', value => {
            if (value) {
                triggerEl = document.activeElement;
                document.body.classList.add('overflow-y-hidden');
                {{ $attributes->has('focusable') ? 'setTimeout(() => firstFocusable().focus(), 100)' : '' }}
            } else {
                document.body.classList.remove('overflow-y-hidden');
                if (triggerEl && document.body.contains(triggerEl) && typeof triggerEl.focus === 'function') {
                    triggerEl.focus();
                }
                triggerEl = null;
            }
        })
    "
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="fixed inset-0 overflow-y-auto overscroll-contain px-4 py-6 sm:px-0 z-50"
    style="display: {{ $show ? 'block' : 'none' }};"
>
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all"
        x-on:click="show = false"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 {{ $backdrop }}"></div>
    </div>

    <div
        x-show="show"
        class="relative mx-auto {{ $panelClass }} {{ $surfaceClass }} rounded-lg overflow-hidden transform transition-all sm:w-full {{ $maxWidth }}"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
</div>
</template>
