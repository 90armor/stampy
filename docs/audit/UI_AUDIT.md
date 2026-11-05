# Stampy UI/UX & frontend audit (Phase 6a)

## Phase 6 outcome

Closed out on 5 Nov 2026 (Phase 6h, branch `ui-polish/6h-hygiene`) by re-running this audit: the same personas, data and pages at 390, 1024 and 1440 in light and dark, axe, query counts and the journeys, with the admin journeys this time completed and saved.

- **Every finding is fixed:** all 38, and the seven doc drifts. Each row's **Status** column below names the slice. Nothing was marked "won't fix".
- **Axe:** 0 violations in 474 captures — every 6a page for every persona, the guest pages and nine modal and error states — plus 0 on the states 6h added (the punch history, the reason hint, the avatars, the trend at 390). **Before:** 2,062 nodes on 6a's pages and guest pages and 92 on the modal states (466 captures with the same harness, measured in 6d against 6a's code: `dlitem` 648, `definition-list` 90, `region` 1,168, `page-has-heading-one` 72, `landmark-one-main` 36, `aria-prohibited-attr` 20, `color-contrast` 10, `heading-order` 10, `scrollable-region-focusable` 8; with 208 decorative calendar day numbers left out of the count). The re-run's first pass found one new rule, `button-name`, 156 nodes: the topbar's account button below 640px had lost its name when the avatar became decorative in this slice. It was fixed before the final pass.
- **Every page, measured as in 6a (420 captures):** no horizontal overflow at any width, one `<h1>` on every page, no page titled just "Stampy", and the only console errors are the 403 and 404 responses of the error pages the list visits on purpose. Time to first byte is 230 ms at most, 49 ms in the middle.
- **Journeys:** the employee's phone journey (sign in with the employee code, a morning half day, an overtime claim, cancelling it), the manager's (approve with a double tap, reject without and then with a reason, URL guessing), the stale and expired cases (Approve on a request cancelled meanwhile says "Sreyneang Kim cancelled their leave request (Medical, Fri 6 Nov)."; a Livewire action after the session is lost lands on `/login?expired=1`), forgot password (one neutral message for every case) and the filters all completed. The admin journeys 6a only opened were all completed through the UI and checked in the database:

| Admin journey | Result |
|---|---|
| Add an employee without a login | saved; no user |
| Add an employee with a login | saved; user `journey.login` (see N1, fixed since) |
| A new schedule, then an effective-dated change to it | assignment from Mon 16 Nov |
| A new holiday | saved |
| An opening-balance adjustment | +1.5 days, with its note |
| Deactivate, then reactivate | inactive with today's last day, then active with none |
| File leave for E3 (no login) | approved on submit |
| File overtime for E3 past the 2-hour limit | approved, with the override reason stored |
| A manual punch and a void, each with a reason | both reasons stored; the voided punch stays listed |
| Each report's CSV and print | header row first, `-OPEN` in the name; A4 PDFs of 9, 9 and 1 pages |

**Query counts, 6a then now** (the same pages, users and data; one render in a fresh app). Every admin page fell from about 250 to about 50 (the approvals badge, F03, and role lookups, 6h). Two pages cost more, for content added since: a manager's dashboard (63 → 88) carries their own leave cards (F11), and their Daily attendance (35 → 38) the not-calculated notice (F07). Against `main` before 6h, every admin page is 2 queries cheaper and no page is dearer. Times are local and noisy: none is near a second.

| User | Page | 6a queries | Now | 6a ms | Now ms |
|---|---|---|---|---|---|
| admin | `/dashboard` | 288 | 90 | 120 | 90 |
| admin | `/employees` | 252 | 58 | 104 | 72 |
| admin | `/employees/2` | 303 | 101 | 146 | 86 |
| admin | `/attendance` | 259 | 69 | 116 | 92 |
| admin | `/attendance?from=2026-10-01&to=2026-10-28` | 253 | 63 | 115 | 133 |
| admin | `/attendance/2` | 252 | 56 | 110 | 57 |
| admin | `/attendance/2?view=table` | 252 | 56 | 155 | 85 |
| admin | `/time-off` | 244 | 51 | 121 | 49 |
| admin | `/approvals` | 661 | 73 | 205 | 61 |
| admin | `/overtime` | 242 | 48 | 98 | 45 |
| admin | `/policies?tab=schedules` | 252 | 58 | 124 | 69 |
| admin | `/policies?tab=holidays` | 252 | 58 | 121 | 73 |
| admin | `/policies?tab=leave-types` | 252 | 58 | 120 | 71 |
| admin | `/policies?tab=overtime` | 252 | 58 | 119 | 74 |
| admin | `/organization` | 244 | 50 | 100 | 49 |
| admin | `/organization?tab=positions` | 244 | 50 | 92 | 47 |
| admin | `/reports?tab=attendance` | 472 | 55 | 240 | 104 |
| admin | `/reports?tab=leave` | 472 | 60 | 237 | 83 |
| admin | `/reports?tab=overtime` | 472 | 47 | 232 | 45 |
| admin | `/profile` | 240 | 46 | 87 | 39 |
| aye.aye.mon | `/dashboard` | 63 | 88 | 60 | 65 |
| aye.aye.mon | `/employees` | 27 | 27 | 46 | 47 |
| aye.aye.mon | `/attendance` | 35 | 38 | 65 | 72 |
| aye.aye.mon | `/attendance/9` | 25 | 25 | 55 | 43 |
| aye.aye.mon | `/approvals` | 101 | 37 | 62 | 45 |
| aye.aye.mon | `/reports?tab=attendance` | 159 | 24 | 130 | 50 |
| aye.aye.mon | `/reports?tab=leave` | 159 | 29 | 128 | 47 |
| aye.aye.mon | `/time-off` | 44 | 48 | 48 | 112 |
| aye.aye.mon | `/overtime` | 24 | 24 | 42 | 40 |
| aye.aye.mon | `/my-attendance` | 25 | 25 | 57 | 42 |
| kyaw.kyaw.naing | `/dashboard` | 52 | 43 | 47 | 40 |
| kyaw.kyaw.naing | `/my-attendance` | 18 | 16 | 58 | 36 |
| kyaw.kyaw.naing | `/my-attendance?view=table` | 18 | 16 | 65 | 39 |
| kyaw.kyaw.naing | `/time-off` | 52 | 48 | 51 | 49 |
| kyaw.kyaw.naing | `/overtime` | 28 | 27 | 43 | 41 |
| kyaw.kyaw.naing | `/profile` | 13 | 13 | 36 | 32 |
| audit.new.joiner | `/time-off` | 39 | 29 | 43 | 39 |
| audit.admin | `/dashboard` | 288 | 111 | 135 | 90 |
| audit.admin | `/time-off` | 258 | 68 | 97 | 58 |

**Still open.** The payroll items wait on HR (Phase 5c, `docs/HR_DECISIONS.md` 24–28). New from the re-run (none a P1), all fixed in the 6h follow-up:

| ID | Severity | Where | What | Decision | Status (Phase 6) |
|---|---|---|---|---|---|
| N1 | P2 | Employee form, "Create a login account" | The generated password the admin is shown never has to be changed and never expires, unlike Reset password (must change, 48 hours). `docs/GO_LIVE.md` calls these temporary passwords. | Owner: make it temporary, like a reset's. | Fixed, 6h follow-up: a temporary password (same words-and-digits form, 48 hours, changed at first sign-in), for a new employee's login and one added later; the Login card says "Temporary password expired — reset it" once it has, with the reset (`GeneratedPasswordTest`) |
| N2 | P3 | My attendance and an employee's calendar, 390 | The Calendar / Table toggle is 38px tall, under 6f's 44px rule for employee pages. | Agent | Fixed, 6h follow-up: 6f made the toggle's frame 44px, but its border and padding left the two buttons 38px; below 640px the buttons are 44px and the frame 50px |
| N3 | P3 | Approvals, 390 | The "Show 6" button for the other approvers' requests is 28px tall. | Agent | Fixed, 6h follow-up: 44px below 640px |
| N4 | P3 | Admin dashboard, 390 | Quick actions are 36px tall; 6f's rule covers employee and approver pages, so this is outside it. | Owner: 44px below 640px, desktop unchanged. | Fixed, 6h follow-up |
| N5 | P3 | `DashboardAttendance` | Each status definition carries a `dot` class that no view renders (and Tailwind doesn't scan `app/`, so it would have no CSS). Dead code. | Agent | Fixed, 6h follow-up: removed |

The calendar's day cells are 39px wide at 390 — seven across a phone — and 64px tall; that is the grid, above WCAG's 24px minimum, not a finding.

---

## The audit (Phase 6a, 28 Oct 2026)

Read-only audit, 28 Oct 2026, branch `ui-polish/6a-audit` from `main` at `c98ab64`. No application code, migration, business logic or dependency was changed. What was added: this report, `.gitignore` for the screenshots, and audit-only tooling in `scripts/audit/` (see its README).

**Method.** Nine personas (below) on the dev database: the normal demo seed, real clock (Wed 28 Oct 2026, around 13:30–14:30 Phnom Penh time), plus audit-only personas and stress data from `scripts/audit/seed-personas.php`. Playwright (Chromium) captured every page at 390 / 1024 / 1440 in light and dark (432 page captures; 506 screenshots with guest pages, journeys, stress and print), measuring horizontal overflow, controls under 44px and 24px, console errors and time to first byte, and ran axe-core (WCAG 2.1 A/AA + best practice) at 390 and 1440 in both themes. Journeys were then scripted end to end. Query counts and server times come from rendering each page in a fresh app instance (`scripts/audit/querycount.php`). Code was read for everything the browser couldn't show.

**Data restored.** The pinned demo seed (Fri 2 Oct 2026 12:00) hashed identically before and after the audit for all 21 tables (`daily_attendances` 2,100 rows, `3682c32c…`). The dev database was then reseeded normally (35 employees, 17 users), and the scheduler restarted.

## Summary: the ten issues that matter most

1. **A deactivated employee can still sign in and use the app** (F01). Nothing checks employment status at login. Visal Chea, who left on 16 Oct, signs in, sees their dashboard, and is offered Request leave and Request overtime.
2. **Approving a request someone just cancelled shows a broken screen** (F02). A manager who clicks Approve on a request the employee cancelled in another tab gets Livewire's error overlay: a whole second app inside a box saying "You don't have access to this page". It should say the request was cancelled.
3. **Every admin page runs about 240 database queries**, 120 of them the same holiday check (F03). They come from the sidebar's approvals badge, which is also computed twice per page. Approvals runs 661. Pages are still fast here (≤ 240 ms), but the cost grows with every pending request.
4. **Printing a report gives one page**: the sidebar, the first four rows, and the right-hand columns cut off (F04).
5. **Every browser tab is titled "Stampy"** (F05). With three tabs open, nobody can tell which is which, and screen readers announce no page name.
6. **On a phone, Daily attendance shows only Date and Employee** (F06). Status, In/Out and Late are off-screen to the right, on the page a manager opens to answer "who's in?".
7. **The attendance calendar warns that attendance "has only been calculated up to today"** every month (F07). The empty days it means are simply in the future, and they're labelled "Not calculated", which reads like a fault.
8. **A new joiner is told Special leave is "also available"**, and is offered Special and Time off in lieu when filing (F08), though they can use neither. Annual says when it becomes usable, but not why.
9. **Forgot password reveals which accounts exist and which have no email** (F09).
10. **Khmer names break the avatar**: the initial is cut mid-character and shows "�" (F10). The avatar markup is copied in six places instead of being one component.

What works well: no console errors on any page; no page scrolls sideways at any width; every server response is under 250 ms; the 403 and 500 pages are styled and helpful; the half-day request flow is clear ("Uses 0.5 day of Annual · Available 20 → 19.5"); a rejection requires a reason; filters live in the URL and survive reload and Back; a double-tap on Approve approves once; the CSV exports have a UTF-8 BOM and keep Khmer names intact.

## Personas

| # | Persona | Login | Notes |
|---|---|---|---|
| A1 | Admin linked to an employee | `audit.admin` (Sophea Chan, AUD-001) | audit-only |
| A2 | Admin, no employee record | `admin` | demo seed |
| M1 | Manager with transitive reports | `aye.aye.mon` (EMP-0001 → EMP-0006 → EMP-0009…) | demo seed |
| M2a | Manager with no reports | `audit.manager.none` (Dara Pich) | audit-only |
| M2b | Manager whose only report has left | `audit.manager.gone` (Bopha Ly, no email) | audit-only |
| E1 | Under 12 months' service | `audit.new.joiner` (Sreyneang Kim, joined 2 May 2026, no email) | audit-only |
| E2 | Long tenure, rich data | `kyaw.kyaw.naing` (EMP-0002) | plus 2025 Annual (6 days carried), an AM half day with afternoon punches, a late-and-early day, approved time-off overtime 19:00–23:00 with an admin limit override |
| E3 | No login | Su Su Hlaing (EMP-0003) | demo seed |
| E4 | Deactivated, with a login | `audit.leaver` (Visal Chea, left Fri 16 Oct) | audit-only |
| — | Khmer-script name, very long leave reason | `audit.khmer` (សុខ សុភាព) | audit-only |
| — | Very long name and department | Aung Kyaw Moe Thandar Htun Myint Myat Phyo Wai Lwin, "Human Resources and Administration Department" | audit-only |

Stress data covered: long name; Khmer name; long reason; a weekend holiday (Sat 10 Oct, from the demo seed); an incomplete day; a late-plus-early day; a half day plus attendance; overtime crossing 22:00; 100+ rows (112 employees, temporarily; the attendance list has 23–700+ rows); 0 rows (M2a, empty filters).

## Findings

Severity: **P1** blocks or misleads, wrong data, security or authorization, or broken on a target viewport · **P2** clear friction, design-system inconsistency, accessibility failure or missing state · **P3** cosmetic. **Decision:** *Owner* when the fix changes behaviour, policy wording, navigation, or anything a user would notice as new; *Agent* otherwise. Screenshot paths are under `docs/audit/shots/`, named `<page>-<persona>-<width>-<theme>.png`.

| ID | Role(s) | Page/route | Viewport/theme | Severity | Category | What's wrong | Evidence | Proposed fix | Effort | Decision | Status (Phase 6) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| F01 | E4 | `/login`, then every self-service page | all | **P1** | Security / authorization | A deactivated employee's login keeps working: `LoginRequest::authenticate()` checks only the password and a temporary password's expiry, and `EmployeeLifecycle::deactivate()` never touches the user. Visal Chea (left 16 Oct) lands on the dashboard, and Time off and Overtime offer Request leave / Request overtime. The services would accept leave dated up to the last day. | `dashboard-e4-1440-light.png`, `time-off-e4-390-light.png`, `journey-e4-request-leave-1440-light.png` | Refuse sign-in once the linked employee is past `left_on` ("Your employment ended on Fri 16 Oct. Contact HR if you need access."), and end their open sessions when they're deactivated. Decide whether leavers keep read-only access (payslips, history). | M | Owner | Fixed, 6b: refused at sign-in after `left_on`, sessions ended (`EnsureStillEmployed`) |
| F02 | M1, admins | `/approvals` | all | **P1** | Stale action / error state | Approve or Reject on a request that was cancelled (or decided) elsewhere: `Approvals::openDecision()` calls `authorize('approve')` first, which fails for a non-pending request, so Livewire shows its raw error overlay: a nested copy of the app's 403 page ("You don't have access to this page") inside a modal. The service's friendly `StaleDecisionException` path is never reached. Reproduced: E1 cancelled while M1's queue was open. | `journey-stale-open-m1-390-light.png` | In `openDecision()` (and `decide()`), check whether the request is still pending at the expected step before authorizing. If it isn't, say what happened ("Sreyneang Kim cancelled this request", "Already approved by …") and refresh the list. The same applies to overtime. | S | Agent | Fixed, 6b: a calm notice naming what happened; the list refreshes |
| F03 | A1, A2 | every admin page (sidebar and topbar badge) | — | P2 | Performance (N+1) | `ApprovalBadge::count()` runs on every admin page, twice (sidebar, topbar). Its stuck rule walks days with `WorkdayCalendar::isWorkday()`, one `holidays` query per day per item: 120 identical queries on every admin page, 240 in all (`/profile`: 240 for admin vs 13 for an employee). `/approvals` runs 661. Server time is still 90–240 ms, but it grows with every pending request. | Query table below | Load the holiday dates for the window once (`holidayNamesBetween()` already exists) and pass them in; compute the badge once per request (e.g. a view composer or `once()`). | S | Agent | Fixed, 6c: holidays loaded once per evaluation, the badge once per request |
| F04 | A2, M1 | `/reports` (any tab), print | print | P2 | Print | No print styles exist. The shell scrolls inside `<main>`, so printing gives one page: the sidebar and topbar, controls, about 4 rows, and the right-hand columns cut off. | `print-reports-attendance-admin-a4-light.png` | Add `print:` rules: hide the sidebar, topbar and controls; let `<main>` and the table flow (no `overflow`/`h-screen`); landscape A4. Or point users to the CSV. | M | Owner (is printing a use case?) | Fixed, 6g: print styles, A4 landscape, a Print button and a print header |
| F05 | all | every app page | all | P2 | Accessibility / navigation | `<title>` is always "Stampy" (`layouts/app.blade.php:8`); only the guest layout takes a page title. Browser tabs and history are indistinguishable, and WCAG 2.4.2 (Page Titled) fails. | harness: all 432 app captures report the title "Stampy" | Pass the topbar header into the layout: "Time off – Stampy", "Kyaw Kyaw Naing · Attendance – Stampy". | S | Agent | Fixed, 6d: every page titled from its header (`PageTitle`) |
| F06 | M1, A2 | `/attendance` | 390 | P2 | Responsive | Below 640px the table scrolls as one piece (as documented), so the first screen of rows shows Date and Employee only. Status, In/Out, Late and Early are a horizontal scroll away on every row, and the Date column repeats today 20 times. The filters also fill the first screen. | `attendance-m1-390-light.png`, `journey-team-attendance-m1-390-light.png` | Options for the owner: pin Status on a phone too, but narrow; put the status badge under the name below `sm`; or drop the Date column when the range is one day. Collapse the filter chips behind a "Filters (n)" disclosure on a phone. | M | Owner | Fixed, 6f: a card per row below 640px, filters behind "Filters (n)" |
| F07 | all | `/my-attendance`, `/attendance/{id}` | all | P2 | Copy / misleading state | In the current month the amber callout says "Attendance has only been calculated up to Wed 28 Oct. The empty cells after that aren't missing punches…", and future days, including tomorrow's public holiday, carry the "— Not calculated" icon. Both read like a fault for dates that simply haven't happened. | `attendance-calendar-m1-390-dark.png`, `my-attendance-e2-1440-light.png` | Show the callout only when a **past** workday is missing a row. Give future days their own quiet treatment (no icon, or an "upcoming" style); keep the holiday flag on future holidays. | S | Agent | Fixed, 6e: the notice only for a past workday with no row; future days are quiet |
| F08 | E1 | `/time-off`, request modal | all | P2 | Misleading copy / UX | For someone not yet eligible for Annual, the Balances card says "Also available: Special (deducted from Annual)…", and the type list offers Special and Time off in lieu, which they can't use. Choosing Annual is refused only at Review ("Annual leave can be used from Sun 2 May 2027"). Neither place says why (12 months' service). | `time-off-e1-390-light.png`, `journey-e1-annual-refused-1440-light.png` | List only types the employee can file now; for the rest, explain in the balance row: "Usable from Sun 2 May 2027, after 12 months' service · 9 days earned so far". | S | Owner (wording) | Fixed, 6e: unusable types disabled with the reason; balances say why |
| F09 | guest | `/forgot-password` | all | P2 | Security | Messages disclose account state: "We can't find a user with that email address" (unknown) vs "This account has no email on file. Ask an admin…" (exists, no email) vs success. Anyone can enumerate usernames. The first message also says "email address" when a username was typed. The throttle message ("Please wait before retrying.") doesn't say for how long. | `journey-forgot-*-390-light.png` | One neutral message for every case ("If this account has an email on file, a reset link is on its way. No email? Ask an admin to reset your password.") and a throttle message with the wait time. | S | Owner | Fixed, 6b: one neutral message; the throttle says how long |
| F10 | all | dashboard, directory, profile, calendar header, topbar | all | P2 | i18n / duplicated markup | Avatar initials use `strtoupper(substr($name, 0, 1))`, a byte slice, so a Khmer (or Myanmar-script) name renders "�". The same avatar markup is copied in six places: `dashboard.blade.php:179,211`, `attendance/show.blade.php:106`, `employees/index.blade.php:147`, `employees/show.blade.php:12`, `topbar.blade.php:61`. | `attendance-calendar-khmer-admin-1440-light.png`, `dashboard-kh-390-light.png` | One `<x-avatar :name>` component using `mb_substr` (or grapheme-safe `grapheme_substr`), replacing all six. | S | Agent | Fixed, 6h: one `<x-avatar>`, a grapheme-safe initial |
| F11 | M1, M2a, M2b | `/dashboard` | all | P2 | Navigation / content | A manager's dashboard is the team view only: managers are employees too, but never see their own leave balance, upcoming leave or decided requests there (`$mine` is built only when `$stats` is null). M2a's "team" dashboard is just themself ("Needs attention: Dara Pich, Not in yet"). | `dashboard-m1-1440-light.png`, `dashboard-m2a-390-light.png` | Add the employee cards (balance, upcoming, decisions) to the manager dashboard's side column. | M | Owner | Fixed, 6g: a manager's or linked admin's own leave cards |
| F12 | A2 | `/dashboard` | all | P2 | Missing state | Needs attention's meta says "16 today" but lists 8, with no way to see the rest. | `dashboard-admin-1440-light.png` | A "View all 16" link to Daily attendance, filtered to today and to the same kinds. | S | Owner | Fixed, 6e follow-up: "Show all" opens the rest in place (no Daily attendance filter can show these kinds together) |
| F13 | A2, M1 | `/dashboard` trend | all | P2 | Misleading chart | A weekend day with one or two people working draws a ~2% bar against all 41 employees instead of the "Off" marker. It reads as an attendance collapse. ATTENDANCE_UI says "Off" only when every scoped row is off; DESIGN_SYSTEM says "Off/Holiday for a non-working day" (see D4). | `dashboard-admin-1440-light.png` | Treat a day as Off when it's a non-workday for most of the scope, and note the people who worked ("Off · 2 worked"). | S | Owner | Fixed, 6e: Off/Holiday when more than half the scope is, "Off · 2 worked" |
| F14 | E2, E1 | `/overtime` | all | P2 | Flow | An employee can't withdraw a pending claim: cancel is allowed only "before the window starts", and a claim's window has always started. After a mistake the only path is asking the manager to reject it. | `journey-ot-submitted-e2-390-light.png` (no Cancel) | Let the requester cancel any **pending** request; keep the start-time rule for approved ones. | S | Owner | Fixed, 6f: a pending request can be cancelled at any time |
| F15 | all | `/login` | all | P2 | Flow / expectation | Sign-in accepts a username or email, not the employee code. The audit brief expected employees to sign in with their code. The error ("Incorrect email/username or password") gives no hint either way. | `journey-login-code-error-e2-390-light.png` | Decide whether the employee code should also be a sign-in identifier; if not, say "Username or email" consistently and print the username where admins give it out. | S | Owner | Fixed, 6g: username, employee code or email; collisions refused |
| F16 | all signed-in | unknown URLs (`/no-such-page`) | all | P2 | Error state | A signed-in user gets the guest 404 (auth hero, "Go to login"): no session is started for unmatched routes, so `errors/partials/page` picks the guest layout. A model 404 (`/employees/99999`) correctly shows the app shell. | `404-admin-1440-light.png` vs `404-model-admin-1440-light.png` | Add a fallback route (`Route::fallback`) inside the `web` group so the session runs, or start the session for error rendering. | S | Agent | Fixed, 6b: a fallback route in the web group, so a 404 has the app shell |
| F17 | all | session expiry / 419 | all | P3 | Error state | No custom 419 view exists (403/404/500 only). Laravel 13's `PreventRequestForgery` accepts same-origin browser requests, so a 419 is now rare: not reproduced in Chromium. A Livewire action after the session is lost silently redirects to `/login` with no "your session expired" message and loses the page's state. | `journey-livewire-session-lost-m1-390-light.png` | Add `errors/419.blade.php` on the shared partial; show "Your session expired — sign in again" on the login page when redirected from an expired session. | S | Agent | Fixed, 6b: 419 and 503 views; "Your session expired" on the login page |
| F18 | all | stat strip (Dashboard, Attendance, Employees) | all | P2 | Accessibility | `<x-stat-card>` wraps `<dt>`/`<dd>` in extra `div`s, so the strip's `<dl>` is invalid (axe `dlitem`, `definition-list`, serious, 432 nodes across 20 page captures). Screen readers lose the label–value pairing. | axe results | Make each cell `<div><dt/><dd/></div>` directly under `<dl>`, with icons outside the dt/dd pair. | S | Agent | Fixed, 6d: valid `<dl>` in the stat strip |
| F19 | all | every app page | all | P2 | Accessibility | No skip link: a keyboard user tabs through 11–14 sidebar and topbar stops before the page's first control (Time off: 13 stops before "File for an employee"). | `journey-keyboard-timeoff-admin-1440-light.png`, tab log | "Skip to content" as the first focusable element, visible on focus, targeting `<main>`. | S | Agent | Fixed, 6d: "Skip to content" first |
| F20 | E2, M1 | `/profile` | 1440, dark | P2 | Contrast | The read-only Name field (linked users) is `#52525b` on `#18181b`, 2.29:1. | `profile-e2-1440-dark.png` | `dark:text-slate-400` on the read-only value. | S | Agent | Fixed, 6d |
| F21 | guest | `/login`, `/forgot-password` | 390–1023, dark | P2 | Contrast | The auth subtitle below `lg` is `text-slate-500` with no dark variant: 3.66:1 on the dark page (the Muted text rule requires `dark:text-slate-400`). | `login-guest-390-dark.png` | Add `dark:text-slate-400`. | S | Agent | Fixed, 6d |
| F22 | E2, M1, admins | `/reports?tab=overtime`, `/my-attendance?view=table` | 390 | P2 | Accessibility | Horizontally scrollable table containers have no focusable content and no `tabindex`, so keyboard users can't scroll them (axe `scrollable-region-focusable`). | axe results | `tabindex="0"`, a `role="region"` and an `aria-label` on each `overflow-x-auto` table wrapper. | S | Agent | Fixed, 6d: `<x-table-scroll>`, a focusable labelled region |
| F23 | E1, E2, M1 | Approvals, Time off, Overtime, dashboard links | 390 | P2 | Touch targets | Employee and approver actions are under 44px on a phone: Approve/Reject 38px, Cancel 70×38, "Request leave on Time off" 174×17, "Back to attendance" 153×20, forgot-password and password-change fields and buttons 38px, "Log out instead" 20px. The design system keeps 44px only on the login page (see D3). | `approvals-admin-390-light.png`, harness `small44` lists | Add `min-h-11` below `sm` to primary actions on employee and approver pages and to all auth pages; pad text links to 44px. | M | Owner (changes the control-height rule on phones) | Fixed, 6f: 44px targets below 640px on employee and approver pages, and the auth pages at every width |
| F24 | M1, M2a, M2b | `/employees` | all | P2 | Copy | The card is titled "All employees" when a manager sees only their team, and the "Actions" column header shows with no actions in it. | `employees-m2a-1440-light.png` | "Your team" (or "Employees") for a manager; hide the Actions header when no row has actions. | S | Agent | Fixed, 6e: "Your team"; no Actions column when no row has one |
| F25 | A2 | `/policies?tab=overtime`, `/policies?tab=schedules` | all | P2 | Copy | Help text shows HR a shell command ("…until rebuilt: `php artisan attendance:build-daily --from=YYYY-MM-DD --to=YYYY-MM-DD`"). The limits are entered in minutes (120, 600) where HR thinks in hours. | `policies-overtime-admin-390-light.png` | "Past days keep their categories until a developer rebuilds them" (or an admin "Rebuild" action); hours inputs ("2 h") stored as minutes. | S | Owner | Fixed, 6e: no command in the help text; limits in hours |
| F26 | A2, M1 | `/reports` | — | P2 | Performance | All three tabs compute their report on page load whichever is shown: every tab costs 472 queries for admin (attendance 9, leave about 7 per employee). | Query table | Lazy-load the inactive tabs (`#[Lazy]`, or render a tab's component only when it's first opened). | S | Agent | Fixed, 6c: only the open tab computes its report |
| F27 | A2 (unlinked admin) | `/my-attendance`, `/time-off` | all | P3 | Navigation | My attendance is in the sidebar but opens a "not linked" empty state. Time off shows a notice and "File for an employee", but no list of leave the admin has filed, so they can't check or cancel it here. | `my-attendance-admin-1440-light.png`, `time-off-admin-1440-light.png` | Hide My attendance when there's no linked record; list "Leave you filed" on Time off. | S | Owner | Fixed, 6g: My attendance hidden without an employee record; Time off says where filed leave appears |
| F28 | admins | Time off / Overtime "File for an employee" | all | P3 | Clarity | The modal title stays "File leave for an employee" after choosing someone, and the form doesn't say it's approved immediately until the Review step. | `journey-file-leave-for-e3-admin-1440-light.png` | Title "File leave for Su Su Hlaing", plus a line "Filed by you — approved straight away, no approval steps." | S | Owner | Fixed, 6g: "File leave for {name}" and the approved-straight-away line |
| F29 | admins | employee picker | all | P3 | Accessibility / missing state | The picker's results are `role="listbox"` containing plain buttons (invalid ARIA: a listbox needs `option` children and arrow-key handling). Results stop at 8 with no "more matches" hint (112 employees, "Stress" matched 70). | `journey-picker-many-admin-390-light.png`, `livewire/partials/employee-picker.blade.php` | Drop `role="listbox"` (a plain list of buttons is fine) or implement the combobox pattern; add "Showing 8 of 70 — keep typing". | S | Agent | Fixed, 6d: a plain list of buttons, "Showing 8 of 70 — keep typing" |
| F30 | all | date fields in modals | all | P3 | Accessibility | Visible label vs accessible name differ: the leave modal's "From"/"To" labels name pickers whose accessible names are "First day"/"Last day" (WCAG 2.5.3, label in name). On a phone the native "To" field shows a bare `dd/mm/yyyy`, with no "Same day" hint. | `journey-leave-form-filled-e2-390-light.png` | Use the visible label as the picker's `label`; show "Same day if empty" as helper text under the native input. | S | Agent | Fixed, 6d |
| F31 | all | various | all | P3 | Accessibility | Pages without an `<h1>`: Profile, My attendance (unlinked), 403/404, and the guest pages below `lg` (whose only h1 is in the hidden hero). There's no `<main>` on guest pages. The pagination override puts `aria-label` on a `<span>` (prohibited). | axe results | An `<h1>` per page (visually the same), `<main>` in the guest layout, and `aria-disabled` on the pagination span instead of `aria-label`. | S | Agent | Fixed, 6d: one `<h1>` per page, `<main>` on guest pages |
| F32 | admins | `/employees` | 390, 1024 | P3 | Responsive / pagination | 10 rows per page (12 pages at 112 employees); on a phone, pagination is only "« Previous / Next »" with no "page 1 of 12". At 1024 long department names wrap to four lines in the scrolled-away column, so row heights jump, and a long name runs under the pinned Status. | `employees-100plus-admin-390-light.png`, `employees-admin-1024-light.png` | 25 per page; "Page 1 of 12" on a phone; clamp Department to one line with the full name in the row. | S | Agent | Fixed, 6f: 25 a page, "Page 1 of 5" on a phone, Department clamped |
| F33 | all | buttons | all | P3 | Consistency | Button labels mix Title Case and sentence case: "Add Employee", "Bulk Reassign" vs "Request leave", "File for an employee", "Assign schedule". The design system asks for sentence case. | `employees-admin-1024-light.png` | Sentence case everywhere. | S | Agent | Fixed, 6e: sentence case everywhere |
| F34 | admins | day modal, add punch | all | P3 | Audit trail | A manual punch correction has no reason or note field, so the history shows who added it but not why. | `journey-add-punch-admin-1440-light.png` | Optional note on Add punch and Void, shown in the punch list. | M | Owner | Fixed, 6g (reasons), 6h (the voided punch is kept beside the re-added one) |
| F35 | A2 | CSV exports | — | P3 | Export | Every CSV has three preamble lines (title, open-month notice, explanation) before the header row, which breaks Excel's Filter/Sort and Power Query's header detection. The BOM and Khmer text were checked; opening in Excel itself was not (no Excel available). | `storage/app/audit-data/admin-attendance.csv` (local) | Move the preamble to the end of the file, or to a second "About" sheet if XLSX is ever offered. | S | Owner | Fixed, 6g: header row first, notes last, `-OPEN` in an open period's filename |
| F36 | — | `resources/views/welcome.blade.php` | — | P3 | Dead code | Laravel's stock welcome page (inline Tailwind v4 CSS, off-palette hex colours) is unused: `/` redirects to the dashboard. It accounts for every hard-coded hex value in `resources/views`. | grep | Delete it. | S | Agent | Fixed, 6h: deleted |
| F37 | — | Livewire loops | — | P3 | Frontend engineering | `@foreach` without `wire:key` in components whose lists change on interaction: report rows (attendance, leave, overtime), schedule assignments (which has delete buttons), the balances partial, form-modal lists. Morphing can reuse the wrong row's DOM. | grep (per-file counts in the appendix) | Add `wire:key` to each row root. | S | Agent | Fixed, 6h: `wire:key` on every loop over records (`WireKeyTest`) |
| F38 | — | calendar holiday name | ≥640 | P3 | Design-system compliance | The calendar cell's holiday name uses a native `title=` attribute (`attendance/show.blade.php:381`). The Interaction states rule says never `title`: it shows on hover only. | code | Drop it; the name is already in the cell's accessible label and the day modal. | S | Agent | Fixed, 6d |

Not findings, checked and fine: no console errors on any page; no page-level horizontal overflow at 390/1024/1440; TTFB ≤ 250 ms everywhere; the late time, Late and Early values are amber; durations use `1h 20m`; Present stays green on late days; holiday names are muted on worked holidays; the 403 page; rejecting needs a reason ("Write a reason to reject this request."); a double-tap on Approve approves once (Livewire disables the form while submitting); the half-day review shows its cost; after a submit, Back returns to the previous page and doesn't resubmit; chip filters write to the URL and survive reload (`?timing[0]=late`); CSVs start with a UTF-8 BOM and keep Khmer names; guessed URLs (`/employees/4`, `/attendance/4`, `/policies`, `/organization`) give the styled 403 to a manager, and `/reports?tab=overtime` falls back to their first tab.

## Journeys

| Journey | Result | Screens / clicks | Where it hesitated |
|---|---|---|---|
| Employee (phone): sign in → today → month → day detail → morning half day → balance and status | Works. | Dashboard → My attendance → day → Time off → Request leave → Review → Submit: 7 clicks. | Sign-in by employee code fails (F15). The half-day choice is a select that appears only after picking a single date. The review is clear ("Uses 0.5 day of Annual · Available 20 → 19.5"), and the balance updates at once (Pending 2 → 3.5). |
| Employee: overtime claim 6 days back, time off in lieu | Works. | Overtime → Request → Review → Submit: 4 clicks. | The review says "With your punches (out 5:20 PM), 20m would be credited", which is clear. The submitted claim can't be cancelled (F14). |
| Employee: cancel a pending request | Works for leave. | Cancel → "Cancel this leave? … Keep leave / Cancel leave": 2 clicks. | Not possible for a pending overtime claim (F14). |
| Employee: reset password | With email: link sent (mail not inspected); without email: told to ask an admin. | 2 screens. | Enumeration and wording (F09). |
| Manager (phone): queue → approve with note → reject with reason → team attendance → report → URL guessing | Works. | Approve: 3 taps. | Approve/Reject are 38px (F23). Team attendance on a phone hides status (F06). A stale item breaks (F02). |
| Admin (desktop): add employee, file for E3 (no login), overtime for E3, manual punch, deactivate, adjustment, report CSVs | Forms opened and validated; filing for E3 shows the employee clearly; CSVs downloaded. | — | **Partly covered:** the forms were opened and checked, but saving a new employee, an effective-dated schedule change, a new holiday, an adjustment, deactivate/reactivate and a limit override were not completed through the UI in this pass (the override was exercised through the service in the audit seed, which accepted it). "Day close" has no UI: the scheduler closes days on its own (by design, CLAUDE.md "`daily_attendances` builds itself"). |

## Doc drift

| ID | Doc | Says | Code does | Status (Phase 6) |
|---|---|---|---|---|
| D1 | `docs/ATTENDANCE_UI.md`, Date picker → Single mode | Lists holiday, join, effective dates and the manual punch date as the single-mode fields, and says "Every date field today is required … so none shows Clear yet." | The leave modal (`leave_start`, `leave_end`), the overtime modal (`overtime_date`) and the deactivate modal (`status_left_on`) use it too, and the leave "To" field is `clearable`. | Fixed, 6e |
| D2 | `docs/DESIGN_SYSTEM.md`, Navigation | "Section tabs (Organization, Policies) are one component, `<x-tab-bar>`". | Reports uses it too (`reports.blade.php`); CLAUDE.md already says so. | Fixed, 6e |
| D3 | `docs/DESIGN_SYSTEM.md`, Control height | "The auth pages keep their 44px touch targets by adding `min-h-11`." | Only `login.blade.php` does; forgot-password and the forced password change are 38px. | Fixed, 6f (with F23) |
| D4 | `docs/DESIGN_SYSTEM.md`, Dashboard vs `docs/ATTENDANCE_UI.md`, pending days | "`Off`/`Holiday` for a non-working day" vs "Off or Holiday (every scoped row)". | The narrower rule: a weekend with any worker draws a near-zero bar (F13). | Fixed, 6e (with F13): one rule in both docs |
| D5 | `errors/partials/page.blade.php` comment | "never Laravel's bare, unstyled default". | There is no 419 or 503 view, so those fall back to Laravel's defaults (F17). | Fixed, 6b (with F17) |
| D6 | `docs/DESIGN_SYSTEM.md`, Spacing and layout | "label-to-control spacing: `mt-1`". | `<x-input-label>` has `mb-1`, as CLAUDE.md says. | Fixed, 6e |
| D7 | `docs/DESIGN_SYSTEM.md`, Interaction states | "Never a native `title` attribute." | The calendar's holiday name has one (F38). | Fixed, 6d (with F38) |

## Proposed fix slices

Each slice is sized to review by screenshot in one sitting. P1s come first.

| Slice | Theme | Findings | Decision needed first |
|---|---|---|---|
| **6b** | Access and stale states | F01, F02, F16, F17, F09 | Owner: F01 (leaver access) and F09 (wording). F02, F16 and F17 can go ahead. |
| **6c** | Query cost | F03, F26 | none |
| **6d** | Accessibility foundations | F05, F18, F19, F20, F21, F22, F29, F30, F31, F38 | none (all Agent) |
| **6e** | Copy and misleading states | F07, F08, F12, F13, F24, F25, F33, D1–D7 | Owner: F08, F12, F13, F25 wording |
| **6f** | Phone ergonomics | F06, F23, F32, F14 | Owner: F06 layout, F23 control-height rule, F14 cancel rule |
| **6g** | Owner-led additions | F11, F15, F27, F28, F34, F35, F04 | Owner, each |
| **6h** | Frontend hygiene | F10, F36, F37 | none (F10 is P2: do it early, it's small) |

## Per-page query counts and timings

Server-side, one page render in a fresh app instance (`scripts/audit/querycount.php`), with audit data loaded. "ms" is total server time in PHP-FPM, locally. Browser TTFB through nginx was 27–250 ms on every page. No page approached 1 s.

| User | Page | Status | Queries | Query ms | Total ms | Most repeated query |
|---|---|---|---|---|---|---|
| admin | `/dashboard` | 200 | 288 | 46.3 | 120 |
| admin | `/employees` | 200 | 252 | 34.2 | 104 |
| admin | `/employees/2` | 200 | 303 | 48.4 | 146 |
| admin | `/attendance` | 200 | 259 | 38.4 | 116 |
| admin | `/attendance?from=2026-10-01&to=2026-10-28` | 200 | 253 | 40.0 | 115 |
| admin | `/attendance/2` | 200 | 252 | 35.4 | 110 |
| admin | `/attendance/2?view=table` | 200 | 252 | 35.9 | 155 |
| admin | `/time-off` | 200 | 244 | 40.1 | 121 |
| admin | `/approvals` | 200 | 661 | 91.3 | 205 |
| admin | `/overtime` | 200 | 242 | 37.6 | 98 |
| admin | `/policies?tab=schedules` | 200 | 252 | 37.1 | 124 |
| admin | `/policies?tab=holidays` | 200 | 252 | 37.4 | 121 |
| admin | `/policies?tab=leave-types` | 200 | 252 | 36.9 | 120 |
| admin | `/policies?tab=overtime` | 200 | 252 | 37.7 | 119 |
| admin | `/organization` | 200 | 244 | 35.9 | 100 |
| admin | `/organization?tab=positions` | 200 | 244 | 33.5 | 92 |
| admin | `/reports?tab=attendance` | 200 | 472 | 69.2 | 240 |
| admin | `/reports?tab=leave` | 200 | 472 | 67.8 | 237 |
| admin | `/reports?tab=overtime` | 200 | 472 | 66.8 | 232 |
| admin | `/profile` | 200 | 240 | 32.9 | 87 |
| aye.aye.mon | `/dashboard` | 200 | 63 | 20.8 | 60 |
| aye.aye.mon | `/employees` | 200 | 27 | 6.8 | 46 |
| aye.aye.mon | `/attendance` | 200 | 35 | 9.6 | 65 |
| aye.aye.mon | `/attendance/9` | 200 | 25 | 6.2 | 55 |
| aye.aye.mon | `/approvals` | 200 | 101 | 16.5 | 62 |
| aye.aye.mon | `/reports?tab=attendance` | 200 | 159 | 28.2 | 130 |
| aye.aye.mon | `/reports?tab=leave` | 200 | 159 | 27.4 | 128 |
| aye.aye.mon | `/time-off` | 200 | 44 | 8.3 | 48 |
| aye.aye.mon | `/overtime` | 200 | 24 | 6.2 | 42 |
| aye.aye.mon | `/my-attendance` | 200 | 25 | 6.0 | 57 |
| kyaw.kyaw.naing | `/dashboard` | 200 | 52 | 10.4 | 47 |
| kyaw.kyaw.naing | `/my-attendance` | 200 | 18 | 4.7 | 58 |
| kyaw.kyaw.naing | `/my-attendance?view=table` | 200 | 18 | 5.2 | 65 |
| kyaw.kyaw.naing | `/time-off` | 200 | 52 | 8.7 | 51 |
| kyaw.kyaw.naing | `/overtime` | 200 | 28 | 6.1 | 43 |
| kyaw.kyaw.naing | `/profile` | 200 | 13 | 3.7 | 36 |
| audit.new.joiner | `/time-off` | 200 | 39 | 7.5 | 43 |
| audit.admin | `/dashboard` | 200 | 288 | 56.9 | 135 |
| audit.admin | `/time-off` | 200 | 258 | 32.7 | 97 |

With 112 employees (70 extra): `/employees` stayed at 252 queries, 100 ms; `/employees?page=11` at 252, 128 ms. The directory doesn't grow with headcount; the admin baseline is the badge (F03).

## Screenshots

`docs/audit/shots/` (gitignored, local only): 432 page captures named `<page>-<persona>-<width>-<theme>.png`, with persona one of `admin` (A2), `a1`, `m1`, `m2a`, `m2b`, `e1`, `e2`, `kh`, `e4`. The pages per persona are listed in `scripts/audit/playwright/pages.mjs`. Plus:
- guest: `login-guest-*`, `login-error-guest-*`, `forgot-password-guest-*`, `password-change-kh-*`, `500-guest-*`, `419-guest-390-light.png` (a sign-in, not a 419: see F17);
- journeys: `journey-*.png` (named in the tables above);
- stress: `employees-100plus-admin-{390,1440}-light.png`;
- print: `print-reports-attendance-admin-1440-light.png`, `print-reports-attendance-admin-a4-light.png`.

## Not covered, or unverified

- **Excel:** the CSVs' BOM and UTF-8 were checked byte by byte; opening them in Excel was not possible here.
- **419:** not reproducible in Chromium (same-origin requests pass `PreventRequestForgery`); Livewire's own expired-page dialog never appeared.
- **Admin journeys:** forms were opened and validated, not all saved (see Journeys).
- **Real devices and screen readers:** Chromium emulation only; accessibility is axe plus a sampled Tab order, not a VoiceOver/TalkBack pass. The 1024 captures had no axe run.
- **Reset-password and confirm-password pages:** need a mailed token or a sensitive action; not captured. Mail delivery wasn't inspected.
- **Session-expiry return:** whether sign-in returns the user to the page they were on was not checked.

## Appendix: method details

- Harness: `scripts/audit/playwright/lib.mjs` grows the viewport to `<main>`'s scroll height before each shot (the shell scrolls inside `<main>`, so a normal full-page screenshot is only the viewport).
- axe-core 4.10.3, tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `best-practice`. Most `color-contrast` hits (147 nodes) are the calendar's out-of-month day numbers, which the design system allows as decorative `slate-300`; the real ones are F20 and F21. `region` (content outside landmarks) fired on 130 captures, mostly the sidebar's logo and the topbar.
- `wire:key` coverage (loops / keys): `reports/attendance` 4/0, `reports/leave` 5/0, `reports/overtime` 5/0, `employees/schedule-assignments` 2/0, `leave/partials/balances` 3/0, `employees/form-modal` 3/0, `overtime-settings/edit` 3/0 (static), `leave/approvals` 4/0 (the included items carry their own keys).
- `wire:model.live` is used only on filters and selects that should apply at once, plus debounced search; no overuse found.
