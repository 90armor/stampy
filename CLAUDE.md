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

- PHP 8.5, Composer 2.10, MySQL 9.4 (Homebrew), Node/npm for Vite asset builds.
- Database: `stampy` (MySQL). `.env` / `.env.example` are pre-configured for `DB_CONNECTION=mysql`.
- Run `php artisan migrate:fresh --seed` to reset and reseed. Seeded accounts (all password `password`, **change after first login**):
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
- **Forms:** label above input (`x-input-label`, `mb-1`), `rounded-lg border-slate-300`, glass background (`bg-white/80 backdrop-blur-sm dark:bg-slate-800/70`), `focus:ring-primary-500` focus rings, red inline errors below the field (`x-input-error`). `x-text-input`/`x-textarea`/`x-select` accept a `surface` prop — `glass` (default, unchanged on every page that doesn't pass it) or `solid` (opaque, no blur; used only inside the employee/department/position modals, which are dense enough that glass hurt legibility). Don't flip the default without checking every page that omits the prop.
- **Empty states:** icon in a soft circular badge + one-line title + optional description + action button (`x-empty-state`).
- **Icons:** Heroicons (outline, 24x24, stroke-width 1.5), inlined via the single `x-icon` component (`resources/views/components/icon.blade.php`) rather than a JS icon library — add new icons there as `match()` cases.
- **App shell:** fixed sidebar (`layouts/partials/sidebar.blade.php`, `w-[242px]`, light glass surface, evergreen/primary-tinted active state — not a dark panel) + sticky glass topbar with a breadcrumb-style page title and dark-mode toggle (`layouts/partials/topbar.blade.php`). Sidebar nav items for features not yet built (Attendance, Time off, Reports) are rendered disabled with a "Soon" badge rather than omitted, so the roadmap is visible without linking anywhere — flip an item to a real link only when that phase actually ships. Auth pages (`layouts/guest.blade.php`) use a split-screen layout: an evergreen hero panel (hidden below `lg`) plus the glass form panel.

Reusable UI lives in `resources/views/components/`: `button.blade.php` (variants: `primary`, `secondary`, `danger`), `card.blade.php`, `badge.blade.php` (colors: `primary`, `green`, `red`, `amber`, `slate`), `empty-state.blade.php`, `icon.blade.php`, `confirm-dialog.blade.php`, `select.blade.php`, plus Breeze's `text-input`, `textarea`, `input-label`, `input-error` (restyled to match this system). Breeze's `primary-button`/`secondary-button` delegate to `x-button` so auth pages and app pages share one look.

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
| status | enum(active, inactive) | default `active`, indexed |
| timestamps | | no soft deletes — see Employee lifecycle above |

Plus the standard `users`, `cache`, `jobs` tables (Laravel defaults) and `roles`/`permissions`/pivot tables (spatie/laravel-permission).

### Future phases (not yet migrated — kept here so later migrations stay consistent with this plan)

**`attendance_logs`** (Phase 2 — raw device punches)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | bigint FK → employees, nullable | nullable until matched by device_user_id |
| device_user_id | string | as reported by the ZKTeco device |
| punch_time | datetime | |
| log_type | string/enum | e.g. check-in / check-out / unknown, device-dependent |
| raw_payload | json, nullable | original device payload for troubleshooting |
| timestamps | | |

**`attendances`** (Phase 2 — processed daily records, derived from `attendance_logs`)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | bigint FK → employees | |
| date | date | |
| check_in | datetime, nullable | |
| check_out | datetime, nullable | |
| status | enum | e.g. present/late/absent/half-day |
| timestamps | | |

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

Exact columns for Phase 2–4 tables will be refined when those phases are scoped in detail — this is a planning skeleton, not a final spec.

## Roles & authorization

Three roles via spatie/laravel-permission: `admin`, `manager`, `employee`.

| Area | admin | manager | employee |
|---|---|---|---|
| Dashboard | ✅ | ✅ | ✅ |
| View employees | ✅ | ✅ | ❌ |
| Create/edit/deactivate employees | ✅ | ❌ | ❌ |
| Departments (view/create/edit/delete) | ✅ | ❌ | ❌ |
| Positions (view/create/edit/delete) | ✅ | ❌ | ❌ |

Enforced in three places: route middleware (`role:admin|manager` / `role:admin` in `routes/web.php`), Livewire component `mount()`/action methods (via Policies), and the sidebar (nav items conditionally rendered by `auth()->user()->hasRole(...)`).

## 5-phase roadmap

1. **Foundation** (complete) — project setup, auth, roles/permissions, departments, positions, employees, layout/navigation. Closed out with a full codebase audit (see `AUDIT.md`) — all Critical/High/Medium findings fixed.
2. **Attendance & device integration** (current) — `attendance_logs` ingestion from the ZKTeco device (matched via `device_user_id`), processing into `attendances`, attendance dashboards/reports.
3. **Leave management** — `leave_types`, `leaves`, request/approval workflow, balances.
4. **Overtime** — `overtime_requests`, request/approval workflow, integration with processed attendance.
5. **Reporting & polish** — cross-cutting reports (attendance/leave/overtime), exports, UX polish, performance pass.

Phase 2's detailed scope isn't finalized yet — don't build features until this section is updated with that scope. Update this file at the start of each new phase.
