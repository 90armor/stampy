# Phase 6a audit tooling — AUDIT-ONLY

Throwaway tooling behind `docs/audit/UI_AUDIT.md`. None of it is app code, a seeder, a test or a dependency; delete the folder once the audit's fix slices are done.

All PHP scripts run inside the `app` container and write to the **dev** `stampy` database.

| File | What it does |
|---|---|
| `pinned-seed.php` | `migrate:fresh --seed` with the clock pinned to Fri 2 Oct 2026 12:00. Stop the scheduler first. |
| `hash.php` | SHA-256 of every table's data (timestamps, password hashes, remember tokens excluded). |
| `seed-personas.php` | Adds the audit personas and stress data on top of a normal seed. Every account's password is `password`. |
| `stale-setup.php` | A pending Medical leave for persona E1 (`DAY=YYYY-MM-DD`), or cancels one (`CANCEL=id`). |
| `stress-employees.php` | 70 extra employees, for the 100+ row directory. |
| `querycount.php` | `php scripts/audit/querycount.php <username> <path>`: status, queries, query ms, server ms and the most repeated query for one page, in a fresh app. |
| `playwright/` | The screenshot/measurement harness (`pages.mjs`) and the journeys. Needs `playwright` and `axe-core` installed next to it (e.g. `npm i --no-save playwright@1.62.1 axe-core@4.10.3` in a scratch folder, then run with `NODE_PATH`), with the app on http://localhost:8000. Run from the repo root. |

Run a tinker script: `docker compose exec app php artisan tinker --execute="require 'scripts/audit/seed-personas.php';"`

**Restoring the dev database afterwards** (as the audit did): `docker compose stop scheduler`, `pinned-seed.php`, `hash.php` (compare with the baseline in the report), then `docker compose exec app php artisan migrate:fresh --seed` and `docker compose start scheduler`.
