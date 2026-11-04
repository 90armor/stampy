#!/bin/sh
# Run the demo seed with the clock pinned to every day of 2027 and to 29 Feb
# 2028 (Tests\Feature\SeedAtAnyDateTest with SEED_SWEEP=full; the suite runs
# a sample of the same dates). It must never throw. About 20 minutes; one
# suite at a time, so don't commit while it runs (CLAUDE.md, Local environment).
#
# Usage, from the host with Docker Compose up:
#   scripts/seed-sweep.sh
#
# The full output is kept in storage/app/seed-sweeps/<YYYYmmdd-HHMMSS>.log
# (gitignored). Exits 1 if any date failed.

cd "$(git rev-parse --show-toplevel)" || exit 1

if ! docker compose ps --status running --services 2>/dev/null | grep -qx app; then
    echo "The app container isn't running: docker compose up -d" >&2
    exit 1
fi

if docker compose exec -T app sh -c 'ps -eo args | grep -q "[v]endor/bin/phpunit"'; then
    echo "A phpunit process is already running in the app container; wait for it or stop it first." >&2
    exit 1
fi

mkdir -p storage/app/seed-sweeps
log="storage/app/seed-sweeps/$(date +%Y%m%d-%H%M%S).log"
echo "Log: $log"

docker compose exec -T -e SEED_SWEEP=full app php vendor/bin/phpunit --colors=never --filter SeedAtAnyDateTest > "$log" 2>&1
status=$?
tail -3 "$log"
exit $status
