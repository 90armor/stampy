@props(['time'])

{{-- Renders nothing when $time is null — the caller decides what a missing
time looks like in its own context (an em dash, a blank cell, etc.), since
that differs by context (e.g. Off cells show nothing at all, Absent cells
still show "—"). Splits a trailing AM/PM off into its own smaller, muted
span per CLAUDE.md's time-display convention — "8:52 AM" reads with the
number as the primary signal, not the meridiem. Always goes through
App\Support\AttendanceTime::format() so every caller honours
config('attendance.time_format') the same way. --}}
@php
    $formatted = \App\Support\AttendanceTime::format($time);
@endphp
@if ($formatted !== null)
    @php
        preg_match('/^(.*?)\s*([AaPp][Mm])?$/', $formatted, $matches);
        $value = $matches[1];
        $meridiem = $matches[2] ?? null;
    @endphp
    <span {{ $attributes }}>{{ $value }}@if ($meridiem)<span class="ml-0.5 text-[0.8em] font-normal opacity-70">{{ $meridiem }}</span>@endif</span>
@endif
