// AUDIT-ONLY (Phase 6d2): the modal focus rules in a real browser — the repo has
// no browser runner in its suite, and focus can't be checked from PHP. One check
// per close rule (notice, trigger, heading, <main>), never another row's Approve,
// the trap, step changes, names. It submits and decides real requests on the dev
// database: reseed afterwards. Needs the demo seed + seed-personas.php +
// stress-employees.php. Exits 1 on any failure.
import { BASE, login, themedContext, withBrowser } from './lib.mjs';

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

        // A validation error: the modal stays, focus stays on the action.
        {
            const { c, p } = await page(b, manager, 'light', width);
            await p.goto(`${BASE}/approvals`, { waitUntil: 'networkidle' });
            await p.getByRole('button', { name: 'Reject', exact: true }).first().focus();
            await p.keyboard.press('Enter'); await settle(p);
            const dialog = p.getByRole('dialog');
            await dialog.getByRole('button', { name: 'Reject', exact: true }).focus();
            await p.keyboard.press('Enter'); await settle(p, 800);
            check('Reject with no reason: the error shows, focus stays on Reject', (await dialog.getByText(/reason|required/i).count()) > 0 && (await active(p)).startsWith('button:Reject'), await active(p));
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
