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
| `f01-4-check.php` | Phase 6b: what happens to a leaver's pending requests, and filing for a leaver (report only; reseed afterwards). |
| `stale-6b.php`, `reset-token.php` | Phase 6b screenshots: a pending request moved on behind the approver's back; a reset token for the reset-password page. |
| `own-employee-id.php` | Phase 6b follow-up screenshots: the id of persona A1's own employee record (AUD-001). |
| `querycount.php` | `php scripts/audit/querycount.php <username> <path>`: status, queries, query ms, server ms and the most repeated query for one page, in a fresh app. |
| `playwright/` | The screenshot/measurement harness (`pages.mjs`), the journeys, `shots-6b.mjs` (Phase 6b's screenshots, into `docs/audit/shots/6b/`) and `shots-6b-fix.mjs` (the 6b follow-up's four spots, into `docs/audit/shots/6b-fix/`; `ONLY=icons` retakes the directory icon alone). Needs `playwright` and `axe-core` installed next to it (e.g. `npm i --no-save playwright@1.62.1 axe-core@4.10.3` in a scratch folder, then copy these `.mjs` files next to that `node_modules` and run them there — ES modules ignore `NODE_PATH` — after pointing `REPO` in `lib.mjs` at this checkout), with the app on http://localhost:8000. Run from the repo root. |

Run a tinker script: `docker compose exec app php artisan tinker --execute="require 'scripts/audit/seed-personas.php';"`

**Restoring the dev database afterwards** (as the audit did): `docker compose stop scheduler`, `pinned-seed.php`, `hash.php` (compare with the baseline in the report), then `docker compose exec app php artisan migrate:fresh --seed` and `docker compose start scheduler`.
