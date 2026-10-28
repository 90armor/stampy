<?php
// AUDIT-ONLY (Phase 6a): a pending Medical leave for the E1 persona (arg: Y-m-d), or cancel one (CANCEL=id).
$svc = app(App\Services\Leave\LeaveRequestService::class);
$e = App\Models\Employee::where('employee_code', 'AUD-004')->first();
if ($id = getenv('CANCEL')) { $svc->cancel(App\Models\Leave::find($id), $e->user); echo "cancelled $id\n"; return; }
$d = Illuminate\Support\Carbon::parse(getenv('DAY'));
$r = $svc->submit($e, App\Models\LeaveType::where('name', 'Medical')->first(), $d, $d, null, 'Stale check', $e->user);
echo 'leave ', $r['leave']->id ?? json_encode(array_keys($r)), "\n";
