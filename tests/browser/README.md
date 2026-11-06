# Browser checks

`focus.mjs` is the one check that needs a real browser: the modal focus rules (where focus goes on open, on every close, on a step change, inside the trap), the validation-error focus, the Filters disclosure, the file-for title and a punch correction's reason (CLAUDE.md, Before merge). It isn't part of `php artisan test`, and it changes the dev database.

1. **Data:** a fresh demo seed plus the audit personas and stress employees:
   ```bash
   docker compose exec app php artisan migrate:fresh --seed
   docker compose exec app php artisan tinker --execute="require 'scripts/audit/seed-personas.php';"
   docker compose exec app php artisan tinker --execute="require 'scripts/audit/stress-employees.php';"
   ```
2. **Playwright,** outside the repo (it isn't a dependency of the app). ES modules don't read `NODE_PATH`, so the scripts run from next to `node_modules`:
   ```bash
   mkdir -p /tmp/stampy-browser && cd /tmp/stampy-browser
   npm init -y && npm i --no-save playwright@1.62.1
   npx playwright install chromium
   cp <repo>/tests/browser/*.mjs .
   STAMPY_REPO=<repo>/ node focus.mjs
   ```
   With the app on http://localhost:8000 (`STAMPY_BASE` to change it; for a checkout served by another container, `STAMPY_EXEC="docker exec <container>"` is how the script runs its one artisan lookup — `docker compose exec -T app` by default). Each check prints `PASS` or `FAIL`, or `SKIP` with the reason when the data of the moment can't exercise it (before about 8:11 nobody is late yet, so Needs attention has no Show all); the script exits 1 on any failure, never on a skip.
3. **Afterwards,** reseed (`migrate:fresh --seed`): it submits, decides and cancels real requests.
