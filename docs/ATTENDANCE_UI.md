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

**Fill emphasizes exceptions.** In the calendar, a Present cell uses the neutral card surface (`bg-white dark:bg-slate-900` with a `slate-200`/`slate-800` ring); its status stays visible through the green day number and green check icon. Fills are reserved for the non-present statuses — absent, incomplete, leave, holiday and in progress — so the exceptions are what the eye finds first. **Off days are the quietest cells, quieter than Present:** no fill, the day number in `slate-500` (4.8:1) at medium weight instead of bold, a fainter `slate-400` icon (dark `slate-500`), and a dashed `slate-300` boundary (dark `slate-700`) so the grid stays readable. A grey Off fill was the heaviest surface in the dark grid and is not used. Holiday cells keep their own fuchsia treatment; not-calculated days stay the lightest neutral fill. The day modal's Off pill stays a normal slate badge. This is a presentation rule for the calendar grid only: it does not change `displayVariant()`, and the day modal's status pill and all table badges stay green for Present.

**Today** is marked by a filled circle behind the day number — `bg-primary-600 text-white` (6.53:1), dark `bg-primary-400 text-slate-900` (5.68:1 for the digits and 5.68:1 for the circle against the dark card; `primary-500` with white text measured only 4.57:1 / 3.83:1) — with `aria-current="date"` on the cell. There is no cell border highlight, which read as one more status ring. The status icon stays in its corner.

**In-cell times** are at least 12px (`text-xs`), and the AM/PM suffix at least 10px (`<x-time>` uses `max(10px, 0.8em)`). Cells have a minimum height rather than a fixed one, so in narrow cells the Out time wraps onto a second line instead of clipping.

The **timing text** color marks the timing fact where it is displayed: `font-medium text-amber-700` in light mode, `dark:text-amber-300` in dark mode ("amber" below means this token pair). Amber badges and amber alerts (such as the not-yet-calculated notice) are a different role with their own treatment.

- **Attendance tables** (Daily Attendance and the employee's own table view): the Late and Early values use the timing text color (`font-medium text-amber-700 dark:text-amber-300`). In/Out times are neutral — no color, no underline.
- **Calendar cells and the day-detail modal:** the late In / early Out time is amber medium-weight text via `<x-time marked>`, with an accessible label such as "Arrived 16 minutes late". No underline, and no extra "+80m" label in calendar cells. The cell still follows status, so a late Present day is an ordinary Present cell with an amber time. The calendar legend's timing entry is a sample amber marked time, not a color swatch.
- **Dashboard:** timing copy may stay amber, since it is the timing fact itself. A late row in Needs attention is the name plus the amber duration (`1h 20m late`) — no `Late` badge and no amber avatar; Absent and Incomplete rows keep their status badge. Recent activity is a log and stays neutral — plain "Checked in" / "Checked out", no amber and no late minutes; the timing fact is shown once, in Needs attention.

**Late before the day is complete (Phase 2.6).** Late is recorded as soon as the in-punch exists, so it also appears on `in_progress` and `incomplete` days: the Attendance table's Late column shows it, the calendar cell and day modal mark the in-time, and the **Late arrival** filter returns late rows of every status. Early leave only ever appears on Present days. The status badge and cell colour stay those of the status (an In progress or Incomplete day with a late in-punch is still blue or violet); late is only the annotation. **Late annotates its own status group.** In every summary, late is a sub-line on the status group it belongs to — never folded into another group and never a group of its own — the same way the live strip shows it on At work and Left. The employee month summary reads `Present 19 (of which 2 late · 4 left early) · Absent 1 · Incomplete 1 (1 late)`, and the Attendance range strip shows `64 late · 184 early` under Present and `3 late` under Incomplete. Present's sub-line counts Present days only; a late Incomplete day is counted under Incomplete. Early leave only ever annotates Present.

Underline is reserved for links everywhere; a timing value is never underlined. Color always accompanies readable text or another non-color signal: the Late/Early column position and value, the marked time's medium weight, and its accessible label. Do not add separate timing chips that duplicate those fields.

Why `amber-700` in light mode: an earlier perception that amber timing text read as reddish came from simultaneous contrast with the old `green-50` Present fill, not from the hue itself. With Present cells now on the plain card surface, `amber-700` (#b45309) reads as orange and stays clearly distinct from `red-700`. `yellow-700` was tried and rejected: it read muddy and lost warning salience. Measured contrast of the timing text against its actual backgrounds: `amber-700` is 5.02:1 on white (cards and Present calendar cells) and 4.81:1 on the `slate-50` row hover; `amber-300` in dark mode is 12.13:1 on the dark card (and dark Present cells) and 11.19:1 on the dark row hover.

### Dates

Attendance dates use the three `App\Support\DisplayDate` forms (see [Design System](DESIGN_SYSTEM.md#typography)): compact `Tue 29 Sep` in table Date columns, the employee table view, card meta and notices; range `23–29 Sep` for the date picker trigger and the summary strip scope; long `Monday, 21 September 2026` for the day-modal title (no weekday eyebrow) and every calendar cell's accessible label.

### Durations and counts

Every attendance **duration** — Worked, Late, Early leave, a month's total — uses one compact format from `App\Support\Duration::format()`: under 60 minutes is minutes only (`21m`); 60 minutes and above is hours plus zero-padded minutes (`1h 20m`, `8h 03m`). This applies in the Daily Attendance table, the employee's calendar day-detail modal and table view, and the dashboard's Needs attention list. `DailyAttendance::formattedWorkedMinutes()`, `formattedLateMinutes()`, and `formattedEarlyLeaveMinutes()` return this format, or null for zero so the caller renders an em dash. Do not format a duration inline or write a second formatter. Stored values (`late_minutes`, `early_leave_minutes`, `worked_minutes`) are always minutes.

Counts are not durations and keep their own wording: summary timing copy stays concise and omits zero values: `49 late`, `152 early`, or `49 late · 152 early`.

## Needs attention (dashboard)

Today's list, worst-first: **Absent** and **Incomplete** (status badges — these only exist once the day's end time has passed), then **late arrivals** (name plus the amber duration, `1h 20m late`, longest first — including people still at work, since Phase 2.6 records late from the in-punch), then **Not in yet** (name plus muted `Not in yet · due 8:00 AM`, narrow scope above). Late and Not in yet carry no badge; neither is a status. The empty state is unchanged.

## Other attendance details

- Raw punch badges in the day modal and table view are neutral (`slate`) for both In and Out. Green means Present; a punch direction is not a status.
- Dashboard Recent activity is a neutral log ("Checked in" / "Checked out", no timing) and shows the date (compact, `Tue 29 Sep`) above the time for any entry that isn't from today, so an older punch can't read as this morning's.

## Pending is never 0% or absent

In-progress or not-yet-calculated attendance is **pending**. It must never render as 0% or as an absence. Today is pending while any row for today is In progress or any active employee in scope has no row yet (`DashboardAttendance::todayIsPending()`).

- **Trend chart:** today's bar carries a muted `Today` marker (like `Off`/`Holiday`) until the day is calculated. If anyone has checked in, the checked-in share so far (in-punches over active employees — the same figure as the Department card's `Checked in N / M`, not the Present count) is drawn as a lighter, provisional bar (`primary-200`, dark `primary-800`) with the marker above it; with nobody checked in yet there is no bar, only the marker.
- **Department attendance:** while today is pending, each department shows a so-far count (`Checked in 28 / 35`) with a lighter provisional bar instead of a percentage.
- **Dashboard stat strip:** today uses the live strip (below), which has no percentages at all.

## Status counts vs. the live strip

Two different questions, two strip forms:

- **Status counts** (Present, Absent, Incomplete) answer *"did they attend"* — an end-of-day view. The Attendance strip uses them for any range that is not exactly today.
- **The live strip** answers *"who is here now"* — today only. It reads `At work 32 (4 late) · Left 3 (1 late · 3 early) · Not in yet 3` and is a **partition of the active employees in scope: the three numbers never overlap and must always sum to active employees.** **At work** is everyone with an in-punch today who hasn't finished (any non-Present row with a first punch); **Left** is everyone Present today (both punches); **Not in yet** is everyone else — no in-punch yet. Late (Phase 2.6) is a **sub-line** on At work and Left, never a fourth group; early leave is a sub-line on Left. It is derived from today's existing rows (`DashboardAttendance::liveToday()` / `liveTodayCells()`), with no builder involvement. The Dashboard strip always uses it — three cells, with no separate headcount cell, since the total is already the strip's meta (`Today, Thu 1 Oct · 35 active employees`); the Attendance strip uses it whenever the range is exactly today, with the scope meta `Today, Wed 30 Sep · so far`.
- **"Not in yet" has two scopes, and the narrower is always a subset of the wider** — never a different definition under the same label:
  - **Strip:** every active employee with no in-punch yet today, at any time of day (before or after the shift starts).
  - **Needs attention:** only those who are actionable — an In progress row with no punch at all whose schedule `start_time + grace_minutes` has passed (`DailyAttendance::isNotInYet()`), shown as `Not in yet · due 8:00 AM` (the scheduled start). Before start + grace the person is simply in progress and isn't listed.

  "Not in yet" is a derived display fact, never an attendance status and never "absent": Off, Holiday, Leave and Absent rows never qualify. It uses muted text, not a status colour.
- **"Checked in" is a different, overlapping figure** and is reserved for *has an in-punch today*, whether still at work or already left. It is used only by the Department card (`Checked in 30 / 35`) and the trend's pending bar (checked in ÷ active employees), never by the strip — so the strip's At work (27) and the Department card's Checked in (30) can differ by exactly the people who have left.

## Date range picker

The Daily Attendance date control is a trigger (showing the range in the `DisplayDate` range form) that opens a popover with the existing **Quick ranges** presets and, under **Custom**, a range picker (`dateRangePicker` in `resources/js/app.js`, an Alpine component — no date library) with three views, like an ordinary calendar: **days**, where a range is picked, and — by clicking the heading — **months** (the 12 months of a year) and **years** (12 at a time, e.g. 2016–2027), for long ranges. Picking a year opens its months; picking a month opens its days. The arrows beside the heading step by a month, a year, or 12 years, matching the view.

- **State and URL are unchanged.** The picker reads and writes the same Livewire `fromDate`/`toDate` properties, as `YYYY-MM-DD`, that the native inputs used, so the query and the `from`/`to` URL parameters are exactly as before. A completed range sets both properties in one request.
- **Selection.** The first pick anchors a new range and the second completes it, in either order (the earlier day becomes From); picking the same day twice is a one-day range. The anchor survives zooming out, so a long range is: pick the start day, click the heading, choose the end's year and month, pick the end day. While a range is in progress the band previews from the anchor to the day under the pointer or keyboard focus, and a visible hint names the start, which may be off screen — `Select a start date`, then `From Mon 3 Feb 2025 · select an end date` (or `· choose the end date's month` / `year` while zoomed out; `DisplayDate::compact()` format). Completing a range closes the popover and returns focus to the trigger.
- **Visual language is the employee calendar's.** Sunday first; today is a filled circle (`bg-primary-600 text-white`, dark `bg-primary-400 text-slate-900`) with `aria-current="date"`. The range is the shared selected tint as one continuous band, rounded where it meets a week row's or the month's edge; its two endpoints carry the full selected state (tint, primary border, semibold) — never a solid primary fill. In dark mode the endpoint layers the tint over the panel colour so it is opaque and does not double the band beneath it. Zoomed out, the month or year that would open carries the selected state, every month or year the range reaches into carries the tint, and the current month or year is primary semibold text with `aria-current="date"`.
- **Keyboard.** Each grid is a single tab stop (roving `tabindex`); what is on screen follows the focused cell. Days: arrow keys move by day (Up/Down by week), Home/End to the start/end of the week, PageUp/PageDown by month (with Shift, by year). Months and years (3-wide grids): arrows move by one (Up/Down by a row), Home/End to the row's ends, PageUp/PageDown by a year or by 12 years. Enter or Space picks the focused day, opens the focused month, or opens the focused year; Enter on the heading zooms out and moves focus into the new grid. Escape closes the popover from any view and returns focus to the trigger; a click outside closes it without moving focus.
- **ARIA.** The popover is a non-modal `role="dialog"` labelled "Choose a date range"; each view is a `role="grid"` table labelled by what it shows (`September 2026`, `Months of 2026`, `Years 2016 to 2027`), and a polite live region repeats that label so paging and zooming are announced; the day grid has full weekday names on its column headers. The heading button is named for what it does (`September 2026. Choose month and year`). Each day cell is a gridcell named by its long date (`Tuesday, 29 September 2026`), with `aria-selected` on the days of the committed range — or only on the anchor while a new range is in progress. A polite status line outside the popover announces the anchor and the completed range.
- **Below 640px (`sm`)** the grid is not shown; the popover keeps the native From/To date inputs, stacked, bound with `wire:model.live` as before.
- Only the Daily Attendance range uses this picker so far; other date inputs are unchanged.

## Filters are controls, not status badges

Status and timing filters are separate control groups of filter chips. Chips are controls, not actions: selected is the shared selected state ([Design System](DESIGN_SYSTEM.md#interaction-states)) — `bg-primary-50 text-primary-700 font-semibold` with a `ring-primary-600` border and a visible check (dark: `bg-primary-900/30 text-primary-200 ring-primary-500`), the same treatment as the Calendar/Table toggle and the current pagination page; unselected is a neutral outline at `font-medium`. Chips never use a solid fill — a solid primary fill is reserved for the page's primary action. Every chip carries `aria-pressed` and a focus-visible ring. Semantic attendance colors are reserved for attendance data and must not indicate filter selection.

The default filter state reads as "no filter applied". Status chips **narrow** rather than enumerate: while every working status (all but Off) is in the filter — the default — none of them renders as selected. Clicking one narrows to that status; further clicks add or remove statuses; removing the last one returns to all working statuses. Off days are a separate **Show off days** toggle, unselected by default. The stored filter, its URL form, and the default query are unchanged (every status except Off); only the presentation and toggle mapping are defined this way (`Attendance\Index::toggleStatus()`). Timing chips are unselected by default.

Filtering must preserve URL-bound state, employee scope, permissions, and the existing distinction between status and timing. Loading feedback should stay local to the records area and must not cause a large layout shift.

## Dense attendance tables

The Attendance table prioritizes scanability:

- Never hide or conditionally remove columns. Keep date, employee identity, status, In/Out times, worked duration, late, early leave, and department visible as distinct columns.
- Column order (owner decision; it reverses an interim Employee-and-Status-first order): Date, Employee, Department, In, Out, Worked, Late, Early, Status, then the row's navigation chevron. Status and the chevron are the trailing pair so they can be pinned together below `xl` (see Responsive behavior). The Employees directory follows the same shape: Employee, Department, Position, Start date, Status, then Actions.
- Header labels never wrap (`whitespace-nowrap` on the header row). The early-leave column header is `Early`, with its full name exposed as `<abbr title="Early leave">`.
- Body rows target about 52px at desktop width: `py-2` cells, the employee name on one `text-sm` line and the employee code on a `text-xs` line.
- Use tabular numerals and prevent time and duration values from wrapping.
- Empty values are a muted em dash (`text-slate-300 dark:text-slate-600`). When the range is a single day the Date column is muted (`text-slate-500 dark:text-slate-400`), since the range control already states the date.
- The employee name is plain text (`text-slate-900 font-medium`, dark `text-slate-100`), not a link. The trailing chevron is the row's only link to attendance detail: visible at rest, at least a 40px target (a negative vertical margin keeps it from growing the row), and labelled `View attendance for {name}, {date}`, with the shared tooltip ("View attendance details", shown on hover and keyboard focus — see [Design System](DESIGN_SYSTEM.md#interaction-states)); the `aria-label` stays the accessible name. It opens that employee's month containing the date. Row hover stays; there is no whole-row click handler, so every cell remains selectable.
- The employee's own attendance table view follows the same rules with its own column order — Date and Status first, since it has no Employee column, and nothing pinned — `Early` abbreviated, muted em dashes, and one trailing per-row affordance. There, that affordance is the admin-only raw-punches disclosure toggle (40px, labelled `Show raw punches for {date}`), since the page already is the employee's attendance detail.
- Keep status badges driven by `displayVariant()`; never recreate semantic logic in Blade.
- Use a compact in-card empty state that explains the result and offers filter reset when applicable.

## Responsive behavior

On narrow screens, preserve the full table and native horizontal scrolling instead of hiding columns, converting rows into cards, or compressing data until it becomes unreadable. The table has a readable minimum width and its container uses `overflow-x-auto`.

A short, non-interactive “Scroll to view all columns” cue appears above the table whenever the table is wider than its card, and requires no JavaScript. Its breakpoint follows the measured table width, not a generic desktop breakpoint: the Daily Attendance table's natural width is about 1119px, which first fits at a 1440px viewport (1134px card), so its cue is hidden only from `min-[1440px]`; the Employees table (about 959px, `min-w-[60rem]`) fits from `xl` (974px card), so its cue is hidden from `xl`. The cue is supplementary; native scrolling remains the interaction.

**Pinned trailing columns below `xl` (1280px).** In both the Daily Attendance and Employees tables, the Status column and the trailing chevron/actions column are `position: sticky` on the right edge of the scroll container, so "did they attend" (or "are they active") and the way into the record stay on screen while the other columns scroll beneath them. From `xl` up nothing is pinned — even where the Daily Attendance table still scrolls (1280–1439px), it scrolls as one piece. The rules (`.table-pin` in `resources/css/app.css`, `pinnedColumns` in `resources/js/app.js`):

- Pinned cells are opaque and match the row exactly: the card surface at rest, the row hover surface while hovered. In dark mode the hover is a translucent `slate-800/60` over the card, so a pinned cell layers that same tint over the same card colour rather than approximating it with a solid shade.
- Status pins immediately left of the trailing column; its offset is that column's measured width, kept current as the layout changes.
- A subtle shadow on the pinned group's left edge shows **only while content is passing under it** — the table overflows and is not scrolled to its right end. When the table fits, or is scrolled fully right so the pinned columns sit in their natural place, there is no shadow.
- The row divider lives in each row's first cell and sits above the pinned cells, so it runs unbroken beneath them.
- At phone width the pinned pair takes a large share of the card (about 200px of 358px for Daily Attendance, about 230px for Employees), so the leading columns are only partly visible at rest; scrolling reveals them.

**Calendar below `sm`** (about 44px cells at 390px): the day number and status icon stack vertically instead of sitting side by side, and a holiday's name is replaced in the cell by a small fuchsia flag (unless the status icon already is the holiday flag) — the name stays in the cell's accessible label and in the day modal. Nothing in a cell clips or collides.

The Daily Attendance summary is the shared stat strip (Present, Absent, Incomplete). It is range-wide on purpose: it follows the date range only, not the status or timing chips, search, or department. So that it cannot be read as contradicting a filtered table, it shows its scope as right-aligned muted meta on the strip's header row (card header pattern), such as `1–29 Sep · all statuses`, which becomes `… · all employees, all statuses` while a search or department filter narrows the table. Present's subtext is the timing count (`7 late · 10 early`), not a duration.

Summary metrics may stack or use compact responsive columns, but labels, counts, percentages, and timing context must remain readable without truncating meaningful information.

## Dark mode and accessibility

- Every surface, border, label, control state, badge, loading state, and empty state must remain legible in light and dark mode.
- Interactive controls need visible keyboard focus and must not rely on hover or color alone.
- Fieldsets and labels preserve the relationship between filter controls and their purpose.
- Decorative icons are hidden from assistive technology; links and buttons retain meaningful accessible names.
- Native table semantics and horizontal scrolling must remain intact.
