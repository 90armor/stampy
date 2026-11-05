<?php

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Services\Leave\LeaveRequestService;
use Illuminate\Support\Carbon;

// AUDIT-ONLY (Phase 6a): a pending Medical leave for the E1 persona (arg: Y-m-d), or cancel one (CANCEL=id).
$svc = app(LeaveRequestService::class);
$e = Employee::where('employee_code', 'AUD-004')->first();
if ($id = getenv('CANCEL')) {
    $svc->cancel(Leave::find($id), $e->user);
    echo "cancelled $id\n";

    return;
}
$d = Carbon::parse(getenv('DAY'));
$r = $svc->submit($e, LeaveType::where('name', 'Medical')->first(), $d, $d, null, 'Stale check', $e->user);
echo 'leave ', $r['leave']->id ?? json_encode(array_keys($r)), "\n";
