// AUDIT-ONLY (Phase 6e): the slice's screenshots at 390 and 1440, light and dark,
// into docs/audit/shots/6e/. Run on a fresh demo seed + seed-personas.php +
// stress-employees.php + setup-6e.php (which prints the missing day). Decides
// real requests: reseed afterwards. MISSING_MONTH=YYYY-MM is that day's month.
import { BASE, OUT, REPO, login, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const DIR = `${OUT}/6e`;
fs.mkdirSync(DIR, { recursive: true });
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const open = async (b, state, theme, width) => {
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
};
const tinker = (env, file) => execSync(`docker compose exec -T ${Object.entries(env).map(([k, v]) => `-e ${k}=${v}`).join(' ')} app php artisan tinker --execute="require '${file}';"`, { cwd: REPO }).toString();
const missingMonth = process.env.MISSING_MONTH;
const kyawId = process.env.KYAW_ID;

await withBrowser(async (b) => {
    const admin = (await login(b, 'admin')).state;
    const kyaw = (await login(b, 'kyaw.kyaw.naing')).state;
    const e1 = (await login(b, 'audit.new.joiner')).state;
    const m2a = (await login(b, 'audit.manager.none')).state;
    const htet = (await login(b, 'htet.htet.oo')).state;

    for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
        const tag = `${width}-${theme}`;
        const shot = async (p, name, full = false) => {
            if (full) {
                const h = await p.evaluate(() => { const m = document.querySelector('main'); return m.scrollHeight + (window.innerHeight - m.clientHeight); });
                await p.setViewportSize({ width, height: Math.min(h, 6000) }); await p.waitForTimeout(300);
            }
            await p.screenshot({ path: `${DIR}/${name}-${tag}.png` });
        };

        { // F07: this month, past and future days; a month with a missing past workday.
            const { c, p } = await open(b, kyaw, theme, width);
            await p.goto(`${BASE}/my-attendance`, { waitUntil: 'networkidle' });
            await shot(p, 'f07-my-attendance-this-month', true);
            if (missingMonth) {
                await p.goto(`${BASE}/my-attendance?month=${missingMonth}`, { waitUntil: 'networkidle' });
                await shot(p, 'f07-my-attendance-missing-workday', true);
            }
            await c.close();
        }
        { // F08: E1's balances card, and the request modal's type list shown open.
            const { c, p } = await open(b, e1, theme, width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await shot(p, 'f08-time-off-balances', true);
            await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
            await p.getByRole('button', { name: /request leave/i }).first().click(); await settle(p);
            // A native list doesn't render open in a headless browser: shown as a list box instead.
            await p.evaluate(() => { const s = document.getElementById('leave_type'); s.size = s.options.length; s.style.height = 'auto'; });
            await shot(p, 'f08-request-modal-types');
            await c.close();
        }
        { // F13: the admin dashboard, Needs attention and the trend.
            const { c, p } = await open(b, admin, theme, width);
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' }); await p.waitForTimeout(900);
            await shot(p, 'f13-dashboard', true);
            await c.close();
        }
        { // F24: a manager's Employees page.
            const { c, p } = await open(b, m2a, theme, width);
            await p.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
            await shot(p, 'f24-employees-m2a', true);
            await c.close();
        }
        { // F25: Policies → Overtime, limits in hours and the new help.
            const { c, p } = await open(b, admin, theme, width);
            await p.goto(`${BASE}/policies?tab=overtime`, { waitUntil: 'networkidle' });
            await p.locator('#ot_max_overtime').scrollIntoViewIfNeeded();
            await p.evaluate(() => document.querySelector('main').scrollBy(0, -260));
            await shot(p, 'f25-policies-overtime-limits');
            // A limit off the quarter hour: the error, focus on the field.
            await p.fill('#ot_max_overtime', '1.3');
            await p.locator('button[type=submit][form=overtime-settings-form]').click(); await settle(p, 800);
            await p.evaluate(() => { document.activeElement.scrollIntoView({ block: 'center' }); });
            await shot(p, 'validation-page-form-focused');
            await c.close();
        }
        { // Validation in a modal: Review with no type, focus on the type.
            const { c, p } = await open(b, kyaw, theme, width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /request leave/i }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('button', { name: 'Review' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            console.log(tag, 'validation →', await p.evaluate(() => `${document.activeElement.id} invalid=${document.activeElement.getAttribute('aria-invalid')} describedby=${document.activeElement.getAttribute('aria-describedby')}`));
            await shot(p, 'validation-modal-focused');
            await c.close();
        }
        { // Stale notice: the admin cancels behind Htet Htet Oo's back.
            const id = tinker({ STEP: 'create', TYPE: 'leave' }, 'scripts/audit/stale-6b.php').match(/ID=(\d+)/)?.[1];
            const { c, p } = await open(b, htet, theme, width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            tinker({ STEP: 'act', TYPE: 'leave', ID: id, ACTION: 'admin-cancel' }, 'scripts/audit/stale-6b.php');
            await p.locator(`[wire\\:click^="openDecision(${id}, 'approve', 'leave'"]`).first().click();
            await settle(p, 900);
            await p.evaluate(() => document.querySelector('[data-focus-notice]')?.scrollIntoView({ block: 'center' }));
            await shot(p, 'stale-cancelled-by-admin');
            await c.close();
        }
        if (kyawId) { // The schedule delete icon's tooltip, on focus.
            const { c, p } = await open(b, admin, theme, width);
            await p.goto(`${BASE}/employees/${kyawId}`, { waitUntil: 'networkidle' });
            const del = p.locator('button[aria-label^="Delete "][aria-label*="schedule assignment"]').first();
            await del.scrollIntoViewIfNeeded();
            await p.evaluate(() => document.querySelector('main').scrollBy(0, 120));
            await del.focus(); await p.waitForTimeout(300);
            await shot(p, 'schedule-delete-tooltip');
            await c.close();
        }
    }
});
console.log('done');
