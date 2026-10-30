<?php
// AUDIT-ONLY (Phase 6c). Exports every report CSV through the components'
// own download() with the clock pinned, so two checkouts can be compared
// byte for byte:
//   php scripts/audit/csv-6c.php <label>
// writes storage/app/csv-6c/<label>/<user>-<report>-<period>[-<filter>].csv
// for admin and aye.aye.mon (M1): attendance and overtime for an open and a
// closed month, leave for an open and a closed year (every type, as the CSV
// has them), plus a department filter. A refusal is written as the file's
// only line.
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;

[$script, $label] = $argv;
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Carbon::setTestNow('2026-10-30 10:00:00');
$dir = storage_path('app/csv-6c/'.$label);
@mkdir($dir, 0777, true);
$engineering = (string) Department::where('name', 'Engineering')->value('id');

$exports = [
    ['attendance', '2026-10', ''], ['attendance', '2026-09', ''], ['attendance', '2026-10', $engineering],
    ['leave', '2026', ''], ['leave', '2025', ''], ['leave', '2026', $engineering],
    ['overtime', '2026-10', ''], ['overtime', '2026-09', ''],
];

foreach (['admin', 'aye.aye.mon'] as $username) {
    $app['auth']->guard('web')->setUser(User::where('username', $username)->firstOrFail());

    foreach ($exports as [$report, $period, $department]) {
        $component = app('livewire')->new('reports.'.$report);
        if ($report === 'leave') {
            $component->year = $period;
        } else {
            $component->month = $period;
        }
        if ($department !== '') {
            $component->department = $department;
        }

        try {
            $response = $component->download();
            ob_start();
            $response->sendContent();
            $body = $response->headers->get('Content-Disposition')."\n".ob_get_clean();
        } catch (Illuminate\Auth\Access\AuthorizationException $e) {
            $body = 'forbidden';
        }

        $name = "{$username}-{$report}-{$period}".($department !== '' ? '-dept' : '').'.csv';
        file_put_contents("$dir/$name", $body);
        echo $name, ' ', strlen($body), "\n";
    }
}
