// AUDIT-ONLY (Phase 6d): axe at 390/1024/1440, light and dark, for every page
// and persona from 6a (pages.mjs), the guest pages, and a few modal states 6a
// didn't reach; a full-page screenshot of each capture for a before/after pixel
// diff. Every axe node is kept, so the decorative calendar numbers can be told
// apart from real hits. Usage: node axe-6d.mjs <label> [persona …]
// Writes $OUT_6D/<label>/axe.json and $OUT_6D/<label>/shots/*.png.
import { BASE, AXE, login, fullShot, themedContext, withBrowser } from './lib.mjs';
import { sets } from './pages.mjs';
import fs from 'node:fs';

const label = process.argv[2] ?? 'run';
const wanted = process.argv.slice(3);
const dir = `${process.env.OUT_6D ?? '.'}/${label}`;
fs.mkdirSync(`${dir}/shots`, { recursive: true });

const settle = async (p, ms = 350) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };

const axeRun = async (page) => {
    await page.addScriptTag({ path: AXE });
    return page.evaluate(async () => {
        const r = await window.axe.run(document, { resultTypes: ['violations'], runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] } });
        return r.violations.map((v) => ({
            id: v.id, impact: v.impact, help: v.help,
            nodes: v.nodes.map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 240), summary: (n.failureSummary || '').split('\n').slice(1, 2).join('').trim().slice(0, 200) })),
        }));
    });
};

// Modal states on top of 6a's list: [slug, path, action].
const extras = {
    admin: [
        ['modal-file-leave-picker', '/time-off', async (p) => {
            await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p, 500);
            await p.getByRole('searchbox').first().fill('Stress'); await settle(p, 800);
        }],
        ['modal-day', '/attendance/2', async (p) => {
            const day = p.locator('button[aria-label*="Present"], a[aria-label*="Present"], [role=button][aria-label*="Present"]').first();
            if (await day.count()) { await day.click(); await settle(p, 500); }
        }],
    ],
    e2: [
        ['modal-request-leave', '/time-off', async (p) => {
            await p.getByRole('button', { name: /request leave/i }).first().click(); await settle(p, 500);
        }],
        ['modal-request-overtime', '/overtime', async (p) => {
            await p.getByRole('button', { name: /request overtime/i }).first().click(); await settle(p, 500);
        }],
    ],
};

const results = [];

async function capture(page, persona, slug, path, action, width, theme) {
    await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    let status = null, axe = [], title = null, h1 = [];
    try {
        const resp = await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 });
        status = resp?.status();
        await page.waitForTimeout(slug.startsWith('dashboard') ? 900 : 250);
        if (action) await action(page);
        title = await page.title();
        h1 = await page.evaluate(() => [...document.querySelectorAll('h1')].filter((h) => h.getClientRects().length).map((h) => h.innerText.trim().replace(/\s+/g, ' ')));
        if (! slug.startsWith('modal-')) await fullShot(page, `${dir}/shots/${slug}-${persona}-${width}-${theme}.png`, width);
        axe = await axeRun(page);
    } catch (e) {
        axe = [{ id: 'harness-error', impact: 'harness', help: e.message.slice(0, 200), nodes: [] }];
    }
    results.push({ persona, slug, path, width, theme, status, url: page.url(), title, h1, axe });
    process.stdout.write('.');
}

await withBrowser(async (browser) => {
    const groups = (wanted.length ? wanted : Object.keys(sets)).map((k) => [k, ...sets[k]]);
    const runGroup = async ([persona, username, pages]) => {
        const { state } = await login(browser, username);
        for (const theme of ['light', 'dark']) {
            const ctx = await themedContext(browser, state, theme);
            const page = await ctx.newPage();
            for (const [slug, path, opts] of pages) for (const width of [390, 1024, 1440]) await capture(page, persona, slug, path, opts?.action ?? null, width, theme);
            for (const [slug, path, action] of extras[persona] ?? []) for (const width of [390, 1440]) await capture(page, persona, slug, path, action, width, theme);
            await ctx.close();
        }
        console.log(`\n${persona} done`);
    };
    for (let i = 0; i < groups.length; i += 3) await Promise.all(groups.slice(i, i + 3).map(runGroup));

    if (! wanted.length) {
        for (const theme of ['light', 'dark']) {
            const ctx = await browser.newContext({ colorScheme: theme });
            await ctx.addInitScript((t) => localStorage.setItem('theme', t), theme);
            const page = await ctx.newPage();
            for (const [slug, path] of [['login', '/login'], ['forgot-password', '/forgot-password'], ['404-guest', '/no-such-page']]) {
                for (const width of [390, 1024, 1440]) await capture(page, 'guest', slug, path, null, width, theme);
            }
            await ctx.close();
        }
    }
});

fs.writeFileSync(`${dir}/axe.json`, JSON.stringify(results));
console.log(`\n${results.length} captures → ${dir}/axe.json`);
