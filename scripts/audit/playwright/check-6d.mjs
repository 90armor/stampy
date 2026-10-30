// AUDIT-ONLY (Phase 6d): what the feature tests can't see — real focus, real
// titles — plus the slice's screenshots (docs/audit/shots/6d/). Needs the demo
// seed + seed-personas.php + stress-employees.php. Run from the repo root.
import { BASE, OUT, DATA, login, themedContext, withBrowser } from './lib.mjs';
import fs from 'node:fs';

const DIR = `${OUT}/6d`;
fs.mkdirSync(DIR, { recursive: true });
const settle = async (p, ms = 350) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const active = (p) => p.evaluate(() => { const e = document.activeElement; return `${e.tagName.toLowerCase()}${e.id ? '#' + e.id : ''}:${(e.getAttribute('aria-label') || e.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 40)}`; });
const ok = (name, pass, detail = '') => console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  → ' + detail : ''}`);
const titles = [];

await withBrowser(async (b) => {
    const admin = (await login(b, 'admin')).state;
    const manager = (await login(b, 'aye.aye.mon')).state;
    const employee = (await login(b, 'kyaw.kyaw.naing')).state;
    const unlinked = (await login(b, 'audit.manager.none')).state;

    // Titles: every route, every tab variant.
    const list = async (state, who, paths) => {
        const c = await themedContext(b, state, 'light'); const p = await c.newPage();
        for (const path of paths) { await p.goto(BASE + path, { waitUntil: 'networkidle' }); titles.push(`${who} ${path} → ${await p.title()}`); }
        await c.close();
    };
    await list(admin, 'admin', ['/dashboard', '/employees', '/employees/2', '/attendance', '/attendance/2', '/attendance/2?view=table', '/my-attendance', '/time-off', '/approvals', '/overtime', '/profile',
        '/reports', '/reports?tab=attendance', '/reports?tab=leave', '/reports?tab=overtime', '/reports/overtime',
        '/policies', '/policies?tab=schedules', '/policies?tab=holidays', '/policies?tab=leave-types', '/policies?tab=overtime',
        '/organization', '/organization?tab=departments', '/organization?tab=positions', '/organization?tab=schedules',
        '/no-such-page', '/employees/99999']);
    await list(manager, 'manager', ['/reports', '/reports?tab=leave', '/employees/4', '/attendance/4', '/policies']);
    await list(employee, 'employee', ['/dashboard', '/my-attendance', '/employees']);
    await list(unlinked, 'unlinked manager', ['/my-attendance']);
    {
        const c = await b.newContext(); const p = await c.newPage();
        for (const path of ['/login', '/forgot-password', '/no-such-page']) { await p.goto(BASE + path, { waitUntil: 'networkidle' }); titles.push(`guest ${path} → ${await p.title()}`); }
        await c.close();
    }

    // The title follows a tab switch with no reload, and wire:navigate.
    {
        const c = await themedContext(b, admin, 'light'); const p = await c.newPage();
        await p.setViewportSize({ width: 1440, height: 900 });
        await p.goto(`${BASE}/reports?tab=attendance`, { waitUntil: 'networkidle' });
        for (const [tab, want] of [['Leave', 'Reports · Leave – Stampy'], ['Overtime', 'Reports · Overtime – Stampy'], ['Attendance', 'Reports · Attendance – Stampy']]) {
            await p.getByRole('navigation', { name: 'Report sections' }).getByRole('button', { name: tab, exact: true }).click(); await settle(p, 500);
            ok(`Reports tab ${tab}: title`, (await p.title()) === want, await p.title());
        }
        await p.goto(`${BASE}/policies?tab=holidays`, { waitUntil: 'networkidle' });
        await p.getByRole('navigation', { name: 'Policy sections' }).getByRole('button', { name: 'Leave types', exact: true }).click(); await settle(p);
        ok('Policies tab: title', (await p.title()) === 'Policies · Leave types – Stampy', await p.title());
        await p.goto(`${BASE}/organization`, { waitUntil: 'networkidle' });
        await p.getByRole('navigation', { name: 'Organization sections' }).getByRole('button', { name: 'Positions', exact: true }).click(); await settle(p);
        ok('Organization tab: title', (await p.title()) === 'Organization · Positions – Stampy', await p.title());
        // wire:navigate between pages, then a tab change on the new page.
        await p.locator('a[href$="/reports"]').first().click(); await settle(p, 800);
        ok('wire:navigate to Reports: title', (await p.title()).startsWith('Reports · '), await p.title());
        await p.getByRole('navigation', { name: 'Report sections' }).getByRole('button', { name: 'Leave', exact: true }).click(); await settle(p, 500);
        ok('...then a tab: title', (await p.title()) === 'Reports · Leave – Stampy', await p.title());
        await p.locator('a[href$="/time-off"]').first().click(); await settle(p, 800);
        ok('wire:navigate to Time off: title', (await p.title()) === 'Time off – Stampy', await p.title());
        ok('...no leftover data-tab-title', (await p.locator('title[data-tab-title]').count()) === 0);
        await c.close();
    }

    // Skip link: first stop, visible, moves focus to <main>; next Tab lands in the content.
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
        const c = await themedContext(b, employee, theme); const p = await c.newPage();
        await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
        await p.keyboard.press('Tab');
        ok(`skip link first [${width} ${theme}]`, (await active(p)).includes('Skip to content'), await active(p));
        const box = await p.evaluate(() => { const r = document.activeElement.getBoundingClientRect(); const cs = getComputedStyle(document.activeElement); return { w: r.width, h: r.height, x: r.x, y: r.y, color: cs.color, bg: cs.backgroundColor, position: cs.position, z: cs.zIndex }; });
        ok(`skip link visible [${width} ${theme}]`, box.w > 80 && box.h > 30 && box.x >= 0, JSON.stringify(box));
        await p.screenshot({ path: `${DIR}/skip-link-focused-${width}-${theme}.png` });
        await p.keyboard.press('Enter'); await p.waitForTimeout(150);
        ok(`Enter moves focus to main [${width} ${theme}]`, (await active(p)).startsWith('main#main-content'), (await active(p)).slice(0, 30));
        ok(`no outline on main [${width} ${theme}]`, await p.evaluate(() => { const cs = getComputedStyle(document.activeElement); return cs.outlineStyle === 'none' || cs.outlineColor === 'rgba(0, 0, 0, 0)'; }));
        ok(`no fragment added to the URL [${width} ${theme}]`, ! p.url().includes('#'), p.url());
        await p.keyboard.press('Tab');
        const next = await active(p);
        ok(`next Tab is in main [${width} ${theme}]`, await p.evaluate(() => !! document.activeElement.closest('main')), next);
        await c.close();
    }

    // Skip link with the phone drawer open.
    for (const theme of ['light', 'dark']) {
        const c = await themedContext(b, employee, theme); const p = await c.newPage();
        await p.setViewportSize({ width: 390, height: 844 });
        await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
        await p.getByRole('button', { name: /open sidebar/i }).click(); await p.waitForTimeout(350);
        ok(`drawer: focus on close [${theme}]`, (await active(p)).includes('Close navigation'), await active(p));
        await p.keyboard.press('Tab');
        ok(`drawer: Tab from last goes to skip link [${theme}]`, (await active(p)).includes('Skip to content'), await active(p));
        await p.screenshot({ path: `${DIR}/skip-link-focused-drawer-390-${theme}.png` });
        await p.keyboard.press('Tab');
        ok(`drawer: Tab from skip link stays in the panel [${theme}]`, await p.evaluate(() => !! document.activeElement.closest('[x-ref=sidebarPanel]')), await active(p));
        await p.keyboard.press('Shift+Tab');
        ok(`drawer: Shift+Tab back to skip link [${theme}]`, (await active(p)).includes('Skip to content'));
        await p.keyboard.press('Shift+Tab');
        ok(`drawer: Shift+Tab wraps to close [${theme}]`, (await active(p)).includes('Close navigation'), await active(p));
        await p.keyboard.press('Tab');
        await p.keyboard.press('Enter'); await p.waitForTimeout(350);
        ok(`drawer: Enter closes it [${theme}]`, ! (await p.locator('[x-ref=sidebarPanel]').isVisible()));
        ok(`drawer: focus on main, not the menu button [${theme}]`, (await active(p)).startsWith('main#main-content'), (await active(p)).slice(0, 30));
        await c.close();
    }

    // After wire:navigate the skip link is first again.
    {
        const c = await themedContext(b, employee, 'light'); const p = await c.newPage();
        await p.setViewportSize({ width: 1440, height: 900 });
        await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        await p.locator('a[href$="/time-off"]').first().click(); await settle(p, 800);
        await p.keyboard.press('Tab');
        ok('after wire:navigate: skip link first', (await active(p)).includes('Skip to content'), await active(p));
        await c.close();
    }

    // Picker: hint, plain list, polite count, debounced.
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
        const c = await themedContext(b, admin, theme); const p = await c.newPage();
        await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
        await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p, 500);
        const input = p.getByRole('searchbox').first();
        // Log every text the live region says while typing slower than the 300ms request debounce.
        await p.evaluate(() => {
            window.said = [];
            const region = [...document.querySelectorAll('[aria-live=polite]')].find((e) => e.closest('[x-data*="announce"]'));
            new MutationObserver(() => { if (region.textContent) window.said.push(region.textContent); }).observe(region, { childList: true, characterData: true, subtree: true });
        });
        for (const ch of 'Stress') { await input.pressSequentially(ch); await p.waitForTimeout(350); }
        await settle(p, 1100);
        const said = await p.evaluate(() => window.said);
        ok(`picker: hint [${width} ${theme}]`, await p.getByText('Showing 8 of 70 — keep typing').isVisible());
        ok(`picker: announced once after a pause [${width} ${theme}]`, said.length === 1 && said[0] === 'Showing 8 of 70', JSON.stringify(said));
        ok(`picker: no listbox [${width} ${theme}]`, (await p.locator('[role=listbox]').count()) === 0);
        ok(`picker: results are buttons [${width} ${theme}]`, (await p.getByRole('list', { name: 'Matching employees' }).getByRole('button').count()) === 8);
        await p.screenshot({ path: `${DIR}/picker-showing-8-of-70-${width}-${theme}.png` });
        await input.fill('Stress Test Person 12'); await settle(p, 1200);
        ok(`picker: a narrower search re-announces [${width} ${theme}]`, (await p.evaluate(() => window.said.at(-1))) === '1 employee found', await p.evaluate(() => window.said.at(-1)));
        await p.getByRole('list', { name: 'Matching employees' }).getByRole('button').first().click(); await settle(p, 500);
        ok(`picker: choosing still works [${width} ${theme}]`, await p.getByText('STR-012').isVisible());
        await c.close();
    }

    // Date fields: names and the phone's helper text.
    for (const [width, theme] of [[390, 'light'], [390, 'dark'], [1440, 'light'], [1440, 'dark']]) {
        const c = await themedContext(b, employee, theme); const p = await c.newPage();
        await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
        await p.getByRole('button', { name: /request leave/i }).first().click(); await settle(p, 500);
        const names = await p.evaluate(() => ['leave_start', 'leave_end', 'leave_start-native', 'leave_end-native'].map((id) => { const e = document.getElementById(id); return `${id}=${e.getClientRects().length ? 'shown' : 'hidden'}:${e.getAttribute('aria-label')}`; }));
        console.log(`      names [${width} ${theme}] ${names.join(' | ')}`);
        const hint = p.getByText('Same day if empty');
        ok(`"To" helper text [${width} ${theme}]`, (await hint.isVisible()) === (width < 640));
        if (width < 640) await p.screenshot({ path: `${DIR}/leave-to-helper-text-${width}-${theme}.png` });
        await c.close();
    }

    // Focused scrollable table: Tab reaches it, arrow keys scroll it, the ring shows.
    for (const [state, path, label, width, tag] of [
        [admin, '/reports?tab=overtime', 'Overtime report', 390, 'report-overtime'],
        [admin, '/reports?tab=attendance', 'Attendance report', 1024, 'report-attendance'],
        [admin, '/employees', 'Employees', 1024, 'employees-pinned'],
        [employee, '/my-attendance?view=table', 'Attendance by day', 390, 'my-attendance-table'],
        [admin, '/attendance', 'Daily attendance', 1024, 'daily-attendance-pinned'],
    ]) for (const theme of ['light', 'dark']) {
        const c = await themedContext(b, state, theme); const p = await c.newPage();
        await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        await p.goto(BASE + path, { waitUntil: 'networkidle' }); await p.waitForTimeout(250);
        let found = false;
        for (let i = 0; i < 40 && ! found; i++) {
            await p.keyboard.press('Tab');
            found = await p.evaluate((l) => document.activeElement.getAttribute('aria-label') === l, label);
        }
        ok(`Tab reaches "${label}" [${width} ${theme}]`, found);
        const before = await p.evaluate(() => document.activeElement.scrollLeft);
        await p.evaluate(() => document.activeElement.scrollIntoView({ block: 'nearest' }));
        await p.screenshot({ path: `${DIR}/focused-table-${tag}-${width}-${theme}.png` });
        await p.keyboard.press('ArrowRight'); await p.keyboard.press('ArrowRight'); await p.waitForTimeout(250);
        const after = await p.evaluate(() => document.activeElement.scrollLeft);
        const can = await p.evaluate(() => { const e = document.activeElement; return e.scrollWidth > e.clientWidth; });
        ok(`arrow keys scroll it [${width} ${theme}]`, ! can || after > before, `scrollable=${can} ${before}→${after}`);
        await c.close();
    }

    // Profile's read-only name, and the login subtitle.
    for (const theme of ['light', 'dark']) {
        const c = await themedContext(b, employee, theme); const p = await c.newPage();
        await p.setViewportSize({ width: 1440, height: 900 });
        await p.goto(`${BASE}/profile`, { waitUntil: 'networkidle' });
        await p.locator('#name').scrollIntoViewIfNeeded();
        await p.locator('#name').locator('xpath=ancestor::section').screenshot({ path: `${DIR}/profile-readonly-name-1440-${theme}.png` });
        await c.close();
        const g = await b.newContext({ colorScheme: theme }); await g.addInitScript((t) => localStorage.setItem('theme', t), theme);
        const q = await g.newPage();
        for (const width of [390, 1440]) {
            await q.setViewportSize({ width, height: width < 600 ? 844 : 900 });
            await q.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
            await q.screenshot({ path: `${DIR}/login-${width}-${theme}.png` });
        }
        await g.close();
    }
});

fs.writeFileSync(`${DATA}/titles-6d.txt`, titles.join('\n') + '\n');
console.log('\nTitles\n' + titles.join('\n'));
