#!/usr/bin/env bash
# Stop hook: after Claude finishes a turn, format any PHP files it touched.
# Exits 2 (blocking, stderr fed back to Claude) only if formatting fails.
#
# Deliberately does NOT also run the test suite: phpunit.xml forces
# DB_HOST=mysql (a docker-network hostname), and Claude Code runs on the
# host here, not inside the app container, so that host never resolves —
# the test-running branch this file used to have was always a no-op in
# practice. Tests are run explicitly instead, via
# `docker compose exec app php artisan test`.
set -uo pipefail
cd "${CLAUDE_PROJECT_DIR}" || exit 0

changed_php=$(git diff --name-only --diff-filter=ACMR HEAD -- '*.php' 2>/dev/null | sort -u)
[ -z "$changed_php" ] && exit 0

pint_output=$(echo "$changed_php" | xargs vendor/bin/pint 2>&1)
pint_status=$?

if [ "$pint_status" -ne 0 ]; then
  echo "$pint_output" >&2
  exit 2
fi

exit 0
