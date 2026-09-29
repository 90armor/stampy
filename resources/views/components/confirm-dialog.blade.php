{{-- Single shared alert dialog per page, opened by dispatching a `confirm-dialog` browser
event (see x-confirm-trigger below). Deliberately NOT nested inside an <x-card> or any other
backdrop-blur/transform ancestor — those establish a CSS containing block for `position: fixed`
descendants, which traps the overlay inside that ancestor's box instead of the viewport.

Pass a distinct `event` name when more than one instance of this dialog is mounted on the same
page at once (e.g. two nested Livewire components tab-switched with Alpine `x-show`, both staying
mounted in the DOM) — otherwise every instance shares the same `window`-scoped listener and a
delete click in one tab silently arms the other tab's (hidden) dialog too. --}}
@props(['event' => 'confirm-dialog'])

@php
    $dialogId = Str::slug($event);
@endphp

<div
    x-data="{
        open: false,
        title: '',
        message: '',
        confirmText: 'Confirm',
        method: null,
        args: [],
        triggerEl: null,
        focusables() {
            return [...this.$refs.panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex=\'-1\'])')]
                .filter((element) => ! element.hasAttribute('disabled'));
        },
        trapTab(event) {
            const focusable = this.focusables();
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (! first || ! last) return;

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
        close() {
            this.open = false;

            const trigger = this.triggerEl;
            this.triggerEl = null;

            this.$nextTick(() => {
                if (trigger && document.body.contains(trigger) && typeof trigger.focus === 'function') {
                    trigger.focus();
                }
            });
        },
        proceed() {
            if (this.method) { $wire.call(this.method, ...this.args); }
            this.close();
        },
    }"
    x-on:{{ $event }}.window="
        triggerEl = document.activeElement;
        title = $event.detail.title;
        message = $event.detail.message ?? '';
        confirmText = $event.detail.confirmText ?? 'Confirm';
        method = $event.detail.method;
        args = $event.detail.args ?? [];
        open = true;
        $nextTick(() => $refs.cancel.focus());
    "
>
    <div
        x-show="open"
        x-cloak
        @keydown.escape.window="close()"
        class="fixed inset-0 z-50 flex items-center justify-center px-4"
    >
        <div class="fixed inset-0 bg-slate-900/50" @click="close()" x-show="open" x-transition.opacity></div>

        <div
            x-ref="panel"
            x-show="open"
            x-transition
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="{{ $dialogId }}-title"
            aria-describedby="{{ $dialogId }}-description"
            @keydown.tab="trapTab($event)"
            class="relative w-full max-w-sm"
        >
            {{-- An alert dialog is a large overlay panel, so it intentionally
            overrides x-card's content-surface radius with the modal radius. --}}
            <x-card class="!rounded-2xl">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                        <x-icon name="exclamation-triangle" class="w-5 h-5" />
                    </div>
                    <div class="flex-1 pt-1.5">
                        <h3 id="{{ $dialogId }}-title" class="text-base font-semibold text-slate-900 dark:text-slate-100" x-text="title"></h3>
                        <p id="{{ $dialogId }}-description" class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-show="message" x-text="message"></p>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <x-button x-ref="cancel" type="button" variant="secondary" @click="close()">Cancel</x-button>
                    <x-button type="button" variant="danger" x-on:click="proceed">
                        <span x-text="confirmText"></span>
                    </x-button>
                </div>
            </x-card>
        </div>
    </div>
</div>
