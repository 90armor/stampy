<?php
// AUDIT-ONLY (Phase 6a). Prints a SHA-256 of every table's substantive data,
// excluding timestamps, password hashes and remember tokens (per-run noise).
// Not part of the app; safe to delete.
use Illuminate\Support\Facades\DB;
$skip = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];
$drop = ['created_at', 'updated_at', 'password', 'remember_token'];
$db = DB::getDatabaseName();
foreach (DB::select('select table_name as t from information_schema.tables where table_schema = ? order by table_name', [$db]) as $row) {
    if (in_array($row->t, $skip, true)) continue;
    $rows = DB::table($row->t)->get()->map(function ($r) use ($drop) { $a = (array) $r; foreach ($drop as $d) unset($a[$d]); ksort($a); return $a; })->all();
    usort($rows, fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));
    echo str_pad($row->t, 28), str_pad((string) count($rows), 7), substr(hash('sha256', json_encode($rows)), 0, 16), "\n";
}
