# Going live

The checklist an admin works through before anyone uses Stampy for real. Do the steps in order: later ones depend on earlier ones (balances need leave types, time off in lieu needs its settings before anyone requests overtime, approvals need managers). Commands are `php artisan …`; under this repo's Docker setup, prefix them with `docker compose exec app`.

## 1. Environment and timezone

Before anything else, take **[`HR_DECISIONS.md`](HR_DECISIONS.md)** to HR: the leave, overtime and time off in lieu policy questions with their current defaults. Steps 4 and 5 enter the answers, and two of them lock once used.

- `APP_ENV=production`, `APP_DEBUG=false`, and a real `APP_KEY` in `.env`.
- **PHP 8.5.12 or later** (check with `php -v`): earlier 8.5 releases have two actively exploited remote code execution flaws (CVE-2026-109618 / CVE-2026-109619). Set `session.upload_progress.enabled = 0` and `fatal_error_backtraces = Off` too, as `docker/php/conf.d/zz-security.ini` does here, and check them with `php -i | grep -E 'upload_progress.enabled|fatal_error_backtraces'` — and in PHP-FPM's configuration (`php-fpm -i`), which is what serves the web requests, not only the CLI's.
- The app timezone is **Asia/Phnom_Penh**, set in `config/app.php` (not in `.env`): every attendance and leave rule is a local-time rule. Check it with `php artisan about` (Environment → Timezone).
- The server's own clock must be correct (NTP). The database's session timezone doesn't matter — no query uses MySQL's clock.

## 2. Database and the production seed

Run `php artisan migrate --force`. **Never run plain `db:seed` in production.** `DatabaseSeeder` builds the demo system: 35 made-up employees with the password `password`, two months of invented punches, demo holidays on invented dates (`HolidaySeeder`), and demo leave requests (`LeaveSeeder`). Every demo seeder — `DatabaseSeeder` and each seeder it calls except the three below — refuses to run in production, even with `--force`, and stops before writing anything (`DemoSeeder`); the error names the seeders that are allowed.

The production seed is the configuration seeders only, run by class:

```bash
php artisan db:seed --class=RoleSeeder --force         # admin, manager, employee
php artisan db:seed --class=LeaveTypeSeeder --force    # the starting leave types (step 4)
php artisan db:seed --class=WorkScheduleSeeder --force # "Default", Mon–Fri 08:00–17:00 — review it in step 3
```

Departments, positions and holidays are entered in the app (step 3), not seeded: `DepartmentSeeder` and `PositionSeeder` are sample names.

## 3. The first admin, then the organization and working time

There is no sign-up. Create the first admin with:

```bash
php artisan user:create-admin
```

It asks for a name, a username (what they sign in with), an email (optional — press Enter to skip) and a password, typed twice and checked by the same rules as the password-change form. It needs the `admin` role from step 2, and refuses — creating nothing — if the username or email is already taken. The password is the one you typed, so nothing asks for a new one at first login.

If this admin will also take leave, link them to an employee record (step 6). An admin with no employee record is only for a system account. Once the employee exists, `php artisan user:create-admin --employee=EMP-0001` creates an admin already linked to it; the account's name then follows the employee record. The command never changes an existing account: to make someone who already has a login an admin, change their role in the employee form.

Then, as that admin:

- **Organization → Departments and Positions:** the real ones.
- **Policies → Schedules:** the company's working hours. **Every schedule needs "Break starts" set**, or half-day leave is refused on it. "Break starts" is the morning/afternoon boundary, 12:00 on the seeded Default. A schedule's hours lock once anyone is assigned to it, so get them right before adding employees.
- **Policies → Holidays:** this year's public holidays. The Khmer-calendar ones (Khmer New Year, Pchum Ben, the Water Festival) are announced each year and have to be entered by hand.

## 4. Leave types

**HR confirms the leave policy in Policies → Leave types before anyone requests leave.** The seeded set is a starting point, not company policy:

- Annual (18 days a year, +1 per 3 years of service, carry over up to 6, usable after 12 months), Medical (30 days) and Unpaid are the company's draft.
- Special (up to 7 days, deducted from Annual) and Maternity (90 calendar days) follow the Cambodian Labour Law defaults.

For each type, check the days per year, the carry-over cap, the service requirement ("Usable after"), the maximum per request, and whether it's paid. How a type counts days, whether it allows half days, and which balance it draws from all lock once the first leave is taken with it. Settle those three first.

## 5. Overtime

**HR confirms the overtime settings in Policies → Overtime before anyone requests time off in lieu.** The defaults follow the Labour Law and Prakas 112/25, not company policy:

- Rates: workday 150%, night 200%, the weekly rest day (Sunday) 200%, holiday 200%. They must run holiday ≥ rest day ≥ night ≥ workday ≥ 100%.
- Night is 22:00–05:00. A day off other than the weekly rest day (Saturday) earns the workday rate.
- Limits: 2 hours of overtime a day on a day with scheduled hours, 10 hours of work a day in all. An admin can go over either with a reason.
- Claims go back at most 7 days. An admin can file further back.
- Time off in lieu: 1:1 (ratio 100%), half a day for every 240 minutes, credited to the "Time off in lieu" leave type.

Settle the three **time off in lieu** settings (ratio, block, leave type) first. **They lock as soon as any time off in lieu is credited**, because every half day is worked out again from all the overtime ever credited, and changing them afterwards is a data operation, not a settings edit. Rates, the night window, the rest day and the limits stay editable. A rate changes only the report's arithmetic. A new night window or rest day applies to days built from then on; to apply it to a past period, run `php artisan attendance:build-daily --from=YYYY-MM-DD --to=YYYY-MM-DD`.

**Time off in lieu's carry-over cap is a placeholder (5 days)** in Policies → Leave types. HR sets the real one. Without a cap, a half day earned on 30 December would lapse the next day.

To pay overtime only, set "Credited to" to "No time off in lieu". The request form then offers Pay only.

**Who approves:** overtime uses the same two steps as leave. The employee's manager (with the manager role) decides first, then an admin. The managers set up in the next step are the overtime approvers too.

**Reports for HR:** Reports → Attendance gives each employee's month in counts (workdays, absences, late and early leave, leave taken and days off worked), and Reports → Leave each employee's balance of a leave type for a year, both with a CSV. Managers see their own team in both. For someone who has left, read untaken leave from the leave CSV's **Available at last day** column, not **Available**, which is the full year's balance.

**Payroll:** Reports → Overtime shows a month's approved, credited overtime per employee: pay minutes by category, time off minutes as a total, and pay-equivalent hours (the minutes × today's rates ÷ 60, pay only — payroll multiplies by the hourly wage). **Download CSV** saves the same table. The filename and the first line say when it was exported. A month still in progress says so on the page and in the file: until the month ends, a late claim or a cancellation can still change it. Export after the month closes, and keep the file payroll was run from.

## 6. People, logins and managers

- **Employees:** add each one with their real join date; it decides their leave grants and service requirements. Fix a wrong join date before any leave is taken against it.
- **Logins:** give a login to everyone who will request their own leave. Employees without one can still have leave filed for them by an admin.
- **Managers:** anyone who approves leave needs the **manager role**, and their team's `Manager` set to them. If an employee's manager doesn't have the role, that employee's requests skip the manager step, with a note saying so, and go straight to an admin.
- **Admins who take leave:** their login has to be linked to their employee record. Otherwise they can't request their own leave, and they aren't anyone's approver at the manager step. Add their employee record first (without a login), then create their admin login linked to it: `php artisan user:create-admin --employee=EMP-0001`.

Each employee gets this year's leave grants when they're created. Check one with `php artisan leave:balance EMP-0001`.

## 7. Opening balances

Leave already taken this year on paper, and days carried in from last year, have to be entered by hand **before employees start requesting**. Years before go-live have no grant rows, so nothing carries into this year on its own. Leave taken on paper isn't in the system either, so without an entry everyone starts with their full allowance.

With fewer than 50 employees this is done by hand, from each employee's profile. No import command is built. In this order:

1. **HR prepares one list:** employee code, Annual taken this year, Medical taken this year, Annual carried in from last year.
2. **Enter it:** on each profile's Leave card, **Add adjustment**, once per figure, for the current year:
   - leave taken is a negative number (`-4` for 4 days taken);
   - carry-in is a positive number (`3`);
   - always add a note, e.g. "Opening balance: Annual taken before go-live".

   Adjustments can't be edited or deleted. Fix a mistake with a reversing entry.
3. **Check every employee against the list:** `php artisan leave:balance EMP-0001` shows each type's entitled, carried, adjustments, used, pending and available. Available should match HR's figure.

If headcount grows well past 50, a CSV import for opening balances would be worth adding.

## 8. The scheduler

Attendance and leave depend on scheduled tasks. In production, cron runs the scheduler every minute:

```
* * * * * cd /path/to/stampy && php artisan schedule:run >> /dev/null 2>&1
```

Under this repo's Docker setup, the `scheduler` service runs `schedule:work` instead. `php artisan schedule:list` should show:

- `attendance:build-daily` for today, every 15 minutes;
- yesterday, every 15 minutes while it has days still open;
- the last 7 days, daily at 02:10;
- **`leave:grant`, daily at 00:05.** It creates each year's grants on 1 January, and first-year grants on the day someone completes their service requirement.

Time off in lieu has no task of its own: each `attendance:build-daily` run also credits it for whoever has earned it, and puts right any crediting that failed earlier. So the list above is complete — check that `leave:grant` and the three attendance tasks are there, and nothing else is needed for overtime.

If `leave:grant` doesn't run, nobody gets next year's leave and new joiners never become eligible. Its output is logged; a failure is logged as an error. Check `storage/logs` after the first night.

## 9. Before opening it up

- Sign in as a manager and as an employee (temporary passwords from the employee form) and look at Time off, Approvals and the dashboard.
- File one test leave request, approve it at both steps, then cancel it. The balance should return.
- File one test overtime claim for a past day with both punches, approve it at both steps, and check its credited time on the Overtime page and in Reports → Overtime. Then cancel it.
- Delete nothing to clean up afterwards: cancelled leave is history, and history is kept.
