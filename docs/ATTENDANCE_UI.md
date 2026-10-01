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
- **Dashboard:** timing copy may stay amber, since it is the timing fact itself. A late row in Needs attention is the name plus the amber duration (`1h 20m late`) — no `Late` badge and no amber avatar; Absent and Incomplete rows keep their status badge. Recent activity's "Checked in 21m late" line is amber for the same reason.

**Late before the day is complete (Phase 2.6).** Late is recorded as soon as the in-punch exists, so it also appears on `in_progress` and `incomplete` days: the Attendance table's Late column shows it, the calendar cell and day modal mark the in-time, and the **Late arrival** filter returns late rows of every status. Early leave only ever appears on Present days. The status badge and cell colour stay those of the status (an In progress or Incomplete day with a late in-punch is still blue or violet); late is only the annotation. The Present sub-line on the summary strip (`64 late · 184 early`) is a breakdown of Present, so it counts only Present days; a late Incomplete day appears in the table and the filter, not in that sub-line.

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
- Dashboard Recent activity shows the date (compact, `Tue 29 Sep`) above the time for any entry that isn't from today, so an older punch can't read as this morning's.

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

## Filters are controls, not status badges

Status and timing filters are separate control groups of filter chips. Chips are controls, not actions: selected is `bg-primary-50 text-primary-700` with a `ring-primary-600` border and a visible check (dark: `bg-primary-900/30 text-primary-200 ring-primary-500`); unselected is a neutral outline. Chips never use a solid fill — a solid primary fill is reserved for the page's primary action. Every chip carries `aria-pressed` and a focus-visible ring. Semantic attendance colors are reserved for attendance data and must not indicate filter selection.

The default filter state reads as "no filter applied". Status chips **narrow** rather than enumerate: while every working status (all but Off) is in the filter — the default — none of them renders as selected. Clicking one narrows to that status; further clicks add or remove statuses; removing the last one returns to all working statuses. Off days are a separate **Show off days** toggle, unselected by default. The stored filter, its URL form, and the default query are unchanged (every status except Off); only the presentation and toggle mapping are defined this way (`Attendance\Index::toggleStatus()`). Timing chips are unselected by default.

Filtering must preserve URL-bound state, employee scope, permissions, and the existing distinction between status and timing. Loading feedback should stay local to the records area and must not cause a large layout shift.

## Dense attendance tables

The Attendance table prioritizes scanability:

- Never hide or conditionally remove columns. Keep date, employee identity, status, In/Out times, worked duration, late, early leave, and department visible as distinct columns.
- Column order puts identity and status first: Employee (always the leftmost column), Status, Date, then In, Out, Worked, Late, Early, and Department, followed by the row's navigation chevron.
- Header labels never wrap (`whitespace-nowrap` on the header row). The early-leave column header is `Early`, with its full name exposed as `<abbr title="Early leave">`.
- Body rows target about 52px at desktop width: `py-2` cells, the employee name on one `text-sm` line and the employee code on a `text-xs` line.
- Use tabular numerals and prevent time and duration values from wrapping.
- Empty values are a muted em dash (`text-slate-300 dark:text-slate-600`). When the range is a single day the Date column is muted (`text-slate-500 dark:text-slate-400`), since the range control already states the date.
- The employee name is plain text (`text-slate-900 font-medium`, dark `text-slate-100`), not a link. The trailing chevron is the row's only link to attendance detail: visible at rest, at least a 40px target (a negative vertical margin keeps it from growing the row), and labelled `View attendance for {name}, {date}`. It opens that employee's month containing the date. Row hover stays; there is no whole-row click handler, so every cell remains selectable.
- The employee's own attendance table view follows the same rules (it has no Employee column, so Date and Status come first), `Early` abbreviated, muted em dashes, and one trailing per-row affordance. There, that affordance is the admin-only raw-punches disclosure toggle (40px, labelled `Show raw punches for {date}`), since the page already is the employee's attendance detail.
- Keep status badges driven by `displayVariant()`; never recreate semantic logic in Blade.
- Use a compact in-card empty state that explains the result and offers filter reset when applicable.

## Responsive behavior

On narrow screens, preserve the full table and native horizontal scrolling instead of hiding columns, converting rows into cards, or compressing data until it becomes unreadable. The table has a readable minimum width and its container uses `overflow-x-auto`.

A short, non-interactive “Scroll to view all columns” cue appears above the table whenever the table is wider than its card, and requires no JavaScript. Its breakpoint follows the measured table width, not a generic desktop breakpoint: the Daily Attendance table's natural width is about 1119px, which first fits at a 1440px viewport (1134px card), so its cue is hidden only from `min-[1440px]`; the Employees table (about 959px, `min-w-[60rem]`) fits from `xl` (974px card), so its cue is hidden from `xl`. The cue is supplementary; native scrolling remains the interaction. Because identity and status are the first columns in both tables, Employee and Status stay visible without scrolling at 1024px.

**Calendar below `sm`** (about 44px cells at 390px): the day number and status icon stack vertically instead of sitting side by side, and a holiday's name is replaced in the cell by a small fuchsia flag (unless the status icon already is the holiday flag) — the name stays in the cell's accessible label and in the day modal. Nothing in a cell clips or collides.

The Daily Attendance summary is the shared stat strip (Present, Absent, Incomplete). It is range-wide on purpose: it follows the date range only, not the status or timing chips, search, or department. So that it cannot be read as contradicting a filtered table, it shows its scope as right-aligned muted meta on the strip's header row (card header pattern), such as `1–29 Sep · all statuses`, which becomes `… · all employees, all statuses` while a search or department filter narrows the table. Present's subtext is the timing count (`7 late · 10 early`), not a duration.

Summary metrics may stack or use compact responsive columns, but labels, counts, percentages, and timing context must remain readable without truncating meaningful information.

## Dark mode and accessibility

- Every surface, border, label, control state, badge, loading state, and empty state must remain legible in light and dark mode.
- Interactive controls need visible keyboard focus and must not rely on hover or color alone.
- Fieldsets and labels preserve the relationship between filter controls and their purpose.
- Decorative icons are hidden from assistive technology; links and buttons retain meaningful accessible names.
- Native table semantics and horizontal scrolling must remain intact.
