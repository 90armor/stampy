{{-- A day's annotations under its status badge (Phase 3e), in the tables:
"AM leave" / "PM leave" on a half-day leave day, "Worked on leave" when the
punches fall in leave time (DailyAttendance::workedOnLeave()), and the
holiday's name on a holiday the badge doesn't already name — a present day
on a holiday, so its zero late/early minutes are explained. A view with a
Note column names the holiday there instead and passes no $holiday. Annotations,
never the badge colour (colour = status only). The leave facts read
leaveDay(): resolve a list with DailyAttendance::withLeaveDays() first.

$record: the day's DailyAttendance. $holiday: the holiday's name, or null. --}}
@props(['record', 'holiday' => null])

@php
    $leaveDay = $record->leaveDay();
    // One fact per line, each short: joined on one line they widened the
    // Daily attendance table's pinned Status column into a scroll at 1440.
    $leaveLines = array_filter([
        $leaveDay->isHalfDay() ? $leaveDay->half->label().' leave' : null,
        $record->workedOnLeave() ? 'Worked on leave' : null,
    ]);
    $holidayLine = $holiday !== null && $record->status !== \App\Enums\AttendanceStatus::Holiday ? $holiday : null;
@endphp

@foreach ($leaveLines as $line)
    <span class="mt-0.5 block whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ $line }}</span>
@endforeach
@if ($holidayLine)
    {{-- Muted like the other notes: colour is for status only, and a
    fuchsia name under a badge read as a second status, close to
    Incomplete's violet. A name can be long ("Commemoration Day of King
    Father Norodom Sihanouk"), so it wraps within a fixed width rather than
    widening the column. --}}
    <span class="mt-0.5 block w-40 text-xs leading-4 text-slate-500 dark:text-slate-400">{{ $holidayLine }}</span>
@endif
