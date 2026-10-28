<?php
// AUDIT-ONLY (Phase 6a). migrate:fresh --seed with the clock pinned to
// Fri 2 Oct 2026 12:00, so the seed hash is independent of the day it runs.
// Stop the scheduler first. Wipes the dev `stampy` database.
\Carbon\Carbon::setTestNow('2026-10-02 12:00:00');
\Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed' => true]);
echo \Illuminate\Support\Facades\Artisan::output();
