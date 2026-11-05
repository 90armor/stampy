// The modal focus rules in a real browser (Phase 6d2; permanent since Phase 6h,
// moved from scripts/audit/) — the suite has no browser runner, and focus
// can't be checked from PHP. One check per close rule (notice, trigger,
// heading, <main>), never another row's Approve, the trap, step changes,
// names; Daily attendance's Filters disclosure and the overtime cancel
// confirmation (6f); the file-for title naming the chosen employee (F28),
// a punch correction's missing reason (F34, 6g), and the profile's modals and the
// breadcrumb's way back (6i). It submits, decides and
// cancels real requests on the dev database: reseed afterwards. Needs the demo
// seed + scripts/audit/seed-personas.php + scripts/audit/stress-employees.php.
// Exits 1 on any failure. How to run it: tests/browser/README.md.
import { BASE, REPO, login, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';

// How to run a command in the app (STAMPY_EXEC for a checkout served elsewhere, e.g. `docker exec <container>`).
const EXEC = process.env.STAMPY_EXEC ?? 'docker compose exec -T app';

const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const active = (p) => p.evaluate(() => {
    const e = document.activeElement;
    if (! e || e === document.body) return '<body>';
    const name = (e.getAttribute('aria-label') || e.innerText || e.id || '').trim().replace(/\s+/g, ' ').slice(0, 70);
    return `${e.tagName.toLowerCase()}${e.hasAttribute('data-focus-notice') ? '[notice]' : ''}${e.hasAttribute('data-section-heading') ? '[section]' : ''}${e.hasAttribute('data-modal-title') ? '[title]' : ''}:${name}`;
});
let failed = 0;
const check = (name, pass, detail = '') => { if (! pass) failed++; console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? '  → ' + detail : ''}`); };
const page = async (b, state, theme = 'light', width = 1440) => {
    const c = await themedContext(b, state, theme);
    const p = await c.newPage();
    await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    return { c, p };
};
// Pending, far enough ahead not to collide: a workday N weeks from now.
const native = (d) => `${String(d.getDate()).padStart(2, '0')}${String(d.getMonth() + 1).padStart(2, '0')}${d.getFullYear()}`;

await withBrowser(async (b) => {
    const admin = (await login(b, 'admin')).state;
    const manager = (await login(b, 'aye.aye.mon')).state;
    const employee = (await login(b, 'kyaw.kyaw.naing')).state;

    for (const width of [1440, 390]) {
        console.log(`\n# ${width}`);

        // Phase 6j: Approvals' Show details opens in place, says so, and keeps focus.
        {
            const { c, p } = await page(b, manager, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            const toggle = p.locator('[data-details-toggle]').first();
            const panel = p.locator(`#${await toggle.getAttribute('aria-controls')}`);
            check('6j: details closed at first', (await toggle.getAttribute('aria-expanded')) === 'false' && ! (await panel.isVisible()));
            await toggle.focus(); await p.keyboard.press('Enter'); await settle(p, 300);
            check('6j: Show details opens the details in place', (await toggle.getAttribute('aria-expanded')) === 'true' && await panel.isVisible() && /Hide details/.test(await toggle.innerText()));
            check('6j: focus stays on the toggle', (await active(p)).startsWith('button:Hide details'), await active(p));
            await p.keyboard.press('Enter'); await settle(p, 300);
            check('6j: and closes them again', (await toggle.getAttribute('aria-expanded')) === 'false' && ! (await panel.isVisible()));
            await c.close();
        }

        // Phase 6j: the type filter chips — pressed state, the URL, only that type, focus kept.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            const chips = p.getByRole('group', { name: 'Request type' });
            const overtime = chips.getByRole('button', { name: /^Overtime \d+$/ });
            check('6j: All is pressed at first', (await chips.getByRole('button', { name: /^All \d+$/ }).getAttribute('aria-pressed')) === 'true');
            await overtime.focus(); await p.keyboard.press('Enter'); await settle(p, 700);
            check('6j: the chip is pressed and in the URL', (await overtime.getAttribute('aria-pressed')) === 'true' && /[?&]type=overtime/.test(p.url()), p.url());
            check('6j: focus stays on the chip', (await active(p)).startsWith('button:Overtime'), await active(p));
            const types = await p.locator('li[wire\\:key^="approval-"]').evaluateAll((rows) => rows.map((r) => r.getAttribute('wire:key').split('-')[1]));
            check('6j: only overtime rows are listed', types.length > 0 && types.every((t) => t === 'overtime'), types.join(','));
            await p.reload({ waitUntil: 'networkidle' });
            check('6j: a reload keeps the filter', (await chips.getByRole('button', { name: /^Overtime \d+$/ }).getAttribute('aria-pressed')) === 'true');
            await c.close();
        }

        // Phase 6j: "You can override"'s toggle says whether it's open and keeps focus.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            const more = p.getByRole('button', { name: /waiting for managers/ });
            if (await more.count()) {
                const rows = await p.locator('li[wire\\:key^="approval-"]').count();
                await more.focus(); await p.keyboard.press('Enter'); await settle(p, 700);
                check('6j: the override toggle opens the rest', (await more.getAttribute('aria-expanded')) === 'true' && (await p.locator('li[wire\\:key^="approval-"]').count()) > rows);
                check('6j: focus stays on the override toggle', /waiting for managers/.test(await active(p)), await active(p));
            } else {
                check('6j: the override toggle is there to check', false, 'no "waiting for managers" button — the data has no non-stuck override');
            }
            await c.close();
        }

        // Rule 1, a notice from the same component: Approve.
        {
            const { c, p } = await page(b, manager, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            const approves = p.getByRole('button', { name: 'Approve', exact: true });
            const before = await approves.count();
            await approves.first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).focus();
            await p.keyboard.press('Enter'); await settle(p, 700);
            const after = await active(p);
            check('rule 1: focus on the notice after Approve', after.startsWith('div[notice]'), after);
            check('never another row\'s Approve', ! /^button:(Approve|Reject)/.test(after), after);
            // The dangerous case: Enter again. Nothing may be decided, and no dialog opens.
            await p.keyboard.press('Enter'); await settle(p, 500);
            check('a repeated Enter opens nothing', (await p.getByRole('dialog').count()) === 0);
            check('...and decides nothing', (await approves.count()) === before - 1, `${before} → ${await approves.count()} Approve buttons`);
            // Step 2: Tab from the notice goes on down the page, in order.
            await c.close();
        }

        // Rule 1, a notice from another component, a round trip later: Time off.
        {
            const { c, p } = await page(b, employee, 'light', width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /request leave/i }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.selectOption('#leave_type', { label: 'Medical' });
            const day = new Date(Date.now() + 86400000 * (width === 1440 ? 40 : 47));
            while ([0, 6].includes(day.getDay())) day.setDate(day.getDate() + 1);
            const iso = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
            await p.evaluate((value) => { const el = document.getElementById('leave_start-native'); el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, iso);
            await p.getByRole('button', { name: 'Review' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 700);
            const review = await active(p);
            check('step: Review → the step\'s heading', review.startsWith('h2[title]:Review'), review);
            await p.keyboard.press('Tab');
            check('step: Tab from the heading stays in the modal', await p.evaluate(() => !! document.activeElement.closest('[role=dialog]')), await active(p));
            const back = p.getByRole('button', { name: 'Back' });
            await back.focus(); await p.keyboard.press('Enter'); await settle(p, 700);
            const form = await active(p);
            check('step: Back → the first field', form.startsWith('select:'), form);
            await p.getByRole('button', { name: 'Review' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 700);
            await p.getByRole('button', { name: /submit request/i }).focus();
            await p.keyboard.press('Enter'); await settle(p, 1000);
            const sent = await active(p);
            check('rule 1: the notice, though it arrives a round trip later', sent.startsWith('div[notice]'), sent);
            await c.close();
        }

        // Rule 2, the trigger: Close (wire:click) and Escape.
        {
            const { c, p } = await page(b, employee, 'light', width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            const trigger = p.getByRole('button', { name: /request leave/i }).first();
            await trigger.focus(); await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('button', { name: 'Close' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 700);
            check('rule 2: Close → trigger', (await active(p)).includes('Request leave'), await active(p));
            await p.keyboard.press('Enter'); await settle(p);
            await p.keyboard.press('Escape'); await settle(p, 500);
            check('rule 2: Escape → trigger', (await active(p)).includes('Request leave'), await active(p));
            await c.close();
        }

        // An old notice isn't this action's: Close after an earlier Approve goes to the trigger.
        {
            const { c, p } = await page(b, manager, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: 'Approve', exact: true }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).focus();
            await p.keyboard.press('Enter'); await settle(p, 700);
            const reject = p.getByRole('button', { name: 'Reject', exact: true }).first();
            await reject.focus(); await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: 'Close' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 700);
            check('an earlier notice doesn\'t take focus from the trigger', (await active(p)).startsWith('button:Reject'), await active(p));
            await c.close();
        }

        // Rule 3, a heading: a holiday moved out of the year shown. No notice, trigger gone.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/policies?tab=holidays`, { waitUntil: 'networkidle' });
            const edit = p.getByRole('button', { name: /^Edit /i }).first();
            await edit.focus(); await p.keyboard.press('Enter'); await settle(p);
            await p.evaluate((w) => { const el = document.getElementById('holiday_date-native'); const v = new Date().getFullYear() + 1; el.value = `${v}-06-${w === 1440 ? 22 : 23}`; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, width);
            await p.getByRole('dialog').getByRole('button', { name: /^(save|update)/i }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            check('rule 3: the section heading', (await active(p)).startsWith('h2[section]:Holidays'), await active(p));
            await c.close();
        }

        // A validation error (Phase 6e): the modal stays, focus moves to the
        // first invalid field, which is marked invalid and described by its message.
        const invalidState = (p, id) => p.evaluate((id) => {
            const field = document.getElementById(id);
            const error = (field?.getAttribute('aria-describedby') ?? '').split(/\s+/).map((e) => document.getElementById(e)).find((e) => e?.hasAttribute('data-input-error'));
            return { invalid: field?.getAttribute('aria-invalid'), message: error?.textContent.trim() ?? null, focused: document.activeElement === field };
        }, id);
        {
            const { c, p } = await page(b, manager, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            const trigger = p.getByRole('button', { name: 'Reject', exact: true }).first();
            await trigger.focus();
            await p.keyboard.press('Enter'); await settle(p);
            const dialog = p.getByRole('dialog');
            await dialog.getByRole('button', { name: 'Reject', exact: true }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            const state = await invalidState(p, 'decision_note');
            check('validation: Reject with no reason → focus on the reason', state.focused, await active(p));
            check('validation: the reason is aria-invalid and described by its message', state.invalid === 'true' && /reason/i.test(state.message ?? ''), JSON.stringify(state));
            // 6d2's close rules still hold after an error: Escape → the trigger.
            await p.keyboard.press('Escape'); await settle(p, 600);
            check('validation: Escape after an error → the trigger', (await active(p)).startsWith('button:Reject'), await active(p));
            await c.close();
        }
        {
            // A two-step modal: Review with no type.
            const { c, p } = await page(b, employee, 'light', width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /request leave/i }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('button', { name: 'Review' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            const state = await invalidState(p, 'leave_type');
            check('validation: Review with no type → focus on the type', state.focused, await active(p));
            check('validation: the type is aria-invalid and described by its message', state.invalid === 'true' && /type/i.test(state.message ?? ''), JSON.stringify(state));
            // Fixing it clears the mark on the next round trip.
            await p.selectOption('#leave_type', { label: 'Medical' });
            await p.getByRole('button', { name: 'Review' }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            const dates = await invalidState(p, width === 1440 ? 'leave_start' : 'leave_start-native');
            check('validation: then no date → focus on the date, the type no longer invalid', dates.focused && (await invalidState(p, 'leave_type')).invalid === null, `${await active(p)} ${JSON.stringify(dates)}`);
            await c.close();
        }
        {
            // Labels in a column of their own (the organization modals).
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/organization`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: 'New department' }).first().click(); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: /^save/i }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            const state = await invalidState(p, 'dept_name');
            check('validation: a two-column form → focus on the field', state.focused && state.invalid === 'true' && !! state.message, `${await active(p)} ${JSON.stringify(state)}`);
            await c.close();
        }
        {
            // Not a modal: Policies → Overtime, a limit off the quarter hour.
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/policies?tab=overtime`, { waitUntil: 'networkidle' });
            await p.fill('#ot_max_overtime', '1.3');
            await p.locator('button[type=submit][form=overtime-settings-form]').click(); await settle(p, 800);
            const state = await invalidState(p, 'ot_max_overtime');
            check('validation: a page form → focus on the field, its message in hours', state.focused && state.invalid === 'true' && /quarter hours/.test(state.message ?? ''), JSON.stringify(state));
            await c.close();
        }

        // Needs attention's Show all (Phase 6e follow-up): one button, its label
        // flips, focus stays on it, and it stays in view as the list opens.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            const toggle = p.locator('button[aria-controls="needs-attention-list"]');
            if (await toggle.count()) {
                const closed = (await toggle.innerText()).trim();
                await toggle.focus(); await p.keyboard.press('Enter'); await p.waitForTimeout(400);
                const opened = await p.evaluate(() => { const e = document.activeElement; const r = e.getBoundingClientRect(); return { label: e.innerText.trim(), expanded: e.getAttribute('aria-expanded'), inView: r.top >= 0 && r.bottom <= window.innerHeight, hidden: document.querySelectorAll('#needs-attention-list > li[style*="display: none"]').length }; });
                check('Show all: focus stays on the button, its label flips', opened.label === 'Show less' && opened.expanded === 'true', `${closed} → ${JSON.stringify(opened)}`);
                check('Show all: every person shown, the button still in view', opened.hidden === 0 && opened.inView, JSON.stringify(opened));
                await p.keyboard.press('Enter'); await p.waitForTimeout(400);
                const back = await p.evaluate(() => ({ label: document.activeElement.innerText.trim(), expanded: document.activeElement.getAttribute('aria-expanded') }));
                check('Show less: back to the first few, focus still on the button', back.label === closed && back.expanded === 'false', JSON.stringify(back));
            } else {
                check('Show all: the dashboard counts more people than it lists', false, 'no Show all button');
            }
            await c.close();
        }

        // Rule 3 through a confirmation: deleting a holiday's row takes the trigger with it.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/policies?tab=holidays`, { waitUntil: 'networkidle' });
            await p.locator('button[aria-label^="Delete "]:visible').first().focus();
            await p.keyboard.press('Enter'); await settle(p, 400);
            await p.getByRole('alertdialog').getByRole('button', { name: /delete/i }).focus();
            await p.keyboard.press('Enter'); await settle(p, 900);
            check('rule 3, through a confirmation: the section heading', (await active(p)).startsWith('h2[section]:Holidays'), await active(p));
            await c.close();
        }

        // Rule 4, <main>: Deactivate on a profile; the trigger becomes Reactivate.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/employees/40`, { waitUntil: 'networkidle' }).catch(() => {});
            let button = p.getByRole('button', { name: 'Deactivate', exact: true });
            if (! await button.count()) { await p.goto(`${BASE}/employees/41`, { waitUntil: 'networkidle' }); button = p.getByRole('button', { name: 'Deactivate', exact: true }); }
            await button.first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: 'Deactivate', exact: true }).focus();
            await p.keyboard.press('Enter'); await settle(p, 900);
            let state = await active(p);
            if (await p.getByRole('dialog').count()) { // a second confirm when leave is affected
                await p.getByRole('dialog').getByRole('button', { name: /deactivate/i }).last().focus();
                await p.keyboard.press('Enter'); await settle(p, 900);
                state = await active(p);
            }
            check('rule 4: <main> when the trigger became something else', state.startsWith('main'), state.slice(0, 40));
            // Put it back.
            await p.getByRole('button', { name: 'Reactivate', exact: true }).first().click(); await settle(p);
            await p.getByRole('dialog').getByRole('button', { name: 'Reactivate', exact: true }).click(); await settle(p, 900);
            await c.close();
        }

        // Trap: focus knocked onto <body>, and onto the page behind.
        {
            const { c, p } = await page(b, employee, 'light', width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /request leave/i }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            const inDialog = () => p.evaluate(() => !! document.activeElement.closest('[role=dialog]'));
            const seen = new Set();
            for (let i = 0; i < 24; i++) { await p.keyboard.press('Tab'); seen.add(await inDialog()); }
            check('trap: 24 Tabs never leave', ! seen.has(false));
            seen.clear();
            for (let i = 0; i < 24; i++) { await p.keyboard.press('Shift+Tab'); seen.add(await inDialog()); }
            check('trap: 24 Shift+Tabs never leave', ! seen.has(false));
            await p.evaluate(() => document.activeElement.blur());
            await p.keyboard.press('Tab');
            check('trap: Tab from <body> goes back in', await inDialog(), await active(p));
            await p.evaluate(() => document.querySelector('main a, main button')?.focus());
            check('trap: focus on the page behind comes back', await inDialog(), await active(p));
            // A re-render that removes the focused element.
            await p.evaluate(() => { const e = document.activeElement; e.blur(); const s = document.createElement('span'); document.querySelector('[role=dialog] [data-modal-title]').after(s); });
            await p.waitForTimeout(100);
            check('trap: a re-render with focus on <body> comes back', await inDialog(), await active(p));
            await c.close();
        }

        // Phase 6f: Daily attendance's Filters disclosure — a phone only.
        {
            const { c, p } = await page(b, manager, 'light', width);
            await p.goto(`${BASE}/attendance`, { waitUntil: 'networkidle' });
            const button = p.getByRole('button', { name: /^Filters/ });
            const search = p.locator('#attendance-search');
            if (width < 640) {
                check('filters: closed on load, search hidden', (await button.getAttribute('aria-expanded')) === 'false' && ! await search.isVisible());
                await button.focus(); await p.keyboard.press('Enter'); await settle(p, 300);
                check('filters: Enter opens it, focus stays on the button', (await button.getAttribute('aria-expanded')) === 'true' && await search.isVisible() && (await active(p)).startsWith('button:Filters'), await active(p));
                await p.getByRole('button', { name: 'Late arrival' }).click(); await settle(p, 700);
                check('filters: still open after a chip re-renders the page, and counts it', (await button.getAttribute('aria-expanded')) === 'true' && await search.isVisible() && (await button.innerText()).trim() === 'Filters (1)', (await button.innerText()).trim());
                check('filters: Reset filters shows while one is set', await p.getByRole('button', { name: 'Reset filters' }).isVisible());
                await button.focus(); await p.keyboard.press('Enter'); await settle(p, 300);
                check('filters: Enter closes it, focus stays on the button', (await button.getAttribute('aria-expanded')) === 'false' && ! await search.isVisible() && (await active(p)).startsWith('button:Filters'), await active(p));
                check('filters: the cards replace the table', await p.locator('ul[aria-label="Attendance records"]').isVisible() && ! await p.getByRole('region', { name: 'Daily attendance' }).isVisible());
            } else {
                check('filters: no disclosure from 640px, every filter shows', ! await button.isVisible() && await search.isVisible() && await p.getByRole('button', { name: 'Late arrival' }).isVisible());
                check('filters: the table, not the cards', await p.getByRole('region', { name: 'Daily attendance' }).isVisible() && ! await p.locator('ul[aria-label="Attendance records"]').isVisible());
            }
            await c.close();
        }

        // Phase 6f, F14: a pending claim can be cancelled, behind "Keep request / Cancel request".
        {
            const { c, p } = await page(b, employee, 'light', width);
            const fill = (id, value) => p.evaluate(([i, v]) => { const el = document.getElementById(`${i}-native`); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, [id, value]);
            let filed = null;
            for (let back = width === 1440 ? 1 : 2, tries = 0; tries < 6 && ! filed; back += 2, tries++) {
                const day = new Date(Date.now() - 86400000 * back);
                if ([0, 6].includes(day.getDay())) continue;
                const iso = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
                await p.goto(`${BASE}/overtime`, { waitUntil: 'networkidle' });
                await p.getByRole('button', { name: /request overtime/i }).first().click(); await settle(p);
                await fill('overtime_date', iso); await fill('overtime_start', '18:00'); await fill('overtime_end', '18:30'); await settle(p, 300);
                await p.getByRole('dialog').getByRole('button', { name: 'Review' }).click(); await settle(p, 700);
                if (await p.getByRole('button', { name: /submit request/i }).count()) {
                    await p.getByRole('button', { name: /submit request/i }).click(); await settle(p, 1000);
                    filed = iso;
                }
            }
            check('cancel: a claim filed to cancel', !! filed, filed ?? 'every candidate day was refused');
            if (filed) {
                await p.goto(`${BASE}/overtime`, { waitUntil: 'networkidle' });
                // The page has each request twice — the phone list and the table — one of them hidden.
                const cancel = p.locator('button[aria-label^="Cancel overtime request"]:visible').first();
                check('cancel: a pending claim offers Cancel', await cancel.isVisible());
                const label = await cancel.getAttribute('aria-label');
                await cancel.focus(); await p.keyboard.press('Enter'); await settle(p, 400);
                const dialog = p.getByRole('alertdialog').or(p.getByRole('dialog'));
                const text = (await dialog.first().innerText()).replace(/\s+/g, ' ');
                check('cancel: the confirmation reads as leave\'s does', text.includes('Cancel this overtime request?') && text.includes('Keep request') && text.includes('Cancel request'), text.slice(0, 160));
                await p.getByRole('button', { name: 'Keep request' }).click(); await settle(p, 400);
                check('cancel: Keep request keeps it, focus back on its Cancel', await p.locator(`button[aria-label="${label}"]:visible`).count() === 1 && (await active(p)) === `button:${label}`, await active(p));
                await p.locator(`button[aria-label="${label}"]:visible`).click(); await settle(p, 400);
                await p.getByRole('button', { name: 'Cancel request' }).click(); await settle(p, 900);
                const after = await active(p);
                check('cancel: Cancel request cancels it, focus on the notice', after.startsWith('div[notice]:Cancelled your overtime request') && await p.locator(`button[aria-label="${label}"]:visible`).count() === 0, after);
            }
            await c.close();
        }

        // Phase 6g, F34: Add punch and Void each need a reason; a missing one
        // takes focus, marked invalid and described by its message. Nothing is
        // written: every submit here fails validation.
        {
            const kyaw = execSync(`${EXEC} php artisan tinker --execute="echo App\\Models\\Employee::where('employee_code', 'EMP-0002')->value('id');"`, { cwd: REPO }).toString().trim().split('\n').pop();
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/attendance/${kyaw}`, { waitUntil: 'networkidle' });
            // The month's first Present day: a past day with punches to void.
            const cell = p.locator(`[wire\\:click^="openDay('"][aria-label*="Present"]`).first();
            const day = (await cell.getAttribute('wire:click')).match(/'([\d-]+)'/)[1];
            await cell.click(); await settle(p, 600);
            check('reason: the day modal opens on Add punch', (await active(p)).startsWith('button:Add punch'), await active(p));
            await p.keyboard.press('Enter'); await settle(p, 600);
            await p.evaluate((id) => { const el = document.getElementById(`${id}-native`); el.value = '07:42'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, `new_punch_time_${day}`);
            await settle(p, 300);
            await p.getByRole('dialog').getByRole('button', { name: 'Save punch' }).click(); await settle(p, 900);
            const added = await invalidState(p, `new_punch_reason_${day}`);
            check('reason: Save punch with none → focus on the reason', added.focused, await active(p));
            check('reason: the reason is aria-invalid and says what to write', added.invalid === 'true' && added.message === 'Write a reason for this change.', JSON.stringify(added));
            await p.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click(); await settle(p, 500);
            const voids = p.getByRole('dialog').getByRole('button', { name: /^Void the / });
            if (await voids.count()) {
                const id = await p.evaluate(() => [...document.querySelectorAll('[role=dialog] button[wire\\:click^="startVoiding("]')].find((e) => e.getClientRects().length)?.getAttribute('wire:click').match(/\d+/)[0]);
                await voids.first().click(); await settle(p, 700);
                check('reason: Void opens its form, focus in the reason', (await active(p)).startsWith(`input:void_reason_${id}`) || await p.evaluate((i) => document.activeElement?.id === `void_reason_${i}`, id), await active(p));
                await p.getByRole('dialog').getByRole('button', { name: 'Void punch' }).click(); await settle(p, 900);
                const voided = await invalidState(p, `void_reason_${id}`);
                check('reason: Void punch with none → focus on the reason, nothing voided', voided.focused && voided.invalid === 'true' && voided.message === 'Write a reason for this change.' && await p.getByRole('dialog').getByRole('button', { name: 'Void punch' }).isVisible(), JSON.stringify(voided));
                await p.keyboard.press('Escape'); await settle(p, 500);
                check('reason: Escape after an error closes the modal', (await p.getByRole('dialog').count()) === 0 || ! await p.getByRole('dialog').isVisible());
            } else {
                check('reason: a punch to void on a Present day', false, `no punch on ${day}`);
            }
            await c.close();
        }

        // Phase 6g, F28: filing for someone names them once chosen, and choosing
        // focuses the title (6d2: the chosen result is gone, so the title).
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p);
            const title = () => p.evaluate(() => { const d = [...document.querySelectorAll('[role=dialog][aria-labelledby]')].find((e) => e.getClientRects().length); return document.getElementById(d?.getAttribute('aria-labelledby'))?.textContent.trim(); });
            const line = p.getByRole('dialog').getByText('Filed by you — approved straight away, no approval steps.');
            check('file for: the line shows from the first step', await line.isVisible());
            await p.keyboard.type('Su Su Hl'); await settle(p, 900);
            const result = p.getByRole('dialog').getByRole('button', { name: /Su Su Hlaing/ }).first();
            await result.focus(); await p.keyboard.press('Enter'); await settle(p, 800);
            check('file for: choosing names the dialog', (await title()) === 'File leave for Su Su Hlaing', await title());
            check('file for: choosing focuses the title', (await active(p)).startsWith('h2[title]:File leave for Su Su Hlaing'), await active(p));
            check('file for: the line still shows', await line.isVisible());
            await p.getByRole('dialog').getByRole('button', { name: 'Change' }).click(); await settle(p, 700);
            check('file for: Change brings back the generic title', (await title()) === 'File leave for an employee', await title());
            await c.close();
        }

        // Names.
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: /file for an employee/i }).first().click(); await settle(p);
            const name = await p.evaluate(() => { const d = [...document.querySelectorAll('[role=dialog][aria-labelledby]')].find((e) => e.getClientRects().length); return document.getElementById(d?.getAttribute('aria-labelledby'))?.textContent.trim(); });
            check('name: the dialog is named by its visible title', name === 'File leave for an employee', name);
            await c.close();
        }
    }

    // Phase 6i: the breadcrumb returns to the list as it was left — filters,
    // range and the page reached through a Livewire update — at 1440 through
    // the crumb, at 390 through the topbar's "‹ Attendance" (44px).
    for (const width of [1440, 390]) {
        const { c, p } = await page(b, admin, 'light', width);
        const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const from = iso(new Date(Date.now() - 86400000 * 30)), to = iso(new Date(Date.now() - 86400000));
        await p.goto(`${BASE}/attendance?from=${from}&to=${to}`, { waitUntil: 'networkidle' });
        await p.getByRole('button', { name: /next/i }).last().click(); await settle(p, 800);
        await p.getByRole('link', { name: /^View attendance for/ }).first().click(); await settle(p, 800);
        const back = width === 1440
            ? p.locator('header').getByRole('link', { name: 'Attendance', exact: true }).last()
            : p.locator('header [data-back-link]');
        check(`breadcrumb (${width}): the way back is visible`, await back.isVisible());
        if (width === 390) {
            const box = await back.boundingBox();
            check('breadcrumb (390): "‹ Attendance" is a 44px target', box && box.height >= 44, box && `${box.height}px`);
            check('breadcrumb (390): the record\'s name is not in the title', ! (await p.locator('header span.font-semibold').first().isVisible()));
        }
        await back.click(); await settle(p, 800);
        const url = new URL(p.url());
        check(`breadcrumb (${width}): back on the list with its range and page`, url.pathname === '/attendance' && url.searchParams.get('from') === from && url.searchParams.get('to') === to && url.searchParams.get('page') === '2', p.url());
        await c.close();
    }

    // Phase 6i: the profile's Change password modal, on a persona nothing
    // else here signs in as — a change signs out its other sessions.
    for (const width of [1440, 390]) {
        const first = (await login(b, 'audit.khmer')).state;
        const second = (await login(b, 'audit.khmer')).state;
        const { c, p } = await page(b, first, 'light', width);
        await p.goto(`${BASE}/profile`, { waitUntil: 'networkidle' });
        const trigger = p.getByRole('button', { name: 'Change password' });
        await trigger.focus(); await p.keyboard.press('Enter'); await settle(p);
        check(`password (${width}): opening focuses Current password`, (await active(p)).startsWith('input:current_password'), await active(p));
        const toggle = p.getByRole('dialog').getByRole('button', { name: 'Show password' }).first();
        await toggle.click();
        check(`password (${width}): show/hide reveals the field and says it's pressed`, (await p.locator('#current_password').getAttribute('type')) === 'text' && (await toggle.getAttribute('aria-pressed')) === 'true');
        await p.keyboard.press('Escape'); await settle(p);
        check(`password (${width}): Escape returns to Change`, (await active(p)).startsWith('button:Change password'), await active(p));

        await trigger.click(); await settle(p);
        await p.fill('#current_password', 'wrong');
        await p.fill('#new_password', 'Another-pass-1');
        await p.fill('#new_password_confirmation', 'Another-pass-1');
        await p.getByRole('dialog').getByRole('button', { name: 'Change password' }).click(); await settle(p, 800);
        check(`password (${width}): a wrong current password focuses that field`, (await active(p)).startsWith('input:current_password'), await active(p));
        await p.fill('#current_password', 'password');
        await p.getByRole('dialog').getByRole('button', { name: 'Change password' }).click(); await settle(p, 900);
        const done = await active(p);
        check(`password (${width}): success closes and focuses the notice`, done.startsWith('div[notice]:Password changed. You\'ve been signed out on other devices.') && (await p.getByRole('dialog').count()) === 0, done);

        const other = await b.newContext({ storageState: second });
        const op = await other.newPage();
        await op.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        check(`password (${width}): the other session is signed out`, new URL(op.url()).pathname === '/login', op.url());
        await other.close();

        await p.reload({ waitUntil: 'networkidle' });
        check(`password (${width}): this session stays signed in`, new URL(p.url()).pathname === '/profile', p.url());
        // Back to the seed's password, so the next run (and width) can sign in.
        await trigger.click(); await settle(p);
        await p.fill('#current_password', 'Another-pass-1');
        await p.fill('#new_password', 'password');
        await p.fill('#new_password_confirmation', 'password');
        await p.getByRole('dialog').getByRole('button', { name: 'Change password' }).click(); await settle(p, 900);
        await c.close();
    }

    // Phase 6i: the admin's Edit account modal — unlinked (name and email)
    // and linked (email, the name on the employee record).
    for (const width of [1440, 390]) {
        {
            const { c, p } = await page(b, admin, 'light', width);
            await p.goto(`${BASE}/profile`, { waitUntil: 'networkidle' });
            const edit = p.getByRole('button', { name: 'Edit account' });
            await edit.focus(); await p.keyboard.press('Enter'); await settle(p);
            check(`edit (${width}, unlinked): opening focuses Name`, (await active(p)).startsWith('input:account_name'), await active(p));
            check(`edit (${width}): the dialog is named by its title`, await p.getByRole('dialog', { name: 'Edit account' }).isVisible());
            await p.fill('#account_name', '');
            await p.getByRole('dialog').getByRole('button', { name: 'Save' }).click(); await settle(p, 800);
            check(`edit (${width}): a blank name focuses Name`, (await active(p)).startsWith('input:account_name'), await active(p));
            await p.fill('#account_name', 'Administrator');
            await p.getByRole('dialog').getByRole('button', { name: 'Save' }).click(); await settle(p, 900);
            check(`edit (${width}): Save closes and focuses the notice`, (await active(p)).startsWith('div[notice]:Account saved.'), await active(p));
            await c.close();
        }
        {
            const linked = (await login(b, 'audit.admin')).state;
            const { c, p } = await page(b, linked, 'light', width);
            await p.goto(`${BASE}/profile`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: 'Edit account' }).click(); await settle(p);
            check(`edit (${width}, linked): opening focuses Email`, (await active(p)).startsWith('input:account_email'), await active(p));
            check(`edit (${width}, linked): no name field, a link to the record`, (await p.locator('#account_name').count()) === 0 && await p.getByRole('dialog').getByRole('link', { name: 'Edit on your employee record' }).isVisible());
            await p.keyboard.press('Escape'); await settle(p);
            check(`edit (${width}, linked): Escape returns to Edit`, (await active(p)).startsWith('button:Edit account'), await active(p));
            await c.close();
        }
    }

    // Phone date labels, tapped.
    {
        const c = await b.newContext({ storageState: employee, hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });
        const p = await c.newPage();
        await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
        await p.getByRole('button', { name: /request leave/i }).first().tap(); await settle(p);
        for (const [label, id] of [['From', 'leave_start'], ['To', 'leave_end']]) {
            await p.locator(`label[for=${id}]`).tap(); await p.waitForTimeout(150);
            check(`tap "${label}" (phone) → native input`, await p.evaluate(() => document.activeElement.id) === `${id}-native`, await p.evaluate(() => document.activeElement.id));
        }
        await c.close();
    }
    {
        const { c, p } = await page(b, employee);
        await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
        await p.getByRole('button', { name: /request leave/i }).first().click(); await settle(p);
        for (const [label, id] of [['From', 'leave_start'], ['To', 'leave_end']]) {
            await p.locator(`label[for=${id}]`).click(); await p.waitForTimeout(150);
            check(`click "${label}" (desktop) → trigger`, await p.evaluate(() => document.activeElement.id) === id, await p.evaluate(() => document.activeElement.id));
        }
        await c.close();
    }

    // Theme toggle's name follows the state.
    {
        const { c, p } = await page(b, employee, 'light');
        await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
        const toggle = p.locator('header button[aria-label*="mode"]');
        const first = await toggle.getAttribute('aria-label');
        await toggle.click();
        const second = await toggle.getAttribute('aria-label');
        check('theme toggle: name follows the state', first === 'Switch to dark mode' && second === 'Switch to light mode', `${first} → ${second}`);
        check('theme toggle: no title', (await toggle.getAttribute('title')) === null);
        await c.close();
    }
});
console.log(failed ? `\n${failed} failed` : '\nall passed');
process.exit(failed ? 1 : 0);
