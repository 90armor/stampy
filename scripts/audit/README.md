# Audit tooling

What's left of the Phase 6 audit tooling (`docs/audit/UI_AUDIT.md`), kept so the audit can be run again. None of it is app code, a seeder, a test or a dependency. The modal focus check that runs before every merge is not here: it is `tests/browser/focus.mjs`. The per-slice screenshot and setup scripts of Phases 6b–6h were deleted at the close-out; they are in git history.

The PHP scripts run inside the `app` container and write to the **dev** `stampy` database.

| File | What it does |
|---|---|
| `seed-personas.php` | Adds the audit personas and stress data on top of a normal seed: admins with and without an employee record, managers with transitive reports, none or only a leaver, a new joiner, a leaver with a login, Khmer and very long names. Every account's password is `password`. |
| `stress-employees.php` | 70 extra employees, for the 100+ row directory. |
| `querycount.php` | `php scripts/audit/querycount.php <username> <path>`: status, queries, query ms, server ms and the most repeated query for one page, in a fresh app. |
| `pinned-seed.php` | `migrate:fresh --seed` with the clock pinned to Fri 2 Oct 2026 12:00, so a seed hash doesn't depend on the day. Stop the scheduler first. |
| `hash.php` | SHA-256 of every table's data (timestamps, password hashes and remember tokens left out). |
| `playwright/pages.mjs` | Every persona's pages (the list the other scripts share). Run directly, it shoots each at 390/1024/1440, light and dark, and records overflow, targets under 44px and 24px, console errors, the `<h1>`, the title, time to first byte and axe at 390 and 1440, into `docs/audit/shots/` and `storage/app/audit-data/<persona>.json`. |
| `playwright/axe.mjs` | axe at all three widths and both themes for every page in `pages.mjs`, the guest pages, and modal and error states, with a screenshot of each: `AXE_OUT=<dir> node axe.mjs <label>` writes `<dir>/<label>/axe.json`. |
| `playwright/journeys.mjs` | The 6a journeys: an employee on a phone (sign in with the employee code, a half day, an overtime claim and cancelling it), forgot password, a manager approving and rejecting, a request cancelled while the approver has it open, a lost session, the filters and the Tab order. `node journeys.mjs [journey …]`; notes into `storage/app/audit-data/`. Submits and decides real requests. |
| `playwright/admin-journeys.mjs` | The admin journeys, each saved through the UI and checked in the database: employees with and without a login, a schedule and an effective-dated change, a holiday, an opening-balance adjustment, deactivate and reactivate, leave and overtime filed for an employee with no login (with a limit override), a punch and a void with reasons, each report's CSV and print. Exits 1 if any fails. |
| `playwright/lib.mjs` | Shared by the scripts above: sign-in, themed pages, measurements, axe, full-page screenshots. |

## Running an audit again

1. **Data:** a fresh demo seed, then the personas and stress employees:
   ```bash
   docker compose exec app php artisan migrate:fresh --seed
   docker compose exec app php artisan tinker --execute="require 'scripts/audit/seed-personas.php';"
   docker compose exec app php artisan tinker --execute="require 'scripts/audit/stress-employees.php';"
   ```
   `pages.mjs` uses fixed employee ids and October dates from that seed; check them against the data before a run.
2. **Playwright** outside the repo, as for `tests/browser/` (its README): `npm i --no-save playwright@1.62.1 axe-core@4.10.3` in a scratch folder, copy `playwright/*.mjs` next to its `node_modules` (ES modules ignore `NODE_PATH`), and run with `STAMPY_REPO=<this checkout>/`, the app on http://localhost:8000.
3. **Order:** what reads before what writes — `axe.mjs` and `pages.mjs` first, then `querycount.php` for each page (`docker compose exec -T app php scripts/audit/querycount.php <user> <path> </dev/null` in a loop: without `</dev/null`, `exec` swallows the loop's input), then `journeys.mjs` and `admin-journeys.mjs`, which change the data.
4. **Afterwards:** `docker compose exec app php artisan migrate:fresh --seed`, and `docker compose start scheduler` if you stopped it.

**A seed hash** (to show a change leaves seeded data alone): stop the scheduler, `require 'scripts/audit/pinned-seed.php'` then `require 'scripts/audit/hash.php'` through tinker, on both checkouts, and compare. A table with a new column differs by that column alone; drop it from `hash.php`'s `$drop` list for the comparison.
