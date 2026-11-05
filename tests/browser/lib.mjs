// What tests/browser/focus.mjs needs from a browser: sign in once per user and
// reuse the session, a page in a given theme, and a browser that always closes.
import { chromium } from 'playwright';

export const BASE = process.env.STAMPY_BASE ?? 'http://localhost:8000';
// The checkout under test (for docker compose commands); defaults to this file's repo.
export const REPO = process.env.STAMPY_REPO ?? new URL('../../', import.meta.url).pathname;

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

export async function withBrowser(fn) {
    const browser = await chromium.launch();
    try { return await fn(browser); } finally { await browser.close(); }
}
