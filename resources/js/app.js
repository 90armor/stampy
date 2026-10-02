
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

// Date range picker (docs/ATTENDANCE_UI.md, "Date range picker"). A month grid
// for choosing a from/to range, bound to a Livewire component's fromDate and
// toDate properties — the same properties, values ('YYYY-MM-DD') and URL the
// native date inputs use, so the query and URL format don't change. Below
// 640px the markup shows native date inputs instead and this grid is hidden.
//
// Dates are handled as 'YYYY-MM-DD' strings with UTC arithmetic, so a DST
// shift in the browser's own timezone can never skip or repeat a day. "Today"
// comes from the server (the app timezone), not from the browser's clock.
//
// No member may share a name with a window global (open, close, status, name,
// focus, scroll, print…): if this file ever fails to load, Alpine resolves a
// name the markup uses against window instead, and @click.outside="close()"
// became window.close() — any click closed the browser tab.
const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const parseIso = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
};
const toIso = (date) => date.toISOString().slice(0, 10);
const addDays = (iso, n) => {
    const d = parseIso(iso);
    d.setUTCDate(d.getUTCDate() + n);
    return toIso(d);
};
const addMonths = (iso, n) => {
    // Clamp to the target month's length: 31 Jan + 1 month is 28/29 Feb.
    const d = parseIso(iso);
    const target = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(d.getUTCDate(), last));
    return toIso(target);
};
const isValidIso = (iso) => typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(iso) && toIso(parseIso(iso)) === iso;
// Matches App\Support\DisplayDate::long() ("Tuesday, 29 September 2026").
const longDate = (iso) => {
    const d = parseIso(iso);
    return `${WEEKDAYS[d.getUTCDay()]}, ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dateRangePicker', ({ today, from = 'fromDate', to = 'toDate' }) => ({
        panelOpen: false,
        today,
        focused: today, // the grid's single tab stop (roving tabindex)
        anchor: null, // the first day picked while a new range is in progress
        preview: null, // the day under the pointer or focus while anchored
        announcement: '', // announced politely to screen readers

        get from() {
            return this.$wire[from];
        },
        get to() {
            return this.$wire[to];
        },

        // The month on screen always follows the focused day.
        get monthLabel() {
            const d = parseIso(this.focused);
            return `${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
        },
        get weeks() {
            const d = parseIso(this.focused);
            const first = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
            const days = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
            const cells = Array(first.getUTCDay()).fill(null); // Sunday-first, like the employee calendar
            for (let day = 1; day <= days; day++) {
                cells.push({ iso: toIso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), day))), day, last: day === days });
            }
            while (cells.length % 7) cells.push(null);
            // Rows are keyed per month, so a month change renders fresh cells
            // (moveFocus() focuses the new day after that render). Position-only
            // keys were tried: Alpine then left tabindex stale on reused cells.
            const weeks = [];
            for (let i = 0; i < cells.length; i += 7) {
                weeks.push({ key: `${this.monthLabel}-${i}`, days: cells.slice(i, i + 7).map((cell, col) => cell && { ...cell, col }) });
            }
            return weeks;
        },

        // The range drawn on the grid: the committed one, or — while a new
        // range is in progress — anchor to the previewed day.
        get range() {
            if (this.anchor) {
                const other = this.preview ?? this.anchor;
                return other < this.anchor ? [other, this.anchor] : [this.anchor, other];
            }
            return isValidIso(this.from) && isValidIso(this.to) ? [this.from, this.to] : [null, null];
        },
        isStart(iso) {
            return iso === this.range[0];
        },
        isEnd(iso) {
            return iso === this.range[1];
        },
        inRange(iso) {
            const [start, end] = this.range;
            return start !== null && iso >= start && iso <= end;
        },
        // aria-selected reflects only what is actually chosen: the committed
        // range, or just the anchor while a new range is in progress.
        isSelected(iso) {
            return this.anchor ? iso === this.anchor : this.inRange(iso);
        },
        // The tint band between the endpoints: full width inside the range,
        // the inner half on an endpoint, nothing on a one-day range. Rounded
        // where the band meets the edge of a week row or of the month.
        bandClass(cell) {
            const [start, end] = this.range;
            if (start === null || start === end || !this.inRange(cell.iso)) return 'hidden';
            const left = cell.iso === start ? 'left-1/2' : 'left-0';
            const right = cell.iso === end ? 'right-1/2' : 'right-0';
            const roundL = cell.iso !== start && (cell.col === 0 || cell.day === 1) ? 'rounded-l-lg' : '';
            const roundR = cell.iso !== end && (cell.col === 6 || cell.last) ? 'rounded-r-lg' : '';
            return `${left} ${right} ${roundL} ${roundR}`;
        },
        label(iso) {
            return longDate(iso);
        },

        togglePanel() {
            this.panelOpen ? this.closePanel() : this.openPanel();
        },
        openPanel() {
            this.anchor = null;
            this.preview = null;
            this.announcement = '';
            this.focused = isValidIso(this.to) ? this.to : this.today;
            this.panelOpen = true;
        },
        // Escape and the presets return focus to the trigger; a click outside
        // leaves focus wherever the click put it.
        closePanel(restoreFocus = true) {
            if (!this.panelOpen) return;
            this.panelOpen = false;
            this.anchor = null;
            this.preview = null;
            if (restoreFocus) this.$nextTick(() => this.$refs.trigger.focus());
        },

        moveFocus(iso) {
            this.focused = iso;
            if (this.anchor) this.preview = iso;
            this.$nextTick(() => this.$refs.grid?.querySelector(`[data-date="${iso}"]`)?.focus());
        },
        shiftMonth(n) {
            this.focused = addMonths(this.focused, n);
        },
        onKeydown(event) {
            const moves = {
                ArrowLeft: () => addDays(this.focused, -1),
                ArrowRight: () => addDays(this.focused, 1),
                ArrowUp: () => addDays(this.focused, -7),
                ArrowDown: () => addDays(this.focused, 7),
                Home: () => addDays(this.focused, -parseIso(this.focused).getUTCDay()),
                End: () => addDays(this.focused, 6 - parseIso(this.focused).getUTCDay()),
                PageUp: () => addMonths(this.focused, event.shiftKey ? -12 : -1),
                PageDown: () => addMonths(this.focused, event.shiftKey ? 12 : 1),
            };
            if (moves[event.key]) {
                event.preventDefault();
                this.moveFocus(moves[event.key]());
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.pick(this.focused);
            }
        },

        // First pick anchors a new range; the second completes it (either
        // order — the earlier day becomes From) and commits both properties
        // in a single Livewire request. Picking the same day twice is a
        // one-day range.
        pick(iso) {
            this.focused = iso;
            if (!this.anchor) {
                this.anchor = iso;
                this.preview = iso;
                this.announcement = `Start date ${longDate(iso)}. Choose an end date.`;
                return;
            }
            const [start, end] = iso < this.anchor ? [iso, this.anchor] : [this.anchor, iso];
            this.$wire.$set(from, start, false);
            this.$wire.$set(to, end);
            this.announcement = start === end ? `Selected ${longDate(start)}.` : `Selected ${longDate(start)} to ${longDate(end)}.`;
            this.closePanel();
        },
    }));
});
