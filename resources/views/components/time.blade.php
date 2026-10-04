@props(['time', 'marked' => false])

{{-- Renders nothing when $time is null — the caller decides what a missing
time looks like in its own context (an em dash, a blank cell, etc.), since
that differs by context (e.g. Off cells show nothing at all, Absent cells
still show "—"). Splits a trailing AM/PM off into its own smaller, muted
span per CLAUDE.md's time-display convention — "8:52 AM" reads with the
number as the primary signal, not the meridiem. The suffix is 0.8em but
never below 10px, so 12px calendar times keep a legible AM/PM. It is
readable text, so its colour is a muted one that reaches 4.5:1 everywhere a
time appears — text-slate-600 (at least 6.96:1, on the tinted calendar
fills) and dark text-slate-400 (at least 4.90:1, on overlays) — not a
lowered opacity, which took it to 2.6–4.3:1. A marked time's suffix keeps
the amber of its number (at least 4.58:1 / 8.71:1). Always goes through
App\Support\AttendanceTime::format() so every caller honours
config('attendance.time_format') the same way.

`marked` is the one timing-annotation treatment for a time that caused a
timing exception (the calendar cell and day-detail modal's late In /
early Out): timing text — amber-700 in light mode, amber-300 in dark —
plus medium weight, never an underline — underline
is reserved for links. Status colour stays on the cell/badge; this only
annotates the specific value. See docs/ATTENDANCE_UI.md. --}}
@php
    $formatted = \App\Support\AttendanceTime::format($time);
@endphp
@if ($formatted !== null)
    @php
        preg_match('/^(.*?)\s*([AaPp][Mm])?$/', $formatted, $matches);
        $value = $matches[1];
        $meridiem = $matches[2] ?? null;
    @endphp
    <span {{ $attributes->class(['font-medium text-amber-700 dark:text-amber-300' => $marked]) }}>{{ $value }}@if ($meridiem)<span @class(['ml-0.5 text-[max(10px,0.8em)] font-normal', 'text-slate-600 dark:text-slate-400' => ! $marked])>{{ $meridiem }}</span>@endif</span>
@endif
