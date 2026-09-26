# Attendance UI

This document is the presentation contract for Stampy's Attendance views. The Daily Attendance page is the reference implementation for data-heavy screens; it must remain dense, readable, responsive, and faithful to the attendance domain.

## Status and timing are separate

Attendance status answers whether an employee attended: `present`, `incomplete`, `absent`, `off`, `holiday`, `leave`, or `in_progress`. Timing describes how a worked day differed from schedule through `late_minutes` and `early_leave_minutes`.

There is no `Late` attendance status. A person who arrived late or left early still has the underlying `present` status. A day may have either timing exception or both without requiring a combined status.

`DailyAttendance::displayVariant()` is the only source of truth for the semantic display variant. Views must not independently infer a color from status or timing fields.

The semantic mapping is:

| Display variant | Meaning |
| --- | --- |
| `green` | Present without a timing exception |
| `amber` | Present with late and/or early timing |
| `violet` | Incomplete |
| `red` | Absent |
| `slate` | Off |
| `accent` | Leave |
| `blue` | In progress |
| `fuchsia` | Holiday |

Color always accompanies readable text or another non-color signal. Status badges show the attendance status, while timing values live in the Late and Early leave columns or fields. Marked In/Out times identify which punch caused a timing exception. Do not add separate timing chips that duplicate those fields.

Summary timing copy stays concise and omits zero values: `49 late`, `152 early`, or `49 late · 152 early`.

## Filters are controls, not status badges

Status and timing filters are separate control groups. Their selected treatment uses the primary interaction palette, with a visible check, `aria-pressed`, and a focus-visible ring. Semantic attendance colors are reserved for attendance data and must not indicate filter selection.

Filtering must preserve URL-bound state, employee scope, permissions, and the existing distinction between status and timing. Loading feedback should stay local to the records area and must not cause a large layout shift.

## Dense attendance tables

The Attendance table prioritizes scanability:

- Keep employee identity, status, scheduled and marked times, worked duration, late minutes, and early-leave minutes visible as distinct columns.
- Use tabular numerals and prevent time values from wrapping.
- Preserve clear headers, restrained row height, visible row separators, and accessible employee/detail links.
- Keep status badges driven by `displayVariant()`; never recreate semantic logic in Blade.
- Use a compact in-card empty state that explains the result and offers filter reset when applicable.

## Responsive behavior

On narrow screens, preserve the full table and native horizontal scrolling instead of hiding columns, converting rows into cards, or compressing data until it becomes unreadable. The table has a readable minimum width and its container uses `overflow-x-auto`.

A short, non-interactive “Scroll to view all columns” cue appears above the table on narrow screens. It is hidden at the desktop breakpoint and requires no JavaScript. The cue is supplementary; native scrolling remains the interaction.

Summary metrics may stack or use compact responsive columns, but labels, counts, percentages, and timing context must remain readable without truncating meaningful information.

## Dark mode and accessibility

- Every surface, border, label, control state, badge, loading state, and empty state must remain legible in light and dark mode.
- Interactive controls need visible keyboard focus and must not rely on hover or color alone.
- Fieldsets and labels preserve the relationship between filter controls and their purpose.
- Decorative icons are hidden from assistive technology; links and buttons retain meaningful accessible names.
- Native table semantics and horizontal scrolling must remain intact.
