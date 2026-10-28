<?php
// AUDIT-ONLY (Phase 6a). Renders one page in-process as one user and prints
// its status, query count, query time and total server time. One fresh app
// per run, so nothing is memoized between pages.
//   php scripts/audit/querycount.php <username> <path>
use Illuminate\Support\Facades\DB;

[$script, $username, $path] = $argv;
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create($path, 'GET');
$app->instance('request', $request);
$kernel->bootstrap();
$app['auth']->guard('web')->setUser(App\Models\User::where('username', $username)->firstOrFail());

$queries = 0; $queryMs = 0.0; $sqls = [];
DB::listen(function ($q) use (&$queries, &$queryMs, &$sqls) {
    $queries++; $queryMs += $q->time;
    $key = preg_replace('/\b\d+\b/', '?', $q->sql);
    $sqls[$key] = ($sqls[$key] ?? 0) + 1;
});
$t = hrtime(true);
$response = $kernel->handle($request);
$ms = (hrtime(true) - $t) / 1e6;
arsort($sqls);
$top = array_slice($sqls, 0, 1, true);
printf("%s\t%s\t%d\t%d\t%.1f\t%.0f\t%dx %s\n", $username, $path, $response->getStatusCode(), $queries, $queryMs, $ms, reset($top), substr(key($top), 0, 90));
