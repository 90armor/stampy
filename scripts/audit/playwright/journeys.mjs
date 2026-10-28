import { BASE, OUT, DATA, login, themedContext, withBrowser } from './lib.mjs';
import fs from 'node:fs';

const log = [];
const note = (journey, step, detail) => { log.push({ journey, step, ...detail }); console.log(`[${journey}] ${step}`, JSON.stringify(detail).slice(0, 300)); };

async function ctxFor(browser, user, width, theme = 'light') {
    const { state, landed } = await login(browser, user);
    const ctx = await themedContext(browser, state, theme);
    const page = await ctx.newPage();
    await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message.slice(0, 200)));
    return { ctx, page, dialogs, errors, landed };
}
const shot = (page, name) => page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false });
const settle = async (page, ms = 400) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
const text = async (loc) => (await loc.allInnerTexts().catch(() => [])).join(' | ').replace(/\s+/g, ' ').slice(0, 400);
const errorsOn = async (page) => text(page.locator('[role=alert], .text-red-600, .text-red-700, p.text-sm.text-red-600'));

async function employeeJourney(browser) {
    const J = 'employee-phone';
    // 1. Login with the employee code (the persona brief asks for it).
    {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await ctx.newPage();
        await page.goto(`${BASE}/login`);
        await shot(page, 'journey-login-e2-390-light');
        await page.fill('#email', 'EMP-0002');
        await page.fill('#password', 'password');
        await page.click('button[type=submit]');
        await settle(page);
        note(J, 'login with employee code EMP-0002', { url: page.url(), message: await text(page.locator('form')) });
        await shot(page, 'journey-login-code-error-e2-390-light');
        await ctx.close();
    }
    const { ctx, page, dialogs, errors } = await ctxFor(browser, 'kyaw.kyaw.naing', 390);
    let clicks = 0;
    await page.goto(`${BASE}/dashboard`); await settle(page);
    note(J, 'dashboard', { h1: await text(page.locator('h1')) });
    // 2. Today → this month → table → day detail
    await page.goto(`${BASE}/my-attendance`); await settle(page); clicks++;
    await page.locator('[aria-current=date]').first().click().catch(() => {}); await settle(page, 600); clicks++;
    await shot(page, 'journey-day-modal-today-e2-390-light');
    note(J, 'open today in calendar', { modal: await text(page.locator('[role=dialog]').first()) });
    await page.keyboard.press('Escape'); await settle(page);
    await page.getByRole('button', { name: /table/i }).first().click().catch(() => {}); await settle(page); clicks++;
    await shot(page, 'journey-my-attendance-table-e2-390-light');
    // 3. File a morning half-day for the next workday
    await page.goto(`${BASE}/time-off`); await settle(page); clicks++;
    const before = await text(page.locator('table').first());
    await page.getByRole('button', { name: /request leave/i }).first().click(); await settle(page, 600); clicks++;
    await shot(page, 'journey-leave-form-e2-390-light');
    await page.selectOption('#leave_type', { label: 'Annual' });
    await page.fill('#leave_start-native', '2026-11-03');
    await settle(page, 300);
    const halfVisible = await page.locator('#leave_half, [name=half], input[value=am]').first().isVisible().catch(() => false);
    note(J, 'half-day choice visible after picking one date', { halfVisible });
    const am = page.getByLabel(/morning/i).first();
    if (await am.count()) { await am.check().catch(() => am.click()); }
    await page.fill('#leave_reason', 'Bank appointment').catch(() => {});
    await shot(page, 'journey-leave-form-filled-e2-390-light');
    await page.getByRole('button', { name: /review/i }).click(); await settle(page, 600); clicks++;
    await shot(page, 'journey-leave-review-e2-390-light');
    note(J, 'leave review', { body: await text(page.locator('[role=dialog]').last()), errors: await errorsOn(page) });
    await page.getByRole('button', { name: /^submit/i }).click(); await settle(page, 800); clicks++;
    await shot(page, 'journey-leave-submitted-e2-390-light');
    note(J, 'leave submitted', { clicks, balanceBefore: before, balanceAfter: await text(page.locator('table').first()), flash: await text(page.locator('[role=status]')) });
    // 4. Overtime claim from 6 days ago, time off in lieu
    await page.goto(`${BASE}/overtime`); await settle(page);
    await page.getByRole('button', { name: /request overtime/i }).first().click(); await settle(page, 600);
    await page.fill('#overtime_date-native', '2026-10-22');
    await page.fill('#overtime_start-native', '17:00');
    await page.fill('#overtime_end-native', '19:00');
    const comp = await page.locator('#overtime_compensation option').allInnerTexts();
    await page.selectOption('#overtime_compensation', 'time_off').catch(() => {});
    await page.fill('#overtime_reason', 'Customer call ran late');
    await shot(page, 'journey-ot-form-e2-390-light');
    await page.getByRole('button', { name: /review/i }).click(); await settle(page, 700);
    await shot(page, 'journey-ot-review-e2-390-light');
    await page.screenshot({ path: `${OUT}/journey-ot-review-full-e2-390-light.png`, fullPage: true });
    note(J, 'overtime review', { compensationOptions: comp, body: await text(page.locator('[role=dialog]').last()), errors: await errorsOn(page) });
    await page.getByRole('button', { name: /^submit/i }).click(); await settle(page, 800);
    await shot(page, 'journey-ot-submitted-e2-390-light');
    note(J, 'overtime submitted', { list: (await text(page.locator('main'))).slice(0, 400) });
    // 5. Cancel the pending overtime request
    const cancel = page.getByRole('button', { name: /^cancel/i }).first();
    note(J, 'cancel button present on overtime', { count: await page.getByRole('button', { name: /^cancel/i }).count() });
    if (await cancel.count()) {
        await cancel.click(); await settle(page, 500);
        await shot(page, 'journey-ot-cancel-confirm-e2-390-light');
        note(J, 'cancel confirm', { dialog: await text(page.locator('[role=alertdialog]')) });
        await page.locator('[role=alertdialog]').getByRole('button').last().click().catch(() => {}); await settle(page, 700);
        await shot(page, 'journey-ot-cancelled-e2-390-light');
    }
    // 6. Back button after submit
    await page.goBack(); await settle(page);
    note(J, 'back after submit', { url: page.url(), dialogs: [...dialogs] });
    note(J, 'console errors', { errors });
    await ctx.close();
}

async function passwordReset(browser) {
    const J = 'password-reset';
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/login`);
    const link = page.getByRole('link', { name: /forgot/i });
    note(J, 'forgot link on login', { count: await link.count() });
    await link.first().click(); await settle(page);
    await shot(page, 'journey-forgot-390-light');
    note(J, 'forgot page', { text: await text(page.locator('main, form').first()) });
    for (const [label, value] of [['with email', 'kyaw.kyaw.naing@example.com'], ['username, no email account', 'audit.new.joiner'], ['unknown', 'nobody@example.com']]) {
        await page.goto(`${BASE}/forgot-password`);
        await page.fill('input[name=email]', value);
        await page.click('button[type=submit]'); await settle(page, 600);
        note(J, label, { message: await text(page.locator('form').first()) });
        await shot(page, `journey-forgot-${label.replace(/\W+/g, '-')}-390-light`);
    }
    await ctx.close();
}

async function managerJourney(browser) {
    const J = 'manager-phone';
    const { ctx, page, dialogs, errors } = await ctxFor(browser, 'aye.aye.mon', 390);
    await page.goto(`${BASE}/approvals`); await settle(page);
    await page.screenshot({ path: `${OUT}/journey-approvals-m1-390-light.png`, fullPage: true });
    note(J, 'queue', { groups: await text(page.locator('h2')) });
    // approve the Khmer-name request with a note
    const kh = page.locator('li', { hasText: 'សុខ សុភាព' }).first();
    await kh.getByRole('button', { name: 'Approve' }).click(); await settle(page, 600);
    await shot(page, 'journey-approve-dialog-m1-390-light');
    await page.fill('#decision_note', 'Approved — take care.');
    // double-tap protection: click twice fast
    const btn = page.locator('[role=dialog] button[type=submit]');
    await Promise.all([btn.click(), btn.click().catch(() => {})]); await settle(page, 900);
    note(J, 'approved (double tap)', { after: (await text(page.locator('main'))).slice(0, 300) });
    // reject Kyaw Kyaw Naing's half day with no reason, then with one
    const kk = page.locator('li', { hasText: 'Kyaw Kyaw Naing' }).first();
    if (await kk.count()) {
        await kk.getByRole('button', { name: 'Reject' }).click(); await settle(page, 600);
        await page.locator('[role=dialog] button[type=submit]').click(); await settle(page, 600);
        await shot(page, 'journey-reject-noreason-m1-390-light');
        note(J, 'reject without reason', { errors: await errorsOn(page) });
        await page.fill('#decision_note', 'Release week — please pick another day.');
        await page.locator('[role=dialog] button[type=submit]').click(); await settle(page, 800);
        await shot(page, 'journey-rejected-m1-390-light');
    }
    await page.goto(`${BASE}/attendance`); await settle(page);
    await page.screenshot({ path: `${OUT}/journey-team-attendance-m1-390-light.png`, fullPage: true });
    await page.goto(`${BASE}/reports?tab=attendance`); await settle(page);
    const dl = await Promise.all([page.waitForEvent('download', { timeout: 8000 }).catch(() => null), page.getByRole('button', { name: /csv/i }).first().click().catch(() => {})]);
    if (dl[0]) { const p = `${DATA}/m1-attendance.csv`; await dl[0].saveAs(p); note(J, 'manager CSV', { file: dl[0].suggestedFilename() }); }
    for (const path of ['/employees/4', '/attendance/4', '/policies', '/reports?tab=overtime', '/organization']) {
        const r = await page.goto(BASE + path); await settle(page);
        note(J, 'url guess ' + path, { status: r.status(), url: page.url(), h: await text(page.locator('h1, h2').first()) });
    }
    note(J, 'console errors', { errors, dialogs });
    await ctx.close();
}

async function staleAndExpiry(browser) {
    const J = 'stale-419';
    // E1 files Medical (Annual isn't usable), M1 opens the queue, E1 cancels, M1 approves.
    const e1 = await ctxFor(browser, 'audit.new.joiner', 1440);
    await e1.page.goto(`${BASE}/time-off`); await settle(e1.page);
    await e1.page.getByRole('button', { name: /request leave/i }).first().click(); await settle(e1.page, 600);
    // Annual first: is the reason clear?
    await e1.page.selectOption('#leave_type', { label: 'Annual' });
    await e1.page.locator('#leave_start').click(); await settle(e1.page, 300);
    await e1.page.keyboard.press('Enter'); await settle(e1.page, 300);
    await e1.page.getByRole('button', { name: /review/i }).click(); await settle(e1.page, 700);
    await shot(e1.page, 'journey-e1-annual-refused-1440-light');
    note(J, 'E1 Annual before eligibility', { errors: await errorsOn(e1.page), options: await e1.page.locator('#leave_type option').allInnerTexts() });
    await e1.page.selectOption('#leave_type', { label: 'Medical' });
    await e1.page.getByRole('button', { name: /review/i }).click(); await settle(e1.page, 700);
    await e1.page.getByRole('button', { name: /^submit/i }).click(); await settle(e1.page, 800);
    const m1 = await ctxFor(browser, 'aye.aye.mon', 1440);
    await m1.page.goto(`${BASE}/approvals`); await settle(m1.page);
    // E1 cancels in their tab
    await e1.page.reload(); await settle(e1.page);
    await e1.page.getByRole('button', { name: /^cancel/i }).first().click(); await settle(e1.page, 400);
    await e1.page.locator('[role=alertdialog]').getByRole('button').last().click(); await settle(e1.page, 700);
    // M1 approves the stale item
    const item = m1.page.locator('li', { hasText: 'Sreyneang Kim' }).first();
    await item.getByRole('button', { name: 'Approve' }).click(); await settle(m1.page, 600);
    await m1.page.locator('[role=dialog] button[type=submit]').click(); await settle(m1.page, 800);
    await shot(m1.page, 'journey-stale-approve-m1-1440-light');
    note(J, 'approve a cancelled request', { message: await text(m1.page.locator('[role=dialog]').last()), errors: await errorsOn(m1.page), page: (await text(m1.page.locator('main'))).slice(0, 300) });
    // Session expiry during a Livewire action: drop the session cookie, then act.
    await m1.ctx.clearCookies();
    await m1.page.goto(`${BASE}/approvals`).catch(() => {});
    const m1b = await ctxFor(browser, 'aye.aye.mon', 390);
    await m1b.page.goto(`${BASE}/attendance`); await settle(m1b.page);
    await m1b.ctx.clearCookies();
    await m1b.page.getByRole('button', { name: /late/i }).first().click().catch(() => {}); await settle(m1b.page, 1200);
    await shot(m1b.page, 'journey-livewire-419-m1-390-light');
    note(J, 'livewire action after session loss', { dialogs: [...m1b.dialogs], url: m1b.page.url() });
    // A plain form POST with an expired token
    const g = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const gp = await g.newPage();
    await gp.goto(`${BASE}/login`); await g.clearCookies();
    await gp.fill('#email', 'admin'); await gp.fill('#password', 'password');
    const resp = await Promise.all([gp.waitForNavigation().catch(() => null), gp.click('button[type=submit]')]);
    await settle(gp);
    await gp.screenshot({ path: `${OUT}/419-guest-390-light.png`, fullPage: true });
    note(J, 'login POST with expired CSRF', { url: gp.url(), title: await gp.title(), body: (await text(gp.locator('body'))).slice(0, 200) });
    await g.close(); await e1.ctx.close(); await m1.ctx.close(); await m1b.ctx.close();
}

async function filtersAndKeyboard(browser) {
    const J = 'filters-keyboard';
    const { ctx, page } = await ctxFor(browser, 'admin', 1440);
    await page.goto(`${BASE}/attendance?from=2026-10-01&to=2026-10-28`); await settle(page);
    await page.getByRole('button', { name: /^late/i }).first().click(); await settle(page, 700);
    const u1 = page.url();
    await page.reload(); await settle(page);
    const pressed = await page.getByRole('button', { name: /^late/i }).first().getAttribute('aria-pressed');
    const nextLink = await page.locator('nav[role=navigation] a, nav[aria-label*=agination] a, [wire\\:click*=nextPage], button[wire\\:click*="gotoPage"], button[wire\\:click*="nextPage"]').count();
    note(J, 'filter in URL and survives reload', { urlAfterChip: u1, ariaPressedAfterReload: pressed, paginationControls: nextLink });
    await page.locator('button[wire\\:click*="nextPage"]').first().click().catch(() => {}); await settle(page, 700);
    note(J, 'pagination keeps filters', { url: page.url() });
    await page.goBack(); await settle(page);
    note(J, 'back after pagination', { url: page.url() });
    // keyboard path on Time off → Request leave
    await page.goto(`${BASE}/time-off`); await settle(page);
    const stops = [];
    for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab');
        stops.push(await page.evaluate(() => {
            const el = document.activeElement; const cs = getComputedStyle(el);
            const ring = cs.outlineStyle !== 'none' && cs.outlineWidth !== '0px' || /rgb/.test(cs.boxShadow) && cs.boxShadow !== 'none';
            return `${el.tagName.toLowerCase()}:${(el.getAttribute('aria-label') || el.innerText || el.id || '').trim().replace(/\s+/g, ' ').slice(0, 30)}${ring ? '' : ' [NO VISIBLE RING]'}`;
        }));
    }
    await shot(page, 'journey-keyboard-timeoff-admin-1440-light');
    note(J, 'tab order on Time off (admin)', { stops });
    await ctx.close();
}

async function adminJourney(browser) {
    const J = 'admin-desktop';
    const { ctx, page, errors, dialogs } = await ctxFor(browser, 'admin', 1440);
    // Add an employee
    await page.goto(`${BASE}/employees`); await settle(page);
    await page.getByRole('button', { name: /new employee|add employee/i }).first().click(); await settle(page, 700);
    await page.screenshot({ path: `${OUT}/journey-employee-form-admin-1440-light.png`, fullPage: false });
    const fields = await page.locator('[role=dialog] label').allInnerTexts();
    note(J, 'employee form', { fields });
    await page.locator('[role=dialog] button[type=submit]').first().click(); await settle(page, 700);
    await shot(page, 'journey-employee-form-errors-admin-1440-light');
    note(J, 'empty submit', { errors: await errorsOn(page) });
    await page.keyboard.press('Escape'); await settle(page);
    // File leave on behalf of Su Su Hlaing (no login)
    await page.goto(`${BASE}/time-off`); await settle(page);
    await page.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(page, 700);
    await page.fill('#leave_employee_search', 'Su Su'); await settle(page, 900);
    await shot(page, 'journey-file-leave-picker-admin-1440-light');
    await page.locator('[role=dialog] [role=option], [role=dialog] li button, [role=dialog] button', { hasText: 'Su Su Hlaing' }).first().click().catch(() => {}); await settle(page, 600);
    await shot(page, 'journey-file-leave-for-e3-admin-1440-light');
    note(J, 'file leave for E3', { dialog: await text(page.locator('[role=dialog]').last()) });
    await page.keyboard.press('Escape'); await settle(page);
    // Overtime for E3 over the 2h cap
    await page.goto(`${BASE}/overtime`); await settle(page);
    await page.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(page, 700);
    await page.fill('#overtime_employee_search', 'Su Su').catch(() => {}); await settle(page, 900);
    await page.locator('[role=dialog] button', { hasText: 'Su Su Hlaing' }).first().click().catch(() => {}); await settle(page, 600);
    await shot(page, 'journey-file-ot-for-e3-admin-1440-light');
    note(J, 'file overtime for E3', { dialog: await text(page.locator('[role=dialog]').last()) });
    await page.keyboard.press('Escape'); await settle(page);
    // Manual punch: open a day on Kyaw Kyaw Naing's calendar
    await page.goto(`${BASE}/attendance/2`); await settle(page);
    await page.locator('[role=gridcell] button, button[aria-label*="October"]').nth(20).click().catch(() => {}); await settle(page, 700);
    await shot(page, 'journey-day-modal-admin-1440-light');
    await page.getByRole('button', { name: /add punch/i }).click().catch(() => {}); await settle(page, 500);
    await shot(page, 'journey-add-punch-admin-1440-light');
    note(J, 'day modal + add punch', { dialog: (await text(page.locator('[role=dialog]').last())).slice(0, 400) });
    await page.keyboard.press('Escape'); await settle(page);
    // Deactivate flow (open only; screenshot the modal)
    await page.goto(`${BASE}/employees/41`); await settle(page);
    await page.getByRole('button', { name: /deactivate/i }).first().click().catch(() => {}); await settle(page, 700);
    await shot(page, 'journey-deactivate-admin-1440-light');
    note(J, 'deactivate modal', { dialog: (await text(page.locator('[role=dialog]').last())).slice(0, 400) });
    await page.keyboard.press('Escape'); await settle(page);
    // Leave adjustment form on the profile
    await page.goto(`${BASE}/employees/2#leave`); await settle(page);
    await page.getByRole('button', { name: /adjust/i }).first().click().catch(() => {}); await settle(page, 700);
    await shot(page, 'journey-adjustment-admin-1440-light');
    note(J, 'adjustment form', { dialog: (await text(page.locator('[role=dialog]').last())).slice(0, 300) });
    await page.keyboard.press('Escape');
    // Reports CSVs
    for (const tab of ['attendance', 'leave', 'overtime']) {
        await page.goto(`${BASE}/reports?tab=${tab}`); await settle(page);
        const [d] = await Promise.all([page.waitForEvent('download', { timeout: 8000 }).catch(() => null), page.getByRole('button', { name: /csv/i }).first().click().catch(() => {})]);
        if (d) { await d.saveAs(`${DATA}/admin-${tab}.csv`); note(J, `CSV ${tab}`, { file: d.suggestedFilename() }); }
    }
    // Print view of a report
    await page.goto(`${BASE}/reports?tab=attendance`); await settle(page);
    await page.emulateMedia({ media: 'print' });
    await page.pdf({ path: `${DATA}/reports-attendance-print.pdf`, format: 'A4' }).catch(() => {});
    await page.screenshot({ path: `${OUT}/print-reports-attendance-admin-1440-light.png`, fullPage: true });
    await page.emulateMedia({ media: 'screen' });
    note(J, 'console errors', { errors, dialogs });
    await ctx.close();
}

const only = process.argv.slice(2);
const all = { employeeJourney, passwordReset, managerJourney, staleAndExpiry, filtersAndKeyboard, adminJourney };
await withBrowser(async (browser) => {
    for (const [name, fn] of Object.entries(all)) {
        if (only.length && !only.includes(name)) continue;
        try { await fn(browser); } catch (e) { note(name, 'FAILED', { error: e.message.slice(0, 300) }); }
    }
});
fs.writeFileSync(`${DATA}/journeys-${only.join('-') || 'all'}.json`, JSON.stringify(log, null, 1));
