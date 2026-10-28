<?php
// AUDIT-ONLY (Phase 6b screenshots). Env: STEP=create TYPE=leave|overtime → a
// pending request from Moe Moe Khaing (EMP-0009, manager Htet Htet Oo), first
// free date; prints its id. STEP=act ID=… TYPE=… ACTION=cancel|approve|reject|skip
// → moves it on behind the approver's back (skip = Aye Aye Mon, the
// skip-level manager, approves step 1).
use App\Enums\OvertimeCompensation;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;

$type = getenv('TYPE');
$service = app($type === 'leave' ? LeaveRequestService::class : OvertimeRequestService::class);
$e = Employee::where('employee_code', 'EMP-0009')->first();

if (getenv('STEP') === 'create') {
    $day = $type === 'leave' ? today()->addDays(33) : today()->addDays(1);
    for ($i = 0; $i < 60; $i++, $day->addDay()) {
        if ($type === 'leave' && $day->isWeekend()) {
            continue;
        }
        try {
            $r = $type === 'leave'
                ? $service->submit($e, LeaveType::where('name', 'Unpaid')->first(), $day, $day, null, 'Screenshot', $e->user)['leave']
                : $service->submit($e, $day, $day->copy()->setTime(17, 0), $day->copy()->setTime(19, 0), OvertimeCompensation::Pay, 'Screenshot', $e->user)['request'];
            echo "ID={$r->id}\n";

            return;
        } catch (Throwable $x) {
        }
    }
    echo "ID=none\n";

    return;
}

$r = $type === 'leave' ? Leave::find(getenv('ID')) : OvertimeRequest::find(getenv('ID'));
match (getenv('ACTION')) {
    'cancel' => $service->cancel($r, $e->user),
    'approve' => $service->approve($r, User::where('username', 'admin')->first()),
    'reject' => $service->reject($r, User::where('username', 'admin')->first(), 'Not this week.'),
    'skip' => $service->approve($r, User::where('username', 'aye.aye.mon')->first()),
};
echo "done\n";
