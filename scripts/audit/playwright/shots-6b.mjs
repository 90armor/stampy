// AUDIT-ONLY (Phase 6b): screenshots of the 6b states at 390/1024/1440, light
// and dark, into docs/audit/shots/6b/. Needs the demo seed + seed-personas.php
// on the dev database. Run from the repo root.
import { BASE, OUT, login, themedContext, withBrowser, fullShot } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const DIR = `${OUT}/6b`;
fs.mkdirSync(DIR, { recursive: true });
const WIDTHS = [390, 1024, 1440];
const THEMES = ['light', 'dark'];
const notes = [];
const php = (env, script) => execSync(`docker compose exec -T ${Object.entries(env).map(([k, v]) => `-e ${k}=${v}`).join(' ')} app php artisan tinker --execute="require '${script}';"`).toString();
const settle = async (p, ms = 400) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };

async function guestPage(b, theme, width) {
    const c = await b.newContext({ colorScheme: theme });
    await c.addInitScript((t) => localStorage.setItem('theme', t), theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
}

async function userPage(b, user, theme, width) {
    const { state } = await login(b, user);
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
}

await withBrowser(async (b) => {
    for (const theme of THEMES) for (const width of WIDTHS) {
        const tag = `${width}-${theme}`;

        // Login refused for a leaver (password right, employment over).
        let { c, p } = await guestPage(b, theme, width);
        await p.goto(`${BASE}/login`); await p.fill('#email', 'audit.leaver'); await p.fill('#password', 'password');
        await p.click('button[type=submit]'); await settle(p);
        await fullShot(p, `${DIR}/login-leaver-refused-${tag}.png`, width);
        if (tag === '390-light') notes.push(['leaver sign-in', (await p.locator('[role=alert]').innerText()).trim()]);

        // Guest 404; forgot password result and throttle; reset-password page.
        await p.goto(`${BASE}/no-such-page`); await settle(p);
        await fullShot(p, `${DIR}/404-guest-${tag}.png`, width);
        const who = `shot${width}${theme}`;
        for (let i = 1; i <= 4; i++) {
            await p.goto(`${BASE}/forgot-password`); await p.fill('#email', who); await p.click('button[type=submit]'); await settle(p);
            if (i === 1) await fullShot(p, `${DIR}/forgot-password-result-${tag}.png`, width);
        }
        await fullShot(p, `${DIR}/forgot-password-throttle-${tag}.png`, width);
        if (tag === '390-light') notes.push(['forgot throttle', (await p.locator('form').innerText()).replace(/\s+/g, ' ')]);
        const token = php({}, 'scripts/audit/reset-token.php').match(/TOKEN=(\S+)/)[1];
        await p.goto(`${BASE}/reset-password/${token}?email=kyaw.kyaw.naing%40example.com`); await settle(p);
        await fullShot(p, `${DIR}/reset-password-${tag}.png`, width);
        await c.close();

        // Session expired mid-use: a Livewire action after the session is gone.
        ({ c, p } = await userPage(b, 'kyaw.kyaw.naing', theme, width));
        await p.goto(`${BASE}/time-off`); await settle(p);
        await c.clearCookies();
        await p.getByRole('button', { name: /request leave/i }).first().click(); await settle(p, 1200);
        await fullShot(p, `${DIR}/login-session-expired-${tag}.png`, width);
        if (tag === '390-light') notes.push(['session expired', p.url()]);
        await p.fill('#email', 'kyaw.kyaw.naing'); await p.fill('#password', 'password'); await p.click('button[type=submit]'); await settle(p);
        if (tag === '390-light') notes.push(['after sign-in', p.url()]);
        // Signed-in 404 in the app shell.
        await p.goto(`${BASE}/no-such-page`); await settle(p);
        await fullShot(p, `${DIR}/404-signed-in-${tag}.png`, width);
        await c.close();

        // Deactivate disabled on your own record (A1 is linked to AUD-001).
        ({ c, p } = await userPage(b, 'audit.admin', theme, width));
        await p.goto(`${BASE}/employees/36`); await settle(p);
        await fullShot(p, `${DIR}/deactivate-own-profile-${tag}.png`, width);
        await p.goto(`${BASE}/employees?q=Sophea`); await settle(p);
        await p.locator('button[aria-disabled=true]').first().focus().catch(() => {}); await p.waitForTimeout(200);
        await fullShot(p, `${DIR}/deactivate-own-directory-${tag}.png`, width);
        await c.close();

        // Each stale-approval notice, leave and overtime.
        for (const type of ['leave', 'overtime']) for (const action of ['cancel', 'approve', 'reject', 'skip']) {
            const id = php({ STEP: 'create', TYPE: type }, 'scripts/audit/stale-6b.php').match(/ID=(\S+)/)[1];
            ({ c, p } = await userPage(b, 'htet.htet.oo', theme, width));
            await p.goto(`${BASE}/approvals`); await settle(p);
            php({ STEP: 'act', TYPE: type, ID: id, ACTION: action }, 'scripts/audit/stale-6b.php');
            const fn = type === 'leave' ? `openDecision(${id}, 'approve', 'leave'` : `openDecision(${id}, 'approve', 'overtime'`;
            await p.locator(`button[wire\\:click^="${fn}"]`).first().click(); await settle(p, 700);
            await fullShot(p, `${DIR}/stale-${type}-${action}-${tag}.png`, width);
            if (tag === '390-light') notes.push([`stale ${type} ${action}`, (await p.locator('main [role=status]').first().innerText().catch(() => '(none)')).trim()]);
            await c.close();
        }
    }
});
fs.writeFileSync(`${DIR}/notes.json`, JSON.stringify(notes, null, 1));
console.log(notes.map((n) => n.join(': ')).join('\n'));
