// AUDIT (Phase 6h close-out): the admin journeys 6a only opened, completed
// through the UI and checked in the database after each save — an employee
// with and without a login, a new schedule and an effective-dated change to
// it, a holiday, an opening-balance adjustment, deactivate and reactivate,
// leave and overtime filed for E3 (Su Su Hlaing, no login), an overtime limit
// override with a reason, a manual punch and a void with reasons, and each
// report's CSV and printed PDF. Writes screenshots to docs/audit/shots/6h/
// and files to storage/app/verify-6h/journeys/. Changes the dev database:
// run on the demo seed + seed-personas.php + stress-employees.php, reseed
// afterwards. Exits 1 if any journey fails.
import { BASE, OUT, REPO, login, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SHOTS = `${OUT}/6h`;
const FILES = `${REPO}storage/app/verify-6h/journeys`;
fs.mkdirSync(SHOTS, { recursive: true });
fs.mkdirSync(FILES, { recursive: true });

const sql = (query) => execSync(`docker compose exec -T mysql mysql -uattendance -psecret stampy -N -e ${JSON.stringify(query)}`, { cwd: REPO, stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const nativeFill = (p, id, value) => p.evaluate(([i, v]) => {
    const el = document.getElementById(`${i}-native`);
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
}, [id, value]);
const dialog = (p) => p.getByRole('dialog').last();
const selectFirst = (p, id) => p.evaluate((i) => { const el = document.getElementById(i); const opt = [...el.options].find((o) => o.value); el.value = opt.value; el.dispatchEvent(new Event('change', { bubbles: true })); return opt.textContent.trim(); }, id);
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = new Date();
const daysFrom = (n) => { const d = new Date(today); d.setDate(d.getDate() + n); return d; };
const nextWeekday = (from, weekday) => { const d = new Date(from); while (d.getDay() !== weekday) d.setDate(d.getDate() + 1); return d; };

const results = [];
const journey = async (name, fn) => {
    try {
        const detail = await fn();
        results.push([name, 'PASS', detail ?? '']);
        console.log(`PASS  ${name}${detail ? '  → ' + detail : ''}`);
    } catch (e) {
        results.push([name, 'FAIL', e.message.split('\n')[0].slice(0, 200)]);
        console.log(`FAIL  ${name}  → ${e.message.split('\n')[0].slice(0, 200)}`);
    }
};
const expect = (ok, message) => { if (! ok) throw new Error(message); };

await withBrowser(async (b) => {
    const { state } = await login(b, 'admin');
    const c = await themedContext(b, state, 'light');
    const p = await c.newPage();
    await p.setViewportSize({ width: 1440, height: 900 });
    const errors = [];
    p.on('pageerror', (e) => errors.push(e.message.slice(0, 160)));
    const shot = (name) => p.screenshot({ path: `${SHOTS}/journey-${name}-1440-light.png` });

    await journey('add an employee without a login', async () => {
        await p.goto(`${BASE}/employees`); await settle(p);
        await p.getByRole('button', { name: 'Add employee' }).first().click(); await settle(p, 700);
        await p.fill('#emp_full_name', 'Journey Without Login');
        await p.fill('#emp_employee_code', 'JRN-001');
        await selectFirst(p, 'emp_department_id');
        await selectFirst(p, 'emp_position_id');
        await nativeFill(p, 'emp_join_date', iso(daysFrom(-30)));
        await dialog(p).getByRole('button', { name: 'Save employee' }).click(); await settle(p, 900);
        await shot('employee-saved');
        const row = sql("select concat(full_name, ' | ', ifnull(user_id, 'no login'), ' | ', join_date) from employees where employee_code = 'JRN-001'");
        expect(row.startsWith('Journey Without Login | no login'), `employees row: "${row}"`);
        return row;
    });

    await journey('add an employee with a login', async () => {
        await p.goto(`${BASE}/employees`); await settle(p);
        await p.getByRole('button', { name: 'Add employee' }).first().click(); await settle(p, 700);
        await p.fill('#emp_full_name', 'Journey With Login');
        await p.fill('#emp_employee_code', 'JRN-002');
        await selectFirst(p, 'emp_department_id');
        await selectFirst(p, 'emp_position_id');
        await nativeFill(p, 'emp_join_date', iso(daysFrom(-30)));
        await dialog(p).locator('input[type=checkbox][wire\\:model\\.live="create_user"]').check(); await settle(p, 700);
        await p.fill('#emp_username', 'journey.login');
        await p.fill('#emp_email', 'journey.login@example.com');
        await shot('employee-with-login-form');
        await dialog(p).getByRole('button', { name: 'Save employee' }).click(); await settle(p, 900);
        await shot('employee-with-login-saved');
        const row = sql("select concat(u.username, ' | ', u.name, ' | must change: ', u.must_change_password) from users u join employees e on e.user_id = u.id where e.employee_code = 'JRN-002'");
        // A generated password is temporary (Phase 6h follow-up, N1).
        expect(row === 'journey.login | Journey With Login | must change: 1', `users row: "${row}"`);
        await p.keyboard.press('Escape'); await settle(p);
        return row;
    });

    const newEmployee = sql("select id from employees where employee_code = 'JRN-001'");

    await journey('a new schedule, then an effective-dated change to it', async () => {
        await p.goto(`${BASE}/policies?tab=schedules`); await settle(p);
        await p.getByRole('button', { name: 'New schedule' }).first().click(); await settle(p, 700);
        await p.fill('#schedule_name', 'Journey 9 to 6');
        await nativeFill(p, 'schedule_start_time', '09:00');
        await nativeFill(p, 'schedule_end_time', '18:00');
        await p.fill('#schedule_break', '60');
        await nativeFill(p, 'schedule_break_start', '13:00');
        await dialog(p).locator('button[type=submit]').click(); await settle(p, 900);
        const schedule = sql("select id from work_schedules where name = 'Journey 9 to 6'");
        expect(schedule !== '', 'no work_schedules row');

        const from = iso(nextWeekday(daysFrom(7), 1));
        await p.goto(`${BASE}/employees/${newEmployee}`); await settle(p);
        await p.getByRole('button', { name: 'Assign schedule' }).first().click(); await settle(p, 700);
        await p.selectOption('#assign_schedule', schedule);
        await nativeFill(p, 'assign_effective_from', from);
        await shot('schedule-assign-form');
        await dialog(p).locator('button[type=submit]').click(); await settle(p, 900);
        await shot('schedule-assigned');
        const row = sql(`select concat(effective_from, ' → ', s.name) from employee_work_schedules a join work_schedules s on s.id = a.work_schedule_id where a.employee_id = ${newEmployee} and a.work_schedule_id = ${schedule}`);
        expect(row === `${from} → Journey 9 to 6`, `assignment: "${row}"`);
        return row;
    });

    await journey('a new holiday', async () => {
        const date = `${today.getFullYear() + 1}-05-20`;
        await p.goto(`${BASE}/policies?tab=holidays`); await settle(p);
        await p.getByRole('button', { name: 'New holiday' }).first().click(); await settle(p, 700);
        await nativeFill(p, 'holiday_date', date);
        await p.fill('#holiday_name', 'Journey Day');
        await dialog(p).locator('button[type=submit]').click(); await settle(p, 900);
        const row = sql(`select concat(date, ' ', name) from holidays where name = 'Journey Day'`);
        expect(row === `${date} Journey Day`, `holiday: "${row}"`);
        return row;
    });

    await journey('an opening-balance adjustment', async () => {
        await p.goto(`${BASE}/employees/2#leave`); await settle(p);
        await p.getByRole('button', { name: 'Add adjustment' }).first().click(); await settle(p, 700);
        await selectFirst(p, 'adj_type');
        await p.fill('#adj_days', '1.5');
        await p.fill('#adj_note', 'Opening balance: 1.5 days carried from the paper records');
        await shot('adjustment-form');
        await dialog(p).locator('button[type=submit]').click(); await settle(p, 900);
        const row = sql("select concat(days, ' | ', year, ' | ', note) from leave_adjustments where note like 'Opening balance: 1.5 days%'");
        expect(row.startsWith('1.5 |'), `leave_adjustments: "${row}"`);
        return row;
    });

    await journey('deactivate, then reactivate', async () => {
        await p.goto(`${BASE}/employees/${newEmployee}`); await settle(p);
        await p.getByRole('button', { name: 'Deactivate' }).first().click(); await settle(p, 700);
        await nativeFill(p, 'status_left_on', iso(today));
        await shot('deactivate-form');
        await dialog(p).locator('button[type=submit]').click(); await settle(p, 900);
        const inactive = sql(`select concat(status, ' ', ifnull(left_on, '-')) from employees where id = ${newEmployee}`);
        expect(inactive === `inactive ${iso(today)}`, `after deactivate: "${inactive}"`);
        await p.getByRole('button', { name: 'Reactivate' }).first().click(); await settle(p, 700);
        await dialog(p).locator('button[type=submit]').click(); await settle(p, 900);
        const active = sql(`select concat(status, ' ', ifnull(left_on, '-')) from employees where id = ${newEmployee}`);
        expect(active === 'active -', `after reactivate: "${active}"`);
        return `${inactive} → ${active}`;
    });

    const chooseSuSu = async () => {
        await p.keyboard.type('Su Su Hl'); await settle(p, 900);
        await dialog(p).getByRole('button', { name: /Su Su Hlaing/ }).first().click(); await settle(p, 800);
    };

    await journey('file leave for E3', async () => {
        const day = iso(nextWeekday(daysFrom(14), 2));
        await p.goto(`${BASE}/time-off`); await settle(p);
        await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p);
        await chooseSuSu();
        await p.evaluate(() => { const el = document.getElementById('leave_type'); el.value = [...el.options].find((o) => o.textContent.trim() === 'Unpaid').value; el.dispatchEvent(new Event('change', { bubbles: true })); });
        await nativeFill(p, 'leave_start', day);
        await nativeFill(p, 'leave_end', day); await settle(p, 400);
        await p.fill('#leave_reason', 'Journey: family errand');
        await dialog(p).getByRole('button', { name: 'Review' }).click(); await settle(p, 900);
        await shot('file-leave-review');
        await dialog(p).getByRole('button', { name: /file and approve/i }).click(); await settle(p, 900);
        const row = sql(`select concat(status, ' ', start_date) from leaves where employee_id = 3 and reason = 'Journey: family errand'`);
        expect(row === `approved ${day}`, `leaves: "${row}"`);
        return row;
    });

    await journey('file overtime for E3 past the 2-hour limit, with an override reason', async () => {
        const day = iso(nextWeekday(daysFrom(-9), 2));
        await p.goto(`${BASE}/overtime`); await settle(p);
        await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p);
        await chooseSuSu();
        await nativeFill(p, 'overtime_date', day);
        await nativeFill(p, 'overtime_start', '17:00');
        await nativeFill(p, 'overtime_end', '21:00');
        await p.selectOption('#overtime_compensation', 'pay');
        await p.fill('#overtime_reason', 'Journey: month-end close');
        await dialog(p).getByRole('button', { name: 'Review' }).click(); await settle(p, 900);
        await p.fill('#overtime_override', 'Journey: the auditors needed the figures that night');
        await shot('file-overtime-override');
        await dialog(p).getByRole('button', { name: /file and approve/i }).click(); await settle(p, 900);
        const row = sql(`select concat(status, ' | ', limit_override_reason) from overtime_requests where employee_id = 3 and reason = 'Journey: month-end close'`);
        expect(row === 'approved | Journey: the auditors needed the figures that night', `overtime_requests: "${row}"`);
        return row;
    });

    await journey('a manual punch and a void, each with a reason', async () => {
        await p.goto(`${BASE}/attendance/2`); await settle(p);
        const cell = p.locator(`[wire\\:click^="openDay('"][aria-label*="Present"]`).first();
        const day = (await cell.getAttribute('wire:click')).match(/'([\d-]+)'/)[1];
        await cell.click(); await settle(p, 600);
        await dialog(p).getByRole('button', { name: 'Add punch' }).click(); await settle(p, 500);
        await nativeFill(p, `new_punch_time_${day}`, '12:58');
        await p.fill(`#new_punch_reason_${day}`, 'Journey: forgot to punch back in after lunch');
        await dialog(p).getByRole('button', { name: 'Save punch' }).click(); await settle(p, 900);
        const id = sql(`select id from attendance_logs where employee_id = 2 and reason = 'Journey: forgot to punch back in after lunch'`);
        expect(id !== '', 'no punch with the reason');
        await dialog(p).getByRole('button', { name: /^Void the In punch at 12:58 PM/ }).click(); await settle(p, 600);
        await p.fill(`#void_reason_${id}`, 'Journey: it was a test');
        await dialog(p).getByRole('button', { name: 'Void punch' }).click(); await settle(p, 900);
        await shot('punch-voided');
        const row = sql(`select concat(reason, ' | ', void_reason, ' | voided: ', voided_at is not null) from attendance_logs where id = ${id}`);
        expect(row === 'Journey: forgot to punch back in after lunch | Journey: it was a test | voided: 1', `attendance_logs: "${row}"`);
        await p.keyboard.press('Escape'); await settle(p);
        return row;
    });

    for (const tab of ['attendance', 'leave', 'overtime']) {
        await journey(`report ${tab}: CSV and print`, async () => {
            await p.goto(`${BASE}/reports?tab=${tab}`); await settle(p, 700);
            const [download] = await Promise.all([p.waitForEvent('download', { timeout: 10000 }), p.getByRole('button', { name: 'Download CSV' }).click()]);
            const file = `${FILES}/${download.suggestedFilename()}`;
            await download.saveAs(file);
            const head = fs.readFileSync(file, 'utf8').split('\n')[0];
            expect(head.startsWith('﻿'), 'no byte-order mark');
            await p.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
            const pdf = `${FILES}/print-${tab}.pdf`;
            await p.pdf({ path: pdf, preferCSSPageSize: true, printBackground: true });
            await p.evaluate(() => window.dispatchEvent(new Event('afterprint')));
            const pages = (fs.readFileSync(pdf, 'latin1').match(/\/Type\s*\/Page[^s]/g) ?? []).length;
            expect(pages > 0, 'empty PDF');
            return `${download.suggestedFilename()}, header "${head.slice(1, 40)}…", PDF ${pages} page(s)`;
        });
    }

    results.push(['page errors', errors.length ? 'FAIL' : 'PASS', errors.join(' / ')]);
    await c.close();
});

fs.writeFileSync(`${FILES}/results.json`, JSON.stringify(results, null, 1));
const failed = results.filter(([, status]) => status === 'FAIL').length;
console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
