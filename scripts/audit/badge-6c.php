<?php
// AUDIT-ONLY (Phase 6c). Prints every user's approvals badge and inbox —
// each group's items, whether each is stuck and its away line — at a few
// pinned instants, so two checkouts can be compared line for line:
//   php scripts/audit/badge-6c.php > out.txt
use App\Contracts\Approvable;
use App\Models\User;
use App\Services\Approval\ApprovalInbox;
use App\Support\ApprovalBadge;
use Carbon\Carbon;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (['2026-10-30 10:00:00', '2026-10-31 12:00:00', '2026-11-02 08:30:00', '2026-11-08 23:00:00'] as $instant) {
    Carbon::setTestNow($instant);
    echo "== $instant\n";

    foreach (User::orderBy('username')->get() as $user) {
        $app['auth']->guard('web')->setUser($user);
        $app->instance('request', Illuminate\Http\Request::create('/'));
        $inbox = ApprovalInbox::for($user);
        echo $user->username, ' badge=', ApprovalBadge::count($user), ' countOf=', ApprovalInbox::countOf($inbox), "\n";

        foreach ($inbox as $group => $items) {
            foreach ($items as $item) {
                /** @var Approvable $item */
                echo '  ', $group, ' ', $item->getMorphClass(), '#', $item->getKey(),
                    ' stuck=', ApprovalInbox::isStuck($item) ? 1 : 0,
                    ' away=', ApprovalInbox::awayReason($item) ?? '-', "\n";
            }
        }
    }
}
