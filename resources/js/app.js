
// Alpine.js is provided by Livewire's bundled copy (via @livewireScripts in the
// layouts) — importing a second, separate `alpinejs` package here causes Livewire
// to detect "multiple instances of Alpine running" and silently stops wire:click /
// wire:model directives from binding on any page with a Livewire component.

document.addEventListener('alpine:init', () => {
    // Pinned trailing table columns (.table-pin in resources/css/app.css). Put on
    // a table's overflow-x-auto container. Keeps two facts in sync with layout
    // and scrolling: the width of the last column, so the Status column pins
    // exactly to its left, and whether content is currently hidden under the
    // pinned columns — the only time their left edge shadow shows.
    window.Alpine.data('pinnedColumns', () => ({
        init() {
            this.measure();
            this.observer = new ResizeObserver(() => this.measure());
            this.observer.observe(this.$el);
            const table = this.$el.querySelector('table');
            if (table) this.observer.observe(table);
        },
        destroy() {
            this.observer?.disconnect();
        },
        measure() {
            const el = this.$el;
            const end = el.querySelector('thead th:last-child');
            if (end) el.style.setProperty('--pin-end-width', `${end.offsetWidth}px`);
            el.classList.toggle('table-pin-overflowing', el.scrollLeft + el.clientWidth < el.scrollWidth - 1);
        },
    }));
});
