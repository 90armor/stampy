#!/bin/sh
# Run the test suite with the clock pinned at each of the 20 instants
# (CLAUDE.md, Local environment, "One suite at a time"). Each instant's full
# PHPUnit output is kept in its own log, so a failure that doesn't reproduce
# still leaves its error text behind:
#
#   storage/app/pinned-runs/<YYYYmmdd-HHMMSS>/<instant>.log   (gitignored)
#
# Usage, from the host with Docker Compose up:
#   scripts/pinned-instants.sh                       the whole suite
#   scripts/pinned-instants.sh --filter SomeTest     any PHPUnit arguments
#
# Tests\TestCase::setUp() travels to PIN_NOW when it's set; a test class that
# pins its own clock still does so after it. Exits 1 if any instant failed.
# Takes about 15 minutes for the whole suite; it's one suite run at a time,
# so don't commit (the pre-commit hook runs the suite) while it's going.

cd "$(git rev-parse --show-toplevel)" || exit 1

if ! docker compose ps --status running --services 2>/dev/null | grep -qx app; then
    echo "The app container isn't running: docker compose up -d" >&2
    exit 1
fi

# An orphaned phpunit (a killed host command doesn't stop it) shares the test
# database with every run after it, which then crawls or fails.
if docker compose exec -T app sh -c 'ps -eo args | grep -q "[v]endor/bin/phpunit"'; then
    echo "A phpunit process is already running in the app container; wait for it or stop it first." >&2
    exit 1
fi

RUN="storage/app/pinned-runs/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$RUN"
echo "Logs: $RUN"

failed=0
for t in \
    "2026-02-02 00:00:30" "2026-02-02 08:11:00" "2026-02-03 07:59:00" "2026-02-04 08:11:00" \
    "2026-02-04 17:00:01" "2026-02-05 16:59:59" "2026-02-06 07:59:00" "2026-02-06 17:00:01" \
    "2026-02-07 23:59:30" "2026-02-08 12:00:00" "2026-02-28 23:59:30" "2026-03-01 00:00:30" \
    "2026-03-31 23:59:30" "2026-04-01 00:00:30" "2026-07-15 12:00:00" "2026-12-31 23:59:30" \
    "2027-01-01 00:00:30" "2028-02-29 12:00:00" "2028-03-01 00:00:30" "2030-07-15 12:00:00"
do
    log="$RUN/$(echo "$t" | tr ' :' '_-').log"
    if docker compose exec -T -e PIN_NOW="$t" app php vendor/bin/phpunit --colors=never "$@" > "$log" 2>&1; then
        status="ok"
    else
        status="FAILED"
        failed=1
    fi
    printf '%s  %-6s  %s\n' "$t" "$status" "$(tail -1 "$log")"
done

exit $failed
