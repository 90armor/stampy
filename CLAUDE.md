# Attendance Management System

Internal HR tool for managing employee attendance, leave, and overtime. Built as a Laravel monolith across 5 phases. This document is the source of truth for tech stack decisions, schema, conventions, and roadmap — keep it updated as each phase lands.

## Tech stack (fixed — do not substitute)

- **Laravel 13** (monolith, no separate API/SPA split)
- **Laravel Breeze** — Blade + Alpine.js stack (auth scaffolding: login, register, password reset, email verification, profile)
- **Livewire 3** — all interactive server-rendered components (pinned to `^3.0`; do not upgrade to Livewire 4 without a deliberate decision)
- **MySQL** — primary datastore
- **spatie/laravel-permission** — roles (no granular permissions used yet in Phase 1, just roles)
- **Tailwind CSS** (via Breeze) — utility-first styling, no component library

Why: Breeze+Blade avoids standing up a separate frontend/API for what is an internal, low-traffic HR tool. Livewire gives interactivity (search, filters, modals, inline validation) without hand-rolling JSON endpoints or a JS framework. Spatie's package is the de facto standard for Laravel RBAC and plays cleanly with route middleware and policies.

## Local environment

- **The app runs under Docker Compose** (`docker compose up -d`): `app` (PHP 8.5, Composer 2.10), `nginx` (http://localhost:8000), `vite` (Node 22, port 5174) and `mysql` (MySQL 9.4, published on host port 3308). Run every `php artisan`, `composer` and test command **inside the `app` container** (`docker compose exec app php artisan ...`) — that is where the database host `mysql` resolves. Nothing in the current workflow uses a host-native (Homebrew) MySQL; an older revision of this file described one, and the host's `127.0.0.1:3306` has no `stampy` database at all.
- **Timezone:** `config('app.timezone')` is `Asia/Phnom_Penh` (UTC+7, no DST) — not UTC. Every core Attendance rule is a local-time rule (`work_date`'s calendar-day boundary, `in_progress`'s "has end_time passed", the 18h overnight pairing window), and `now()`/`today()`/bare `Carbon::parse()` calls all resolve against this automatically since Laravel sets PHP's default timezone from it at bootstrap — nothing in app code references it directly. **MySQL's own session timezone is a separate setting and currently resolves to `SYSTEM`** (the Docker container's OS timezone, itself UTC) — checked deliberately, not assumed: no query in `DailySummaryBuilder`, `PunchIngestor`, or anywhere else in Attendance depends on it, because every date/datetime comparison (`whereDate`, `whereBetween`, `whereYear`) compares the stored column against a PHP-supplied literal parameter, never MySQL's own `NOW()`/`CURDATE()`/session clock. No connection-level `'timezone'` setting was added because there's nothing for it to fix — but if a future query ever calls a MySQL-side clock function, this stops being true and that query would need explicit handling.
- **Dev database:** `stampy`, in the `mysql` container (user `attendance` / password `secret`; host `mysql` inside the compose network, `127.0.0.1:3308` from the host). The `app` service's `environment:` block in `docker-compose.yml` sets `DB_*` as real environment variables, and **real environment variables outrank every `.env*` file** — so inside the container `.env`'s `127.0.0.1` / `root` values are ignored (they only describe a host-native MySQL this project no longer uses). `docker compose exec app php artisan migrate:fresh --seed` therefore wipes the dev database `stampy`, not a scratch one.
- Run `docker compose exec app php artisan migrate:fresh --seed` to reset and reseed the dev database. Seeded accounts (all password `password`, **change after first login**):
  - `admin@example.com` — admin
  - `aye.aye.mon@example.com` — manager (Engineering)
  - `kyaw.kyaw.naing@example.com` — employee (Engineering)
  - `zaw.zaw.htet@example.com` — manager (Operations)
  - (Su Su Hlaing and Thida Win are seeded as employee records with no login account, to exercise the "employee without a user account" case.)
- **Test database:** the suite runs against MySQL, not sqlite (see phpunit.xml's comment for why — briefly, sqlite has no enforced DATE column type and already hid one real bug). It needs a `stampy_testing` database, separate from the dev `stampy` one, granted to the same `attendance` user. `docker/mysql/init/01-create-testing-db.sql` creates and grants it automatically, but **only on a fresh `mysql_data` volume** — the official MySQL image runs `/docker-entrypoint-initdb.d/` scripts once, the first time the data directory is empty. If you already have an existing volume from before this file existed (e.g. `docker compose up` predates this change), run once by hand:
  ```
  docker compose exec mysql mysql -uroot -psecret -e "
    CREATE DATABASE IF NOT EXISTS stampy_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON stampy_testing.* TO 'attendance'@'%';
    FLUSH PRIVILEGES;"
  ```
- **Running the tests:** `docker compose exec app php artisan test` (whole suite, ~8s) or `... php artisan test --filter=SomeTest`. Tests cannot run on the host: `phpunit.xml` pins `DB_HOST=mysql`, which only resolves inside the compose network.
- **Test-database safety (three layers — don't remove any):**
  1. `phpunit.xml` forces `DB_DATABASE=stampy_testing` (`force="true"`; `tests/bootstrap.php` also unsets the container's `$_SERVER` copies so the forced values reach Laravel). PHPUnit's forced values are real environment variables, so **they win over `.env.testing`** for test runs; the two files hold identical values, so they cannot disagree today — if one is changed, change both.
  2. `.env.testing` (committed; contains nothing secret) makes `--env=testing` on an artisan command actually select `stampy_testing`: `docker compose exec app php artisan migrate:fresh --env=testing`. This does not work by itself — the container's real `DB_*` variables would still win — so `bootstrap/app.php` drops them when `--env=testing` is on the command line. Without the flag, artisan still targets the dev `stampy` database, unchanged.
  3. `Tests\TestCase::createApplication()` throws before `RefreshDatabase` can wipe anything if the configured **or** live (`select database()`) database name doesn't end in `_testing`. A run pointed at `stampy` fails every test with "Refusing to run tests".
- **One suite at a time.** `stampy_testing` is a single shared database and `RefreshDatabase` runs `migrate:fresh` on it at the start of every PHPUnit process. Two concurrent runs (or anything else — a seeder, a second agent — writing to it) fail wholesale (`Table 'users' already exists`, ~230 of 232 tests red) or subtly (a committed seeder row such as the `Software Engineer` position collides with tests that create that same name → `positions_name_unique`). Serial runs are deterministic, including in random order and under 20 pinned clocks (every weekday, month/year/leap-day boundaries, times either side of midnight and of the 08:00–17:00 shift) — see the test-trust pass.

## Design system

One primary color, one accent color, one font, a small set of reusable Blade components — with full light/dark support. Apply these everywhere — don't introduce one-off styles. This look (evergreen/mint palette, sidebar + topbar shell, dark mode) was deliberately aligned with a reference UI prototype (`employee-attendance-system-2`, a Next.js/shadcn mockup called "Northstar") that is **not** part of this codebase's stack — it was used only as a visual reference, then re-implemented natively in Blade/Tailwind.

- **Font:** Inter for interface copy, loaded via Bunny Fonts (`fonts.bunny.net`) — set as the Tailwind `sans` default in `tailwind.config.js`. DM Serif Display (also via Bunny Fonts) is used as a single editorial accent — currently only the italicized word in the auth hero headline (`font-serif` utility) — never for body or UI copy.
- **Primary color:** deep evergreen, defined as the `primary` color scale in `tailwind.config.js` (50–900). Use `primary-600`/`primary-700` for buttons/links, `primary-50`/`primary-100` for tints and active-state backgrounds.
- **Accent color:** mint, defined as the `accent` color scale (50–900). Used for icon badges, the brand mark, and highlight text (e.g. the auth hero) — not for primary actions.
- **Neutrals:** the `slate` scale name is kept for continuity but its hex values are overridden in `tailwind.config.js` to a **warm** palette (equivalent to Tailwind's stock `stone` scale) instead of stock cool-toned slate — matches the warm cream/oklch neutrals in the Northstar reference. `slate-50` page background / `slate-950` in dark mode, `white`/`slate-900` cards, glass sidebar/topbar (see below).
- **Glass surfaces:** sidebar, topbar, cards (`x-card`), the dropdown menu, and auth-page inputs/theme-toggle are translucent + blurred (`bg-white/70 backdrop-blur-xl dark:bg-slate-900/60` pattern, opacity varies slightly by surface) rather than solid — this is a deliberate glassmorphism look, not a stray utility. Pair every glass surface with a soft decorative backdrop: the `.bg-shell` utility (`resources/css/app.css`) adds two subtle mint/evergreen radial gradients behind `app.blade.php`'s `<body>` and `guest.blade.php`'s outer wrapper so the blur has something to pick up. Apply `.bg-shell` to any new full-page layout.
- **Dark mode:** class-based (`darkMode: 'class'` in `tailwind.config.js`), toggled by adding/removing `.dark` on `<html>`. A small inline script in each layout's `<head>` (`app.blade.php`, `guest.blade.php`) applies the stored `localStorage.theme` (or system preference) before paint to avoid a flash of the wrong theme. The toggle button in the topbar and on the auth pages flips the class and persists the choice. Every new component must carry a `dark:` variant — check contrast for text, borders, inputs, and hover states, not just backgrounds.
- **Cards:** `bg-white/70 backdrop-blur-xl rounded-lg shadow-sm ring-1 ring-slate-200/60 hover:ring-primary-200/60 dark:bg-slate-900/60 dark:ring-slate-800/70` (see `x-card`) — glass, no heavy borders, subtle primary-tinted ring on hover.
- **Tables:** row dividers are an inset `h-px bg-slate-200/60 dark:bg-slate-800/60` line, not a `divide-y` utility — for a real `<table>` (e.g. Employees, Attendance), give the header `<tr>` and every body `<tr>` `relative`, then drop a `pointer-events-none absolute inset-x-6 bottom-0 h-px bg-slate-200/60 dark:bg-slate-800/60` span into the *last* `<th>`/`<td>` of each row (`@unless ($loop->last)` on body rows, so the final row doesn't get a trailing line before the card edge/pagination); for a card-style row list with no `<table>` (e.g. Departments, Positions), a plain `border-t border-slate-200/60 dark:border-slate-800/60` on each row (`first:border-t-0`) does the same job more simply. Either way it's **`slate-200/60`, not `slate-100`** — this file used to say `divide-y divide-slate-100 dark:divide-slate-800`, which was never what any real page actually did, and `slate-100` (this app's warm-stone-remapped scale) is nearly invisible against a white/glass card in light mode. If a new table's borders are hard to see, this is almost certainly why — check the actual color, not just whether a border class is present. `hover:bg-slate-50 dark:hover:bg-slate-800/60` rows, generous cell padding (`px-6 py-4`), uppercase `text-xs` column headers.
- **Links (data rows that navigate elsewhere):** give the link text the primary accent color at rest — `text-primary-700 dark:text-primary-400` — plus a **permanent, muted underline** (`underline decoration-1 underline-offset-2 decoration-primary-300 dark:decoration-primary-700`) that strengthens on hover (`hover:decoration-primary-600 dark:hover:decoration-primary-400`), and a `focus-visible:ring-2 focus-visible:ring-primary-500` on the `<a>` for keyboard users. Color alone was tried first and wasn't enough: in a table where every name in a column is a link, there's no unlinked neighbor to contrast against, so a color-only treatment just reads as that column's styling rather than as interactivity — the underline is what actually reads as "clickable" regardless of neighboring rows. Never rely on a hover-only change (no underline until hover, color-only hover, etc.) — touch devices have no hover state, so whatever signals "link" must already be visible at rest. A trailing `chevron-right` icon at the row's end must also be visible at rest, not merely present — `text-slate-400 dark:text-slate-500` (not `slate-300`/`slate-700`, which reads as nearly invisible) — strengthening further on row hover (`group-hover:text-primary-600`, `group` on the containing `<tr>`). This applies when the link is a specific clickable element inside the row (e.g. Attendance's employee-name cell, kept deliberately narrow so the rest of the row's times/durations stay selectable text) — a different, equally valid pattern is making the *whole row* the click target (Employees' directory list: `cursor-pointer` + `@click="window.location = ..."` + a `focus-visible` ring on the `<tr>` itself), which doesn't need the text styled as a link at all since the row hover/cursor already signals it. Pick whichever pattern fits — cell-link when the row has content people need to select and copy, whole-row when it doesn't — but a cell-link must always get this full styling (color + underline + visible chevron); a link that looks like plain text, or blends into its column, is in practice no link.
- **Status vs. timing — two independent dimensions, never conflated:** `AttendanceStatus` answers exactly one question, "did they attend" — `present` / `incomplete` / `absent` / `off` / `holiday` / `leave` / `in_progress`. Whether the *timing* was off (arrived late, left early) is a completely separate fact, tracked on `DailyAttendance`'s `late_minutes`/`early_leave_minutes` columns and surfaced through `isLate()` / `leftEarly()` / `hasTimingException()` — **never** as a status value. A `Late` status used to exist and was removed: it mixed the two dimensions, and a day that was both late *and* left early would have needed a third, combined status — the question "what do we call a day that's both?" was itself the sign timing didn't belong in the enum. A day both punches cover is always `present`, whatever the timing — including a worked holiday, whose late/early minutes are forced to 0 by the builder (see the Database schema section's precedence order), so it can never register as a `timing` exception either.
- **`DailyAttendance::displayVariant()` is the single source of truth for colour** — the one method that resolves both dimensions (status + timing) into one display bucket. Every view (calendar, table, list) colours a cell/badge by looking up this variant; no view may re-derive a colour bucket by checking `late_minutes`/`early_leave_minutes`/`status` directly. This is what keeps the three views from drifting apart the way they did when each independently decided "does this day have a problem":

  | variant | colour | meaning |
  |---|---|---|
  | `present` | green | normal day |
  | `timing` | amber | attended, but arrival or departure was outside schedule (`present` + `hasTimingException()`) |
  | `incomplete` | violet | data defect — a punch is missing |
  | `absent` | red | did not attend |
  | `off` | slate | not a working day (`leave` keeps its own accent color, same idea) |
  | `in_progress` | blue | today, schedule end_time hasn't passed, would otherwise be absent/incomplete — "not yet", not a failure |
  | `holiday` | fuchsia | a company holiday (see Database schema) |
  | not calculated | muted | no `daily_attendances` row yet — not a variant string (there's no model instance to call it on), handled separately in each view |

  `violet` (`incomplete`) was a deliberate, one-time exception to the app's core green/amber/red/slate palette (plus primary/accent for a couple of low-frequency states) — Incomplete (a device defect — a punch never recorded) and a timing exception (normal employee behavior) used to share amber and were indistinguishable at a glance in light mode. `blue` (`in_progress`) and `fuchsia` (`holiday`) followed for the same reason when Phase 2.4f added both: `in_progress` must not read as red or amber (it means "not yet", not a problem), and `holiday` deliberately avoids primary/accent — both are still green-family hues under this app's palette, which would repeat the exact amber/violet confusability this section already fixed once. Together these are the four non-base colors `x-badge` carries (`primary`/`green`/`red`/`amber`/`slate`/`violet`/`blue`/`fuchsia`). Don't add another one-off hue for a future variant without the same kind of justification, and don't "clean this up" back to a base color. `text-violet-700 dark:text-violet-300` (light: 6.48:1 on `bg-violet-50`; dark: 9.43:1 on `bg-violet-900/20` composited over the card background), `text-blue-700 dark:text-blue-300` (6.16:1 / 9.51:1, same recipe), `text-fuchsia-700 dark:text-fuchsia-300` (5.89:1 / 9.79:1) — the `-300` in dark mode for all three, not `-400`, for the same reason `absent` uses `red-300`: measured against the actual composited background, not assumed from the shade number.
- **The calendar's status icon reflects attendance, not timing:** a `timing`-variant cell reuses `present`'s check icon rather than a separate glyph — the icon answers "did they show up" (shape-safe in greyscale on its own), while the amber colour and the marked time (below) carry the timing exception. This is deliberate, not a gap: conflating "attended" and "arrived late" into one icon (a clock) was the old, now-removed design.
- **Marked times (a specific value is out of range, not just the day):** when a value inside a cell needs to say "this exact number is the problem" — e.g. the calendar/table/list's late-arrival/early-leave times — color alone repeats the exact failure the status icons exist to prevent (someone who can't distinguish red from the surrounding muted gray would see an ordinary-looking time). Pair `text-red-700 dark:text-red-300` with `underline decoration-red-600 decoration-2 underline-offset-2 dark:decoration-red-400` on just that value — the underline is a shape cue that survives greyscale on its own, independent of whether the color reads at all. A prior version of this used a plain corner dot to mean "something about this day needs a closer look"; it was removed for saying too little (it didn't say *what*, and sat close enough to the status icon to be missed) — mark the specific value instead of the day when the UI can point at a specific value.
- **Status badge + timing chips (table/list), not a recolored badge:** the table and list's Status column shows the real attendance status ("Present") in its normal badge color, with small separate amber chips alongside it for each timing exception present (`Late 21m`, `Early 4m`) — so the row reads "Present, 21 minutes late" instead of either hiding the lateness behind a plain badge or overwriting the attendance fact with a fabricated "Late" one. The calendar handles this differently (the whole cell borrows `timing`'s amber via `displayVariant()`, no separate chip) because its cell has no status word to begin with — only an icon — so recoloring it doesn't contradict any text the way recoloring a badge that still says "Present" would.
- **Forms:** label above input (`x-input-label`, `mb-1`), `rounded-lg border-slate-300`, glass background (`bg-white/80 backdrop-blur-sm dark:bg-slate-800/70`), `focus:ring-primary-500` focus rings, red inline errors below the field (`x-input-error`). `x-text-input`/`x-textarea`/`x-select` accept a `surface` prop — `glass` (default, unchanged on every page that doesn't pass it) or `solid` (opaque, no blur; used only inside the employee/department/position modals, which are dense enough that glass hurt legibility). Don't flip the default without checking every page that omits the prop.
- **Empty states:** icon in a soft circular badge + one-line title + optional description + action button (`x-empty-state`).
- **Icons:** Heroicons (outline, 24x24, stroke-width 1.5), inlined via the single `x-icon` component (`resources/views/components/icon.blade.php`) rather than a JS icon library — add new icons there as `match()` cases.
- **App shell:** fixed sidebar (`layouts/partials/sidebar.blade.php`, `w-[242px]`, light glass surface, evergreen/primary-tinted active state — not a dark panel) + sticky glass topbar with a breadcrumb-style page title and dark-mode toggle (`layouts/partials/topbar.blade.php`). Sidebar nav items for features not yet built (Attendance, Time off, Reports) are rendered disabled with a "Soon" badge rather than omitted, so the roadmap is visible without linking anywhere — flip an item to a real link only when that phase actually ships. Auth pages (`layouts/guest.blade.php`) use a split-screen layout: an evergreen hero panel (hidden below `lg`) plus the glass form panel.

Reusable UI lives in `resources/views/components/`: `button.blade.php` (variants: `primary`, `secondary`, `danger`), `card.blade.php`, `badge.blade.php` (colors: `primary`, `green`, `red`, `amber`, `slate`, `violet`, `blue`, `fuchsia` — the last three exist only for Attendance's Incomplete/InProgress/Holiday statuses, see the "Status vs. timing" note above), `empty-state.blade.php`, `icon.blade.php`, `confirm-dialog.blade.php`, `select.blade.php`, plus Breeze's `text-input`, `textarea`, `input-label`, `input-error` (restyled to match this system). Breeze's `primary-button`/`secondary-button` delegate to `x-button` so auth pages and app pages share one look.

**Alpine note:** any element using a bare `@click`/`x-show`/etc. with no `x-data` anywhere in its ancestor chain silently gets no directive binding at all (confirmed empirically on this Alpine 3.17 setup — it isn't just an edge case). Add `x-data="{}"` directly on any standalone interactive element that isn't already nested inside a component with its own `x-data` (e.g. the dark-mode toggle buttons).

**Modals:** every create/edit modal builds on `<x-modal>` (`resources/views/components/modal.blade.php`) — never hand-roll the overlay/backdrop/focus-trap again; that duplication across the employee/department/position modals is exactly what got cleaned up before Phase 2. Props beyond Breeze's originals: `entangle="propertyName"` two-way binds the modal's Alpine `show` state to a Livewire boolean via `$wire.entangle().live`, so Escape and backdrop-click correctly close the Livewire property itself instead of only hiding the panel client-side — pass it whenever the modal's visibility is driven by a Livewire property (as opposed to Breeze's original `open-modal`/`close-modal` window-event pattern, which still works unentangled). `surface="solid"` swaps in the opaque panel (see Forms note above). `maxWidth` only accepts the named presets defined in the component (`sm`/`md`/`lg`/`xl`/`2xl`/`employee-form`) — never interpolate a raw pixel value, since Tailwind's class scanner only picks up literal text in source files and a runtime-built `max-w-[...]` string produces no CSS silently; add a new named preset instead. Focus restoration (returning focus to whatever triggered the modal) is automatic — don't re-implement it per modal.

## Naming conventions

- **Tables/migrations:** snake_case, plural table names, one migration file per table, named `create_{table}_table`.
- **Models:** singular StudCase (`Employee`, `Department`), relationships as standard Eloquent methods (`department()`, `employees()`).
- **Livewire components:** namespaced by feature under `App\Livewire\{Feature}\{Action}`, e.g. `App\Livewire\Employees\Index`, `App\Livewire\Employees\Form`. Matching views under `resources/views/livewire/{feature}/{action}.blade.php` (kebab-case). A single `Form` component handles both create and edit (mounted with an optional model for edit).
- **Policies:** `app/Policies/{Model}Policy.php`, auto-discovered by Laravel's naming convention (no manual registration needed).
- **Routes:** RESTful names (`employees.index`, `organization.index`), grouped by role-based middleware in `routes/web.php`.

## Coding conventions

- **Validation:** Livewire components validate via `rules()` (or `#[Validate]` attributes) — never trust unvalidated `wire:model` input. No Form Requests are used yet since all Phase 1 writes go through Livewire; introduce Form Requests only if/when plain controllers start handling validated input (e.g. a future API).
- **Authorization:** every Livewire component that manages a resource calls `$this->authorize(...)` in `mount()` for page-level checks and again in the specific action method (e.g. `deactivate()`, `save()`, `delete()`) before mutating. Route groups also carry `role:` middleware as a second layer of defense (see `routes/web.php`).
- **Employee lifecycle:** no soft deletes anywhere in Phase 1 — "deactivate" in the UI only sets `employees.status = inactive`; the row (and its future attendance history) is never removed by the app. Departments/positions with employees assigned cannot be hard-deleted either (guarded in `Departments\Index::delete()` / `Positions\Index::delete()`).
- **User/HR data split:** `users` is auth-only (name, email, password). All HR data lives on `employees`, linked via nullable `employees.user_id` — an employee may exist with no login (not yet onboarded to self-service), and a `User` always optionally has one `Employee` profile.
- **Tests:** `tests/Feature` uses Livewire's `Livewire::test()` harness against components directly (mount/call/assert) for business logic, and plain HTTP tests (`$this->get(...)->assertForbidden()`) for route-level role gating, since Livewire's test harness converts `AuthorizationException` into a response rather than letting it bubble as a PHP exception.

## Testing notes

- **Livewire authorization tests:** after a Livewire call is denied by `authorize()`, the response is a 403 rather than a normal component snapshot, so chaining further `->set()`/`->call()` on the same test instance throws `Invalid Livewire snapshot structure`. Assert `->assertForbidden()` on the denied call itself; start a fresh `Livewire::test()` instance for any subsequent assertions.

## Database schema

### Phase 1 (built)

**`departments`**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string, unique | |
| description | text, nullable | |
| timestamps | | |

**`positions`**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string, unique | |
| description | text, nullable | |
| timestamps | | |

**`employees`**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK → users, nullable | `nullOnDelete`; employee may have no login yet |
| employee_code | string, unique | |
| full_name | string | |
| department_id | bigint FK → departments | `restrictOnDelete` |
| position_id | bigint FK → positions | `restrictOnDelete` |
| join_date | date | |
| device_user_id | string, unique, nullable | maps to the user ID on the ZKTeco fingerprint device |
| work_schedule_id | bigint FK → work_schedules, nullable | `nullOnDelete`; see `Employee::effectiveSchedule()` — falls back to the default schedule when null |
| manager_id | bigint FK → employees, nullable | `nullOnDelete`; self-referencing, added in Phase 2.4c for row-level attendance scoping (a manager sees themself plus transitive subordinates) |
| status | enum(active, inactive) | default `active`, indexed |
| timestamps | | no soft deletes — see Employee lifecycle above |

**`users`** — not just Laravel's defaults; Phase 2.4d added password-reset columns for accounts without email:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | |
| username | string, unique | login identifier — separate from `email`, which is nullable (an employee without a real inbox still needs to log in) |
| email | string, unique, nullable | |
| email_verified_at | timestamp, nullable | |
| password | string | |
| must_change_password | bool | default `false`; live flag, cleared on the user's own password change |
| password_changed_at | timestamp, nullable | |
| password_reset_by | bigint FK → users, nullable | `nullOnDelete`, self-referencing; an audit record of an admin-issued reset, kept even after the user changes it — distinct from `must_change_password` |
| password_reset_at | timestamp, nullable | |
| temporary_password_expires_at | timestamp, nullable | checked at login against this stored value, not inferred from `updated_at` |
| remember_token, timestamps | | |

Plus `cache`, `jobs`, `sessions`, `password_reset_tokens` (Laravel defaults) and `roles`/`permissions`/pivot tables (spatie/laravel-permission).

### Phase 2 (built)

**`work_schedules`**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | |
| start_time / end_time | time | |
| grace_minutes | unsigned smallint | default `0`; minutes after `start_time` before a late arrival counts as late at all — once past grace, `late_minutes` is the full gap from `start_time`, not the remainder past grace |
| break_minutes | unsigned smallint | default `0`; subtracted from worked time |
| workdays | json | array of ISO weekday numbers (1=Monday) |
| is_default | bool | default `false`; the schedule `Employee::effectiveSchedule()` falls back to when an employee has none assigned |
| timestamps | | |

**Minute arithmetic truncates, never rounds** — `late_minutes` and `early_leave_minutes` are `intdiv(seconds, 60)` in `DailySummaryBuilder::calculate()`. Concretely, against an 08:00 start with 10 minutes' grace: `08:10:59` is **not** late (grace is inclusive through the whole of minute 10), late begins at `08:11:00`, and `08:11:59` is 11 minutes late, not 12; likewise leaving at `16:59:01` is not an early leave. **This is a deliberate rule, not an oversight — do not "fix" it to `round()` or `ceil()`.** Wherever rounding is ambiguous, attendance favours the employee: docking someone over seconds is indefensible, especially once these minutes feed payroll. `round()` looks more accurate in isolation, and would quietly start marking arrivals from `08:10:30` onward as late. Pinned by `DailySummaryBuilderTest::test_seconds_never_count_against_the_employee_late_and_early_minutes_truncate` (and the grace boundary itself, `>` not `>=`, by `test_arrival_exactly_at_the_end_of_grace_is_not_late`).

**`attendance_logs`** — raw device punches. Append-only: a wrong punch (e.g. someone else's finger matched the device) is never edited or deleted, only voided — see the "append-only" note below.
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | bigint FK → employees | `cascadeOnDelete` |
| punched_at | datetime | |
| punch_type | string | `in` / `out` |
| source | string | default `device`; `manual` for admin-entered corrections |
| device_id | string, nullable | |
| created_by | bigint FK → users, nullable | `nullOnDelete`; who entered a manual punch |
| raw | json, nullable | original device payload for troubleshooting |
| voided_at | timestamp, nullable | |
| voided_by | bigint FK → users, nullable | `nullOnDelete` |
| timestamps | | |

Unique on `(employee_id, punched_at, source)` — deliberately *not* including `voided_at`: on MySQL a unique index treats every `NULL` as distinct from every other `NULL`, so including it would let the same device punch collide, get voided, and then be re-imported as a live "duplicate" — reopening the exact bug the index exists to prevent (confirmed empirically, not assumed). A punch voided at its exact key permanently occupies that key; reviving (not re-inserting) is the correct fix when an admin re-adds a punch at the same timestamp they just voided — see `Attendance\Show::addPunch()`.

**`daily_attendances`** — one row per employee per date, entirely derived from `attendance_logs` by `DailySummaryBuilder`. Must always be fully recomputable — nothing else may write to it.
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | bigint FK → employees | `cascadeOnDelete` |
| work_date | date | |
| work_schedule_id | bigint FK → work_schedules, nullable | `nullOnDelete`; the schedule actually used for *this day's* calculation, since an employee's schedule can change over time |
| first_in / last_out | datetime, nullable | the paired punches for the day, per `DailySummaryBuilder`'s 18h pairing window |
| worked_minutes | unsigned int | default `0` |
| late_minutes / early_leave_minutes | unsigned int | default `0`; see the "status vs. timing" note below |
| status | string | `AttendanceStatus` value — `present`/`incomplete`/`absent`/`off`/`holiday`/`leave`/`in_progress` |
| note | string, nullable | |
| timestamps | | |

Unique on `(employee_id, work_date)`; indexed on `(work_date, status)`.

**`holidays`** — company-wide, one row per actual date. No recurrence rules: most Myanmar public holidays follow the lunar calendar (Thingyan, Thadingyut, Tazaungdaing) and shift every year, announced by government rather than computable — annual data entry is correct here, not a limitation. Also covers an ad-hoc single day off with no extra mechanism.
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| date | date, unique | |
| name | string | |
| note | string, nullable | |
| created_by | bigint FK → users, nullable | `nullOnDelete` |
| timestamps | | |

Read directly by the calendar as a separate display layer (`Attendance\Show::holidaysByDate()`), not derived from `daily_attendances` — a future holiday has no `daily_attendances` row yet, and employees need to see upcoming holidays. Admin CRUD lives under Organization's third tab (`livewire:holidays.index`), reusing Departments/Positions' list-plus-modal pattern and `/organization`'s existing `role:admin` route gate — no new route or policy-registration wiring needed beyond `HolidayPolicy` itself (auto-discovered, same as every other policy). Creating, editing (both old and new date), or deleting a holiday synchronously rebuilds that date for every active employee (`Holidays\Index::rebuildDate()`) — measured at ~0.08s for 35 employees, so no queueing/batching was needed. Deliberately just the one date, not `Attendance\Show`'s D-1/D/D+1 window for a punch change: a holiday never touches `attendance_logs`, it only changes how one date's already-paired punches are interpreted, so it can never affect what an adjacent day pairs as its own first_in/last_out.

Three model decisions here aren't obvious from the schema alone, and have already been re-litigated at least once — recorded so they aren't re-opened again:
- **`attendance_logs` is append-only.** A correction is a void (`voided_at`/`voided_by`) plus a new row, never an edit to an existing one — preserves a full audit trail of what the device actually reported vs. what an admin corrected, and is what makes `daily_attendances` safely recomputable at any time.
- **Status answers "did they attend"; timing is a separate dimension.** `AttendanceStatus` has no `Late` value — a `late_minutes`/`early_leave_minutes` > 0 day is still `present`, and a worked holiday's timing fields are forced to 0 by the builder so it can never register as a timing exception either. See the "Status vs. timing" note under Design system for the full reasoning; `DailyAttendance::displayVariant()` is what every view colours from, so this distinction isn't just a data-modeling footnote — it's the one thing every Attendance view must route through.
- **Status precedence, highest to lowest** (`DailySummaryBuilder::calculate()`, where the full comment lives): **(1) `off`** — no punches at all and not a scheduled workday, holiday or not. **(2) `holiday`** — no punches at all, a workday, holiday-marked. **(3) `present`** — both punches exist, on any day; a holiday only zeroes its late/early minutes, it doesn't need to override the status. **(4) `in_progress`** — today, the schedule's `end_time` hasn't passed, and punches so far would otherwise resolve to (5) or (6). **(5) `incomplete`** — exactly one of {in, out}, on any day, holiday or not — a missing punch is a device-defect fact independent of whether the day was a holiday. **(6) `absent`** — a workday, no punches, none of the above applied. Someone who works a non-workday (an ordinary weekend or a weekend holiday) is (3), not (1) — that already worked before holidays or in_progress existed and neither changes it.

### Future phases (not yet migrated — kept here so later migrations stay consistent with this plan)

**`leave_types`** (Phase 3)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | e.g. Annual, Sick, Unpaid |
| default_days_per_year | integer, nullable | |
| timestamps | | |

**`leaves`** (Phase 3)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | bigint FK → employees | |
| leave_type_id | bigint FK → leave_types | |
| start_date / end_date | date | |
| status | enum | pending/approved/rejected |
| approved_by | bigint FK → users, nullable | |
| timestamps | | |

**`overtime_requests`** (Phase 4)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | bigint FK → employees | |
| date | date | |
| hours | decimal | |
| status | enum | pending/approved/rejected |
| approved_by | bigint FK → users, nullable | |
| timestamps | | |

Exact columns for Phase 3–4 tables will be refined when those phases are scoped in detail — this is a planning skeleton, not a final spec.

## Roles & authorization

Three roles via spatie/laravel-permission: `admin`, `manager`, `employee`.

| Area | admin | manager | employee |
|---|---|---|---|
| Dashboard | ✅ | ✅ | ✅ |
| View employees | ✅ | ✅ | ❌ |
| Create/edit/deactivate employees | ✅ | ❌ | ❌ |
| Departments (view/create/edit/delete) | ✅ | ❌ | ❌ |
| Positions (view/create/edit/delete) | ✅ | ❌ | ❌ |
| Holidays (view/create/edit/delete) | ✅ | ❌ | ❌ |

Enforced in three places: route middleware (`role:admin|manager` / `role:admin` in `routes/web.php`), Livewire component `mount()`/action methods (via Policies), and the sidebar (nav items conditionally rendered by `auth()->user()->hasRole(...)`).

## 5-phase roadmap

1. **Foundation** (complete) — project setup, auth, roles/permissions, departments, positions, employees, layout/navigation. Closed out with a full codebase audit (see `AUDIT.md`) — all Critical/High/Medium findings fixed.
2. **Attendance & device integration** (current) — `attendance_logs` ingestion from the ZKTeco device (matched via `device_user_id`), processing into `daily_attendances`, attendance dashboards/reports.
3. **Leave management** — `leave_types`, `leaves`, request/approval workflow, balances.
4. **Overtime** — `overtime_requests`, request/approval workflow, integration with processed attendance.
5. **Reporting & polish** — cross-cutting reports (attendance/leave/overtime), exports, UX polish, performance pass.

Phase 2's detailed scope isn't finalized yet — don't build features until this section is updated with that scope. Update this file at the start of each new phase.
