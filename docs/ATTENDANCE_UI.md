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

**Fill emphasizes exceptions.** In the calendar, a Present cell uses the neutral card surface (`bg-white dark:bg-slate-800` with a `divider`-token ring, `ring-slate-divider`); its status stays visible through the green day number and green check icon. Fills are reserved for the non-present statuses — absent, incomplete, leave, holiday and in progress — so the exceptions are what the eye finds first. **Off days are the quietest cells, quieter than Present:** no fill, the day number in `slate-500` (4.8:1) at medium weight instead of bold, a fainter `slate-400` icon (dark `slate-500`), and a dashed `divider`-token boundary so the grid stays readable. A grey Off fill was the heaviest surface in the dark grid and is not used. Holiday cells keep their own fuchsia treatment; not-calculated days stay the lightest neutral fill. The day modal's Off pill stays a normal slate badge. This is a presentation rule for the calendar grid only: it does not change `displayVariant()`, and the day modal's status pill and all table badges stay green for Present.

**Today** is marked by a filled circle behind the day number — the shared filled selected state: white semibold on `primary-600` (6.53:1), dark white semibold on `primary-500` (4.57:1 for the text; the fill is 3.26:1 against the dark card) — with `aria-current="date"` on the cell. There is no cell border highlight, which read as one more status ring. The status icon stays in its corner. **Exception — date pickers:** in the date picker (below) a fill means "this is a chosen date", so there today is a quiet marker (a semibold primary number with a dot beneath) instead of a filled circle. The employee calendar has no selection to compete with, so it keeps the circle.

**In-cell times** are at least 12px (`text-xs`), and the AM/PM suffix at least 10px (`<x-time>` uses `max(10px, 0.8em)`). Cells have a minimum height rather than a fixed one, so in narrow cells the Out time wraps onto a second line instead of clipping.

The **timing text** color marks the timing fact where it is displayed: `font-medium text-amber-700` in light mode, `dark:text-amber-300` in dark mode ("amber" below means this token pair). Amber badges and amber alerts (such as the not-yet-calculated notice) are a different role with their own treatment.

- **Attendance tables** (Daily Attendance and the employee's own table view): the Late and Early values use the timing text color (`font-medium text-amber-700 dark:text-amber-300`). In/Out times are neutral — no color, no underline.
- **Calendar cells and the day-detail modal:** the late In / early Out time is amber medium-weight text via `<x-time marked>`, with an accessible label such as "Arrived 16 minutes late". No underline, and no extra "+80m" label in calendar cells. The cell still follows status, so a late Present day is an ordinary Present cell with an amber time. The calendar legend's timing entry is a sample amber marked time, not a color swatch.
- **Dashboard:** timing copy may stay amber, since it is the timing fact itself. A late row in Needs attention is the name plus the amber duration (`1h 20m late`) — no `Late` badge and no amber avatar; Absent and Incomplete rows keep their status badge. Recent activity is a log and stays neutral — plain "Punched in" / "Punched out", no amber and no late minutes; the timing fact is shown once, in Needs attention.

**Late before the day is complete (Phase 2.6).** Late is recorded as soon as the in-punch exists, so it also appears on `in_progress` and `incomplete` days: the Attendance table's Late column shows it, the calendar cell and day modal mark the in-time, and the **Late arrival** filter returns late rows of every status. Early leave only ever appears on Present days. The status badge and cell colour stay those of the status (an In progress or Incomplete day with a late in-punch is still blue or violet); late is only the annotation. **Late annotates its own status group.** In every summary, late is a sub-line on the status group it belongs to — never folded into another group and never a group of its own — the same way the live strip shows it on At work and Left. The employee month summary reads `Present 19 (of which 2 late · 4 left early) · Absent 1 · Incomplete 1 (1 late)`, and the Attendance range strip shows `64 late · 184 early` under Present and `3 late` under Incomplete. Present's sub-line counts Present days only; a late Incomplete day is counted under Incomplete. Early leave only ever annotates Present.

Underline is reserved for links everywhere; a timing value is never underlined. Color always accompanies readable text or another non-color signal: the Late/Early column position and value, the marked time's medium weight, and its accessible label. Do not add separate timing chips that duplicate those fields.

Why `amber-700` in light mode: an earlier perception that amber timing text read as reddish came from simultaneous contrast with the old `green-50` Present fill, not from the hue itself. With Present cells now on the plain card surface, `amber-700` (#b45309) reads as orange and stays clearly distinct from `red-700`. `yellow-700` was tried and rejected: it read muddy and lost warning salience. Measured contrast of the timing text against its actual backgrounds: `amber-700` is 5.02:1 on white (cards and Present calendar cells) and 4.81:1 on the `slate-50` row hover; `amber-300` in dark mode is 10.33:1 on the dark card (and dark Present cells) and 9.38:1 on the dark row hover.

### Dates

Attendance dates use the three `App\Support\DisplayDate` forms (see [Design System](DESIGN_SYSTEM.md#typography)): compact `Tue 29 Sep` in table Date columns, the employee table view, card meta and notices; range `23–29 Sep` for the date picker trigger and the summary strip scope; long `Monday, 21 September 2026` for the day-modal title (no weekday eyebrow) and every calendar cell's accessible label.

### Durations and counts

Every attendance **duration** — Worked, Late, Early leave, a month's total — uses one compact format from `App\Support\Duration::format()`: under 60 minutes is minutes only (`21m`); 60 minutes and above is hours plus zero-padded minutes (`1h 20m`, `8h 03m`). This applies in the Daily Attendance table, the employee's calendar day-detail modal and table view, and the dashboard's Needs attention list. `DailyAttendance::formattedWorkedMinutes()`, `formattedLateMinutes()`, and `formattedEarlyLeaveMinutes()` return this format, or null for zero so the caller renders an em dash. Do not format a duration inline or write a second formatter. Stored values (`late_minutes`, `early_leave_minutes`, `worked_minutes`) are always minutes.

Counts are not durations and keep their own wording: summary timing copy stays concise and omits zero values: `49 late`, `152 early`, or `49 late · 152 early`.

## Needs attention (dashboard)

Today's list follows the builder's statuses (Phase 2.7), worst-first: **Absent** and **Incomplete** (status badges — an Absent row is a punchless day whose schedule end has passed, exactly as the attendance table shows it), then **late arrivals** (name plus the amber duration, `1h 20m late`, longest first — including people still at work, since Phase 2.6 records late from the in-punch), then **Not in yet** (name plus muted `Not in yet · due 8:00 AM`: only a punchless In progress row past start + grace). Late and Not in yet carry no badge; neither is a status. The header meta is the **real total** (`35 today`); the list shows the 8 most urgent (`DashboardAttendance::needsAttentionTotal()`). The empty state is unchanged.

## Other attendance details

- Raw punch badges in the day modal and table view are neutral (`slate`) for both In and Out. Green means Present; a punch direction is not a status.
- **Raw punches** (`<x-attendance.day-detail-panel>`, shared by the day modal and the table's expanded row): a `Raw punches N` heading with **Add punch** as a secondary button on the right (admins only); one row per punch — time, In/Out badge, source and any note — divided by the `divider` line, with a red **Void** ghost button (32px, visible at rest, `aria-label` naming the punch). The add form is a tinted well: date, time and type on one row, Cancel / Save punch right-aligned under them. The day modal shows a **Note** only when the day has one, and the employee's name under the date.
- **Overnight out-punches** are listed on both days, so neither day reads as missing or extra: on the shift's day, after its own punches, as `12:42 AM (+1)` · `recorded Thu 1 Oct`; on the day it was punched, in time order, as `ends Wed 30 Sep's shift`. Both rows are the same punch (`Attendance\Show::overnightPunches()`, which also covers the last day of the previous month) and either Void voids it.
- **Void confirmation** opens above the day modal: `<x-confirm-dialog>` is teleported to `<body>` at `z-[60]`, one layer above modals, and Escape closes only the confirmation.
- Dashboard Recent activity is a neutral log ("Punched in" / "Punched out", no timing — event-log language, since "Checked in" is reserved for the any-punch aggregate) and shows the date (compact, `Tue 29 Sep`) above the time for any entry that isn't from today, so an older punch can't read as this morning's.

## The attendance rate, and pending days

The dashboard follows the builder's statuses — one source of truth with the attendance table (Phase 2.7). Its rate is **attended ÷ active employees, attended = present + incomplete**: an incomplete day was attended, a punch is just missing. The trend and the Department card both use it. Both sides count employees who are **active now**: a deactivated employee's rows leave the numerator along with the headcount, so their past days drop out of the 7-day trend (no deactivation date exists to keep them — a Phase 3 scoping question).

In-progress or not-yet-calculated attendance is **pending**, and every trend day carries a value, a marker, or both — never a blank slot without a reason, never 0% for something unfinished, never Absent for someone who may still come in:

| A trend day that is… | Shows |
|---|---|
| Off or Holiday (every scoped row) | no bar, a muted `Off` / `Holiday` marker |
| Today, while pending (any In progress row, or an active employee with no row yet — `todayIsPending()`) | a lighter provisional bar (`primary-200`, dark `primary-800`) of **checked in so far** — anyone with a punch, in or out — and a `Today` marker; no bar while nobody has checked in |
| An earlier day still open (an in-only row inside its pairing window — a 9:30 in-punch keeps yesterday open until 03:30) | the same provisional bar and a `Pending` marker |
| A past day with no rows, or with In progress rows whose window has already closed (the builder didn't run) | no bar, a muted `Not calculated` marker |
| Closed | the rate as a `primary-500` bar; a closed workday with 0 attended gets a `0%` marker |

Markers too wide for their slot (`Not calculated` at phone width) wrap onto two lines. The **Department card** shows counts, never a bare percentage: `Checked in N / M` with the provisional bar while today is pending, `Attended N / M` once it has closed.

## Status counts vs. the live strip

Two different questions, two strip forms:

- **Status counts** (Present, Absent, Incomplete) answer *"did they attend"* — an end-of-day view. The Attendance strip uses them for any range that is not exactly today.
- **The live strip** answers *"who is here now"* — today only. It reads `At work 32 (4 late · 2 past end time) · Left 3 (1 late · 3 early) · Not in 3 (1 due · 2 absent)` and is a **partition of the active employees in scope by punches: the three numbers never overlap and must always sum to active employees.** **At work** has an in-punch and no out-punch yet; **Left** has an out-punch — Present, or an out-only Incomplete day (they did leave; the missing in-punch is the Incomplete fact); **Not in** has no punches today. Sub-lines annotate a group, never form a fourth one:
  - At work: `{n} late` (Phase 2.6) and `{n} past end time` — In progress rows whose schedule end has passed (Phase 2.7: an in-only day stays open until its pairing window closes — overtime, or a missing out-punch).
  - Left: `{n} late` and `{n} early`.
  - Not in: its **exact breakdown by state, which always sums to the cell** — `{n} due` (a punchless In progress row, or no row built yet), `{n} absent` (the schedule end passed with no punch), `{n} off`, `{n} on holiday`, `{n} on leave`. A row with no punches can only be one of these, so `Not in 3 · 2 absent · 1 off` always adds up.
  - At work's and Left's sub-lines are timing annotations — each counts a subset of its group, and the two can overlap (someone late and past end time), so they are not a breakdown.

  It is derived from today's existing rows (`DashboardAttendance::liveToday()` / `liveTodayCells()`), with no builder involvement. The Dashboard strip always uses it — three cells, with no separate headcount cell, since the total is already the strip's meta (`Today, Thu 1 Oct · 35 active employees`); the Attendance strip uses it whenever the range is exactly today, with the scope meta `Today, Wed 30 Sep · so far`.
- **"Not in yet" lives only in Needs attention:** a punchless In progress row past `start_time + grace_minutes` (`DailyAttendance::isNotInYet()`), shown as `Not in yet · due 8:00 AM`. It is a derived display fact, never an attendance status. Every such person is also in the strip's `due` sub-line, which is wider (it also counts people before their start + grace, and anyone not built yet).
- **"Checked in" is a different, overlapping figure** and is reserved for *has any punch today* — an in-punch or an out-punch, whether still at work or already left. An out-only Incomplete day counts: the person came in, the in-punch is what's missing. It is used only by the Department card (`Checked in 33 / 35`) and the trend's pending bar (checked in ÷ active employees), never by the strip. Because it counts exactly the strip's first two groups, the departments' Checked in figures always total the strip's **At work + Left**; it differs from At work alone by exactly the people who have left. **One word, one meaning:** Recent activity's event log says "Punched in" / "Punched out", never "Checked in"; the trend tooltip says "N% attended" for a closed day and "N% checked in so far" for a pending one, never "present" (the rate counts incomplete days too).

## Date picker

One component in two modes, sharing one calendar (`<x-date-picker.calendar>`) and one Alpine component (`datePicker` in `resources/js/app.js` — no date library): **range** for the Daily Attendance date control, and **single** (`<x-date-picker>`) for every other date field. Everything below applies to both modes unless it says otherwise.

### Range mode

The Daily Attendance date control is a trigger (showing the range in the `DisplayDate` range form) that opens a popover with **Quick ranges** — Today, Yesterday, Last 7 days, Last 30 days, This month, Last month, defined once in `Attendance\Index::presetRanges()` — and, under **Custom**, the calendar in range mode, with three views, like an ordinary calendar: **days**, where a range is picked, and — by clicking the heading — **months** (the 12 months of a year) and **years** (12 at a time, e.g. 2016–2027), for long ranges. Picking a year opens its months; picking a month opens its days. The arrows beside the heading step by a month, a year, or 12 years, matching the view.

- **State and URL are unchanged.** The picker reads and writes the same Livewire `fromDate`/`toDate` properties, as `YYYY-MM-DD`, that the native inputs used, so the query and the `from`/`to` URL parameters are exactly as before. A completed range sets both properties in one request.
- **Selection.** The first pick anchors a new range and the second completes it, in either order (the earlier day becomes From); picking the same day twice is a one-day range. The anchor survives zooming out, so a long range is: pick the start day, click the heading, choose the end's year and month, pick the end day. While a range is in progress the band previews from the anchor to the day under the pointer or keyboard focus, and a visible hint names the start, which may be off screen — `Select a start date`, then `From Mon 3 Feb 2025 · select an end date` (or `· choose the end date's month` / `year` while zoomed out; `DisplayDate::compact()` format). Completing a range closes the popover and returns focus to the trigger.
- **Quick ranges show what is applied.** A preset carries the shared selected state (`aria-pressed="true"`) while the applied range — or the pending one, while a new range is in progress — equals it exactly. Two presets can match at once (on the 1st of a month, Today and This month are the same day), and both show.
- **Treatments, and nothing else.** Days, Sunday first, neighbouring-month cells blank:
  - **Endpoint** — the start, the end, and the single day of a one-day range — is **filled** — the shared filled selected state: white semibold on `primary-600` (6.53:1), dark white semibold on `primary-500` (4.57:1 for the text; the fill is 3.26:1 against the dark card), the same as the employee calendar's today circle and the time input's chosen values. In the dark popover the fill measures only 2.75:1 against the surface and 2.20:1 against the range band, so it carries a 1px inset `primary-400` edge (4.08:1 / 3.27:1) — see [Design System](DESIGN_SYSTEM.md#interaction-states), Filled selected state. A previewed end is filled too while it is under the pointer or focus.
  - **In range** — the days between — is the shared selected tint as one continuous band (`bg-primary-50`, dark `bg-primary-600/35` — 1.25:1 against the popover; primary-200 text on it 6.63:1; in dark mode the endpoint fill is 2.75:1 against the popover and 2.20:1 against the band — under 3:1; the white semibold text and `aria-selected` also carry the state), rounded where it meets a week row's or the month's edge, with primary text.
  - **Today** is a **quiet marker**: a semibold primary number with a small dot beneath (white on a fill), and `aria-current="date"`. It is never a fill, because a fill now means "endpoint" — unlike the employee calendar, whose filled circle has no such competition.
  - Months and years use the same three: a month or year **holding a chosen endpoint** is filled; one the range **reaches into** is tinted; the **current** one has the quiet marker. "Chosen" means the committed from/to, or only the anchor while a new range is in progress, compared on the full date — the anchor's month never looks selected in another year.
  - **A ring is only ever keyboard focus** (`focus-visible`, `ring-2` with a 2px offset so it also reads on a fill). The keyboard cursor has no other styling.
- **Keyboard.** Opening the picker (Enter, Space or a click) moves focus straight into the day grid, on the selected date (for a range, its end — the month the picker shows), else today, else the first enabled day, whichever is first within the field's limits; the arrow keys work at once. Below 640px the range picker's calendar is hidden, so focus goes to its first preset. Each grid is a single tab stop (roving `tabindex`); what is on screen follows the focused cell. Days: arrow keys move by day (Up/Down by week), Home/End to the start/end of the week, PageUp/PageDown by month (with Shift, by year). Months and years (3-wide grids): arrows move by one (Up/Down by a row), Home/End to the row's ends, PageUp/PageDown by a year or by 12 years. Enter or Space picks the focused day, opens the focused month, or opens the focused year; Enter on the heading zooms out and moves focus into the new grid. Escape closes the popover from any view and returns focus to the trigger; a click outside closes it without moving focus.
- **ARIA.** The popover is a non-modal `role="dialog"` labelled "Choose a date range"; each view is a `role="grid"` table labelled by what it shows (`September 2026`, `Months of 2026`, `Years 2016 to 2027`), and a polite live region repeats that label so paging and zooming are announced; the day grid has full weekday names on its column headers. The heading button is named for what it does (`September 2026. Choose month and year`). Each day cell is a gridcell named by its long date (`Tuesday, 29 September 2026`), with `aria-selected` on the days of the committed range — or only on the anchor while a new range is in progress; a month or year cell is `aria-selected` when it holds a chosen endpoint. A polite status line outside the popover announces the anchor and the completed range.
- **Below 640px (`sm`)** the grid is not shown; the popover keeps the native From/To date inputs, stacked, bound with `wire:model.live` as before.

### Single mode

`<x-date-picker id model label [min] [max]>` replaces the native date input in every date field: holiday date, join date, a schedule assignment's effective date, bulk reassignment's effective date, and a manual punch's date. Time inputs stay native. `DatePickerTest` fails on a bare `type="date"` input anywhere else.

- **Trigger** looks like the text inputs beside it (`rounded-lg`, the control surface and border, `text-sm`), with a calendar icon and the date in the `DisplayDate` compact form (`Tue 29 Sep`; year only outside the current year), or a muted `Select a date`. It keeps the field's `id`, so the `<x-input-label for>` still points at it, and its accessible name is the label plus the date (`Date, Thursday, 15 October 2026`), since a label alone would hide the value.
- **Picking** writes the property deferred, like the plain `wire:model` it replaces — the value travels with the form's next request — then closes the popover and returns focus to the trigger. A single date is a one-day range, so it is the filled endpoint; there is no band and no quick ranges. Zoomed out, the month or year holding the date is filled.
- **Bounds.** `min`/`max` mirror the field's server-side rule, and days outside them are muted, `aria-disabled` and can't be picked: a punch from the employee's join date to today; an assignment's effective date from the join date; bulk reassignment at most 60 days back. Holiday and join dates are unbounded. The server rule stays the authority.
- **Footer: Today and Clear.** **Today** sits on the right and picks today; it is shown only when today is inside the field's `min`/`max` (an employee who joins next month gets no Today on their punch or assignment date). **Clear** sits on the left and only on a field whose server rule is `nullable` (`clearable`): it empties the value, the trigger returns to its placeholder, the popover closes and focus returns to the trigger. A required field gets no Clear — picking again already replaces the value. Every date field today is required (holiday date, join date, both effective dates, punch date), so none shows Clear yet. Typing a date is not possible. The Daily Attendance range has neither button: Reset filters and the Today preset cover both.
- **In a modal.** The popover is `position: fixed` and placed against the trigger — below it when it fits, otherwise above (the join date flips up in the employee form), with the panel scrolling within the space if neither fits — so a modal's scrolling body can't clip it. It stays inside the modal's DOM, so the modal's focus trap includes it. Escape closes only the picker while it is open; with the picker closed, Escape reaches the modal as before.
- **Below 640px** a native date input on the same property, with the same `min`/`max`, takes the trigger's place.

## Filters are controls, not status badges

Status and timing filters are separate control groups of filter chips. Chips are controls, not actions: selected is the shared selected state ([Design System](DESIGN_SYSTEM.md#interaction-states)) — `bg-primary-50 text-primary-700 font-semibold` with a `ring-primary-600` border and a visible check (dark: `bg-primary-600/35 text-primary-200 ring-primary-500`), the same treatment as the Calendar/Table toggle and the current pagination page; unselected is a neutral outline at `font-medium`. Chips never use a solid fill — a solid primary fill is reserved for the page's primary action. Every chip carries `aria-pressed` and a focus-visible ring. Semantic attendance colors are reserved for attendance data and must not indicate filter selection. **Selecting a chip never changes its size**, so the chips after it never shift: every chip is a fixed `h-7`; an unselected chip carries the check's room as padding (`px-[1.375rem]`, 22px each side, against the selected `px-3` + 14px check + 6px gap), and the label reserves its semibold width with an invisible, `aria-hidden` semibold copy in the same grid cell.

The default filter state reads as "no filter applied". Status chips **narrow** rather than enumerate: while every working status (all but Off) is in the filter — the default — none of them renders as selected. Clicking one narrows to that status; further clicks add or remove statuses; removing the last one returns to all working statuses. Off days are a separate **Show off days** toggle, unselected by default. The stored filter, its URL form, and the default query are unchanged (every status except Off); only the presentation and toggle mapping are defined this way (`Attendance\Index::toggleStatus()`). Timing chips are unselected by default.

Filtering must preserve URL-bound state, employee scope, permissions, and the existing distinction between status and timing. Loading feedback should stay local to the records area and must not cause a large layout shift.

## Dense attendance tables

The Attendance table prioritizes scanability:

- Never hide or conditionally remove columns. Keep date, employee identity, status, In/Out times, worked duration, late, early leave, and department visible as distinct columns.
- Column order (owner decision; it reverses an interim Employee-and-Status-first order): Date, Employee, Department, In, Out, Worked, Late, Early, Status, then the row's navigation chevron. Status and the chevron are the trailing pair so they can be pinned together from `sm` to below `xl` (see Responsive behavior). The Employees directory follows the same shape: Employee, Department, Position, Start date, Status, then Actions.
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

**Pinned trailing columns from `sm` to below `xl` (640–1279px).** In both the Daily Attendance and Employees tables, the Status column and the trailing chevron/actions column are `position: sticky` on the right edge of the scroll container, so "did they attend" (or "are they active") and the way into the record stay on screen while the other columns scroll beneath them. Outside that band nothing is pinned. Below `sm` (phones) the tables scroll as one piece with the existing scroll cue: pinning the pair there covered about half the card (≈200px of 358px for Daily Attendance, ≈230px for Employees), leaving the leading columns barely visible. From `xl` up there is room for everything — even where the Daily Attendance table still scrolls (1280–1439px), it scrolls as one piece. The rules (`.table-pin` in `resources/css/app.css`, `pinnedColumns` in `resources/js/app.js`):

- Pinned cells are opaque and match the row exactly: the card surface at rest, the row hover surface while hovered. In dark mode the hover is a translucent `slate-750/60` over the card, so a pinned cell layers that same tint over the same card colour rather than approximating it with a solid shade.
- Status pins immediately left of the trailing column; its offset is that column's measured width, kept current as the layout changes.
- A subtle shadow on the pinned group's left edge shows **only while content is passing under it** — the table overflows and is not scrolled to its right end. When the table fits, or is scrolled fully right so the pinned columns sit in their natural place, there is no shadow.
- The row divider lives in each row's first cell and sits above the pinned cells, so it runs unbroken beneath them.

**Calendar below `sm`** (about 44px cells at 390px): the day number and status icon stack vertically instead of sitting side by side, and a holiday's name is replaced in the cell by a small fuchsia flag (unless the status icon already is the holiday flag) — the name stays in the cell's accessible label and in the day modal. Nothing in a cell clips or collides.

The Daily Attendance summary is the shared stat strip (Present, Absent, Incomplete). It is range-wide on purpose: it follows the date range only, not the status or timing chips, search, or department. So that it cannot be read as contradicting a filtered table, it shows its scope as right-aligned muted meta on the strip's header row (card header pattern), such as `1–29 Sep · all statuses`, which becomes `… · all employees, all statuses` while a search or department filter narrows the table. Present's subtext is the timing count (`7 late · 10 early`), not a duration.

Summary metrics may stack or use compact responsive columns, but labels, counts, percentages, and timing context must remain readable without truncating meaningful information.

## Dark mode and accessibility

- Every surface, border, label, control state, badge, loading state, and empty state must remain legible in light and dark mode.
- Interactive controls need visible keyboard focus and must not rely on hover or color alone.
- Fieldsets and labels preserve the relationship between filter controls and their purpose.
- Decorative icons are hidden from assistive technology; links and buttons retain meaningful accessible names.
- Native table semantics and horizontal scrolling must remain intact.
