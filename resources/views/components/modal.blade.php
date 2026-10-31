@props([
    'name' => null,
    'show' => false,
    'maxWidth' => '2xl',
    'entangle' => null,
    'backdrop' => 'bg-slate-950/60 backdrop-blur-sm',
    'panelClass' => 'mb-6',
    // The accessible name when the modal has no visible title to point at.
    'label' => null,
    // The current step of a modal with more than one (form, review): a change
    // moves focus (docs/DESIGN_SYSTEM.md, Modals and focus).
    'step' => null,
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

// Built here, not with @if in the tag below: Livewire marks the block with a comment
// unless it can tell it's inside a tag, and the => in x-data's arrow functions fools it.
$accessibleName = $label ? 'aria-label="'.e($label).'"' : 'aria-labelledby="'.e($name).'-title"';

$showJs = $entangle ? "\$wire.entangle('{$entangle}').live" : \Illuminate\Support\Js::from($show);
@endphp

{{--
    x-teleport moves this element to be a direct child of <body> at runtime.
    Without it, a modal embedded inside an ancestor with backdrop-filter gets
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
{{--
    Focus (docs/DESIGN_SYSTEM.md, Modals and focus): opening always moves focus
    into the dialog (focusInitial()), Tab stays in it however the contents
    re-render (trap(), reclaim()), and closing follows one order
    (stampyFocus in resources/js/app.js). The trigger falls back to the last
    element focused outside any modal, tracked page-wide there: a modal can be
    rendered only once it opens, so a fresh instance starts with show already
    true and no history — hence also the `if (show) opened()` at init. No
    comments inside x-init: Alpine compiles it as an expression and a // line
    breaks it.
--}}
<template x-teleport="body">
<div
    x-data="{
        show: {{ $showJs }},
        triggerEl: null,
        focusContext: null,
        lastFocused: null,
        ready: false,
        observer: null,
        step: null,
        firstStep: null,
        focusables() {
            // All focusable element types...
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)]
                // Enabled and rendered: a hidden one (a date field's native
                // input above 640px) can't take focus and would stall Tab.
                .filter(el => ! el.hasAttribute('disabled') && el.offsetParent !== null)
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
        // Always move focus into the dialog on open: the visible field
        // marked autofocus, else the first visible focusable element. Without
        // this, a modal with no autofocus field (the calendar's day modal)
        // left focus on the page behind it, and Tab walked that page.
        // Livewire can flip show before it has rendered the modal's
        // fields, so keep trying for a few frames until something can take
        // focus (or the user has already moved it inside).
        // Where focus returns on close: the element focused when the modal
        // opened, or, when that is gone (wire:loading disabled the trigger
        // during the round trip), the last element focused outside any modal.
        rememberTrigger() {
            const active = document.activeElement;
            this.triggerEl = active && active !== document.body && ! $el.contains(active) ? active : window.stampyLastFocusOutsideModal;
        },
        opened() {
            this.rememberTrigger();
            this.focusContext = window.stampyFocus.capture(this.triggerEl);
            this.ready = false;
            document.body.classList.add('overflow-y-hidden');
            $nextTick(() => requestAnimationFrame(() => this.focusInitial()));
        },
        // Every way a modal closes: show flips (Escape, the backdrop, the
        // server's entangled value), or Livewire removes it from the page. The
        // same order either way (stampyFocus in resources/js/app.js), once the
        // render that closed it has finished.
        closed() {
            document.body.classList.remove('overflow-y-hidden');
            const context = this.focusContext;
            this.focusContext = null;
            if (context) setTimeout(() => window.stampyFocus.settle(context));
        },
        destroy() {
            this.observer?.disconnect();
            this.closed();
        },
        focusInitial(tries = 30) {
            if (! this.show) return;
            if ($el.contains(document.activeElement)) { this.ready = true; return; }
            const marked = [...$el.querySelectorAll('[autofocus]')].find(el => ! el.hasAttribute('disabled') && el.offsetParent !== null);
            const target = marked || this.firstFocusable();
            if (target) { target.focus(); this.ready = true; return; }
            if (tries > 0) { requestAnimationFrame(() => this.focusInitial(tries - 1)); return; }
            this.ready = true;
        },
        title() { return $el.querySelector('[data-modal-title]') },
        reclaim() { (this.title() || this.firstFocusable() || $el).focus() },
        // A confirmation opened from inside this modal sits on top of it, and owns focus.
        covered() {
            const other = document.activeElement?.closest('[aria-modal=\'true\']');
            return other && other !== $el && ! $el.contains(other);
        },
        // On window, not the dialog: with focus on <body> or the page behind,
        // a listener here would never hear the key.
        trap(event) {
            if (! this.show || this.covered()) return;
            event.preventDefault();
            if (! $el.contains(document.activeElement)) { (this.firstFocusable() || $el).focus(); return; }
            (event.shiftKey ? this.prevFocusable() : this.nextFocusable())?.focus();
        },
        currentStep() { return $el.querySelector('[data-modal-step]')?.dataset.modalStep },
        watchContents() {
            this.step = this.firstStep = this.currentStep();
            this.observer = new MutationObserver(() => this.contentsChanged());
            this.observer.observe($el, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-modal-step', 'disabled'] });
        },
        // A re-render can remove the focused element. A new step moves focus on
        // purpose: its heading, or going back, the first step's first field.
        contentsChanged() {
            if (! this.show || ! this.ready) return;
            const step = this.currentStep();
            if (step !== this.step) {
                this.step = step;
                $nextTick(() => step === this.firstStep ? this.firstFocusable()?.focus() : this.title()?.focus());
            } else if (! document.activeElement || document.activeElement === document.body) {
                // A button disabled for its round trip drops focus, and gets it
                // back when it's enabled again (a validation error, say).
                const last = this.lastFocused;
                if (! last || ! $el.contains(last)) { this.reclaim(); } else if (! last.disabled) { last.focus(); }
            }
        },
    }"
    x-init="
        watchContents();
        if (show) { opened(); }
        $watch('show', value => {
            if (value) {
                opened();
            } else {
                closed();
            }
        })
    "
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.window="trap($event)"
    x-on:focusin.window="if (show && ! covered() && ! $el.contains($event.target)) reclaim()"
    x-on:focusin="lastFocused = $event.target"
    x-show="show"
    role="dialog"
    aria-modal="true"
    {!! $accessibleName !!}
    tabindex="-1"
    class="fixed inset-0 overflow-y-auto overscroll-contain px-4 py-6 focus:outline-none sm:px-0 z-50"
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
        class="relative mx-auto {{ $panelClass }} rounded-2xl bg-white shadow-xl ring-1 ring-slate-border transform transition-all dark:bg-slate-750 sm:w-full {{ $maxWidth }}"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        @if ($step !== null)
            <span hidden data-modal-step="{{ $step }}"></span>
        @endif
        {{ $slot }}
    </div>
</div>
</template>
