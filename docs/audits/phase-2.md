# Phase 2 Codebase Audit

> **Historical record, dated 2026-09-21.** Every finding below is now either
> fixed in code or carried forward as an explicit open question in
> `CLAUDE.md`'s "Open questions for Phase 3" section (Phase 2.5d closeout).
> Kept here for the reasoning behind those fixes and the process disclosures
> below, not as a live task list — nothing here should be re-actioned
> without first checking whether it's already addressed.

Scope: computed-data correctness (`daily_attendances` derivation), test quality,
authorization matrix, Phase 1 pages revisited, convention drift against
CLAUDE.md, data integrity, leftovers, and failure behavior. Branch `dev-9a`.
Audit only — no application code was changed as part of this review, with two
process exceptions disclosed immediately below.

This audit was run as four parallel, independent investigations (data
correctness; test quality/mutation testing; authorization; and
phase-1/conventions/integrity/leftovers/failure-behavior), then merged and
re-ordered by severity here. Two things about that process need to be
understood before reading the findings.

---

## ⚠️ Audit process disclosures (read first)

**1. A "critical" authorization-bypass finding below was a false positive — an
artifact of two audit agents colliding on the same file, not a real bug.**
The phase-1/conventions agent read `app/Policies/EmployeePolicy.php` at a
moment when the test-quality agent's mutation-testing pass had that exact
line (`EmployeePolicy.php:44`) temporarily flipped from `&&` to `||` as one
of its deliberate, always-reverted mutation experiments. The phase-1 agent
saw `||`, tested it, and reported a live manager-can-view-anyone bypass. I
verified this myself directly after both agents finished: `git status` and
`git diff app/Policies/EmployeePolicy.php` show the file is byte-for-byte
clean against HEAD, and it currently reads
`$user->hasRole('manager') && $actingEmployee->isManagerOf($employee)` —
correct, matching its own docblock and `Attendance\Index`'s identical
pattern. **There is no bypass.** I'm disclosing the collision itself,
because it shows the two agents' scratch/mutation work briefly overlapped on
a real file mid-session even though both correctly restored it — worth
knowing if you re-run a similarly parallelized audit.

**2. The data-correctness agent unintentionally reset your dev database.**
Its first command, `docker compose exec app php artisan migrate:fresh --seed
--env=testing`, was meant to target a disposable test database. It didn't:
there is no `.env.testing` file in this repo, so `--env=testing` silently
no-ops, and `docker-compose.yml` hardcodes `DB_DATABASE=stampy` as a real
container environment variable for the `app` service — so the command ran
against the actual dockerized dev `stampy` database (the one backing the
`stampy-app-1`/`nginx`/`vite` containers that have been up for two days),
wiping and reseeding it. The agent caught this after one command via a
row-count check and didn't repeat it; all later work used explicit
`-e DB_DATABASE=stampy_testing` overrides, verified per-command. Both
seeders (`EmployeeSeeder`, `AttendanceLogSeeder`) are fully deterministic
(fixed `mt_srand`, dates computed relative to today), so the dev DB should
now look exactly like a routine `migrate:fresh --seed` run performed today —
**but if you had any manual/ad-hoc data in that database that didn't come
from the seeder, it is gone.** Please check before relying on it.

Relatedly, the same agent also flagged that **CLAUDE.md's documented dev
environment (native Homebrew MySQL at `127.0.0.1:3306`, database `stampy`)
doesn't match what's actually in use** — that host/port has no `stampy`
database at all (only a stale, pre-Phase-2 `attendance_system` schema); the
real, actively-seeded dev database is the dockerized one on port 3308. This
mismatch is very likely what caused the reset above, and would likely trip
up a new contributor too — see Finding H6 below.

**3. A separate concurrent process reset the disposable `stampy_testing`
database mid-session** (observed 35→0 employees with no command issued by
the agent that noticed it) — almost certainly two agents' independent test
runs colliding on the same shared database with no coordination between
them. The affected agent worked around it with atomic single-invocation
scripts; flagging as an audit-infrastructure caveat, not a code finding.

---

## Critical

None confirmed. (See disclosure #1 above for the one candidate that turned
out to be a false positive.)

---

## High

**H1. Manual punch entry and `attendance:build-daily` both accept dates
outside an employee's employment window or in the future, silently creating
contradictory computed data — confirmed by reproduction.**
- `app/Livewire/Attendance/Show.php:151-155` — `addPunch()`'s validation is
  only `'newPunchDate' => ['required', 'date']`. Reproduced: submitting a
  punch 30 days before an employee's `join_date` is accepted with zero
  validation errors and creates an `attendance_logs` row plus an
  `incomplete` `daily_attendances` row for a day the employee wasn't
  employed. A punch 2 years in the future is accepted identically.
- `app/Console/Commands/AttendanceBuildDailyCommand.php` /
  `app/Services/Attendance/DailySummaryBuilder.php:238-245` — reproduced:
  `php artisan attendance:build-daily --date=2030-01-01` runs cleanly and
  reports "Created: 35, absent: 35" — the status `match()` only
  special-cases *today* (`isInProgress()`), so any future date falls
  through to the same path as a genuine past no-show and resolves to
  `Absent`.
- **Why it matters:** both are reachable through completely normal use (an
  admin fat-fingering a date in the punch modal; an operator running the
  build command with a typo'd date range), and both silently pollute the
  one table the whole system is supposed to treat as authoritative and
  "entirely derived" — dashboard, `Attendance\Index`, and any future
  reporting would show these as real absences/incomplete days with no way
  to distinguish them from genuine data.
- **Fix (describe only):** add `after_or_equal:<join_date>` and
  `before_or_equal:today` to `addPunch()`'s validation rule; reject
  `--date`/`--to` values after today in
  `AttendanceBuildDailyCommand::resolveRange()`, or have the builder return
  an explicit "not applicable yet" status for future dates instead of
  falling through to `Absent`.

**H2. `WorkSchedule::default()` can return `null` while
`Employee::effectiveSchedule()`'s signature promises non-null — confirmed
reproduction, hard `TypeError`, and no way in the UI to prevent the
triggering state.**
- `app/Models/WorkSchedule.php:36-39`:
  `public static function default(): ?self { return static::where('is_default', true)->first(); }`
- `app/Models/Employee.php:85-88`:
  `public function effectiveSchedule(): WorkSchedule { return $this->workSchedule ?? WorkSchedule::default(); }`
- Reproduced directly: with zero `work_schedules` rows present,
  `DailySummaryBuilder::build()` throws
  `TypeError: App\Models\Employee::effectiveSchedule(): Return value must be of type App\Models\WorkSchedule, null returned.`
  Nothing at the DB level enforces "exactly one `is_default = true` row must
  always exist" (no unique partial index, no app-level guard), and per H3
  below, there is no UI to manage `work_schedules` at all — so the entire
  attendance pipeline is silently dependent on the seeder having run once
  and nobody ever removing that one row.
- **Fix (describe only):** either have `effectiveSchedule()` throw a clear
  domain exception when no default exists, or enforce "at least one default
  schedule" at the DB or application-boot level.

**H3. No UI exists anywhere to assign or manage `work_schedule_id`.**
`employees.work_schedule_id` is a real, migrated, actively-used nullable FK
(`Employee::effectiveSchedule()` and `DailySummaryBuilder::build()` both
depend on it), but there is no `WorkSchedule` Livewire component, view,
route, or policy anywhere in the codebase, and `Employees\FormModal`'s field
list doesn't include it. Every employee is permanently pinned to whatever
schedule has `is_default = true` — an admin has no way to give one team a
different shift through the app. This isn't flagged in CLAUDE.md's roadmap
as a deliberate Phase-2 scope cut, so it reads as an oversight, and it
directly compounds the blast radius of H2 (the one row nothing can be built
without also can't be managed).

**H4. `Employees\Show` never displays `manager_id`, `work_schedule_id`, or
the linked `user`/login state.**
`resources/views/livewire/employees/show.blade.php:37-54`'s Details card
only renders Department, Position, Start date, and Device user ID
(`app/Livewire/Employees/Show.php:17` only eager-loads
`department`/`position`). An admin or manager viewing an employee's profile
has no way to see who that employee reports to, what schedule applies to
them, or whether they have a login account — despite all three being core
Phase-2 fields that `FormModal` itself edits. This is exactly the "Phase 1
page assumes fields that no longer reflect reality" risk this audit was
asked to check for.

**H5. `attendance_logs` has no usable index for
`DashboardAttendance::recentActivity()`'s admin-scope query — will degrade
to a full scan at realistic data volume, on the highest-traffic page in the
app.**
`app/Support/DashboardAttendance.php:249-255` runs, for an admin
(`$employeeIds === null`): `AttendanceLog::query()->notVoided()->orderByDesc('punched_at')->limit($limit)->get()`.
`attendance_logs`' only index is the composite unique
`(employee_id, punched_at, source)` — MySQL cannot use that index to serve
an `ORDER BY punched_at` when `employee_id` isn't filtered, and `voided_at`
isn't indexed at all. At ~200 employees × 2 years × ~2 punches/day (≈290K+
rows), every admin dashboard load becomes a full table scan + filesort. A
manager's scoped version (`whereIn('employee_id', ...)`) is unaffected,
since it can use the composite index's leading column — this specifically
hits admins, on the page they'll load most often.
- **Fix (describe only):** add an index on `(voided_at, punched_at)` (or
  plain `(punched_at)`).

**H6. CLAUDE.md's documented local dev environment doesn't match the
environment actually in active use.**
CLAUDE.md's "Local environment" section describes native Homebrew MySQL at
`127.0.0.1:3306`, database `stampy`. That host/database combination exists
but has no Phase 2 schema at all (only a stale `attendance_system` schema).
The database actually receiving seeded Phase 2 data and backing the running
`stampy-app-1`/`nginx`/`vite` containers is the dockerized MySQL service
(exposed on host port 3308). This directly caused disclosure #2 above during
this very audit, and would likely mislead a new contributor the same way.
- **Fix (describe only):** update CLAUDE.md's Local environment section to
  describe the actual current workflow (docker-compose-based, it appears),
  or clarify if both a native and a dockerized setup are meant to coexist
  and which one is authoritative for "the dev DB."

**H7. The `grace_minutes` exact boundary is untested, despite being an
explicitly documented business rule — mutation survived.**
`app/Services/Attendance/DailySummaryBuilder.php:188`:
`if ($minutesAfterStart > $schedule->grace_minutes)`. Mutating `>` to `>=`
and running the full suite: **0 failures** (`DailySummaryBuilderTest`:
24/24 passed with the mutation active). CLAUDE.md documents this exact rule
("grace only decides WHETHER first_in counts as late... once outside grace,
late_minutes is the full gap from start_time") but no test places a punch
exactly `grace_minutes` after `start_time` — existing tests use 8 minutes
(under an 10-minute grace) and 25 minutes (over it), never exactly 10. A
regression on this documented boundary would currently ship silently.
- **Fix (describe only):** add a test asserting `late_minutes === 0` for a
  punch exactly `grace_minutes` after `start_time`.

---

## Medium

**M1. `Attendance\Show`'s read-only action methods have no authorization
check of their own; their safety is currently an emergent property of the
Livewire framework, not this app's code.**
`app/Livewire/Attendance/Show.php:92-145` (`previousMonth`, `nextMonth`,
`openDay`, `closeDayModal`, `cancelAddingPunch`) never call
`$this->authorize()`, unlike `startAddingPunch`/`addPunch`/`voidPunch`.
Empirically verified safe today: Livewire 3.8.8's `ModelSynth` always
re-derives a typed model property's identity from the checksum-verified
original snapshot, silently discarding any client-supplied replacement — a
real HTTP round-trip attempt to swap `$employee` via
`Livewire::test()->set('employee', $victim)` on a mounted component
provably failed to change which employee's data rendered. But this
protection lives entirely in Livewire's internals, not in anything this
component asserts — a future refactor that stores the employee id as a
plain `int` property instead of a typed `Employee` model (a very natural-
looking change) would silently reopen this exact hole, since a plain scalar
*is* directly settable via the update payload with no re-derivation.
- **Fix (describe only):** add a cheap `$this->authorize('view', $this->employee)`
  at the top of `render()` (the one method every request path funnels
  through) so the guarantee comes from this component's own code.

**M2. No test exercises `Departments\Index`, `Positions\Index`, or
`Holidays\Index` directly as a manager to prove their own `authorize()`
calls hold independent of route middleware.**
Each component's `mount()`/`create()`/`edit()`/`save()`/`delete()` does call
`$this->authorize(...)` correctly (verified by reading the code), but only
the shared `/organization` route (role:admin middleware) is tested for
manager denial. `EmployeeManagementTest.php` already demonstrates the
correct pattern for `Employees\FormModal`
(`test_manager_cannot_call_save_directly_on_the_form_modal`) — this is
exactly the defense-in-depth layer CLAUDE.md's Authorization convention
calls for, unverified for 3 of 4 admin-gated feature areas.

**M3. `Employees\Show` (`/employees/{employee}`) has zero dedicated tests.**
No test file references `Livewire\Employees\Show` or
`route('employees.show', ...)`. Its row-level policy is well-tested in
isolation and indirectly via the identically-scoped `Attendance\Show` route,
making an actual bug unlikely, but "manager viewing a peer/other-branch
employee via this specific route" has no direct coverage.

**M4. `dashboard.blade.php` still uses the stale `divide-slate-100` divider
pattern CLAUDE.md explicitly documents as wrong, in two places.**
`resources/views/dashboard.blade.php:124` (needsAttention list) and `:269`
(recent activity list) both use
`divide-y divide-slate-100 dark:divide-slate-800`. CLAUDE.md's Design
system section states almost verbatim that this exact class combination
"was never what any real page actually did" and is "nearly invisible
against a white/glass card in light mode" — every other card-style list in
the app (Departments/Positions/Holidays, Employees form) correctly uses
`divide-slate-200/60 dark:divide-slate-800/60`. These two lines predate the
current uncommitted dashboard changes but are live in the working tree now.

**M5. `tests/Feature/DashboardTest.php` has real coverage gaps.**
`weeklyTrend()` — the method with the most date-arithmetic surface — is
never asserted on anywhere in the file. `needsAttention` and
`recentActivity` are only exercised as admin; no test proves a manager
doesn't see another team's absent/incomplete/late employees or punches in
these two (their sibling `departmentAttendance` *is* tested for
manager-scoping). Given this audit found real authorization/scoping issues
elsewhere, this specific gap is worth closing rather than assuming the
matching code pattern is correct by association.

**M6. `AttendanceBuildDailyCommand` has zero dedicated test file.**
Confirmed via repo-wide grep — no test references `attendance:build-daily`.
Untested: the `--date`/`--from`/`--to` mutual-exclusion and ordering
validation, the malformed-date catch, employee lookup by id vs.
`employee_code`, the "no active employee found" path, and — directly
relevant to H1 above — **the hire-date guard and the complete absence of a
future-date guard**. A test suite that actually drove this command's
branches would very likely have caught H1's future-date half.

**M7. `MAX_SHIFT_HOURS` and `duplicate_window_seconds` exact boundaries are
both untested — mutations survived on both.**
`DailySummaryBuilder.php:75` (`<=` → `<` on the 18-hour pairing window) and
`PunchIngestor.php:133` (`>` → `>=` on the 90-second duplicate-grouping
window): both mutations ran the full suite with **zero failures**. Existing
tests approach each boundary from only one side (17h59m/18h01m; 8s/45s),
never landing exactly on 18h00m00s or 90s.

**M8. `CsvAttendanceSource` has no dedicated test file.**
All coverage is indirect through `AttendanceImportCommandTest` and one
fixture that only exercises `has_header=true` with a complete column map.
Untested: `has_header=false` mode, the missing-configured-column exception,
the empty/unreadable-file exceptions, blank CSV lines, and rows missing
`device_user_id`/`punched_at` specifically (only "unparseable date" —
present-but-malformed — is exercised).

**M9. Test-suite flakiness observed, independent of any mutation.**
Two full-suite runs during mutation testing produced unrelated failures
(a unique-constraint collision in `PositionManagementTest` on
`positions.positions_name_unique`, and stray `PunchIngestorTest`/
`DashboardTest` failures) that did not reproduce on immediate re-run with
identical code. Suggests cross-test pollution or shared-state race
(possibly Faker's `unique()` state, or `stampy_testing` not being fully
isolated between consecutive runs — see disclosure #3). Not chased further
since it's outside this audit's assertion-quality scope, but intermittent
failures erode trust in every other signal this audit (and CI) relies on.

**M10. `CalendarViewTest`'s "persists via URL" test doesn't actually verify
that.**
`tests/Feature/Attendance/CalendarViewTest.php:61-73`
(`test_the_view_toggle_switches_and_persists_via_url`): the HTTP-GET half
with `?view=table` only asserts `->assertOk()`; the "persists" behavior the
test name promises is never actually checked (a separate, unrelated
Livewire-harness call later in the same test proves the property is
settable, not that the query string populated it on mount).

**M11. Stray committed screenshot at the repo root, present since the
initial commit.**
`undefined/debug-modal.png` (1440×900, 57KB) sits in a directory literally
named `undefined` — the signature of a debugging/screenshot tool that
concatenated a path with an undefined value. Not a secret, but dead weight
that's been in version control since `adf7a62` and looks like a bug
artifact to anyone browsing the repo.

---

## Low

**L1. 18-or-more-hour same-calendar-day punch pairs are silently dropped
with no indication a punch was excluded.**
Constructed case: in-punch `00:00:00`, out-punch `23:59:00`, same day
(23h59m apart). This exceeds `MAX_SHIFT_HOURS` (18), so — working exactly as
the code's own comments describe — the out-punch is never paired;
`daily_attendances` shows `last_out=null`, `worked_minutes=0`,
`status=incomplete`, with no visible trace that a same-day out-punch
existed but was excluded. Not a bug, but a real employee whose punches
span nearly 24h resolves to "Incomplete" with zero surfaced explanation.
Consider either flagging "excluded punch" on the row, or documenting this
explicitly as a known/accepted limitation in CLAUDE.md's precedence section.

**L2. `FormModal::resetPassword()` has no guard against a null
`$editing->user`.**
`app/Livewire/Employees/FormModal.php`: `$user = $this->editing->user;` then
`$user->forceFill(...)->save()` — for an employee with no linked login
(exactly the Su Su Hlaing/Thida Win seeded scenario), `$user` is `null` and
this fatal-errors. The Blade view guards the button
(`@if ($editing?->user_id)`), but the action method itself has no
equivalent guard — reachable by a direct `Livewire::test()->call('resetPassword')`,
or any future UI regression that lets the button render regardless.

**L3. `manager_id` cycle prevention is application-layer only, not
DB-enforced.**
`FormModal::managerIsNotACycle()` correctly blocks self-assignment and
cycles through the UI, and `Employee::resolveSubordinateIds()` degrades
gracefully if a cycle is ever created outside that form (visited-set +
depth cap of 10 + a log warning, not an infinite loop or crash) — verified
by reading the implementation. Still, nothing outside that one validated
form prevents a cycle from being written (tinker, a future API, a bad
seeder/migration).

**L4. `EmployeePolicy::delete()` gates deactivation, not deletion —
misleading name.**
`Employees\Index::deactivate()` authorizes via the `delete` ability but only
ever sets `status = inactive` (hard delete is confirmed unreachable through
any UI path). Consistent with the "no soft deletes" invariant, but the
ability name reads as if actual deletion is possible.

**L5. Manual punch add/void authorization (admin-only, even for a manager's
own subordinate) isn't documented in CLAUDE.md's Roles & Authorization
table.**
The table only covers Dashboard/Employees/Departments/Positions/Holidays.
The actual behavior is intentional and consistent (reuses `EmployeePolicy::update`,
same "admin manages this person's HR data" gate `FormModal` uses), but
undocumented.

**L6. `tests/Unit/` has effectively zero real coverage.**
The only file is the stock Laravel stub (`assertTrue(true)`). Pure-logic
units with no test at the unit level anywhere include `AttendanceStatus`,
`DailyAttendance::displayVariant()`, and `IngestionSummary` — all are only
exercised indirectly through `tests/Feature`.

**L7. `AttendanceIndexTest::test_admin_can_view_the_attendance_list` is a
pure smoke test.**
Only `->assertOk()`, no content assertion — would pass even on a blank or
broken render. Low severity since it's clearly intentional (paired with the
negative-case test) and content is covered extensively elsewhere in the
same file.

**L8. `DashboardAttendance`'s `'leave'` segment breaks the established
color convention.**
`app/Support/DashboardAttendance.php:80` uses `bg-accent-400` where every
other segment maps 1:1 to `DailyAttendance::displayVariant()`'s palette
(green/red/violet/blue/fuchsia/slate). `leave` has no accent-colored variant
anywhere else in the app, and CLAUDE.md's variant table doesn't list one
either. Currently unreachable (nothing assigns `Leave` yet pre-Phase-3), but
will look inconsistent the moment it becomes reachable.

**L9. Two small inaccuracies in CLAUDE.md itself.**
(a) The Design system section's "Together these are the **four** non-base
colors `x-badge` carries" sentence lists eight color names total (five
original + three added); it should read "three" (the count of what that
paragraph is actually describing, `violet`/`blue`/`fuchsia`). (b) The
often-cited example that "`x-badge` has no violet variant even though
violet is now a documented status colour" is itself stale —
`resources/views/components/badge.blade.php` already defines `violet`,
`blue`, and `fuchsia` with the exact contrast ratios CLAUDE.md cites, and
CLAUDE.md's own component list already mentions all three. Not a code
finding — just flagging that this specific pre-supplied example should be
retired rather than repeated in future audits.

---

## What was verified clean (no findings)

- **Computed-data correctness:** 9 independently hand-computed employee-days
  across present / late / early-leave / both / incomplete (missing in and
  missing out) / absent / off / holiday / overnight — all 9 matched
  `daily_attendances` exactly. The documented grace-boundary behavior
  (`>` not `>=`) matches its own code comment precisely. An explicit vs.
  null-falling-back-to-default `work_schedule_id` produce identical computed
  numbers with correctly differing provenance. Rebuilding the full seeded
  range twice produced byte-identical `daily_attendances` rows (idempotent).
  No MySQL-side clock function (`NOW()`/`CURDATE()`) is used anywhere in
  this path — every comparison uses a PHP-supplied literal, confirming
  CLAUDE.md's timezone invariant holds for this code specifically.
- **Phase 2.4e status/timing refactor:** the one pre-existing test modified
  in that commit was strictly extended (a new `early` key added to an
  already-exact key-set assertion, no assertion loosened or removed) — no
  coverage regression found.
- **No nondeterministic (seeded-randomness-dependent) tests found** —
  every `AttendanceLog`/`DailyAttendance` factory call site in
  `tests/Feature` explicitly pins its date/time rather than relying on the
  factory's randomized default.
- **FK/cascade behavior on deactivation:** confirmed deactivation touches
  nothing else (`attendance_logs`, `daily_attendances`, `manager_id` on
  subordinates, linked `users` row all untouched); hard delete is confirmed
  unreachable through any UI path; direct-DB deletion (bypassing the app)
  correctly cascades `attendance_logs`/`daily_attendances` with no orphans.
- **Livewire property tampering:** no genuine bypass found on
  `Attendance\Show`'s `$employee` property, empirically verified via a real
  round-trip through Livewire's update mechanism (see M1 for the caveat
  about *why* this currently holds).
- **Enum cases, TODO/FIXME, committed secrets:** all clean — every
  `AttendanceStatus`/`PunchSource`/`PunchType` case is reachable and used;
  no real TODO/FIXME in `app/` or `resources/views/`; `.env` is correctly
  untracked and `.gitignore` covers the usual suspects; nothing unexpected
  in recent commit history.
- **Marked-time, cell-link, whole-row-click, and `displayVariant()` design
  conventions:** spot-checked against actual rendered Blade output (not
  just CLAUDE.md's claims) in the Attendance calendar/table/list views and
  Employees list/detail — all match the documented spec exactly.
- **No leftover demo/placeholder data:** the dashboard's old
  `DemoAttendance` hardcoded-data problem has already been replaced (see
  uncommitted `DashboardAttendance.php` on this branch) with real,
  correctly-scoped data; a repo-wide grep for demo/fake/placeholder/TODO
  found nothing else.
- **`wobbly-snuggling-wand.md`**: does not exist anywhere in the working
  tree or reachable git history — either already cleaned up, or was a
  placeholder name in the audit brief rather than a real target.

---

## Could not verify

- **Whether the false-positive critical finding's collision (disclosure #1)
  caused any other transient misreads** across the four parallel agents —
  each agent's own report was internally consistent and I spot-verified the
  one alarming claim directly, but I did not re-verify every other file
  each agent touched for similar mid-mutation collisions.
- **Actual `EXPLAIN` output at realistic scale** for the `attendance_logs`
  index finding (H5) — based on correct application of standard MySQL
  index-usage rules to the schema and query shape, not a literal query plan
  run against a seeded 200-employee/2-year dataset (not built due to time).
- **Whether the real dev database (the dockerized `stampy`, see H6) could
  ever reach the "zero `work_schedules` rows" state (H2) through normal
  seeding/operation** — reproduced only against a database deliberately
  emptied of that table; did not test whether normal seeder execution order
  could ever produce this on a fresh install.
- **A fully unauthenticated, hand-forged raw HTTP request to
  `/livewire/update`** (bypassing a valid session entirely) — the checksum
  layer was verified thoroughly by reading Livewire's source and by
  authenticated tampering tests, but a raw end-to-end unauthenticated
  attempt through nginx was not constructed.
- **Exhaustive per-view design-convention audit** — the divider-color check
  (M4) and others were targeted greps/spot-checks against specific views
  named in CLAUDE.md, not a manual pass over every remaining Blade partial
  (e.g. Holidays' calendar-adjacent partials, the day-detail panel).
- **The identity of the process that reset `stampy_testing` mid-session**
  (disclosure #3) — worked around, not diagnosed.
- **Live ZKTeco device data** — only seeded/synthetic and hand-constructed
  scratch data was available for the correctness checks; no live device
  integration exists to test against.
- **Whether any file outside the ~15 explicitly named per-section scopes**
  has similar issues — each of the four investigations was scoped to
  specific files/areas per its brief; a fully exhaustive line-by-line pass
  over the entire `app/` and `resources/views/` tree was not performed.
