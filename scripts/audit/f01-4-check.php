<?php
// AUDIT-ONLY (Phase 6b, F01.4 — report only): what happens to a leaver's
// pending requests, and whether an admin can file for a leaver after left_on.
// Uses AUD-007 (the Khmer-name persona) on the dev database; reseed afterwards.
use App\Enums\OvertimeCompensation;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Approval\ApprovalInbox;
use App\Services\EmployeeLifecycle;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;

$admin = User::where('username', 'admin')->first();
$manager = User::where('username', 'aye.aye.mon')->first();
$e = Employee::where('employee_code', 'AUD-007')->first();
$leaves = app(LeaveRequestService::class);
$ot = app(OvertimeRequestService::class);
$day = today()->subDays(3); while ($day->isWeekend()) { $day->subDay(); }
$otDay = today()->subDays(2); while ($otDay->isWeekend()) { $otDay->subDay(); }

$leave = $leaves->submit($e, LeaveType::where('name', 'Medical')->first(), $day, $day, null, 'F01.4 check', $e->user)['leave'];
$claim = $ot->submit($e, $otDay, $otDay->copy()->setTime(17, 0), $otDay->copy()->setTime(18, 0), OvertimeCompensation::Pay, 'F01.4 check', $e->user)['request'];
$future = $e->leaves()->where('status', 'pending')->where('start_date', '>', today())->first();
echo "before: leave #{$leave->id} {$leave->status->value} (".$day->toDateString()."), claim #{$claim->id} {$claim->status->value} ({$otDay->toDateString()}), future leave #".($future?->id ?? '-')."\n";

$leftOn = today()->subDay(); while ($leftOn->lt($day) || $leftOn->lt($otDay)) { $leftOn->addDay(); }
app(EmployeeLifecycle::class)->deactivate($e->fresh(), $leftOn, $admin);
echo "deactivated, left_on {$leftOn->toDateString()}\n";
echo "after: leave {$leave->fresh()->status->value}, claim {$claim->fresh()->status->value}, future leave ".($future?->fresh()->status->value ?? '-')."\n";

$inbox = ApprovalInbox::for($manager);
$ids = $inbox['stepOne']->map(fn ($i) => class_basename($i).'#'.$i->id)->implode(', ');
echo "manager step-1 queue holds: {$ids}\n";
echo "manager badge count: ".ApprovalInbox::count($manager)."\n";

$r = $leaves->approve($leave->fresh(), $manager);
echo "manager approves the leaver's leave: now {$r['leave']->status->value}, step {$r['leave']->current_step}\n";
$r = $leaves->approve($leave->fresh(), $admin);
echo "admin approves it: now {$r['leave']->status->value}\n";

foreach ([[today()->addDay(), 'tomorrow'], [$leftOn->copy()->addDay(), 'the day after left_on']] as [$d, $label]) {
    try { $leaves->submit($e->fresh(), LeaveType::where('name', 'Annual')->first(), $d, $d, null, null, $admin); echo "admin files leave for {$label}: ACCEPTED\n"; }
    catch (Throwable $x) { echo "admin files leave for {$label}: refused — ".$x->getMessage()."\n"; }
}
try { $ot->submit($e->fresh(), today(), today()->setTime(18, 0), today()->setTime(19, 0), OvertimeCompensation::Pay, null, $admin); echo "admin files overtime for today: ACCEPTED\n"; }
catch (Throwable $x) { echo "admin files overtime for today: refused — ".$x->getMessage()."\n"; }
try { $d = $leftOn->copy()->subDays(1); $leaves->submit($e->fresh(), LeaveType::where('name', 'Annual')->first(), $d, $d, null, null, $admin); echo "admin files leave on/before left_on ({$d->toDateString()}): ACCEPTED\n"; }
catch (Throwable $x) { echo "admin files leave before left_on: refused — ".$x->getMessage()."\n"; }
