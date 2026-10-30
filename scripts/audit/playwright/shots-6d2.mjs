// AUDIT-ONLY (Phase 6d2): the slice's screenshots at 390 and 1440, light and dark,
// into docs/audit/shots/6d2/, and what Chromium's accessibility tree says about one
// modal: the nearest thing to a screen reader this harness has, and not a substitute
// for one. Decides real requests: reseed afterwards.
import { BASE, OUT, login, themedContext, withBrowser } from './lib.mjs';
import fs from 'node:fs';

const DIR = `${OUT}/6d2`;
fs.mkdirSync(DIR, { recursive: true });
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const open = async (b, state, theme, width) => {
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
};

await withBrowser(async (b) => {
    const admin = (await login(b, 'admin')).state;
    const manager = (await login(b, 'aye.aye.mon')).state;
    const employee = (await login(b, 'kyaw.kyaw.naing')).state;

    for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
        const tag = `${width}-${theme}`;

        { // Review step, focus on its heading.
            const { c, p } = await open(b, employee, theme, width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /request leave/i }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.selectOption('#leave_type', { label: 'Medical' });
            await p.evaluate(() => { const el = document.getElementById('leave_start-native'); el.value = '2026-12-02'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
            await p.getByRole('button', { name: 'Review' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            console.log(tag, 'review →', await p.evaluate(() => document.activeElement.innerText));
            await p.screenshot({ path: `${DIR}/review-heading-focused-${tag}.png` });
            await c.close();
        }
        { // Approvals after an approve, focus on the notice.
            const { c, p } = await open(b, manager, theme, width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: 'Approve', exact: true }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            console.log(tag, 'approve →', await p.evaluate(() => document.activeElement.innerText.slice(0, 50)));
            await p.screenshot({ path: `${DIR}/approvals-notice-focused-${tag}.png` });
            await c.close();
        }
        { // A picker result focused.
            const { c, p } = await open(b, admin, theme, width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /file for an employee/i }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.keyboard.type('Stress'); await settle(p, 900);
            await p.keyboard.press('Tab'); await p.keyboard.press('Tab'); await p.waitForTimeout(250);
            console.log(tag, 'picker →', await p.evaluate(() => document.activeElement.innerText.replace(/\s+/g, ' ')));
            await p.screenshot({ path: `${DIR}/picker-result-focused-${tag}.png` });
            await c.close();
        }
        { // The theme toggle focused.
            const { c, p } = await open(b, employee, theme, width);
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            for (let i = 0; i < 12; i++) {
                await p.keyboard.press('Tab');
                if (/Switch to/.test(await p.evaluate(() => document.activeElement.getAttribute('aria-label') ?? ''))) break;
            }
            await p.waitForTimeout(250);
            console.log(tag, 'toggle →', await p.evaluate(() => document.activeElement.getAttribute('aria-label')));
            await p.screenshot({ path: `${DIR}/theme-toggle-focused-${tag}.png` });
            await c.close();
        }
    }

    // The accessibility tree of the leave request modal, as Chromium computes it.
    const { c, p } = await open(b, employee, 'light', 1440);
    await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
    await p.getByRole('button', { name: /request leave/i }).first().focus();
    await p.keyboard.press('Enter'); await settle(p);
    const cdp = await c.newCDPSession(p);
    const tree = async () => (await cdp.send('Accessibility.getFullAXTree')).nodes.filter((n) => ! n.ignored);
    const summary = (nodes) => nodes.filter((n) => ['dialog', 'heading', 'status'].includes(n.role?.value) || n.properties?.some((q) => q.name === 'focused' && q.value.value))
        .map((n) => `${n.role.value} "${(n.name?.value ?? '').slice(0, 60)}"${n.properties?.some((q) => q.name === 'focused' && q.value.value) ? ' (focused)' : ''}`);
    console.log('\nForm step:', summary(await tree()));
    await p.selectOption('#leave_type', { label: 'Medical' });
    await p.evaluate(() => { const el = document.getElementById('leave_start-native'); el.value = '2026-12-03'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.getByRole('button', { name: 'Review' }).focus();
    await p.keyboard.press('Enter'); await settle(p, 800);
    console.log('Review step:', summary(await tree()));
    await c.close();
});
