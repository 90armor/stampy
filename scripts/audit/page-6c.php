<?php
// AUDIT-ONLY (Phase 6c). Renders one page in-process as one user with the
// clock pinned and prints its visible text (tags, scripts, Livewire
// snapshots and tokens left out), so two checkouts can be diffed:
//   php scripts/audit/page-6c.php <username> <path>
[$script, $username, $path] = $argv;
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create($path, 'GET');
$app->instance('request', $request);
$kernel->bootstrap();
Carbon\Carbon::setTestNow('2026-10-30 10:00:00');
$app['auth']->guard('web')->setUser(App\Models\User::where('username', $username)->firstOrFail());

$html = $kernel->handle($request)->getContent();
$html = preg_replace(['#<script\b.*?</script>#s', '#<style\b.*?</style>#s', '#<template\b[^>]*>#', '#</template>#'], ' ', $html);
$text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)));
echo preg_replace('/[ \t]*\n\s*/', "\n", preg_replace('/[ \t]+/', ' ', trim($text))), "\n";
