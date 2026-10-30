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
| `csv-6c.php`, `badge-6c.php`, `page-6c.php` | Phase 6c: every report CSV through the components' own `download()` with the clock pinned (`php scripts/audit/csv-6c.php <label>` → `storage/app/csv-6c/<label>/`); every user's badge and inbox — groups, stuck flags, away lines — at four pinned instants; and one page's visible text with the clock pinned (`php scripts/audit/page-6c.php <username> <path>`). Run on two checkouts and `diff`. `playwright/shots-6c.mjs` takes the Reports screenshots into `docs/audit/shots/6c/`. |
| `playwright/axe-6d.mjs`, `axe-6d-report.mjs`, `pixeldiff-6d.mjs`, `check-6d.mjs`, `keyboard-6d.mjs` | Phase 6d: axe at all three widths for every 6a page and persona, plus four modal states, with a screenshot of each (`OUT_6D=<dir> node axe-6d.mjs before`, again as `after`); the before/after table by rule id; a pixel diff of the two runs; what only a real browser can show (focus, titles after a tab switch, the picker's announcements), with the slice's screenshots in `docs/audit/shots/6d/`; and the employee and manager journeys by keyboard alone, which submit and decide real requests, so reseed afterwards. Need `pixelmatch` and `pngjs` too for the diff. `STAMPY_REPO=<checkout>/` replaces editing `REPO` in `lib.mjs`. |
| `playwright/focus-6d2.mjs`, `keyboard-6d2.mjs`, `shots-6d2.mjs` | Phase 6d2: the modal focus rules in a real browser (one check per close rule, never another row's Approve, the trap, steps, names, tapped labels), exiting 1 on any failure; the journeys by keyboard alone as a table of moment → where focus lands; and the slice's screenshots in `docs/audit/shots/6d2/` with an accessibility-tree check. The first two submit and decide real requests: reseed afterwards. |
| `querycount.php` | `php scripts/audit/querycount.php <username> <path>`: status, queries, query ms, server ms and the most repeated query for one page, in a fresh app. |
| `playwright/` | The screenshot/measurement harness (`pages.mjs`), the journeys, `shots-6b.mjs` (Phase 6b's screenshots, into `docs/audit/shots/6b/`) and `shots-6b-fix.mjs` (the 6b follow-up's four spots, into `docs/audit/shots/6b-fix/`; `ONLY=icons` retakes the directory icon alone). Needs `playwright` and `axe-core` installed next to it (e.g. `npm i --no-save playwright@1.62.1 axe-core@4.10.3` in a scratch folder, then copy these `.mjs` files next to that `node_modules` and run them there — ES modules ignore `NODE_PATH` — after pointing `REPO` in `lib.mjs` at this checkout), with the app on http://localhost:8000. Run from the repo root. |

Run a tinker script: `docker compose exec app php artisan tinker --execute="require 'scripts/audit/seed-personas.php';"`

**Restoring the dev database afterwards** (as the audit did): `docker compose stop scheduler`, `pinned-seed.php`, `hash.php` (compare with the baseline in the report), then `docker compose exec app php artisan migrate:fresh --seed` and `docker compose start scheduler`.
