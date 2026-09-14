# Phase 1 Codebase Audit

Scope: Login, Dashboard, Employees (list, detail, create/edit modal), Organization
(Departments & Positions). Laravel 11 + Livewire 3 + Blade + Tailwind, Spatie
permissions. Audit only — no code was changed as part of the original review.

Reviewed: `routes/web.php`, all `app/Livewire/**`, `app/Models/**`,
`app/Policies/**`, `app/Support/DemoAttendance.php`, `app/Providers/AppServiceProvider.php`,
migrations, factories, seeders, `resources/views/**` (layouts, components, dashboard,
organization, employees, auth/login), and `tests/Feature/**`.

**Status: all High and Medium findings (#1–#7, #9) are fixed** — see the ✅ markers
below and the commits referenced in each. Low findings #8, #10–#13 and the
remaining test-coverage gaps are still open. Phase 1 is closed; further work here
is tracked as regular follow-up, not blocking.

---

## Critical

None found. Route-level middleware (`role:admin|manager` / `role:admin`) and
per-action `$this->authorize()` calls are present and correctly layered on every
Livewire mutation (`create`, `edit`, `save`, `delete`, `deactivate`, `reactivate`)
across Employees, Departments, and Positions — a non-admin cannot reach an admin
action by calling the Livewire method directly, since authorization is checked
inside the method, not just in the view (`@can`).

---

## High

1. **✅ FIXED (`74e1d5c`)** — ~~No uniqueness constraint on department/position `name` — before Phase 2 links attendance by these entities.~~
   `database/migrations/2026_09_08_064808_create_departments_table.php` and
   `..._create_positions_table.php` had no `unique()` on `name`, and neither
   `Departments\Index` nor `Positions\Index` validation rules included a `unique`
   rule — two "Engineering" departments could coexist, silently splitting
   employees across duplicates in every dropdown, filter, and future attendance
   rollup.
   **Fixed:** added a unique DB index on both `name` columns plus a matching
   `unique` validation rule (ignoring the record being edited) in
   `Departments\Index`/`Positions\Index`, with tests for the rejection and the
   edit-keeps-own-name case.

2. **✅ FIXED (`2960a1e`)** — ~~`Gate::define('manage-employees' / 'manage-departments')` is dead, unused authorization code.~~
   `app/Providers/AppServiceProvider.php:23-24` defined two gates that were never
   referenced anywhere — all real authorization went through `EmployeePolicy` /
   `DepartmentPolicy` / `PositionPolicy` instead. An easy trap for someone reading
   `AppServiceProvider` first and assuming it was load-bearing.
   **Fixed:** both `Gate::define` calls deleted.

---

## Medium

3. **✅ FIXED (`cf41370`)** — ~~`Employee` uses `SoftDeletes` but nothing ever soft-deletes an employee — the trait, `deleted_at` column, and its unique-constraint interaction are all dead weight that will misbehave if anyone calls `delete()`.~~
   Deactivation was implemented as `status = 'inactive'`, never `$employee->delete()`,
   yet `Employee` still carried the `SoftDeletes` trait and a `deleted_at` column,
   and the `Departments\Index::delete()` / `Positions\Index::delete()` comments
   inaccurately described deactivation as "soft-deleted."
   **Fixed:** `SoftDeletes` and the `deleted_at` column removed entirely (status
   is the actual lifecycle mechanism); the misleading comments corrected; the
   now-pointless `withTrashed()` calls in the delete guards removed too.

4. **✅ FIXED (`8652d10`)** — ~~Dashboard "new this month" stat uses `created_at`, not `join_date`.~~
   `created_at` is when the DB row was inserted, not when the employee actually
   joined — backfilling a hire from three months ago inflated "new this month,"
   while a hire entered into the system after their start date wouldn't show up.
   **Fixed:** the stat now filters on `join_date`.

5. **✅ FIXED (`d07178e`)** — ~~No index on `employees.status`, and the search filter's `LIKE '%term%'` can't use an index on `full_name`/`employee_code` regardless of whether one exists.~~
   `status` is queried directly in the dashboard and employee list on every
   request with no index. Separately, the search box's leading-wildcard `LIKE`
   can't use a plain B-tree index regardless — fine at current scale, worth
   knowing before it gets load-tested.
   **Fixed:** added an index on `status`. Deliberately did *not* add a full-text
   index for the `LIKE '%...%'` search — not needed at current scale; noted in
   the migration for whoever revisits this later.

6. **✅ FIXED (`1b747a6`)** — ~~All three custom modals (Employee create/edit, Department, Position) bypass both the shared `<x-modal>` component and the shared `<x-text-input>`/`<x-textarea>` components — not just the employee modal.~~
   All three hand-rolled their own overlay markup and plain `<input>`/`<select>`/
   `<textarea>` with an inline `$fieldClass` string duplicated three times,
   instead of the shared components. Also the direct cause of finding #9.
   **Fixed:** all three rebuilt on `<x-modal>`, with fields swapped for
   `<x-text-input>` / `<x-textarea>` / a new `<x-select>` component; the
   duplicated `$fieldClass`/`$selectClass` locals deleted. `<x-modal>` gained a
   `surface="solid"` variant (opaque panel, since these forms are dense enough
   that the default glass hurt legibility) and an `entangle` prop to keep its
   Alpine visibility state correctly synced with the Livewire `showModal`
   property. The employee modal's settings-row layout, section headers,
   dividers, helper text, read-only employee code, and scroll-fade mask are
   all pixel-for-pixel unchanged — verified with side-by-side light/dark
   screenshots.

7. **✅ FIXED (`a4a38c4`)** — ~~Seeded admin credentials (`admin@example.com` / `password`) ship in `AdminUserSeeder`, runnable via `DatabaseSeeder`, with only a code comment as a safeguard.~~
   The comment said "must be changed after first login" but nothing enforced
   that — no environment guard against running this seeder against production.
   **Fixed:** the seeder now returns immediately when `app()->isProduction()`.

---

## Low

8. **Role-based visibility logic is duplicated in four places with no single source of truth.** `routes/web.php` (`role:admin|manager`, `role:admin` middleware), `EmployeePolicy`/`DepartmentPolicy`/`PositionPolicy`, the dashboard route closure (`auth()->user()->hasAnyRole(['admin','manager'])`, `routes/web.php:20`), and `resources/views/layouts/partials/sidebar.blade.php:4-8` (`hasAnyRole`/`hasRole` inline per nav item) all separately encode "who can see this." They currently agree with each other, but there's no mechanism that would catch them drifting apart — e.g. sidebar shows "Reports" as visible to admin/manager (`sidebar.blade.php:8`) even though the route/feature doesn't exist yet, which is fine as a placeholder but is exactly the kind of entry that's easy to forget to gate for real once the route ships.

9. **✅ FIXED (`1b747a6`)** — ~~No focus trap or Escape-to-close on any of the three custom modals, and no focus restoration to the triggering button on close.~~ Fixed as part of finding #6's move onto `<x-modal>`, which already had the focus trap and Escape handling — plus `<x-modal>` itself gained focus restoration (the triggering element is captured on open and refocused on close, whichever way it closes) as part of this fix. Verified in a real browser: Escape and backdrop-click both close the modal and return focus to the button that opened it; 25 Tab presses in a row never escape the modal. `x-confirm-dialog` (`resources/views/components/confirm-dialog.blade.php`) still has no focus trap or focus restoration — out of scope for this fix, not addressed.

10. **Icon-only action buttons rely on `title` alone, not `aria-label`.** The Edit/Deactivate/Reactivate buttons in the employee table (`resources/views/livewire/employees/index.blade.php:142-181`) each carry a `title="..."` attribute but no `aria-label`; `title` is not reliably exposed to screen readers and isn't part of the accessible name computation the same way `aria-label` is. The dark-mode toggle in the topbar does this correctly (`aria-label="Toggle dark mode"`, `topbar.blade.php:39`) — the inconsistency is the tell that this was missed rather than a deliberate choice.

11. **"View all" on the Recent Activity card is a `<span>` styled to look disabled, not a real disabled control.** `resources/views/dashboard.blade.php:241-246` — a `cursor-not-allowed` `<span title="Coming with Reports">` has no semantic disabled state for assistive tech; a `<button disabled>` or `aria-disabled="true"` would be more correct, though low-impact since it's non-interactive either way.

12. **Multiple sequential count queries on the dashboard could be one trip.** `routes/web.php:21-33` runs `Employee::count()`, `Employee::where('status','active')->count()`, and a `whereMonth`/`whereYear` count as three separate queries (plus `Department::count()`/`Position::count()` later for onboarding). Not a bug, and each query is cheap, but a single `selectRaw` with conditional aggregates (or `Employee::selectRaw('count(*) as total, sum(status="active") as active, ...')`) would cut this to one round trip. Low priority given current data volume.

13. **`DemoAttendance::assignStatuses()` loads every active employee into memory on every dashboard render** (`routes/web.php:42-45`, `Employee::where('status','active')->orderBy('id')->get()`), to synthesize fake attendance rows. This is explicitly placeholder code pending a real Attendance model (per the class doc-comment) — flagging only so it isn't forgotten as "real" logic once device integration starts; it will need to become a proper query against real attendance records, not a full in-memory employee collection, before it sees real headcounts.

---

## Clean / no issues found

- **Authorization on Livewire actions**: every mutating method (`create`, `edit`,
  `save`, `delete`, `deactivate`, `reactivate`) across `Employees\Index`,
  `Employees\FormModal`, `Departments\Index`, `Positions\Index` calls
  `$this->authorize(...)` before doing anything, independent of the `@can` UI
  gating — calling a method directly (bypassing the button) is still blocked.
- **Mass assignment**: `$fillable` on `Employee`, `Department`, `Position` is
  scoped tightly to real columns; `User` uses the `#[Fillable(...)]` attribute
  restricted to `name`/`email`/`password`. `FormModal::save()` builds its update
  array explicitly from validated component properties rather than forwarding a
  request array, so there's no over-posting vector.
- **SQL injection / search & filters**: `Employees\Index::render()`'s search and
  filter `where()` calls all pass values as bound parameters (string
  interpolation only builds the `%term%` value, never raw SQL) — no injection
  risk.
- **Device user ID uniqueness**: enforced both at the DB level (`unique()` on
  `device_user_id` in the migration) and in `FormModal::rules()` validation,
  correctly excluding the record being edited.
- **N+1 queries**: `Employees\Index` eager-loads `['department','position']`;
  `Employees\Show` eager-loads the same on mount; `Departments\Index` /
  `Positions\Index` use `withCount('employees')`; dashboard's department
  attendance uses `withCount` with a scoped closure. No N+1 patterns found on
  any of the audited pages.
- **Status filtering consistency**: every place that should exclude inactive
  employees from "current workforce" figures does — dashboard stats, attendance
  breakdown, department attendance %, and the employees list's own active/inactive
  counters are all consistently scoped by `status`.
- **CSRF / login flow**: standard Breeze `LoginRequest` with per-email+IP rate
  limiting (5 attempts) and session regeneration on login/logout; nothing
  unusual or weakened.
- **Timezone**: `config/app.php` sets `'timezone' => 'UTC'`; all `now()`/date
  handling in the audited routes is consistent with that — no mixed-timezone
  logic found.
- **Form component drift**: was confirmed and scoped to the three modals in
  finding #6 (now fixed); the rest of the form components (`login.blade.php`,
  `delete-user-form.blade.php` via `<x-modal>`) used the shared components
  correctly even before the fix.

---

## Test coverage gaps

Existing tests (`EmployeeManagementTest`, `DepartmentManagementTest`,
`PositionManagementTest`) cover the happy paths well: create (with/without
linked user), edit, employee-code uniqueness, deactivate, search, and one
route-level "wrong role gets 403" case per resource. Since the original audit,
these gaps were closed:

- ~~**Manager attempting admin-only Employee actions**~~ — ✅ added: a manager
  calling `create`/`edit`/`save` on `FormModal` and `deactivate`/`reactivate` on
  `Employees\Index` directly is now asserted denied (the exact "can a non-admin
  call admin actions directly" scenario this audit was asked to check).
- ~~**Department/Position name uniqueness**~~ — ✅ added alongside finding #1's
  fix: rejection test plus an edit-keeps-own-name test for both.

Still missing:

- **Reactivate flow** — `Employees\Index::reactivate()` has no *happy-path*
  test (only the manager-denied case added above); the admin-succeeds case
  still isn't asserted, unlike deactivate.
- **`device_user_id` uniqueness** — validated in code (see Clean section) but
  never asserted in a test, unlike `employee_code`.
- **`Employees\Show` authorization** — no test hits `GET /employees/{employee}`
  for an unauthorized role or confirms it works for an authorized one.
- **Validation failure paths** — only `employee_code` uniqueness is tested;
  missing required fields (e.g. no `department_id`), invalid `email` when
  `create_user` is true, and invalid `role` are all unvalidated by tests.
