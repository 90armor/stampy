// AUDIT-ONLY (Phase 6g): the slice's screenshots at 390 and 1440, light and
// dark, into docs/audit/shots/6g/, with axe on its new states, and a PDF of
// each report tab as the browser prints it (an open and a closed month; from
// a light and a dark page — both must print light). Run on a fresh demo seed +
// seed-personas.php + stress-employees.php. It adds and voids a punch (with
// reasons) on Kyaw Kyaw Naing's attendance: reseed afterwards. ONLY=pdf makes
// the PDFs alone, writing nothing.
import { BASE, OUT, REPO, login, runAxe, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const DIR = `${OUT}/6g`;
fs.mkdirSync(DIR, { recursive: true });
const axeHits = [];
const axe = async (p, what) => { const v = await runAxe(p); if (v.length) axeHits.push([what, v]); };
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const height = (width) => (width < 600 ? 844 : 900);
const open = async (b, state, theme, width) => {
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: height(width) });
    return { c, p };
};
const guest = async (b, theme, width) => {
    const c = await b.newContext({ colorScheme: theme });
    await c.addInitScript((t) => localStorage.setItem('theme', t), theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: height(width) });
    return { c, p };
};
const tinker = (code) => execSync(`docker compose exec -T app php artisan tinker --execute="${code}"`, { cwd: REPO }).toString().trim().split('\n').pop();
const kyaw = tinker("echo App\\\\Models\\\\Employee::where('employee_code', 'EMP-0002')->value('id');");
const nativeFill = (p, id, value) => p.evaluate(([i, v]) => { const el = document.getElementById(`${i}-native`); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, [id, value]);
const today = new Date();
const thisMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
const last = new Date(today.getFullYear(), today.getMonth() - 1, 1);
const lastMonth = `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}`;

await withBrowser(async (b) => {
    const states = {};
    for (const u of ['admin', 'aye.aye.mon', 'audit.admin']) states[u] = (await login(b, u)).state;
    let corrected = false;

    if (process.env.ONLY !== 'pdf') for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
        const tag = `${width}-${theme}`;
        const shot = async (p, name, full = false) => {
            if (full) {
                const h = await p.evaluate(() => { const m = document.querySelector('main'); return m ? m.scrollHeight + (window.innerHeight - m.clientHeight) : document.body.scrollHeight; });
                await p.setViewportSize({ width, height: Math.min(Math.max(h, height(width)), 8000) }); await p.waitForTimeout(300);
            }
            await p.screenshot({ path: `${DIR}/${name}-${tag}.png` });
            if (full) await p.setViewportSize({ width, height: height(width) });
        };

        { // F15: the login form's label, and the error.
            const { c, p } = await guest(b, theme, width);
            await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
            await shot(p, 'f15-login');
            await p.fill('#email', ' emp-0002 '); await p.fill('#password', 'wrong-password');
            await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]); await settle(p, 300);
            await shot(p, 'f15-login-error');
            await axe(p, `f15 login error ${tag}`);
            await p.goto(`${BASE}/forgot-password`, { waitUntil: 'networkidle' });
            await shot(p, 'f15-forgot-password');
            await c.close();
        }

        { // F11: M1's and A1's dashboards with their own cards (at 390, after the team's).
            for (const [user, name] of [['aye.aye.mon', 'f11-dashboard-m1'], ['audit.admin', 'f11-dashboard-a1']]) {
                const { c, p } = await open(b, states[user], theme, width);
                await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' }); await settle(p, 400);
                await shot(p, name, true);
                await axe(p, `${name} ${tag}`);
                await c.close();
            }
        }

        { // F27: A2's navigation without My attendance, and Time off's line.
            const { c, p } = await open(b, states.admin, theme, width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await shot(p, 'f27-a2-time-off');
            await axe(p, `f27 time off ${tag}`);
            if (width < 1024) {
                await p.getByRole('button', { name: /open sidebar/i }).first().click(); await settle(p, 500);
            }
            await shot(p, 'f27-a2-navigation');
            await c.close();
        }

        { // F28: File leave before and after choosing an employee.
            const { c, p } = await open(b, states.admin, theme, width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p);
            await shot(p, 'f28-file-leave-choosing');
            await p.keyboard.type('Su Su Hl'); await settle(p, 900);
            await p.getByRole('dialog').getByRole('button', { name: /Su Su Hlaing/ }).first().click(); await settle(p, 800);
            await shot(p, 'f28-file-leave-chosen');
            await axe(p, `f28 file leave chosen ${tag}`);
            await c.close();
        }

        { // F34: Add punch with the reason missing; the punch list with reasons.
            const { c, p } = await open(b, states.admin, theme, width);
            await p.goto(`${BASE}/attendance/${kyaw}`, { waitUntil: 'networkidle' });
            const cell = p.locator(`[wire\\:click^="openDay('"][aria-label*="Present"]`).first();
            const day = (await cell.getAttribute('wire:click')).match(/'([\d-]+)'/)[1];
            await cell.click(); await settle(p, 600);
            await p.getByRole('dialog').getByRole('button', { name: 'Add punch' }).click(); await settle(p, 500);
            await nativeFill(p, `new_punch_time_${day}`, '12:58'); await settle(p, 300);
            await p.getByRole('dialog').getByRole('button', { name: 'Save punch' }).click(); await settle(p, 900);
            await p.locator(`#new_punch_reason_${day}`).scrollIntoViewIfNeeded();
            await shot(p, 'f34-add-punch-reason-error');
            await axe(p, `f34 add punch error ${tag}`);
            if (! corrected) {
                await p.fill(`#new_punch_reason_${day}`, 'Forgot to punch back in after lunch');
                await p.getByRole('dialog').getByRole('button', { name: 'Save punch' }).click(); await settle(p, 900);
                const voids = p.getByRole('dialog').getByRole('button', { name: /^Void the Out punch/ });
                await voids.first().click(); await settle(p, 600);
                await shot(p, 'f34-void-form');
                await axe(p, `f34 void form ${tag}`);
                await p.locator('[id^="void_reason_"]:visible').fill('Someone else\'s finger on the reader');
                await p.getByRole('dialog').getByRole('button', { name: 'Void punch' }).click(); await settle(p, 900);
                corrected = true;
            } else {
                await p.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click(); await settle(p, 400);
            }
            await p.getByRole('dialog').getByText('Raw punches').scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
            await shot(p, 'f34-punch-list-with-reasons');
            await c.close();
        }

        { // F04: the Reports toolbar with Print.
            const { c, p } = await open(b, states.admin, theme, width);
            await p.goto(`${BASE}/reports?tab=attendance`, { waitUntil: 'networkidle' });
            await shot(p, 'f04-reports-toolbar');
            await axe(p, `f04 reports ${tag}`);
            await c.close();
        }
    }

    // F04: each tab printed, an open and a closed month (leave: this year), from
    // a light and a dark page. page.pdf() prints without firing beforeprint, so
    // it's dispatched first — what the browser's own Print does.
    for (const theme of ['light', 'dark']) {
        const { c, p } = await open(b, states.admin, theme, 1440);
        for (const [tab, period, label] of [
            ['attendance', `month=${thisMonth}`, 'open'], ['attendance', `month=${lastMonth}`, 'closed'],
            ['overtime', `month=${thisMonth}`, 'open'], ['overtime', `month=${lastMonth}`, 'closed'],
            ['leave', '', 'open'],
        ]) {
            await p.goto(`${BASE}/reports?tab=${tab}${period ? '&' + period : ''}`, { waitUntil: 'networkidle' }); await settle(p, 500);
            await p.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
            await p.pdf({ path: `${DIR}/f04-print-${tab}-${label}-from-${theme}.pdf`, preferCSSPageSize: true, printBackground: true });
            await p.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        }
        await c.close();
    }
});
console.log(`→ ${DIR}`);
console.log(axeHits.length ? `axe: ${JSON.stringify(axeHits, null, 1)}` : 'axe: no violations on the new states');
