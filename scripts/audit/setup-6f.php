<?php
// AUDIT-ONLY (Phase 6f screenshots), after a normal seed + seed-personas.php:
// pending overtime claims to cancel (F14) — up to PER (default 3) each for
// Kyaw Kyaw Naing, សុខ សុភាព and Sreyneang Kim, 6:00–6:30 PM on the last seven
// days, a day that already has a request skipped — and Kyaw's first one
// approved by his manager, so it waits at step 2 in the admin's queue. Prints
// one JSON line per claim: username, id, step, and the label the Overtime
// page's Cancel button carries.
use App\Enums\OvertimeCompensation;
use App\Models\User;
use App\Services\Approval\ApprovalFlow;
use App\Services\Overtime\OvertimeRequestService;

$service = app(OvertimeRequestService::class);
$per = (int) (getenv('PER') ?: 3);

foreach (['kyaw.kyaw.naing', 'audit.khmer', 'audit.new.joiner'] as $username) {
    $user = User::where('username', $username)->firstOrFail();
    $made = 0;
    for ($back = 1; $back <= 7 && $made < $per; $back++) {
        $day = today()->subDays($back);
        try {
            $request = $service->submit($user->employee, $day, $day->copy()->setTime(18, 0), $day->copy()->setTime(18, 30), OvertimeCompensation::Pay, 'Stayed to finish the stock count', $user)['request'];
        } catch (Throwable $e) {
            continue;
        }
        if ($username === 'kyaw.kyaw.naing' && $made === 0 && $request->current_step === ApprovalFlow::MANAGER_STEP) {
            $service->approve($request, app(ApprovalFlow::class)->stepOneApprovers($request)->first());
            $request->refresh();
        }
        echo json_encode(['user' => $username, 'id' => $request->id, 'step' => $request->current_step, 'label' => 'Cancel overtime request, '.$request->displayDateAndWindow()], JSON_UNESCAPED_UNICODE)."\n";
        $made++;
    }
}
