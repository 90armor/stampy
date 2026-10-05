{{-- A day's annotations under its status badge (Phase 3e), in the tables:
"AM leave" / "PM leave" on a half-day leave day, "Worked on leave" when the
punches fall in leave time (DailyAttendance::workedOnLeave()), and the
holiday's name on a holiday the badge doesn't already name — a present day
on a holiday, so its zero late/early minutes are explained. Annotations,
never the badge colour (colour = status only). The leave facts read
leaveDay(): resolve a list with DailyAttendance::withLeaveDays() first.

$record: the day's DailyAttendance. $holiday: the holiday's name, or null. --}}
@props(['record', 'holiday' => null])

@php
    $leaveDay = $record->leaveDay();
    $leaveLine = implode(' · ', array_filter([
        $leaveDay->isHalfDay() ? $leaveDay->half->label().' leave' : null,
        $record->workedOnLeave() ? 'Worked on leave' : null,
    ]));
    $holidayLine = $holiday !== null && $record->status !== \App\Enums\AttendanceStatus::Holiday ? $holiday : null;
@endphp

@if ($leaveLine !== '')
    <span class="mt-0.5 block whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ $leaveLine }}</span>
@endif
@if ($holidayLine)
    {{-- Fuchsia, as the calendar cell and the day modal name a holiday. --}}
    <span class="mt-0.5 block whitespace-nowrap text-xs font-medium text-fuchsia-700 dark:text-fuchsia-300">{{ $holidayLine }}</span>
@endif
