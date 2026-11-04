// AUDIT-ONLY (Phase 6f): the slice's screenshots at 390, 1024 and 1440, light and
// dark, into docs/audit/shots/6f/. Run on a fresh demo seed + seed-personas.php
// + stress-employees.php; it runs setup-6f.php itself (pending claims to cancel).
// Cancels and approves real requests: reseed afterwards.
import { BASE, OUT, REPO, login, runAxe, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const DIR = `${OUT}/6f`;
fs.mkdirSync(DIR, { recursive: true });
// axe on the states 6a never reached: the cards, Filters open, the cancel confirmation.
const axeHits = [];
const axe = async (p, what) => { const v = await runAxe(p); if (v.length) axeHits.push([what, v]); };
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const open = async (b, state, theme, width) => {
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
};
const claims = execSync(`docker compose exec -T app php artisan tinker --execute="require 'scripts/audit/setup-6f.php';"`, { cwd: REPO })
    .toString().split('\n').filter((l) => l.startsWith('{')).map((l) => JSON.parse(l));
console.log(claims);
const stepTwo = claims.find((c) => c.user === 'kyaw.kyaw.naing' && c.step === 2);
const toCancel = claims.filter((c) => c !== stepTwo);
const widths = [390, 1024, 1440];
const today = new Date();
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const weekAgo = iso(new Date(today.getTime() - 6 * 86400000));

await withBrowser(async (b) => {
    const states = {};
    for (const u of ['admin', 'aye.aye.mon', 'audit.manager.none', 'kyaw.kyaw.naing', 'audit.khmer', 'audit.new.joiner']) states[u] = (await login(b, u)).state;

    for (const theme of ['light', 'dark']) for (const width of widths) {
        const tag = `${width}-${theme}`;
        const shot = async (p, name, full = false) => {
            if (full) {
                const h = await p.evaluate(() => { const m = document.querySelector('main'); return m.scrollHeight + (window.innerHeight - m.clientHeight); });
                await p.setViewportSize({ width, height: Math.min(Math.max(h, width < 600 ? 844 : 900), 7000) }); await p.waitForTimeout(300);
            }
            await p.screenshot({ path: `${DIR}/${name}-${tag}.png` });
            if (full) await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        };

        { // F06: Daily attendance — one day (M1, today), a range (admin, a long and a Khmer name, an incomplete day), filters closed and open.
            let { c, p } = await open(b, states['aye.aye.mon'], theme, width);
            await p.goto(`${BASE}/attendance`, { waitUntil: 'networkidle' });
            await shot(p, 'f06-attendance-one-day-m1', true);
            await axe(p, `f06 cards ${tag}`);
            await c.close();
            ({ c, p } = await open(b, states.admin, theme, width));
            await p.goto(`${BASE}/attendance?from=${weekAgo}&to=${iso(today)}&q=AUD`, { waitUntil: 'networkidle' });
            await shot(p, 'f06-attendance-range-long-khmer-names', true);
            await p.goto(`${BASE}/attendance?from=${weekAgo}&to=${iso(today)}&status%5B0%5D=incomplete`, { waitUntil: 'networkidle' });
            await shot(p, 'f06-attendance-range-incomplete');
            if (width < 640) {
                await p.goto(`${BASE}/attendance`, { waitUntil: 'networkidle' });
                await shot(p, 'f06-filters-closed');
                await p.getByRole('button', { name: /^Filters/ }).click(); await settle(p, 300);
                await p.getByRole('button', { name: 'Late arrival' }).click(); await settle(p, 700);
                await shot(p, 'f06-filters-open');
                await axe(p, `f06 filters open ${tag}`);
            }
            await c.close();
            ({ c, p } = await open(b, states['audit.manager.none'], theme, width));
            await p.goto(`${BASE}/attendance`, { waitUntil: 'networkidle' });
            await shot(p, 'f06-attendance-empty-team-m2a', true);
            await c.close();
        }

        { // F14: a pending claim with Cancel, the confirmation, after cancelling.
            const claim = toCancel.shift();
            if (claim) {
                const { c, p } = await open(b, states[claim.user], theme, width);
                await p.goto(`${BASE}/overtime`, { waitUntil: 'networkidle' });
                const cancel = p.locator(`button[aria-label="${claim.label}"]:visible`);
                await cancel.scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
                await shot(p, 'f14-overtime-pending-claim-cancel');
                await cancel.click(); await settle(p, 400);
                await shot(p, 'f14-overtime-cancel-confirmation');
                await axe(p, `f14 cancel confirmation ${tag}`);
                await p.getByRole('button', { name: 'Cancel request' }).click(); await settle(p, 900);
                await shot(p, 'f14-overtime-after-cancelling');
                await c.close();
            } else console.log('no claim left for', tag);
        }

        { // F23: Approvals with 44px buttons, and the notice after an approve.
            const { c, p } = await open(b, states['aye.aye.mon'], theme, width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            await shot(p, 'f23-approvals');
            // Leave only, so it never approves a claim the F14 shots still need; at 390, where the notice was in question.
            const approve = p.locator(`button[wire\\:click*="'approve', 'leave'"]`);
            if (width === 390 && await approve.count()) {
                await approve.last().scrollIntoViewIfNeeded(); await approve.last().click(); await settle(p, 400);
                await shot(p, 'f23-approvals-decision-modal');
                await p.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click(); await settle(p, 900);
                await shot(p, 'f23-approvals-notice-after-approve');
            }
            await c.close();
        }

        { // F23 / D3: login and forgot password.
            for (const [name, path] of [['login', '/login'], ['forgot-password', '/forgot-password']]) {
                const c = await b.newContext({ colorScheme: theme });
                await c.addInitScript((t) => localStorage.setItem('theme', t), theme);
                const p = await c.newPage(); await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
                await p.goto(BASE + path, { waitUntil: 'networkidle' });
                await shot(p, `d3-${name}`);
                await c.close();
            }
        }

        { // F32: Employees — the last rows and pagination; at 1024+ the long name and department.
            const { c, p } = await open(b, states.admin, theme, width);
            await p.goto(`${BASE}/employees`, { waitUntil: 'networkidle' });
            await p.getByRole('navigation', { name: 'Pagination Navigation' }).scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
            await shot(p, 'f32-employees-pagination');
            await p.goto(`${BASE}/employees?q=Aung`, { waitUntil: 'networkidle' });
            await shot(p, 'f32-employees-long-name-department');
            await c.close();
        }
    }

    { // F14: the admin's queue after the employee cancels a step-2 request — open before, stale notice after.
        if (stepTwo) for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
            const tag = `${width}-${theme}`;
            const { c, p } = await open(b, states.admin, theme, width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            const row = p.getByText(stepTwo.label.replace('Cancel overtime request, ', '')).first();
            if (theme === 'light' && width === 390) {
                await row.scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
                await p.screenshot({ path: `${DIR}/f14-admin-queue-before-cancel-${tag}.png` });
                execSync(`docker compose exec -T app php artisan tinker --execute="app(App\\\\Services\\\\Overtime\\\\OvertimeRequestService::class)->cancel(App\\\\Models\\\\OvertimeRequest::findOrFail(${stepTwo.id}), App\\\\Models\\\\User::where('username','kyaw.kyaw.naing')->first());"`, { cwd: REPO });
                // Approve on the row that's still on screen: the 6b stale notice.
                const approve = p.locator(`button[wire\\:click^="openDecision(${stepTwo.id}, 'approve'"]`);
                await approve.click(); await settle(p, 900);
                await p.screenshot({ path: `${DIR}/f14-admin-stale-notice-after-employee-cancelled-${tag}.png` });
            }
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            await p.screenshot({ path: `${DIR}/f14-admin-queue-after-employee-cancelled-${tag}.png`, fullPage: true });
            await c.close();
        }
    }
});
console.log(`→ ${DIR}`);
console.log(axeHits.length ? `axe: ${JSON.stringify(axeHits, null, 1)}` : 'axe: no violations on the new states');
