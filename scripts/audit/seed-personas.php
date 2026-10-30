<?php

// AUDIT-ONLY (Phase 6a UI/UX audit). Not a seeder, not part of the app.
// Adds the audit personas and stress data on top of the normal demo seed,
// through the app's own models and services. Run after a fresh
// `migrate:fresh --seed`, then reseed to remove everything it adds:
//   docker compose exec app php artisan tinker --execute="require 'scripts/audit/seed-personas.php';"
// Every account it creates has the password "password".

use App\Enums\LeaveHalf;
use App\Enums\OvertimeCompensation;
use App\Enums\PunchSource;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\EmployeeLifecycle;
use App\Services\Leave\LeaveDayCounter;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

$admin = User::where('username', 'admin')->firstOrFail();
$builder = app(DailySummaryBuilder::class);
$position = Position::orderBy('id')->firstOrFail();
$engineering = Department::where('name', 'Engineering')->firstOrFail();
$longDept = Department::create(['name' => 'Human Resources and Administration Department', 'description' => 'Audit-only department with a long name']);
$annual = LeaveType::where('name', 'Annual')->firstOrFail();
$today = today();

$makeUser = function (string $username, string $name, ?string $email, string $role) {
    $user = new User;
    $user->forceFill([
        'name' => $name,
        'username' => $username,
        'email' => $email,
        'password' => Hash::make('password'),
        'must_change_password' => false,
    ])->save();
    $user->assignRole($role);

    return $user;
};

$makeEmployee = fn (array $attrs) => Employee::create($attrs + [
    'position_id' => $position->id,
    'department_id' => $engineering->id,
]);

// Ordinary punches on every scheduled workday in a range (08:00–17:00 with
// small, reproducible variation), then a rebuild.
$punchWorkdays = function (Employee $employee, Carbon $from, Carbon $to) use ($builder, $admin) {
    for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
        if ($d->isWeekend() || $d->isSameDay(today())) {
            continue;
        }
        $seed = (int) $d->format('j');
        foreach ([[$d->copy()->setTime(7, 50 + $seed % 9), 'in'], [$d->copy()->setTime(17, 2 + $seed % 7), 'out']] as [$at, $type]) {
            AttendanceLog::create(['employee_id' => $employee->id, 'punched_at' => $at, 'punch_type' => $type, 'source' => PunchSource::Manual, 'created_by' => $admin->id]);
        }
    }
    $builder->rebuildBetween($employee, $from, today());
};

$punch = function (Employee $employee, string $at, string $type) use ($admin) {
    AttendanceLog::create(['employee_id' => $employee->id, 'punched_at' => Carbon::parse($at), 'punch_type' => $type, 'source' => PunchSource::Manual, 'created_by' => $admin->id]);
};

// A1 — admin linked to an employee record.
$a1User = $makeUser('audit.admin', 'Sophea Chan', 'audit.admin@example.com', 'admin');
$a1 = $makeEmployee(['user_id' => $a1User->id, 'employee_code' => 'AUD-001', 'full_name' => 'Sophea Chan', 'department_id' => $longDept->id, 'join_date' => '2021-03-01']);
$punchWorkdays($a1, $today->copy()->subDays(40), $today->copy());

// M2a — manager with no reports at all.
$m2aUser = $makeUser('audit.manager.none', 'Dara Pich', 'audit.manager.none@example.com', 'manager');
$m2a = $makeEmployee(['user_id' => $m2aUser->id, 'employee_code' => 'AUD-002', 'full_name' => 'Dara Pich', 'department_id' => $longDept->id, 'join_date' => '2022-01-10']);
$punchWorkdays($m2a, $today->copy()->subDays(20), $today->copy());

// M2b — manager whose only report has left (E4 below).
$m2bUser = $makeUser('audit.manager.gone', 'Bopha Ly', null, 'manager');
$m2b = $makeEmployee(['user_id' => $m2bUser->id, 'employee_code' => 'AUD-003', 'full_name' => 'Bopha Ly', 'department_id' => $longDept->id, 'join_date' => '2022-02-14']);

// E1 — under 12 months of service (Annual not usable yet), with a login, reporting to Aye Aye Mon.
$aye = Employee::where('employee_code', 'EMP-0001')->firstOrFail();
$e1User = $makeUser('audit.new.joiner', 'Sreyneang Kim', null, 'employee');
$e1 = $makeEmployee(['user_id' => $e1User->id, 'employee_code' => 'AUD-004', 'full_name' => 'Sreyneang Kim', 'join_date' => $today->copy()->subMonths(5)->startOfMonth()->addDay()->toDateString(), 'manager_id' => $aye->id]);
$punchWorkdays($e1, $today->copy()->subDays(45), $today->copy()->subDay());

// E4 — deactivated employee with a login (left on the 16th of this month or last), reporting to M2b.
$e4User = $makeUser('audit.leaver', 'Visal Chea', 'audit.leaver@example.com', 'employee');
$e4 = $makeEmployee(['user_id' => $e4User->id, 'employee_code' => 'AUD-005', 'full_name' => 'Visal Chea', 'join_date' => '2024-02-01', 'manager_id' => $m2b->id]);
$leftOn = $today->day > 16 ? $today->copy()->day(16) : $today->copy()->subMonth()->day(16);
$punchWorkdays($e4, $leftOn->copy()->subDays(30), $leftOn->copy());
app(EmployeeLifecycle::class)->deactivate($e4->fresh(), $leftOn, $admin);

// Very long name (no login) in the long department, under Aye Aye Mon.
$long = $makeEmployee(['employee_code' => 'AUD-006', 'full_name' => 'Aung Kyaw Moe Thandar Htun Myint Myat Phyo Wai Lwin', 'department_id' => $longDept->id, 'join_date' => '2023-03-01', 'manager_id' => $aye->id]);
$punchWorkdays($long, $today->copy()->subDays(30), $today->copy()->subDay());

// Khmer-script name, with a login, under Aye Aye Mon; a pending leave with a very long reason.
$khUser = $makeUser('audit.khmer', 'សុខ សុភាព', null, 'employee');
$kh = $makeEmployee(['user_id' => $khUser->id, 'employee_code' => 'AUD-007', 'full_name' => 'សុខ សុភាព', 'join_date' => '2023-05-15', 'manager_id' => $aye->id]);
$punchWorkdays($kh, $today->copy()->subDays(30), $today->copy()->subDay());
$leaves = app(LeaveRequestService::class);
$future = $today->copy()->addWeeks(2)->startOfWeek();
// A week of holidays (9–11 Nov) has no working day to request: try the next one.
for ($i = 0; $i < 3 && array_sum(app(LeaveDayCounter::class)->count($kh->fresh(), $annual, $future, $future->copy()->addDays(2))) === 0; $i++) {
    $future->addWeek();
}
$leaves->submit($kh->fresh(), $annual, $future, $future->copy()->addDays(2), null,
    str_repeat('My grandmother in Battambang is having surgery and I need to travel there to look after her during the recovery, arrange the hospital paperwork with my cousins, and make sure the house is ready for her return. ', 4).'ចាំបាច់ត្រូវទៅជួយគ្រួសារ។',
    $khUser);

// E2 — long-tenure Kyaw Kyaw Naing (EMP-0002): last year's Annual (so this year has carry-over),
// a morning half-day with attendance the same afternoon, a late + early day,
// and approved time-off overtime crossing 22:00 (admin override over the 2h cap).
$e2 = Employee::where('employee_code', 'EMP-0002')->firstOrFail();
LeaveEntitlement::create(['employee_id' => $e2->id, 'leave_type_id' => $annual->id, 'year' => $today->year - 1, 'days' => 19, 'granted_by' => $admin->id]);

$weekday = fn (Carbon $d) => $d->isWeekend() ? $d->previousWeekday() : $d;
$halfDay = $weekday($today->copy()->subDays(8));
AttendanceLog::where('employee_id', $e2->id)->whereDate('punched_at', $halfDay)->get()->each->delete();
$punch($e2, $halfDay->format('Y-m-d').' 13:04:00', 'in');
$punch($e2, $halfDay->format('Y-m-d').' 17:06:00', 'out');
$leaves->submit($e2->fresh(), $annual, $halfDay, $halfDay, LeaveHalf::Am, 'Doctor appointment in the morning', $admin);

$lateEarly = $weekday($today->copy()->subDays(9));
AttendanceLog::where('employee_id', $e2->id)->whereDate('punched_at', $lateEarly)->get()->each->delete();
$punch($e2, $lateEarly->format('Y-m-d').' 08:27:00', 'in');
$punch($e2, $lateEarly->format('Y-m-d').' 16:31:00', 'out');

$otDay = $weekday($today->copy()->subDays(5));
AttendanceLog::where('employee_id', $e2->id)->whereDate('punched_at', $otDay)->get()->each->delete();
$punch($e2, $otDay->format('Y-m-d').' 07:56:00', 'in');
$punch($e2, $otDay->format('Y-m-d').' 23:12:00', 'out');
$builder->rebuildBetween($e2->fresh(), $lateEarly->copy()->subDay(), $today->copy());
app(OvertimeRequestService::class)->submit(
    $e2->fresh(), $otDay, $otDay->copy()->setTime(19, 0), $otDay->copy()->setTime(23, 0),
    OvertimeCompensation::TimeOff, 'Production release went late; stayed for the rollback window.', $admin,
    'Release night agreed with the team lead in advance.',
);

echo "Audit personas created.\n";
foreach (['audit.admin', 'audit.manager.none', 'audit.manager.gone', 'audit.new.joiner', 'audit.leaver', 'audit.khmer'] as $u) {
    echo "  {$u} / password\n";
}
