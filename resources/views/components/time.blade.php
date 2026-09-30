@props(['time', 'marked' => false])

{{-- Renders nothing when $time is null — the caller decides what a missing
time looks like in its own context (an em dash, a blank cell, etc.), since
that differs by context (e.g. Off cells show nothing at all, Absent cells
still show "—"). Splits a trailing AM/PM off into its own smaller, muted
span per CLAUDE.md's time-display convention — "8:52 AM" reads with the
number as the primary signal, not the meridiem. The suffix is 0.8em but
never below 10px, so 12px calendar times keep a legible AM/PM. Always goes through
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
    <span {{ $attributes->class(['font-medium text-amber-700 dark:text-amber-300' => $marked]) }}>{{ $value }}@if ($meridiem)<span class="ml-0.5 text-[max(10px,0.8em)] font-normal opacity-70">{{ $meridiem }}</span>@endif</span>
@endif
