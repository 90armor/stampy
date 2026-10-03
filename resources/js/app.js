
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

// Date picker (docs/ATTENDANCE_UI.md, "Date picker"). One component, two modes,
// one calendar (resources/views/components/date-picker/calendar.blade.php):
// - mode 'range': the Daily Attendance range, bound to the Livewire fromDate
//   and toDate properties — the same values ('YYYY-MM-DD') and URL the native
//   inputs used.
// - mode 'single': <x-date-picker>, one date bound to one Livewire property
//   (`model`), written deferred like a plain wire:model, with optional
//   min/max ('YYYY-MM-DD') mirroring the field's server-side rule.
// Below 640px the markup shows native date inputs instead of this calendar.
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
const YEARS_PER_PAGE = 12;
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
// Matches App\Support\DisplayDate::compact() ("Tue 29 Sep", with the year
// only outside the current year: "Mon 3 Feb 2025").
const compactDate = (iso, currentYear) => {
    const d = parseIso(iso);
    const year = d.getUTCFullYear() === currentYear ? '' : ` ${d.getUTCFullYear()}`;
    return `${WEEKDAYS[d.getUTCDay()].slice(0, 3)} ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()].slice(0, 3)}${year}`;
};
const isValidIso = (iso) => typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(iso) && toIso(parseIso(iso)) === iso;
// Matches App\Support\DisplayDate::long() ("Tuesday, 29 September 2026").
const longDate = (iso) => {
    const d = parseIso(iso);
    return `${WEEKDAYS[d.getUTCDay()]}, ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('datePicker', ({ mode = 'range', today, presets = {}, from = 'fromDate', to = 'toDate', model = null, min = null, max = null }) => ({
        mode,
        panelOpen: false,
        today,
        min, // the first and last pickable dates, or null
        max,
        presets, // { key: { label, from, to } } — Attendance\Index::presetRanges()
        // Which grid is on screen. 'days' is where a range is picked; the
        // heading zooms out to 'months' and then 'years' for long jumps, and
        // picking a year or a month zooms back in.
        view: 'days',
        focused: today, // the active cell in every view (roving tabindex): a day, its month, its year
        anchor: null, // the first day picked while a new range is in progress
        preview: null, // the day under the pointer or focus while anchored
        announcement: '', // announced politely to screen readers

        // A single date is a one-day range: from and to are the same value,
        // so the calendar's endpoint, band and zoom treatments need no
        // single-date branch.
        get from() {
            return this.$wire[this.mode === 'single' ? model : from];
        },
        get to() {
            return this.$wire[this.mode === 'single' ? model : to];
        },
        // The single-date trigger's text and accessible name.
        get display() {
            return isValidIso(this.from) ? compactDate(this.from, parseIso(this.today).getUTCFullYear()) : '';
        },
        get displayLong() {
            return isValidIso(this.from) ? longDate(this.from) : '';
        },
        isDisabled(iso) {
            return (this.min !== null && iso < this.min) || (this.max !== null && iso > this.max);
        },

        // What is on screen always follows the focused day.
        get focusedYear() {
            return parseIso(this.focused).getUTCFullYear();
        },
        get focusedMonthKey() {
            return this.focused.slice(0, 7);
        },
        get yearBlockStart() {
            return Math.floor(this.focusedYear / YEARS_PER_PAGE) * YEARS_PER_PAGE;
        },
        get monthLabel() {
            const d = parseIso(this.focused);
            return `${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
        },
        get heading() {
            if (this.view === 'months') return String(this.focusedYear);
            if (this.view === 'years') return `${this.yearBlockStart}–${this.yearBlockStart + YEARS_PER_PAGE - 1}`;
            return this.monthLabel;
        },
        get gridLabel() {
            if (this.view === 'months') return `Months of ${this.focusedYear}`;
            if (this.view === 'years') return `Years ${this.yearBlockStart} to ${this.yearBlockStart + YEARS_PER_PAGE - 1}`;
            return this.monthLabel;
        },
        get zoomLabel() {
            return this.view === 'days' ? `${this.monthLabel}. Choose month and year` : `${this.focusedYear}. Choose year`;
        },
        get stepLabels() {
            if (this.view === 'months') return ['Previous year', 'Next year'];
            if (this.view === 'years') return [`Previous ${YEARS_PER_PAGE} years`, `Next ${YEARS_PER_PAGE} years`];
            return ['Previous month', 'Next month'];
        },
        // While a range is in progress the hint names its start, which may
        // be months or years away from what is on screen.
        get hint() {
            if (this.mode === 'single') {
                return { days: 'Select a date', months: 'Choose a month', years: 'Choose a year' }[this.view];
            }
            const from = this.anchor ? `From ${compactDate(this.anchor, parseIso(this.today).getUTCFullYear())} · ` : '';
            if (this.view === 'months') return this.anchor ? `${from}choose the end date's month` : 'Choose a month';
            if (this.view === 'years') return this.anchor ? `${from}choose the end date's year` : 'Choose a year';
            return this.anchor ? `${from}select an end date` : 'Select a start date';
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
        // Months and years are 3-wide grids. Each cell knows its first and
        // last possible day, so "does the range touch it" is a string compare
        // ('-31' is past every real day of any month).
        get monthRows() {
            const y = this.focusedYear;
            const cells = MONTHS.map((name, i) => {
                const key = `${y}-${String(i + 1).padStart(2, '0')}`;
                return { key, label: name.slice(0, 3), name: `${name} ${y}`, first: `${key}-01`, last: `${key}-31` };
            });
            return [0, 3, 6, 9].map((i) => ({ key: `${y}-${i}`, cells: cells.slice(i, i + 3) }));
        },
        get yearRows() {
            const start = this.yearBlockStart;
            const cells = Array.from({ length: YEARS_PER_PAGE }, (_, i) => {
                const year = start + i;
                return { year, first: `${year}-01-01`, last: `${year}-12-31` };
            });
            return [0, 3, 6, 9].map((i) => ({ key: `${start}-${i}`, cells: cells.slice(i, i + 3) }));
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
        // A month or year the range reaches into, so a long range stays
        // visible while zoomed out.
        touchesRange(cell) {
            const [start, end] = this.range;
            return start !== null && cell.first <= end && cell.last >= start;
        },
        // The days actually chosen: the committed from/to, or only the anchor
        // while a new range is in progress (a previewed end isn't chosen).
        get chosen() {
            if (this.anchor) return [this.anchor];
            return isValidIso(this.from) && isValidIso(this.to) ? [this.from, this.to] : [];
        },
        // Zoomed out, a month or year is "selected" only when it contains a
        // chosen endpoint — compared on the full date, so the anchor's month
        // never looks selected in another year.
        holdsEndpoint(cell) {
            return this.chosen.some((iso) => iso >= cell.first && iso <= cell.last);
        },
        isCurrentMonth(cell) {
            return cell.key === this.today.slice(0, 7);
        },
        isCurrentYear(cell) {
            return String(cell.year) === this.today.slice(0, 4);
        },
        // A quick range reads as selected while the applied range — or the
        // pending one, while a new range is in progress — equals it.
        presetActive(key) {
            const preset = this.presets[key];
            const [start, end] = this.range;
            return Boolean(preset) && start === preset.from && end === preset.to;
        },
        applyPreset(key) {
            this.$wire.setRange(key);
            this.closePanel();
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
            this.view = 'days';
            let start = isValidIso(this.to) ? this.to : this.today;
            if (this.min !== null && start < this.min) start = this.min;
            if (this.max !== null && start > this.max) start = this.max;
            this.focused = start;
            this.panelOpen = true;
            this.$nextTick(() => this.place());
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

        // The popover is position: fixed, placed against the trigger, so no
        // scrolling ancestor can clip it (the employee form scrolls its own
        // body). Under the trigger when it fits, else above, else whichever
        // side has more room, with the panel scrolling inside that space. A
        // fixed element is placed relative to its containing block, which a
        // transformed ancestor (the modal panel) changes: measuring where
        // top/left 0 lands gives that origin, whatever it is.
        place() {
            const panel = this.$refs.panel, trigger = this.$refs.trigger;
            if (!this.panelOpen || !panel || !trigger) return;
            const gap = 8, margin = 16;
            panel.style.maxHeight = '';
            panel.style.top = '0px';
            panel.style.left = '0px';
            const origin = panel.getBoundingClientRect();
            const t = trigger.getBoundingClientRect();
            const h = panel.offsetHeight, w = panel.offsetWidth;
            const below = window.innerHeight - t.bottom - gap - margin;
            const above = t.top - gap - margin;
            let top;
            if (h <= below || below >= above) {
                top = t.bottom + gap;
                if (h > below) panel.style.maxHeight = `${Math.max(below, 160)}px`;
            } else {
                top = t.top - gap - Math.min(h, above);
                if (h > above) panel.style.maxHeight = `${above}px`;
            }
            const left = Math.min(Math.max(t.left, margin), window.innerWidth - margin - w);
            panel.style.top = `${top - origin.top}px`;
            panel.style.left = `${left - origin.left}px`;
        },
        init() {
            this.reflow = () => this.panelOpen && this.place();
            window.addEventListener('scroll', this.reflow, true);
            window.addEventListener('resize', this.reflow);
        },
        destroy() {
            window.removeEventListener('scroll', this.reflow, true);
            window.removeEventListener('resize', this.reflow);
        },

        // Focus the active cell of whichever grid is on screen, once it has
        // rendered.
        focusActiveCell() {
            this.$nextTick(() => {
                const selector = {
                    days: `[data-date="${this.focused}"]`,
                    months: `[data-month="${this.focusedMonthKey}"]`,
                    years: `[data-year="${this.focusedYear}"]`,
                }[this.view];
                this.$refs.picker?.querySelector(selector)?.focus();
            });
        },
        moveFocus(iso) {
            this.focused = iso;
            if (this.anchor && this.view === 'days') this.preview = iso;
            this.focusActiveCell();
        },
        // The heading's arrows: a month, a year or a page of years.
        step(n) {
            this.focused = addMonths(this.focused, n * { days: 1, months: 12, years: 12 * YEARS_PER_PAGE }[this.view]);
        },
        zoomOut() {
            this.view = this.view === 'days' ? 'months' : 'years';
            this.focusActiveCell();
        },
        // Moving between months and years keeps the day of the month,
        // clamped (31 Jan → 28/29 Feb).
        sameDayInMonth(key) {
            const [y, m] = key.split('-').map(Number);
            const d = parseIso(this.focused);
            return addMonths(this.focused, (y - d.getUTCFullYear()) * 12 + (m - 1 - d.getUTCMonth()));
        },
        sameDayInYear(year) {
            return addMonths(this.focused, (year - this.focusedYear) * 12);
        },
        pickYear(year) {
            this.focused = this.sameDayInYear(year);
            this.view = 'months';
            this.focusActiveCell();
        },
        pickMonth(key) {
            this.focused = this.sameDayInMonth(key);
            this.view = 'days';
            this.focusActiveCell();
        },

        onKeydown(event) {
            // Only the grids' cells; the heading and arrow buttons keep their
            // native keys (Enter on the heading must zoom, not pick a day).
            if (event.target.tagName !== 'TD') return;
            const f = this.focused;
            const col = this.view === 'months' ? parseIso(f).getUTCMonth() % 3 : (this.focusedYear - this.yearBlockStart) % 3;
            // Months and years move by month-steps: a year is 12, a row of
            // years is 36, a page of years is 12 × YEARS_PER_PAGE.
            const unit = this.view === 'years' ? 12 : 1;
            const moves = this.view === 'days'
                ? {
                    ArrowLeft: () => addDays(f, -1),
                    ArrowRight: () => addDays(f, 1),
                    ArrowUp: () => addDays(f, -7),
                    ArrowDown: () => addDays(f, 7),
                    Home: () => addDays(f, -parseIso(f).getUTCDay()),
                    End: () => addDays(f, 6 - parseIso(f).getUTCDay()),
                    PageUp: () => addMonths(f, event.shiftKey ? -12 : -1),
                    PageDown: () => addMonths(f, event.shiftKey ? 12 : 1),
                }
                : {
                    ArrowLeft: () => addMonths(f, -unit),
                    ArrowRight: () => addMonths(f, unit),
                    ArrowUp: () => addMonths(f, -3 * unit),
                    ArrowDown: () => addMonths(f, 3 * unit),
                    Home: () => addMonths(f, -col * unit),
                    End: () => addMonths(f, (2 - col) * unit),
                    PageUp: () => addMonths(f, -12 * (this.view === 'years' ? YEARS_PER_PAGE : 1)),
                    PageDown: () => addMonths(f, 12 * (this.view === 'years' ? YEARS_PER_PAGE : 1)),
                };
            if (moves[event.key]) {
                event.preventDefault();
                this.moveFocus(moves[event.key]());
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (this.view === 'days') this.pick(f);
                else if (this.view === 'months') this.pickMonth(this.focusedMonthKey);
                else this.pickYear(this.focusedYear);
            }
        },

        // First pick anchors a new range; the second completes it (either
        // order — the earlier day becomes From) and commits both properties
        // in a single Livewire request. Picking the same day twice is a
        // one-day range. Zooming out between the two picks keeps the anchor.
        // Single mode's footer. Today picks today (the footer only offers it
        // when today is pickable); Clear, on nullable fields only, empties the
        // value — the trigger falls back to its placeholder.
        pickToday() {
            this.pick(this.today);
        },
        clear() {
            this.$wire.$set(model, null, false);
            this.announcement = 'Date cleared.';
            this.closePanel();
        },
        pick(iso) {
            if (this.isDisabled(iso)) return;
            this.focused = iso;
            if (this.mode === 'single') {
                // Deferred, like the plain wire:model it replaces: the value
                // travels with the form's next request.
                this.$wire.$set(model, iso, false);
                this.announcement = `Selected ${longDate(iso)}.`;
                this.closePanel();
                return;
            }
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

// Time input (docs/DESIGN_SYSTEM.md, "Time input"; <x-time-input>). Typed
// segments — hour, minute, AM/PM — each an accessible spinbutton, plus a
// popover of hour / minute (5-minute steps) / AM-PM columns. Bound to one
// Livewire property as 'HH:MM' (24-hour), written deferred like the plain
// wire:model it replaces; displays the app's 12-hour time format. Below 640px
// the markup shows a native time input instead.
//
// Bounds are 'HH:MM' strings, compared lexically: `min`/`max`, `after` (the
// name of another property this time must be later than, e.g. end after
// start) and `capDate` + `nowCap` (no later than now while that date
// property is today — a manual punch). The server rule stays the authority:
// an out-of-range typed time is kept and flagged aria-invalid.
const pad2 = (n) => String(n).padStart(2, '0');
const to24 = (hour, minute, meridiem) => `${pad2((hour % 12) + (meridiem === 'PM' ? 12 : 0))}:${pad2(minute)}`;
const plusMinute = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    const total = Math.min(h * 60 + m + 1, 23 * 60 + 59);
    return `${pad2(Math.floor(total / 60))}:${pad2(total % 60)}`;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('timeInput', ({ model, min = null, max = null, after = null, capDate = null, nowCap = null, today = null, disabled = false }) => ({
        hour: null, // 1–12
        minute: null, // 0–59
        meridiem: null, // 'AM' | 'PM'
        disabled,
        popoverOpen: false,
        buffer: '', // digits typed so far into the focused segment
        hours: [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
        minutes: Array.from({ length: 12 }, (_, i) => i * 5),

        init() {
            // Follow the property whenever it changes (a modal opened for another record, a reset).
            window.Alpine.effect(() => this.load(this.$wire[model]));
            this.reflow = () => this.popoverOpen && this.place();
            window.addEventListener('scroll', this.reflow, true);
            window.addEventListener('resize', this.reflow);
        },
        destroy() {
            window.removeEventListener('scroll', this.reflow, true);
            window.removeEventListener('resize', this.reflow);
        },
        load(value) {
            if (typeof value !== 'string' || !/^\d{2}:\d{2}/.test(value)) {
                if (!value) [this.hour, this.minute, this.meridiem] = [null, null, null];
                return;
            }
            const [h, m] = value.slice(0, 5).split(':').map(Number);
            if (this.value === value.slice(0, 5)) return;
            this.hour = h % 12 === 0 ? 12 : h % 12;
            this.minute = m;
            this.meridiem = h < 12 ? 'AM' : 'PM';
        },

        get value() {
            return this.hour !== null && this.minute !== null && this.meridiem !== null ? to24(this.hour, this.minute, this.meridiem) : null;
        },
        get minTime() {
            const fromAfter = after && typeof this.$wire[after] === 'string' && this.$wire[after].length >= 5 ? plusMinute(this.$wire[after].slice(0, 5)) : null;
            return [min, fromAfter].filter(Boolean).sort().pop() ?? null;
        },
        get maxTime() {
            const fromNow = capDate && this.$wire[capDate] === today ? nowCap : null;
            return [max, fromNow].filter(Boolean).sort()[0] ?? null;
        },
        allowed(hhmm) {
            return (this.minTime === null || hhmm >= this.minTime) && (this.maxTime === null || hhmm <= this.maxTime);
        },
        get invalid() {
            return this.value !== null && !this.allowed(this.value);
        },
        // Bounds as words, for the group's description.
        get boundsText() {
            const fmt = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return `${h % 12 === 0 ? 12 : h % 12}:${pad2(m)} ${h < 12 ? 'AM' : 'PM'}`; };
            return [this.minTime && `from ${fmt(this.minTime)}`, this.maxTime && `until ${fmt(this.maxTime)}`].filter(Boolean).join(', ');
        },
        get display() {
            return this.value === null ? '' : `${this.hour}:${pad2(this.minute)} ${this.meridiem}`;
        },

        commit() {
            // Only a whole time is written; an emptied field writes ''.
            if (this.value !== null) this.$wire.$set(model, this.value, false);
            else if (this.hour === null && this.minute === null && this.meridiem === null) this.$wire.$set(model, '', false);
        },
        segments() {
            return [...this.$refs.segments.querySelectorAll('[role="spinbutton"]')];
        },
        focusSegment(index) {
            this.buffer = '';
            this.segments()[Math.max(0, Math.min(2, index))]?.focus();
        },
        onSegmentKeydown(event, segment) {
            if (this.disabled) return;
            const index = ['hour', 'minute', 'meridiem'].indexOf(segment);
            const key = event.key;
            if (key === 'Tab') return;
            if (key === 'ArrowLeft') { event.preventDefault(); return this.focusSegment(index - 1); }
            if (key === 'ArrowRight') { event.preventDefault(); return this.focusSegment(index + 1); }
            if (key === 'ArrowUp' || key === 'ArrowDown') {
                event.preventDefault();
                this.stepSegment(segment, key === 'ArrowUp' ? 1 : -1);
                return this.commit();
            }
            if (key === 'Backspace' || key === 'Delete') {
                event.preventDefault();
                this[segment] = null;
                this.buffer = '';
                return this.commit();
            }
            if (segment === 'meridiem') {
                if (/^[aA]$/.test(key)) { event.preventDefault(); this.meridiem = 'AM'; return this.commit(); }
                if (/^[pP]$/.test(key)) { event.preventDefault(); this.meridiem = 'PM'; return this.commit(); }
                return;
            }
            if (!/^\d$/.test(key)) return;
            event.preventDefault();
            const digit = Number(key);
            if (segment === 'hour') {
                if (this.buffer === '1' && digit <= 2) { this.hour = 10 + digit; this.focusSegment(1); }
                else if (this.buffer === '0' && digit >= 1) { this.hour = digit; this.focusSegment(1); }
                else if (digit >= 2) { this.hour = digit; this.focusSegment(1); }
                else { this.buffer = String(digit); if (digit === 1) this.hour = 1; }
            } else {
                if (this.buffer !== '') { this.minute = Number(this.buffer) * 10 + digit; this.focusSegment(2); }
                else if (digit >= 6) { this.minute = digit; this.focusSegment(2); }
                else { this.buffer = String(digit); this.minute = digit; }
            }
            // A new time typed into an empty field defaults to AM, as a native input does.
            if (this.meridiem === null && this.hour !== null && this.minute !== null) this.meridiem = 'AM';
            this.commit();
        },
        stepSegment(segment, n) {
            if (segment === 'hour') this.hour = this.hour === null ? (n > 0 ? 1 : 12) : ((this.hour - 1 + n + 12) % 12) + 1;
            if (segment === 'minute') this.minute = this.minute === null ? 0 : (this.minute + n + 60) % 60;
            if (segment === 'meridiem') this.meridiem = this.meridiem === 'AM' ? 'PM' : 'AM';
        },

        // Popover: three listboxes. Picking a minute completes a time and closes it.
        togglePopover() {
            if (this.disabled) return;
            this.popoverOpen ? this.closePopover() : this.openPopover();
        },
        openPopover() {
            this.popoverOpen = true;
            this.$nextTick(() => {
                this.place();
                (this.$refs.popover.querySelector('[aria-selected="true"]') ?? this.$refs.popover.querySelector('[role="option"]'))?.focus();
            });
        },
        closePopover(restoreFocus = true) {
            if (!this.popoverOpen) return;
            this.popoverOpen = false;
            if (restoreFocus) this.$nextTick(() => this.$refs.toggle.focus());
        },
        optionAllowed(column, option) {
            const h = column === 'hour' ? option : (this.hour ?? 12);
            const m = column === 'minute' ? option : (this.minute ?? 0);
            const mer = column === 'meridiem' ? option : (this.meridiem ?? 'AM');
            if (column === 'hour') return this.minutes.some((mm) => this.allowed(to24(h, mm, mer))) || this.allowed(to24(h, m, mer));
            if (column === 'meridiem') return this.hours.some((hh) => this.allowed(to24(hh, 0, mer)) || this.allowed(to24(hh, 59, mer)));
            return this.allowed(to24(h, m, mer));
        },
        choose(column, option) {
            if (!this.optionAllowed(column, option)) return;
            this[column] = option;
            if (this.hour === null) this.hour = 12;
            if (this.minute === null && column !== 'minute') this.minute = 0;
            if (this.meridiem === null) this.meridiem = 'AM';
            this.commit();
            if (column === 'minute') this.closePopover();
        },
        onOptionKeydown(event, column, option) {
            const cols = [...this.$refs.popover.querySelectorAll('[role="listbox"]')];
            const col = event.target.closest('[role="listbox"]');
            const options = [...col.querySelectorAll('[role="option"]')];
            const i = options.indexOf(event.target);
            const moves = { ArrowDown: () => options[Math.min(i + 1, options.length - 1)], ArrowUp: () => options[Math.max(i - 1, 0)], Home: () => options[0], End: () => options[options.length - 1] };
            if (moves[event.key]) { event.preventDefault(); return moves[event.key]().focus(); }
            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                event.preventDefault();
                const next = cols[cols.indexOf(col) + (event.key === 'ArrowRight' ? 1 : -1)];
                return (next?.querySelector('[aria-selected="true"]') ?? next?.querySelector('[role="option"]'))?.focus();
            }
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.choose(column, option); }
        },
        // Fixed, placed against the field like the date picker's popover, so a modal's scrolling body can't clip it.
        place() {
            const panel = this.$refs.popover, anchor = this.$refs.field;
            if (!this.popoverOpen || !panel || !anchor) return;
            const gap = 8, margin = 16;
            panel.style.top = '0px';
            panel.style.left = '0px';
            const origin = panel.getBoundingClientRect(), a = anchor.getBoundingClientRect();
            const h = panel.offsetHeight, w = panel.offsetWidth;
            const below = window.innerHeight - a.bottom - gap - margin, above = a.top - gap - margin;
            const top = h <= below || below >= above ? a.bottom + gap : a.top - gap - Math.min(h, above);
            const left = Math.min(Math.max(a.left, margin), window.innerWidth - margin - w);
            panel.style.top = `${top - origin.top}px`;
            panel.style.left = `${left - origin.left}px`;
        },
    }));
});
