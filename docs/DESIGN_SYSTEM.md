# Stampy Design System v1

Attendance-specific presentation and responsive-table guidance lives in [Attendance UI](ATTENDANCE_UI.md).

This document is the source of truth for Stampy's visual interface. It records conventions supported by the current Laravel, Blade, Livewire, Alpine.js, Tailwind CSS, and Heroicons implementation. It is a foundation for incremental page modernization, not a mandate for a whole-application redesign.

## Principles

1. Clarity over decoration. Attendance and HR data must remain easy to scan.
2. Consistency over novelty. Extend shared Blade components before creating page-specific patterns.
3. Readability over visual effects. Content surfaces are opaque; glass is a shell treatment only.
4. Semantic meaning over arbitrary color. Choose a color because of its role or status meaning.
5. Accessible at rest. Controls and links cannot depend on hover to appear interactive.
6. Incremental change. Modernize representative pages against this system before expanding it.

## Color system

`tailwind.config.js` retains the existing palettes. Design System v1 does not add semantic Tailwind utility names.

- `primary-*`: deep evergreen. Primary actions, active navigation, links, and focus emphasis.
- `accent-*`: mint. Brand marks, restrained highlights, and the Leave status; not a primary-action substitute.
- `slate-*`: Stampy's neutral scale — warm stone in light mode, cool zinc in dark mode (see Neutral scale, below). Page, surface, border, and text hierarchy.
- Status colors: green, amber, violet, red, slate, accent, blue, and fuchsia retain the meanings defined below.

### Neutral scale

**Light neutrals are warm stone; dark neutrals are cool zinc.** Both are the one `slate` scale, implemented as CSS variables: `resources/css/app.css` defines `--slate-50` … `--slate-950` (plus the in-between `--slate-750`) as RGB channels, with Tailwind's stone values on `:root` and its zinc values under `.dark`, and `tailwind.config.js` reads every step as `rgb(var(--slate-N) / <alpha-value>)`. A class names a step; the theme picks the hue. Opacity modifiers, `@apply` and `theme()` all resolve through the same variables, and any colour set outside a class (the dashboard chart, the autofill surface) reads `--slate-N` too. The `750` step is the OKLab midpoint of 800 and 700 in each family.

**Why two hues.** Warm hues that look like paper in light mode read as brown at dark-mode lightness. Measured on the rendered dark surfaces, stone sat at a hue of 12–24° with 6–10% HSL saturation (card 12° / 6.5%, page 24° / 9.8%), and the owner read it as brown. Zinc at the same lightness sits at 240° with 4–6% saturation (card 240° / 3.7%), close to the neutral, slightly cool dark of macOS (window `#1e1f21`, grouped box `#252628`, both about 220–240° and 4–5%). Light mode keeps stone because the warm cream is part of the brand; switching the dark hue changed no light-mode pixel.

**Always use the `slate` scale for neutrals.** New code must never use `stone-*`, `zinc-*`, `neutral-*` (or `gray-*`) classes or hard-coded neutral hex values: they bypass the variables, so they would show the wrong hue in one of the two themes. If a neutral is needed outside a class, read `--slate-N`. `NeutralScaleTest` fails the build on a stone/zinc/neutral class in the app's views, scripts or styles.

### Semantic roles

| Semantic role | Light implementation | Dark implementation | Usage |
|---|---|---|---|
| Text / Primary | `text-slate-900` | `dark:text-slate-100` | Headings and primary readable content |
| Text / Secondary | `text-slate-700` | `dark:text-slate-300` | Supporting body content and controls |
| Text / Muted | `text-slate-500` | `dark:text-slate-400` | **All readable muted text**: metadata, hints, helper text, summary notes, column and weekday headers, role labels, voided rows |
| Icon / Subtle | `text-slate-400` | `dark:text-slate-500` | **Decorative icons only** — never text. Light `slate-400` is 2.52:1 on white and dark `slate-500` 3.08:1 on the card, both under 4.5:1 |
| Surface / Page | `bg-slate-100` | `dark:bg-slate-900` | Application and authentication background |
| Surface / Card | `bg-white` | `dark:bg-slate-800` | Cards, table containers, information panels |
| Surface / Control | `bg-white` | `dark:bg-slate-750` | Inputs, selects, textareas, compact controls |
| Surface / Overlay panel | `bg-white` | `dark:bg-slate-750` | Dropdowns, popovers, modal panels — one step above the card |
| Line / Border | `ring-slate-border` or `border-slate-border` | same (the token switches with the theme) | Surface edges and controls (see Lines) |
| Line / Divider | `border-slate-divider`, `divide-slate-divider` or `bg-slate-divider` | same | Every line inside a surface (see Lines) |
| Action / Primary | `bg-primary-600 text-white` | same | Main action on a page or flow |
| Action / Secondary | `bg-white text-slate-700` | `dark:bg-slate-800 dark:text-slate-200` | Supporting actions |
| Action / Danger | `text-red-600` with red boundary/tint | `dark:text-red-400` | Destructive actions |
| Focus / Interactive | `ring-primary-500` | `dark:ring-primary-500` | Keyboard focus and focused controls |

**Muted text rule, measured.** Readable muted text is `slate-500` in light mode and `slate-400` in dark mode; light `slate-400` (and dark `slate-500`) are for decorative icons only. Light `slate-500`: 4.80:1 on white cards, 4.59:1 on a `slate-50` row hover, **4.40:1 on the `slate-100` page** — just under AA, where page subtitles, "Back to …" links and inactive Organization tabs sit (recorded for Phase 5). Dark `slate-400`: 5.81:1 on the card, 4.90:1 on overlays and controls (`slate-750`), 5.28:1 on a hovered row, 6.91:1 on the page. Disabled controls (the sidebar's "Soon" items, a disabled button) are exempt from the contrast minimum and keep their quieter tones; they are a Phase 5 item too. Decorative marks that carry no information — the table em-dash, a day outside the shown month, a disabled picker option — may use `slate-300`.

Attendance color is model-driven. `DailyAttendance::displayVariant()` is the source of truth and is status-only: present green, incomplete violet, absent red, off slate, leave accent, in progress blue, and holiday fuchsia. Views must not independently derive these buckets. Status is reinforced with text or shape, never color alone.

**Color = status only; co-occurring attributes are annotations.** A cell or badge color encodes one value per record — its status. Attributes that can co-occur with that status on the same record (attendance timing exceptions now, partial leave later) are annotations inside the cell, never the cell or badge color. For attendance timing the annotation is the timing text color (`amber-700` light, `amber-300` dark, medium weight) on the specific value; see [Attendance UI](ATTENDANCE_UI.md). In dense grids (the attendance calendar) **fill emphasizes exceptions**: the common, expected status (Present) uses the neutral surface with a colored number and icon, and only exception statuses get a tinted fill; a non-working state (Off) is quieter still — no fill and a dashed boundary.

## Typography

- Inter is the primary UI font through `font-sans`, with system sans-serif fallbacks.
- DM Serif Display is limited to the auth hero's editorial accent through `font-serif`.
- Default body and control copy is `text-sm`; supporting metadata is `text-xs` or `text-sm`.
- Page titles use `text-2xl font-semibold tracking-tight` on every page, the dashboard included. Section and card headings use sentence case ("Quick actions", not "Quick Actions").
- Dates go through `App\Support\DisplayDate`, in day-month order, in exactly three forms:
  - **compact** `Tue 29 Sep` — tables, stat strip and card meta, lists, detail fields, and inline dates in notices and messages;
  - **range** `23–29 Sep`, `28 Sep – 3 Oct` — the date picker trigger, strip scope and card meta for a span (a one-day range is compact);
  - **long** `Tuesday, 29 September 2026` — page subtitles, modal titles, and accessible labels.
  Compact and range show the year only when it isn't the current year (`Mon 20 May 2024`, `28 Dec 2025 – 3 Jan 2026`); long always includes it. Do not call `->format()` for display elsewhere, and do not pair a weekday eyebrow with a date — compact already carries the weekday. A month heading such as `September 2026` names a month, not a date, and stays as is.
- Numeric attendance values use `tabular-nums` when alignment helps comparison. Durations use the single compact format from `App\Support\Duration` (`21m`, `1h 20m`); see [Attendance UI](ATTENDANCE_UI.md).
- Avoid introducing arbitrary font families, tiny critical copy, or long uppercase labels.

## Spacing and layout

Use Tailwind's spacing scale and favor the established rhythm:

- Page container: `max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8`.
- Standard card padding: `p-6`; compact/stat card padding: `p-4`.
- Form groups: `space-y-5` or `space-y-6`; label-to-control spacing: `mt-1`; error spacing: `mt-2`.
- Common inline gaps: `gap-2` or `gap-3`; section gaps: `gap-4` to `gap-6`.
- Table cells: `px-6 py-4`; table headings: `px-6 py-3`.

Do not add arbitrary pixel spacing until the standard scale demonstrably cannot express the requirement.

## Radius and elevation

- `rounded-md`: badges and compact status elements.
- `rounded-lg`: inputs, selects, textareas, buttons, and other controls.
- `rounded-xl`: cards, dropdown menus, and popovers.
- `rounded-2xl`: modal and large overlay panels.
- `rounded-full`: geometry that is intentionally circular, including avatars, dots, progress tracks, and circular icon containers; not status badges or control styling.
- Cards use `shadow-sm` plus a ring. Separation comes from the surface step first: white cards on a `slate-100` page in light mode. In dark mode a shadow is invisible on near-black, so the card edge is the `border` line token (`ring-slate-border`, 1.47:1 against the dark page) around the `slate-800` card on the `slate-900` page; do not drop it for a shadow.
- Dropdowns and modal panels use `shadow-lg` or `shadow-xl` because they float above content.
- Elevation communicates hierarchy, not decoration. Normal cards do not gain elevation or a colored ring on hover unless the entire card is interactive.

## Dark surfaces

Dark mode is one ladder of steps on the cool zinc neutrals (see Neutral scale). Every layer is defined relative to the card, so a change to the card moves everything with it. The page step stays clearly below the card on purpose: Stampy's layout is cards on a page, and that separation is what the eye uses to find them, so the page is not lifted to match the macOS window (`#1e1f21`).

| Layer | Dark value | Notes |
|---|---|---|
| Page | `slate-900` (dark `#18181b`) | |
| Card | `slate-800` (dark `#27272a`) | card vs page 1.19:1 |
| Card ring | the `border` line token | a shadow can't separate a card from a dark page |
| Overlay (dropdown, popover, modal) and control | `slate-750` (dark `#333338`; OKLab midpoint of 800 and 700) | one step above the card |
| Divider | the `divider` line token | see Lines |
| Row hover | `slate-750/60` over the card | pinned table cells layer the same tint over the card |
| Hover on a control or overlay | `slate-600/30` | translucent: it sits on cards and overlays, which share the control step |
| Control border | the `border` line token | see Lines |
| Selected tint | `primary-600/35` | the shared selected state and the date picker's range band (1.25:1 against the popover) |

Muted text is `slate-400`: 5.81:1 on the card, 4.90:1 on overlays and controls, 5.28:1 on a hovered row. Every status badge passes 4.5:1 on the card and on a hovered row (lowest: Absent on a hovered row, 4.70:1).

## Lines

There are two line levels, and every neutral line uses one of them. Both are tokens on the `slate` scale, defined as CSS variables in `resources/css/app.css` (`--slate-divider`, `--slate-border`), so a class names the level and the theme supplies the colour:

| Token | Use | Classes | Light | Dark |
|---|---|---|---|---|
| `divider` | Every line **inside** a surface: table row lines, card section lines, list dividers, the stat strip's dividers, modal header and footer lines, the calendar's cell edges, the dashboard chart's grid | `border-slate-divider`, `divide-slate-divider`, `bg-slate-divider` (on an `h-px`/`w-px` rule), `ring-slate-divider` | stone ink at 11% — **1.25:1** on the white card | zinc ink at 9% — **1.27:1** on the card |
| `border` | **Edges**: the card ring, popover, dropdown and modal edges, the sidebar and topbar edges, boxed list items; and **controls**: inputs, selects, textareas, the date-picker trigger, outline buttons, unselected chips, pagination items, checkboxes | `ring-slate-border`, `border-slate-border` | stone ink at 19% — **1.49:1** on the white card | zinc ink at 15% — **1.51:1** on the card |

- **Why two levels.** A line inside a surface separates content that already belongs together, so it should recede; an edge or a control boundary says "this is a separate thing" or "this takes input", so it is a step stronger. The dark divider matches the chart grid the owner liked (sampled `#38383c` on `#27272a`, 1.28:1); light mode mirrors it at 1.25:1.
- **Translucent ink, so one value works on every surface.** A solid divider one step above the card would vanish on overlays, which sit on that same step. Ink at a fixed opacity keeps both tokens' contrast within a few hundredths on the card, the page and an overlay (dark divider 1.24–1.28:1, dark border 1.49–1.52:1). The ink is warm stone in light mode and cool zinc in dark, like the neutrals. Measured on the rendered page: divider 1.25:1 (light) / 1.27:1 (dark) against the card; the card ring 1.49:1 / 1.47:1 against the page; an input border 1.48:1 / 1.77:1 against the card (higher in dark because the border composites over the input's own lighter fill).
- **No other line values.** Don't add a numbered slate step, an opacity modifier or a third level for a line; the tokens take no opacity modifier. Two exceptions, both outside the line system: the slate status badge's ring (`ring-slate-500/10`, dark `/20`) belongs to the badge colour system, like every status badge's ring, and the day modal's Off pill mirrors it; a disabled checked checkbox's `border-slate-500` is its fill, not a line. `NeutralScaleTest` fails on any other numbered slate line class.
- **The chart reads the token.** The dashboard chart's grid is `--slate-divider`, so it can't drift from the table lines.

## Surface architecture

Surface role determines treatment; pages do not choose between glass and solid variants.

### Shell surfaces

The sidebar, topbar, and authentication shell may use translucency and `backdrop-blur`. `.bg-shell` supplies the restrained decorative background that makes this treatment legible. Keep the effect subtle and retain clear borders. `.bg-shell` is a light-mode treatment only: in dark mode it paints nothing. A glow tinted the page between cards and, even confined to the top-left corner, put a green cast into the glass sidebar and topbar; the dark shell is the plain neutral page step.

### Content surfaces

Cards, stat cards, table containers, information panels, controls, dropdown menus, popovers, and modal panels are solid. Use `bg-white`/`dark:bg-slate-800` for container surfaces, `bg-white`/`dark:bg-slate-750` for overlays (dropdowns, popovers, modal panels — one step above the card) and for controls. Do not add `backdrop-blur` to content components.

### Overlay treatment

The modal backdrop may use transparency and `backdrop-blur-sm`. This is an overlay treatment, not a glass content surface. The panel above it remains solid.

## Components

- `<x-card>` is the standard solid `rounded-xl` content container. Use `:padding="false"` when a table or custom edge-to-edge layout owns its inner spacing.
- `<x-button>` owns primary, secondary, and danger action styling. Breeze primary and secondary button wrappers delegate to it.
- `<x-badge>` owns compact `rounded-md` status labels. Badges never wrap (`whitespace-nowrap`): a multi-word status such as "In progress" stays on one line. Add variants only when a stable semantic state requires a distinct meaning.
- Pagination uses the themed Livewire override at `resources/views/vendor/livewire/tailwind.blade.php`: warm slate borders and surfaces, the primary focus ring, `rounded-lg`, 36px items, and the shared selected state (see Interaction states) for the current page, which carries `aria-current="page"`. Do not fall back to Livewire's stock view, whose Tailwind `gray`/`blue` classes are off-palette and read as stray blue borders in dark mode.
- `<x-text-input>`, `<x-select>`, and `<x-textarea>` own form-control visuals and are always solid.
- `<x-dropdown>` owns menu positioning, transitions, and its solid `rounded-xl` menu surface.
- `<x-modal>` owns the backdrop, focus trap, focus restoration, transitions, and solid `rounded-2xl` panel. It remains teleported to `<body>`. The panel deliberately does not use `overflow-hidden`: modal bodies that need scrolling own it locally, and panel-level clipping can cut focus rings or overlay content.
- `<x-stat-card>` is one cell of the **stat strip**, the only pattern for a row of headline figures (Dashboard, Attendance, Employees). A strip is one `<x-card :padding="false">` holding a `<dl>` grid of `<x-stat-card>` cells separated by dividers — never a card per figure and never tinted tiles nested in a card. A strip has exactly **three** cells and stays one row with vertical dividers (`grid-cols-3 divide-x`) at every width, including a 390px phone. Never wrap cells into a multi-row grid: an odd cell left alone, or a divider that runs only part of the way, is the failure this rule prevents; a strip that seems to need a fourth figure should move it into the header meta (as the dashboard does with its headcount). Cells and the strip's header row use `px-6`, the standard card padding, so the figures line up with other cards' titles. Every cell has the same treatment: a neutral 32px `rounded-lg` icon tile (`bg-slate-100 text-slate-500`, dark `bg-slate-800 text-slate-400`; shown from `lg`, where every cell has room for it), a `text-xs font-medium` muted label, a `text-xl font-semibold tabular-nums` value, and an optional `text-xs` muted subtext. Cell content is top-aligned, so a sub-line in one cell never shifts the label and value of its neighbours. Icons are neutral signifiers, not status colors. A strip states its scope using the card header pattern: a header row above the cells, inset to the cells' padding, with the scope as right-aligned muted meta (for example `1–29 Sep · all statuses`) — never a small label pinned in the top-left corner. This matters most when the figures don't follow the page's filters.
- `<x-empty-state>` provides an icon, title, optional description, and optional action.
- `<x-time-input>` is every time field (manual punch time, schedule start and end): see Time input, under Forms. Never use a bare `type="time"` input.
- `<x-date-picker>` is every date field: the shared date picker in single mode, with a native date input below 640px. Never use a bare `type="date"` input. Its calendar (`<x-date-picker.calendar>`) is also the Daily Attendance range picker's. In pickers, today is a quiet marker rather than the employee calendar's filled circle, because a fill marks a chosen date. Rules in [Attendance UI](ATTENDANCE_UI.md#date-picker).
- `<x-icon>` is the only Heroicons entry point. Add icons there rather than embedding a second icon system.
- **Icon size.** One size for interface icons: **20px** (`h-5 w-5`, also the component's default when no size is given) — buttons, inputs, navigation, row actions, stat cards, alerts, the calendar's arrows. Exceptions, and only these:
  - **14px (`h-3.5 w-3.5`) inside a status pill or filter chip** — the day modal's status pill and the check in a selected filter chip. A 20px glyph crowds a 28px pill; the chips' fixed size (docs/ATTENDANCE_UI.md, Filters) is built on the 14px check.
  - **The calendar cell's status icon: 20px, 16px from `sm`** (`h-5 w-5 sm:h-4 sm:w-4`), where the cell also carries the times.
  - Larger decorative icons (`h-6 w-6`, empty-state badges and the like).

  Write the size at the call site (`h-5 w-5`) when in doubt; never a smaller size outside the exceptions. A caller's size replaces the 20px default rather than joining it — they used to be merged, and since Tailwind emits `h-5` after `h-4`/`h-3.5`, every icon that asked for 16px quietly rendered at 20px, so the code said one size and the screen showed another. `IconTest` checks both the component and every call site.

Prefer composition over adding props that expose implementation choices. Props should express genuine behavior or content, not optional design-system rules.

### Card header pattern

**No eyebrows, anywhere.** A small uppercase, letter-spaced kicker above a title ("People directory", "Account", "Welcome back") is not used on pages, cards, auth screens or the auth hero. A card whose only heading was such a label gets a real sentence-case title instead (`Details`, `Login`, `Schedule`). Section labels inside forms, modals and popovers stay but use sentence case: `text-sm font-semibold` for a form section (`Identity`, `Working hours`), `text-xs font-medium` muted for a small group label (`Quick ranges`, `From`). Uppercase remains only for table column headers, calendar weekday headers and the compact `Soon` chip.

A card that needs a header uses one pattern: the title (`text-lg font-semibold`) on the left and optional right-aligned muted meta (`text-xs text-slate-500 dark:text-slate-400`, `tabular-nums`) on the right, baseline-aligned. Cards carry no eyebrow labels. When a card's content has a time scope, put it in the meta slot as real dates (`Tue 29 Sep`, `23–29 Sep`), not a relative eyebrow such as "Today" or "Last seven days". A count that summarizes the card (Needs attention's `7 today`) also goes in the meta slot, as muted text rather than a colored badge.

### Avatars

Initial avatars use one neutral tint everywhere — `bg-slate-100 text-slate-600`, dark `bg-slate-800 text-slate-300` — in tables, lists, headers and the topbar. Avatars never carry status or rotating decorative hues: amber, violet, red and similar colors are reserved for attendance meaning, and an avatar that borrows them competes with the status column.

### Dashboard

The dashboard is the stat strip followed by two independent column stacks (a wide main column and a side column at least `21rem` wide: `xl:grid-cols-[minmax(0,2fr)_minmax(21rem,1fr)]`). Each column flows at its own height, so cards of different heights never leave vertical holes; do not return to a row-based grid where each row takes its tallest card's height. Two columns start at `xl` (1280px); below it the stacks merge into one reading order with Needs attention first. A 4-of-12 side column at `lg` was about 224px at 1024px and cut Needs attention's names to a few characters — a name list needs its width. The attendance trend is a bar chart of the attendance rate per day — attended (present + incomplete) ÷ active employees — in `primary-500`, the same green as the department bars, so the page has one data-visualization green. Every day carries a value, a marker or both: `Off`/`Holiday` for a non-working day, `Today`/`Pending` with a lighter provisional bar for a day still open, `Not calculated` for a past day the builder hasn't finished, `0%` for a closed workday nobody attended. Pending figures are never shown as 0% or as an absence; departments show counts (`Checked in N / M` while pending, `Attended N / M` once closed), never a bare percentage. See [Attendance UI](ATTENDANCE_UI.md).

### Entity detail pages

Entity detail pages are operational records, not dashboards or profile heroes. Lead with a compact identity header, then place structured metadata and primary operational state in the main reading area while lower-density account or related-workflow sections may use a narrower supporting column on desktop. Preserve a single logical mobile reading order, use description lists for labeled metadata, and keep management, contextual, and navigation actions visually distinct and permission-aware.

## Interaction states

- Every interactive element has a visible rest state and a keyboard focus indicator. Equivalent primitives use the existing primary/evergreen palette for a clear, no-layout-shift `focus-visible` ring; the treatment respects the component type rather than forcing the same border construction onto buttons, links, fields, and navigation.
- Hover may strengthen an existing affordance; it must not reveal the only action or link cue.
- Pressed (`:active`) feedback is temporary and distinct from focus and selected/current state. Pointer activation must not create a decorative persistent ring.
- **Selected state** is one shared control state, not an action, used by filter chips, segmented toggles (Calendar/Table) and the current pagination page: `bg-primary-50 text-primary-700 font-semibold` with a primary border (`ring-primary-600`, or `border-primary-600` on the bordered pagination items; dark: `bg-primary-600/35 text-primary-200` with a `primary-500` border), plus a check where the control is a multi-select chip. Unselected is a neutral outline at `font-medium`. Each carries its semantic state attribute: `aria-pressed` on chips and toggles, `aria-current="page"` on the current page. Never use a solid primary fill for selection; it must not out-weigh the page's primary action. A default filter state must read as "no filter applied" rather than as many selected chips.
- **Filled selected state** marks a single chosen value inside a picker — a date picker endpoint, a time input's chosen hour/minute/AM-PM — and the employee calendar's today circle: white semibold text on `primary-600` (6.53:1) in light mode, on `primary-500` in dark mode (4.57:1 for the text; the fill is 3.26:1 against the card). It is the one place a solid primary fill marks selection: a single value in a dense grid, where the tinted selected state above would read as a range. In dark mode inside a popover (the date picker and the time input) the fill alone is too close to its surroundings — 2.75:1 against the `slate-750` popover and 2.20:1 against the range band — so it gets a 1px inset `primary-400` edge, dark mode and popovers only: 4.08:1 against the popover and 3.27:1 against the band. It is an inset shadow (`dark:shadow-[inset_0_0_0_1px_theme(colors.primary.400)]`), not `ring-inset`, because Tailwind's ring variables are shared and an inset ring would pull the keyboard-focus ring inside the cell too. The employee calendar's today circle sits on the card, where the fill already reaches 3.26:1, and has no edge.
- Disabled controls use reduced contrast and `cursor-not-allowed` where appropriate, while remaining readable. Loading actions remain disabled against repeat submission and retain meaningful copy or an accessible loading indicator.
- Text-entry and selection controls may retain a visible `focus` border/ring while being edited; action controls and links prefer `focus-visible` so keyboard focus is prominent without adding unnecessary pointer-click persistence.
- Destructive actions use red semantics and require confirmation when the effect is material.
- Row navigation must be keyboard reachable. A row whose record has a detail destination may use a trailing chevron link as its only navigation target — visible at rest, at least 40px, with an `aria-label` naming the record — leaving identity text plain and every cell selectable (the Attendance tables do this). Otherwise a cell link uses visible primary-colored underlined text; a fully clickable row uses cursor, hover, and `focus-visible` treatment.
- **Tooltips** label icon-only row actions and links (Employees, Holidays, the Daily Attendance chevron). One pattern: wrap the control in `<span class="group/action relative inline-flex">` and follow it with a `role="tooltip"` span — `rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white` (dark: `bg-slate-100 text-slate-900`), above the control (`bottom-full right-0 mb-2`), `opacity-0` until `group-hover/action:opacity-100` **and** `group-focus-within/action:opacity-100`, so keyboard focus shows it exactly as hover does. Never a native `title` attribute, which shows on hover only, after a delay, and never on focus. The tooltip is a visible label, not the accessible name: the control keeps its own `aria-label`.
- Underline is reserved for links. Never underline a value to mark it (a timing exception, an error, an emphasis); use color plus weight or an explicit label instead.
- Preserve user-entered state during Livewire updates and restore focus after modal dismissal.

## Forms

Labels sit above controls and are programmatically associated with them. Controls use a solid surface, `rounded-lg`, a default border, `text-sm`, and primary focus rings. Validation errors appear directly below the field in red and fields expose `aria-invalid`/`aria-describedby` when applicable.

### Control height

One height for every control that sits in a row with others: `h-control` (`2.375rem`, 38px — `tailwind.config.js`, `theme.extend.height.control`). `<x-text-input>`, `<x-select>`, `<x-button>` (every variant, `<button>` and `<a>`), the date picker's single and range triggers, the time input's segmented field and every native fallback (`type="date"`/`type="time"` below 640px) carry it, as do the raw search and date inputs in the Attendance and Employees filter bars. The v1 audit found 36px buttons beside 38px inputs; with one token a filter bar, the manual-punch row and a modal footer line up without per-call fixes. `ControlHeightTest` renders each component and scans every view's raw `<input>`/`<select>` for it.

- A control never grows a second line: content is single-line (`whitespace-nowrap` on buttons and on the time input's segments, including the empty `--:-- --` placeholder), and a call site that's too narrow for its content gets a wider width class, not a taller box. The manual-punch form's time column is at least `10rem` for this reason.
- Set the height, not vertical padding: a control with `h-control` keeps its horizontal padding and centres its content (`items-center` or the form plugin's own line box).
- Not controls, so not `h-control`: `<x-textarea>` (multi-line by nature), compact chips such as the date picker's presets, 36px pagination items, and square icon buttons in rows and fields (`h-9 w-9`, the 40px row chevron, the time input's 28px popover toggle). The auth pages keep their 44px touch targets by adding `min-h-11` on top, which wins over the token.

### Time input

`<x-time-input id model label [min] [max] [after] [cap-at-now-when] [disabled]>` (`timeInput` in `resources/js/app.js`) looks like the date picker's trigger — the control surface, `border` line token, `rounded-lg`, `text-sm`, a leading clock icon — and shows the app's time format (`8:02 AM`, `config('attendance.time_format')`). The stored value and its validation are unchanged: one Livewire property as `H:i`, written deferred like the plain `wire:model` it replaces.

- **Typing stays enabled.** Hour, minute and AM/PM are separate segments, each a `role="spinbutton"` with a label (`Hour`, `Minutes`, `AM/PM`) and `aria-valuenow`/`aria-valuetext`. Digits auto-advance (`9` → minutes; `1` waits for a second digit; two minute digits → AM/PM), `A`/`P` set the meridiem, ArrowUp/ArrowDown change the focused segment, ArrowLeft/Right move between segments, Backspace clears one. A time typed into an empty field defaults to AM, as the native input does. The segment group is labelled by the field's label, which carries `id="{id}-label"`.
- **Popover.** The trailing button — it keeps the field's `id`, so clicking the `<x-input-label for>` opens it — shows Hour (1–12), Minute (5-minute steps; exact minutes by typing) and AM/PM listboxes. The chosen value has the shared filled selected state (`primary-600` + white, dark `primary-500` + white, as the date picker's chosen date); arrows move within and across columns, Enter or Space picks, picking a minute completes the time and closes it, and Escape closes it and returns focus to the button (with the popover closed, Escape reaches the modal as usual). It is `position: fixed` and placed against the field, like the date picker, so a modal's scrolling body can't clip it.
- **Bounds** mirror the field's rule: `min`/`max` (`H:i`), `after` (another property this time must be later than — the schedule's end after its start) and `cap-at-now-when` (a date property; while it is today, no later than now — a manual punch). Options outside them are muted and `aria-disabled`; a typed time outside them is kept, flagged `aria-invalid` with a red border, and described (`from 9:01 AM`) — the server rule stays the authority.
- **Locked** (`disabled`, e.g. a referenced schedule): shown on the muted control surface, not focusable or editable.
- **Below 640px** a native time input on the same property, with the same `min`/`max`, takes its place.

### Selection controls

Native checkboxes and radio buttons form one selection-control family. Enabled unchecked controls use neutral solid surfaces and borders; enabled checked controls use the primary evergreen fill and retain their native checkmark or dot in both light and dark mode. Disabled controls stay in the neutral palette so evergreen consistently signals an active, editable selection: disabled unchecked controls use a subdued neutral surface, while disabled checked controls use a stronger neutral fill plus the native checkmark or dot to preserve the selected state without suggesting interactivity. Enabled controls may strengthen their border or fill on hover, disabled controls do not receive hover feedback, and keyboard focus uses the primary `focus-visible` ring. Validation may replace the boundary and focus emphasis with the established red error treatment. Preserve the native checkbox/radio semantics and distinct square/circular shapes; do not rely on color alone to communicate selection.

Keep touch targets at least 44px high for primary auth controls and small-screen-critical actions. Use correct input types and autocomplete attributes. Do not rely on placeholder text as a label. Loading submissions should disable repeat action and retain a textual state such as “Saving…”.

## Tables and data lists

- Use uppercase `text-xs` headers that never wrap, and `px-6 py-3` headings. Body cells default to `px-6 py-4`; dense operational tables (Attendance) use `px-6 py-2` for about 52px rows. Abbreviate a long header rather than let it wrap, exposing the full name with `<abbr title>` or `sr-only` text.
- Empty cell values are a muted em dash (`text-slate-300 dark:text-slate-600`), so data reads before placeholders.
- Use the inset `divider` line (`bg-slate-divider` on an `h-px` span, or `border-slate-divider`), omitting a trailing divider after the last row.
- Row hover is `hover:bg-slate-50 dark:hover:bg-slate-750/60`.
- Preserve selectable data when choosing between a cell link and a whole-row target.
- Wrap wide tables in `overflow-x-auto`; do not compress data until it becomes unreadable. From `sm` to below `xl` (640–1279px), a dense table may pin its Status and trailing action columns with `.table-pin` (opaque, row-matched surfaces; an edge shadow only while content passes under them) — never a leading column, never on a phone (below `sm` the pair would cover half the card), and never from `xl` up.
- Place pagination within the same solid data surface, separated by a standard divider. Render the footer (and its divider) only when `hasPages()` is true; a one-page list ends at its last row, with no empty band.

## Navigation

The desktop sidebar is fixed-width at `242px`; the topbar is sticky. Both are shell surfaces and may remain glass. Active navigation uses a primary tint, primary text/icon, and a persistent leading marker. Mobile navigation is a modal drawer with a dismissible backdrop. Breadcrumbs collapse nonessential ancestors at small widths.

Navigation visibility must match destination authorization. UI hiding is presentation only and never replaces route middleware or policy checks.

## Overlays

Dropdowns and popovers are opaque, elevated content surfaces. Modals use `<x-modal>` and remain teleported to `<body>`; do not nest a hand-built fixed overlay in page content. Escape and backdrop click close dismissible overlays. Opening a modal always moves focus into it — the visible field marked `autofocus`, else the first focusable element (the calendar's day modal lands on **Add punch**, or **Close** for a viewer who can't add punches) — focus is trapped while it is open, and closing returns it to the trigger, or, when the trigger lost focus before the modal opened (`wire:loading` disables it during the round trip), to the last element focused outside any modal (`ModalFocusTest`). Confirmation dialogs follow the same visual surface rules.

## Loading states

- Disable an action while its request is running to prevent duplicate submission.
- Keep the control's width stable and use explicit copy such as “Signing in…” or “Saving…”.
- A spinner supplements text and carries `aria-hidden="true"`; status copy carries the meaning.
- For Livewire regions, prefer a local loading state near the affected content over blocking the full application shell.
- Avoid skeletons unless latency and layout stability on a representative page justify them.

## Empty states

Use `<x-empty-state>` inside the owning solid content surface. State what is missing, optionally explain why, and offer one authorized next action when one exists. “No results” after filtering should help users reset filters; it is distinct from “no records exist.” Users without an employee link use `<x-no-employee-record>` rather than a misleading empty table.

## Accessibility

- Meet WCAG AA contrast for text, controls, focus, and status treatments in both themes.
- Do not communicate status, errors, timing exceptions, or interactivity by color alone.
- All controls require an accessible name; icon-only buttons need an `aria-label` or equivalent visible/tooltip label.
- Maintain logical heading order, landmarks, table semantics, and form associations.
- Focus indicators use `focus-visible` where mouse focus would be noisy; never remove focus without a replacement.
- Respect reduced-motion preferences for any nonessential future animation. Existing transitions must be short and functional.
- Ensure actions are visible and reachable on touch devices without hover.

## Responsive behavior

Design from the smallest supported width outward. The app switches from the sidebar to a drawer below `lg`; auth switches from split-screen to form-only below `lg`. Stack form and toolbar controls before they become cramped: a multi-column filter row switches on only at the breakpoint where its column minimums actually fit the card (the Employees filters use `xl`, since at `lg` the sidebar leaves a 718px card). From `sm` to below `xl`, the dense tables pin their Status and trailing action columns to the right edge so those stay visible while the rest scrolls; below `sm` they scroll as one piece (see [Attendance UI](ATTENDANCE_UI.md#responsive-behavior)), and a scroll cue's breakpoint follows the table's measured width. Use wrapping and horizontal table scrolling intentionally. Test at narrow mobile, tablet, desktop, and zoomed desktop widths in both themes.

## Dark mode

Dark mode is class-based and applied before paint from `localStorage.theme`, falling back to system preference. Every component must define text, surface, boundary, hover, focus, disabled, and status treatments—not only a dark background. Reapply the stored theme after `livewire:navigated`, as the layouts currently do.

## Audit baseline and migration scope

The v1 audit covered the app and guest layouts; shared Blade/Breeze components; Alpine theme, dropdown, drawer, and modal behavior; representative dashboard, attendance, employee, organization, auth, and profile screens; tables, forms, empty states, badges, overlays, responsive structures, and relevant feature tests.

The principal inconsistency was content glass: cards, form controls, dropdowns, and some modal panels used translucent surfaces while other dense overlays already used solid ones for readability. Pass B resolves this at the shared-component level, removes the obsolete `surface` prop, updates its callers, and normalizes representative page-level controls. It deliberately does not redesign individual pages.

## Future tokenization

The semantic roles in this document are guidance mapped to existing Tailwind classes, not new utilities. The `slate` CSS variables (Neutral scale) are not semantic tokens either: they keep the scale's step names and only let the theme choose the hue. Do not add utilities such as `text-muted`, `bg-surface`, or `border-default`, and do not add a semantic palette to `tailwind.config.js` in v1.

After multiple representative screens have been redesigned and these roles have proven stable, implementation-level semantic tokens may be evaluated. Any proposal should demonstrate that it reduces drift without obscuring Tailwind behavior, preserves status semantics, and works in both themes. Until then, use the mappings above. The two line tokens (Lines) and `h-control` (Control height) are the deliberate exceptions: each replaced values that had already drifted apart, not a role that was merely stable.
