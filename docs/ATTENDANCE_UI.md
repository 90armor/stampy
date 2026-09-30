# Attendance UI

This document is the presentation contract for Stampy's Attendance views. The Daily Attendance page is the reference implementation for data-heavy screens; it must remain dense, readable, responsive, and faithful to the attendance domain.

## Status and timing are separate

Attendance status answers whether an employee attended: `present`, `incomplete`, `absent`, `off`, `holiday`, `leave`, or `in_progress`. Timing describes how a worked day differed from schedule through `late_minutes` and `early_leave_minutes`.

There is no `Late` attendance status. A person who arrived late or left early still has the underlying `present` status. A day may have either timing exception or both without requiring a combined status.

### Color = status only; timing is an annotation

A cell or badge color encodes the attendance **status** only — one value per day. Attributes that can co-occur on the same day (timing exceptions now, partial leave later) are **annotations inside the cell**, never the cell or badge color. A late or early Present day is a green "Present".

`DailyAttendance::displayVariant()` is the only source of truth for the display variant, and it is status-only. Views must not independently infer a color from status or timing fields. Timing stays exposed through `isLate()`, `leftEarly()`, and `hasTimingException()`.

| Display variant | Meaning |
| --- | --- |
| `green` | Present (with or without a timing exception) |
| `violet` | Incomplete |
| `red` | Absent |
| `slate` | Off |
| `accent` | Leave |
| `blue` | In progress |
| `fuchsia` | Holiday |

Amber marks the timing fact where it is displayed:

- **Attendance tables** (Daily Attendance and the employee's own table view): the Late and Early values are amber (`font-medium text-amber-700 dark:text-amber-300`). In/Out times are neutral — no color, no underline.
- **Calendar cells and the day-detail modal:** the late In / early Out time is amber medium-weight text via `<x-time marked>`, with an accessible label such as "Arrived 16 minutes late". No underline, and no extra "+80m" label in calendar cells. The cell fill still follows status, so a late Present day is a green cell with an amber time. The calendar legend's timing entry is a sample amber marked time, not a color swatch.
- **Dashboard:** timing copy may stay amber, since it is the timing fact itself. A late row in Needs attention is the name plus the amber duration (`1h 20m late`) — no `Late` badge and no amber avatar; Absent and Incomplete rows keep their status badge. Recent activity's "Checked in 21m late" line is amber for the same reason.

Underline is reserved for links everywhere; a timing value is never underlined. Color always accompanies readable text or another non-color signal: the Late/Early column position and value, the marked time's medium weight, and its accessible label. Do not add separate timing chips that duplicate those fields.

Contrast of the amber annotation, measured against its actual backgrounds: `amber-700` is 5.02:1 on white, 4.80:1 on a `green-50` Present cell, 4.81:1 on the `slate-50` row hover; `amber-300` is 12.13:1 on the dark card, 11.00:1 on a dark `green-900/20` cell, 11.19:1 on the dark row hover.

### Durations and counts

Every attendance **duration** — Worked, Late, Early leave, a month's total — uses one compact format from `App\Support\Duration::format()`: under 60 minutes is minutes only (`21m`); 60 minutes and above is hours plus zero-padded minutes (`1h 20m`, `8h 03m`). This applies in the Daily Attendance table, the employee's calendar day-detail modal and table view, and the dashboard's Needs attention list. `DailyAttendance::formattedWorkedMinutes()`, `formattedLateMinutes()`, and `formattedEarlyLeaveMinutes()` return this format, or null for zero so the caller renders an em dash. Do not format a duration inline or write a second formatter. Stored values (`late_minutes`, `early_leave_minutes`, `worked_minutes`) are always minutes.

Counts are not durations and keep their own wording: summary timing copy stays concise and omits zero values: `49 late`, `152 early`, or `49 late · 152 early`.

## Filters are controls, not status badges

Status and timing filters are separate control groups of filter chips. Chips are controls, not actions: selected is `bg-primary-50 text-primary-700` with a `ring-primary-600` border and a visible check (dark: `bg-primary-900/30 text-primary-200 ring-primary-500`); unselected is a neutral outline. Chips never use a solid fill — a solid primary fill is reserved for the page's primary action. Every chip carries `aria-pressed` and a focus-visible ring. Semantic attendance colors are reserved for attendance data and must not indicate filter selection.

The default filter state reads as "no filter applied". Status chips **narrow** rather than enumerate: while every working status (all but Off) is in the filter — the default — none of them renders as selected. Clicking one narrows to that status; further clicks add or remove statuses; removing the last one returns to all working statuses. Off days are a separate **Show off days** toggle, unselected by default. The stored filter, its URL form, and the default query are unchanged (every status except Off); only the presentation and toggle mapping are defined this way (`Attendance\Index::toggleStatus()`). Timing chips are unselected by default.

Filtering must preserve URL-bound state, employee scope, permissions, and the existing distinction between status and timing. Loading feedback should stay local to the records area and must not cause a large layout shift.

## Dense attendance tables

The Attendance table prioritizes scanability:

- Never hide or conditionally remove columns. Keep date, employee identity, status, In/Out times, worked duration, late, early leave, and department visible as distinct columns.
- Column order puts identity and status first: Date, Employee, Status, then In, Out, Worked, Late, Early, and Department, followed by the row's navigation chevron.
- Header labels never wrap (`whitespace-nowrap` on the header row). The early-leave column header is `Early`, with its full name exposed as `<abbr title="Early leave">`.
- Body rows target about 52px at desktop width: `py-2` cells, the employee name on one `text-sm` line and the employee code on a `text-xs` line.
- Use tabular numerals and prevent time and duration values from wrapping.
- Empty values are a muted em dash (`text-slate-300 dark:text-slate-600`). When the range is a single day the Date column is muted (`text-slate-500 dark:text-slate-400`), since the range control already states the date.
- The employee name is plain text (`text-slate-900 font-medium`, dark `text-slate-100`), not a link. The trailing chevron is the row's only link to attendance detail: visible at rest, at least a 40px target (a negative vertical margin keeps it from growing the row), and labelled `View attendance for {name}, {date}`. It opens that employee's month containing the date. Row hover stays; there is no whole-row click handler, so every cell remains selectable.
- The employee's own attendance table view follows the same rules: Date and Status first, `Early` abbreviated, muted em dashes, and one trailing per-row affordance. There, that affordance is the admin-only raw-punches disclosure toggle (40px, labelled `Show raw punches for {date}`), since the page already is the employee's attendance detail.
- Keep status badges driven by `displayVariant()`; never recreate semantic logic in Blade.
- Use a compact in-card empty state that explains the result and offers filter reset when applicable.

## Responsive behavior

On narrow screens, preserve the full table and native horizontal scrolling instead of hiding columns, converting rows into cards, or compressing data until it becomes unreadable. The table has a readable minimum width and its container uses `overflow-x-auto`.

A short, non-interactive “Scroll to view all columns” cue appears above the table on narrow screens. It is hidden at the desktop breakpoint and requires no JavaScript. The cue is supplementary; native scrolling remains the interaction.

The Daily Attendance summary is the shared stat strip (Present, Absent, Incomplete). It is range-wide on purpose: it follows the date range only, not the status or timing chips, search, or department. So that it cannot be read as contradicting a filtered table, it shows a visible scope line such as `Sep 1–29 · all statuses`, which becomes `… · all employees, all statuses` while a search or department filter narrows the table. Present's subtext is the timing count (`7 late · 10 early`), not a duration.

Summary metrics may stack or use compact responsive columns, but labels, counts, percentages, and timing context must remain readable without truncating meaningful information.

## Dark mode and accessibility

- Every surface, border, label, control state, badge, loading state, and empty state must remain legible in light and dark mode.
- Interactive controls need visible keyboard focus and must not rely on hover or color alone.
- Fieldsets and labels preserve the relationship between filter controls and their purpose.
- Decorative icons are hidden from assistive technology; links and buttons retain meaningful accessible names.
- Native table semantics and horizontal scrolling must remain intact.
