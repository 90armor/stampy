@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'bg-white py-1 dark:bg-slate-750'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$width = match ($width) {
    '48' => 'w-48',
    default => $width,
};
@endphp

<div
    class="relative"
    x-data="{
        open: false,
        triggerEl: null,
        toggle() {
            if (this.open) {
                this.open = false;
                return;
            }

            this.triggerEl = document.activeElement;
            this.open = true;
        },
        close(restoreFocus = false) {
            this.open = false;

            if (restoreFocus) {
                const trigger = this.triggerEl;
                this.$nextTick(() => {
                    if (trigger && document.body.contains(trigger) && typeof trigger.focus === 'function') {
                        trigger.focus();
                    }
                });
            }
        },
    }"
    @click.outside="close()"
    @close.stop="close()"
    @keydown.escape.stop.prevent="close(true)"
>
    <div @click="toggle()">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute z-50 mt-2 {{ $width }} rounded-xl shadow-lg {{ $alignmentClasses }}"
            style="display: none;"
            @click="close()">
        <div class="rounded-xl ring-1 ring-black ring-opacity-5 dark:ring-white/10 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
