{{-- The date picker's calendar: heading, arrows and the days / months / years
grids, shared by both modes of the datePicker Alpine component
(resources/js/app.js) — the Daily Attendance range picker and <x-date-picker>
for single dates. Must sit inside an element carrying x-data="datePicker(...)".
Alpine renders it, so Livewire's morph must leave it alone: the caller wraps it
in wire:ignore. Rules: docs/ATTENDANCE_UI.md, Date picker. --}}
@php
    $pickerNavButton = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-slate-400 dark:hover:bg-slate-600/30 dark:hover:text-slate-100';
    // The only ring in the picker is keyboard focus, offset so it also reads
    // on a filled endpoint.
    $pickerFocus = 'group-focus-visible:ring-2 group-focus-visible:ring-primary-500 group-focus-visible:ring-offset-2 group-focus-visible:ring-offset-white dark:group-focus-visible:ring-primary-400 dark:group-focus-visible:ring-offset-slate-750';
    // Dark mode: primary-500 is 2.75:1 against the popover and 2.20:1 against
    // the range band, so a 1px inset primary-400 edge (4.08:1 / 3.27:1) draws
    // the boundary. A shadow, not ring-inset, so the focus ring stays outside.
    $pickerFill = 'bg-primary-600 font-semibold text-white dark:bg-primary-500 dark:shadow-[inset_0_0_0_1px_theme(colors.primary.400)]';
    $pickerTint = 'bg-primary-50 font-medium text-primary-700 dark:bg-primary-600/35 dark:text-primary-200';
    $pickerPlain = 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-600/30';
    $pickerCurrent = 'font-semibold text-primary-700 hover:bg-slate-100 dark:text-primary-300 dark:hover:bg-slate-600/30';
    // A day outside the field's allowed dates (min/max): muted, not pickable.
    $pickerDisabled = 'cursor-not-allowed text-slate-300 dark:text-slate-600';
    // Month and year cells: a 3-wide grid of buttons-in-cells.
    $pickerZoomCell = 'relative flex h-10 w-full items-center justify-center rounded-lg text-sm transition '.$pickerFocus;
@endphp
<div x-ref="picker" @keydown="onKeydown($event)" {{ $attributes }}>
    <div class="mt-2 flex items-center justify-between">
        <button type="button" @click="step(-1)" :aria-label="stepLabels[0]" class="{{ $pickerNavButton }}">
            <x-icon name="chevron-left" class="h-4 w-4" />
        </button>
        <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
            <button
                type="button"
                x-show="view !== 'years'"
                @click="zoomOut()"
                :aria-label="zoomLabel"
                class="inline-flex items-center gap-1 rounded-lg px-2 py-1 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:hover:bg-slate-600/30"
            >
                <span x-text="heading"></span>
                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
            </button>
            <span x-show="view === 'years'" class="inline-block px-2 py-1 tabular-nums" x-text="heading"></span>
        </h3>
        <button type="button" @click="step(1)" :aria-label="stepLabels[1]" class="{{ $pickerNavButton }}">
            <x-icon name="chevron-right" class="h-4 w-4" />
        </button>
    </div>
    {{-- The grid's label, announced politely as it changes
    (paging months, zooming out or in). --}}
    <span class="sr-only" aria-live="polite" x-text="gridLabel"></span>

    {{-- Days --}}
    <table x-show="view === 'days'" role="grid" :aria-label="gridLabel" class="mt-2 w-full table-fixed border-collapse">
        <thead>
            <tr>
                @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday)
                    <th scope="col" abbr="{{ $weekday }}" class="pb-1 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <span aria-hidden="true">{{ substr($weekday, 0, 2) }}</span>
                        <span class="sr-only">{{ $weekday }}</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody @mouseleave="anchor && (preview = focused)">
            <template x-for="week in weeks" :key="week.key">
                <tr>
                    <template x-for="(cell, col) in week.days" :key="col">
                        <td
                            class="group relative h-10 p-0 text-center focus:outline-none"
                            :class="cell && ! isDisabled(cell.iso) && 'cursor-pointer'"
                            :data-date="cell ? cell.iso : null"
                            :tabindex="cell ? (cell.iso === focused ? 0 : -1) : null"
                            :aria-selected="cell ? String(isSelected(cell.iso)) : null"
                            :aria-current="cell && cell.iso === today ? 'date' : null"
                            :aria-disabled="cell && isDisabled(cell.iso) ? 'true' : null"
                            :aria-label="cell ? label(cell.iso) : null"
                            @click="cell && pick(cell.iso)"
                            @mouseenter="cell && anchor && (preview = cell.iso)"
                            @focus="cell && (focused = cell.iso)"
                        >
                            <template x-if="cell">
                                <div aria-hidden="true">
                                    <span class="pointer-events-none absolute inset-y-0.5 bg-primary-50 dark:bg-primary-600/35" :class="bandClass(cell)"></span>
                                    <span
                                        class="relative mx-auto flex h-9 w-9 items-center justify-center rounded-lg text-sm tabular-nums transition {{ $pickerFocus }}"
                                        :class="isDisabled(cell.iso)
                                            ? '{{ $pickerDisabled }}'
                                            : isStart(cell.iso) || isEnd(cell.iso)
                                            ? '{{ $pickerFill }}'
                                            : (inRange(cell.iso)
                                                ? 'font-medium text-primary-700 dark:text-primary-200'
                                                : (cell.iso === today ? '{{ $pickerCurrent }}' : '{{ $pickerPlain }}'))"
                                    >
                                        <span x-text="cell.day"></span>
                                        {{-- Today's quiet marker; white (dark: slate-900) on a fill. --}}
                                        <span
                                            x-show="cell.iso === today"
                                            class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full"
                                            :class="isStart(cell.iso) || isEnd(cell.iso) ? 'bg-white' : 'bg-primary-600 dark:bg-primary-400'"
                                        ></span>
                                    </span>
                                </div>
                            </template>
                        </td>
                    </template>
                </tr>
            </template>
        </tbody>
    </table>

    {{-- Months --}}
    <table x-show="view === 'months'" x-cloak role="grid" :aria-label="gridLabel" class="mt-2 w-full table-fixed border-separate border-spacing-1">
        <tbody>
            <template x-for="row in monthRows" :key="row.key">
                <tr>
                    <template x-for="cell in row.cells" :key="cell.key">
                        <td
                            class="group cursor-pointer p-0 focus:outline-none"
                            :data-month="cell.key"
                            :tabindex="cell.key === focusedMonthKey ? 0 : -1"
                            :aria-selected="String(holdsEndpoint(cell))"
                            :aria-current="cell.key === today.slice(0, 7) ? 'date' : null"
                            :aria-label="cell.name"
                            @click="pickMonth(cell.key)"
                            @focus="focused = sameDayInMonth(cell.key)"
                        >
                            <span
                                aria-hidden="true"
                                class="{{ $pickerZoomCell }}"
                                :class="holdsEndpoint(cell)
                                    ? '{{ $pickerFill }}'
                                    : (touchesRange(cell) ? '{{ $pickerTint }}' : (isCurrentMonth(cell) ? '{{ $pickerCurrent }}' : '{{ $pickerPlain }}'))"
                            >
                                <span x-text="cell.label"></span>
                                <span
                                    x-show="isCurrentMonth(cell)"
                                    class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full"
                                    :class="holdsEndpoint(cell) ? 'bg-white' : 'bg-primary-600 dark:bg-primary-400'"
                                ></span>
                            </span>
                        </td>
                    </template>
                </tr>
            </template>
        </tbody>
    </table>

    {{-- Years --}}
    <table x-show="view === 'years'" x-cloak role="grid" :aria-label="gridLabel" class="mt-2 w-full table-fixed border-separate border-spacing-1">
        <tbody>
            <template x-for="row in yearRows" :key="row.key">
                <tr>
                    <template x-for="cell in row.cells" :key="cell.year">
                        <td
                            class="group cursor-pointer p-0 focus:outline-none"
                            :data-year="cell.year"
                            :tabindex="cell.year === focusedYear ? 0 : -1"
                            :aria-selected="String(holdsEndpoint(cell))"
                            :aria-current="String(cell.year) === today.slice(0, 4) ? 'date' : null"
                            :aria-label="String(cell.year)"
                            @click="pickYear(cell.year)"
                            @focus="focused = sameDayInYear(cell.year)"
                        >
                            <span
                                aria-hidden="true"
                                class="{{ $pickerZoomCell }} tabular-nums"
                                :class="holdsEndpoint(cell)
                                    ? '{{ $pickerFill }}'
                                    : (touchesRange(cell) ? '{{ $pickerTint }}' : (isCurrentYear(cell) ? '{{ $pickerCurrent }}' : '{{ $pickerPlain }}'))"
                            >
                                <span x-text="cell.year"></span>
                                <span
                                    x-show="isCurrentYear(cell)"
                                    class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full"
                                    :class="holdsEndpoint(cell) ? 'bg-white' : 'bg-primary-600 dark:bg-primary-400'"
                                ></span>
                            </span>
                        </td>
                    </template>
                </tr>
            </template>
        </tbody>
    </table>

    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400" x-text="hint"></p>
</div>
