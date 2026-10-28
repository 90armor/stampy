import { chromium } from 'playwright';
import fs from 'node:fs';

export const BASE = 'http://localhost:8000';
export const REPO = new URL('../../../', import.meta.url).pathname;
export const OUT = `${REPO}docs/audit/shots`;
export const DATA = `${REPO}storage/app/audit-data`;
// axe-core is resolved from wherever playwright + axe-core are installed (see README).
export const AXE = (await import('node:module')).createRequire(import.meta.url).resolve('axe-core/axe.min.js');
fs.mkdirSync(DATA, { recursive: true });

export async function login(browser, username) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/login`);
    await page.fill('#email', username);
    await page.fill('#password', 'password');
    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
    const landed = page.url();
    const state = await ctx.storageState();
    await ctx.close();
    return { state, landed };
}

export async function themedContext(browser, state, theme) {
    const ctx = await browser.newContext({ storageState: state, colorScheme: theme, viewport: { width: 1440, height: 900 } });
    await ctx.addInitScript((t) => { try { localStorage.setItem('theme', t); } catch (e) {} }, theme);
    return ctx;
}

// Visible interactive elements smaller than 44px (and than 24px, WCAG 2.5.8).
export const measure = () => {
    const sel = 'a[href], button, input:not([type=hidden]), select, textarea, [role=button], [role=link], [role=tab], summary, [tabindex]:not([tabindex="-1"])';
    const small44 = [], small24 = [];
    for (const el of document.querySelectorAll(sel)) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        if (r.width === 0 || r.height === 0 || cs.visibility === 'hidden' || cs.display === 'none') continue;
        if (el.closest('[aria-hidden=true]')) continue;
        const label = (el.getAttribute('aria-label') || el.innerText || el.value || el.name || el.id || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
        const item = `${el.tagName.toLowerCase()} "${label}" ${Math.round(r.width)}x${Math.round(r.height)}`;
        if (r.height < 44 || r.width < 44) small44.push(item);
        if (r.height < 24 || r.width < 24) small24.push(item);
    }
    const nav = performance.getEntriesByType('navigation')[0];
    return {
        title: document.title,
        overflowX: Math.max(document.documentElement.scrollWidth - window.innerWidth, (() => { const m = document.querySelector('main'); return m ? m.scrollWidth - m.clientWidth : 0; })()),
        small44Count: small44.length, small44: small44.slice(0, 25),
        small24Count: small24.length, small24: small24.slice(0, 25),
        ttfb: nav ? Math.round(nav.responseStart - nav.requestStart) : null,
        h1: [...document.querySelectorAll('h1')].map((h) => h.innerText.trim()).slice(0, 3),
    };
};

// The app shell scrolls inside <main>, so a full-page screenshot is the viewport
// only: grow the viewport to the content height, shoot, and restore it.
export async function fullShot(page, path, width) {
    const base = width < 600 ? 844 : 900;
    const h = await page.evaluate(() => { const m = document.querySelector('main'); return m ? m.scrollHeight + (window.innerHeight - m.clientHeight) : document.documentElement.scrollHeight; });
    if (h > base) { await page.setViewportSize({ width, height: Math.min(h, 12000) }); await page.waitForTimeout(350); }
    await page.screenshot({ path, fullPage: true });
    if (h > base) await page.setViewportSize({ width, height: base });
}

export async function runAxe(page) {
    await page.addScriptTag({ path: AXE });
    return page.evaluate(async () => {
        const r = await window.axe.run(document, { resultTypes: ['violations'], runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] } });
        return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, count: v.nodes.length, targets: v.nodes.slice(0, 4).map((n) => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n').slice(1, 2).join('').slice(0, 140)) }));
    });
}

export async function shootAll(browser, persona, username, pages, { widths = [390, 1024, 1440], themes = ['light', 'dark'] } = {}) {
    const { state, landed } = await login(browser, username);
    const results = { persona, username, landed, pages: [] };
    for (const theme of themes) {
        const ctx = await themedContext(browser, state, theme);
        const page = await ctx.newPage();
        const consoleErrors = [];
        page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
        page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message.slice(0, 200)));
        for (const [slug, path, opts = {}] of pages) {
            for (const width of widths) {
                consoleErrors.length = 0;
                await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
                let status = null;
                try {
                    const resp = await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 30000 });
                    status = resp?.status();
                    await page.waitForTimeout(opts.wait ?? (slug.startsWith('dashboard') ? 900 : 250));
                    if (opts.action) await opts.action(page);
                } catch (e) { consoleErrors.push('nav: ' + e.message.slice(0, 160)); }
                const file = `${slug}-${persona}-${width}-${theme}.png`;
                const m = await page.evaluate(measure).catch(() => ({}));
                await fullShot(page, `${OUT}/${file}`, width).catch((e) => consoleErrors.push('shot: ' + e.message.slice(0, 100)));
                let axe = null;
                if (width !== 1024 && !opts.noAxe) axe = await runAxe(page).catch((e) => [{ id: 'axe-error', help: e.message.slice(0, 100) }]);
                results.pages.push({ slug, path, width, theme, status, url: page.url(), file, ...m, consoleErrors: [...consoleErrors], axe });
                process.stdout.write('.');
            }
        }
        await ctx.close();
    }
    fs.writeFileSync(`${DATA}/${persona}.json`, JSON.stringify(results, null, 1));
    console.log(`\n${persona}: ${results.pages.length} captures, landed ${landed}`);
    return results;
}

export async function withBrowser(fn) {
    const browser = await chromium.launch();
    try { return await fn(browser); } finally { await browser.close(); }
}
