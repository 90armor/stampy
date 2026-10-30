// AUDIT-ONLY (Phase 6b follow-up): the four spots the screenshot review asked to
// fix, at 390/1024/1440, light and dark, into docs/audit/shots/6b-fix/. Needs
// the demo seed + seed-personas.php on the dev database. Run from the repo root.
import { BASE, OUT, login, themedContext, withBrowser, fullShot } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const DIR = `${OUT}/6b-fix`;
fs.mkdirSync(DIR, { recursive: true });
const WIDTHS = [390, 1024, 1440];
const THEMES = ['light', 'dark'];
const notes = [];
const php = (env, script) => execSync(`docker compose exec -T ${Object.entries(env).map(([k, v]) => `-e ${k}=${v}`).join(' ')} app php artisan tinker --execute="require '${script}';"`).toString();
const settle = async (p, ms = 400) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };

async function userPage(b, user, theme, width) {
    const { state } = await login(b, user);
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
}

// What the badge (sidebar, or the menu button's label below lg) and the list say.
async function counts(p) {
    return p.evaluate(() => {
        const badge = [...document.querySelectorAll('[x-on\\:approval-badge\\.window]')].map((el) => (el.matches('button') ? el.querySelector('.sr-only').innerText : el.style.display === 'none' ? '(hidden)' : el.innerText).trim());
        const lists = [...document.querySelectorAll('main h2')].map((h) => `${h.innerText}: ${h.parentElement.querySelector('p')?.innerText ?? ''}`);
        return { badge, lists };
    });
}

// A clip around an element, padded, for the small icon states.
async function clipShot(p, locator, path, pad = 24) {
    await locator.scrollIntoViewIfNeeded();
    const box = await locator.boundingBox();
    const vw = p.viewportSize().width;
    const x = Math.max(0, box.x - 380);
    await p.screenshot({ path, clip: { x, y: Math.max(0, box.y - pad * 2), width: Math.min(box.x - x + box.width + pad, vw - x), height: box.height + pad * 3 } });
}

await withBrowser(async (b) => {
    for (const theme of THEMES) for (const width of WIDTHS) {
        const tag = `${width}-${theme}`;
        let c, p;

        // ONLY=icons retakes spot 4 alone (spots 1–2 use up pending requests).
        const all = process.env.ONLY !== 'icons';
        // 1 + 2: the stale notice names the request; the badge follows the list.
        if (all) for (const [type, action] of [['leave', 'cancel'], ['overtime', 'approve'], ['leave', 'reject'], ['overtime', 'skip']]) {
            const id = php({ STEP: 'create', TYPE: type }, 'scripts/audit/stale-6b.php').match(/ID=(\S+)/)[1];
            ({ c, p } = await userPage(b, 'htet.htet.oo', theme, width));
            await p.goto(`${BASE}/approvals`); await settle(p);
            const before = await counts(p);
            php({ STEP: 'act', TYPE: type, ID: id, ACTION: action }, 'scripts/audit/stale-6b.php');
            await p.locator(`button[wire\\:click^="openDecision(${id}, 'approve', '${type}'"]`).first().click(); await settle(p, 700);
            const after = await counts(p);
            if (width < 1024) {
                // Below lg the badge lives in the drawer: open it to show the number.
                await fullShot(p, `${DIR}/stale-${type}-${action}-${tag}.png`, width);
                await p.locator('header button, div.sticky button').first().click().catch(() => {}); await p.waitForTimeout(400);
                await p.screenshot({ path: `${DIR}/stale-${type}-${action}-drawer-${tag}.png` });
            } else {
                await fullShot(p, `${DIR}/stale-${type}-${action}-${tag}.png`, width);
            }
            if (theme === 'light') notes.push([`stale ${type} ${action} ${width}`, (await p.locator('main [role=status]').first().innerText().catch(() => '(none)')).trim(), JSON.stringify({ before, after })]);
            await c.close();
        }

        // 2: after a normal approve, the badge drops with the list.
        if (all) {
        ({ c, p } = await userPage(b, 'htet.htet.oo', theme, width));
        await p.goto(`${BASE}/approvals`); await settle(p);
        const first = p.locator('button[wire\\:click^="openDecision("][wire\\:click*="\'approve\'"]').first();
        if (await first.count()) {
            await first.click(); await settle(p);
            await p.locator('form[wire\\:submit="decide"] button[type=submit]').click(); await settle(p, 700);
            if (width >= 1024) await fullShot(p, `${DIR}/after-approve-${tag}.png`, width);
            else {
                await p.locator('div.sticky button').first().click().catch(() => {}); await p.waitForTimeout(400);
                await p.screenshot({ path: `${DIR}/after-approve-drawer-${tag}.png` });
            }
            if (theme === 'light') notes.push([`after approve ${width}`, JSON.stringify(await counts(p))]);
        }
        await c.close();
        }

        // 3: the profile header — the hint under Deactivate.
        ({ c, p } = await userPage(b, 'audit.admin', theme, width));
        const own = php({}, 'scripts/audit/own-employee-id.php').match(/ID=(\S+)/)[1];
        await p.goto(`${BASE}/employees/${own}`); await settle(p);
        if (all) await p.screenshot({ path: `${DIR}/profile-header-${tag}.png`, clip: { x: 0, y: 0, width, height: width < 600 ? 420 : 300 } });
        if (theme === 'light') notes.push([`profile ${width}`, JSON.stringify(await p.evaluate(() => {
            const btn = document.querySelector('[aria-describedby=deactivate-self-hint]').getBoundingClientRect();
            const hint = document.getElementById('deactivate-self-hint').getBoundingClientRect();
            return { button: [Math.round(btn.left), Math.round(btn.right), Math.round(btn.bottom)], hint: [Math.round(hint.left), Math.round(hint.right), Math.round(hint.top)] };
        }))]);

        // 4: the directory's disabled icon — at rest, hovered, keyboard-focused.
        await p.goto(`${BASE}/employees?q=Sophea`); await settle(p);
        const icon = p.locator('button[aria-disabled=true]').first();
        await clipShot(p, icon, `${DIR}/directory-icon-rest-${tag}.png`);
        await icon.hover(); await p.waitForTimeout(250);
        await clipShot(p, icon, `${DIR}/directory-icon-hover-${tag}.png`);
        await p.mouse.move(0, 0); await p.keyboard.press('Tab'); await icon.focus(); await p.waitForTimeout(250);
        await clipShot(p, icon, `${DIR}/directory-icon-focus-${tag}.png`);
        await fullShot(p, `${DIR}/directory-${tag}.png`, width);
        if (theme === 'light') notes.push([`icon ${width}`, JSON.stringify(await icon.evaluate((el) => {
            const cs = getComputedStyle(el);
            return { color: cs.color, focusVisible: el.matches(':focus-visible'), ring: cs.boxShadow };
        }))]);
        await c.close();
    }
});
fs.writeFileSync(`${DIR}/notes${process.env.ONLY ? '-' + process.env.ONLY : ''}.json`, JSON.stringify(notes, null, 1));
console.log(notes.map((n) => n.join(' | ')).join('\n'));
