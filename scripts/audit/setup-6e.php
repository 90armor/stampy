<?php
// AUDIT-ONLY (Phase 6e screenshots), after a normal seed: a past workday in
// last month with no row for Kyaw Kyaw Naing (EMP-0002) — the calendar's
// "not calculated" notice — and a second, future schedule assignment for him,
// so the profile shows a delete icon with its tooltip; and (6e follow-up) an
// employee who joined in the middle of last month, built from their join
// date, for the calendar's quiet days before it. Prints what it did.
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\EmployeeScheduleAssigner;
use App\Support\WorkdayCalendar;

$kyaw = Employee::where('employee_code', 'EMP-0002')->firstOrFail();
$day = today()->subMonthNoOverflow()->startOfMonth()->addDays(9);
while (! WorkdayCalendar::isWorkday($kyaw, $day)) {
    $day->addDay();
}
DailyAttendance::where('employee_id', $kyaw->id)->whereDate('work_date', $day)->delete();
echo "missing row: {$day->toDateString()}\n";

$early = WorkSchedule::firstOrCreate(['name' => 'Early shift'], [
    'start_time' => '07:00:00', 'end_time' => '16:00:00', 'grace_minutes' => 10, 'break_minutes' => 60,
    'break_start' => '11:00:00', 'workdays' => [1, 2, 3, 4, 5], 'is_default' => false,
]);
app(EmployeeScheduleAssigner::class)->assign($kyaw, $early, today()->addDays(30));
echo "assignment: Early shift from ".today()->addDays(30)->toDateString()." (employee id {$kyaw->id})\n";

$joined = today()->subMonthNoOverflow()->startOfMonth()->addDays(13);
$joiner = Employee::firstOrCreate(['employee_code' => 'AUD-008'], [
    'full_name' => 'Mid Month Joiner', 'department_id' => $kyaw->department_id, 'position_id' => $kyaw->position_id,
    'join_date' => $joined->toDateString(), 'manager_id' => $kyaw->manager_id,
]);
Illuminate\Support\Facades\Artisan::call('attendance:build-daily', ['--from' => $joined->toDateString(), '--to' => today()->toDateString(), '--employee' => 'AUD-008']);
echo "joiner: AUD-008 from {$joined->toDateString()} (employee id {$joiner->id})\n";
