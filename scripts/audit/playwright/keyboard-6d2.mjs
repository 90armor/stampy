// AUDIT-ONLY (Phase 6d2): the journeys by keyboard alone, at 1440 and 390, as a
// table of "moment → where focus lands", each against what the rules say. It
// submits and decides real requests on the dev database: reseed afterwards.
// Exits 1 if any row is wrong, or any stop has no visible focus.
import { BASE, withBrowser } from './lib.mjs';
import fs from 'node:fs';

const rows = [];
let current = '';
const describe = async (p) => { await p.waitForTimeout(200); return p.evaluate(() => {
    const e = document.activeElement;
    if (! e || e === document.body) return { name: '<body>', visible: false, kind: 'body' };
    const cs = getComputedStyle(e);
    const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 && ! /rgba\(.*, 0\)$/.test(cs.outlineColor);
    const shadow = (el) => (getComputedStyle(el).boxShadow.match(/rgba?\([^)]*\) -?[\d.]+px -?[\d.]+px -?[\d.]+px -?[\d.]+px( inset)?/g) ?? []).some((s) => ! /rgba\([^)]*, 0\)/.test(s) && parseFloat(s.split(') ')[1].split(' ')[3]) > 0);
    // A picker cell's or a segmented field's ring sits on an inner or outer element.
    const ring = shadow(e) || [...e.querySelectorAll('*')].some(shadow) || (e.parentElement && shadow(e.parentElement)) || (e.closest('[x-ref=field]') && shadow(e.closest('[x-ref=field]')));
    const quiet = e.tagName === 'MAIN' || e.hasAttribute('data-modal-title') || e.hasAttribute('data-section-heading');
    const name = (e.getAttribute('aria-label') || e.labels?.[0]?.innerText || e.innerText || e.id || '').trim().replace(/\s+/g, ' ').slice(0, 60);
    const kind = e.hasAttribute('data-focus-notice') ? 'notice' : e.hasAttribute('data-section-heading') ? 'section heading' : e.hasAttribute('data-modal-title') ? 'modal heading' : e.tagName === 'MAIN' ? 'main' : e.tagName.toLowerCase();
    return { name, visible: outline || ring || quiet, kind };
}); };
const label = (d) => (d.kind === 'body' ? '<body>' : `${d.kind}: ${d.name}`);
const noRing = new Set();
async function moment(p, what, expected) {
    await p.waitForLoadState('networkidle').catch(() => {});
    await p.waitForTimeout(450);
    const d = await describe(p);
    const ok = expected.test(label(d));
    if (! d.visible && d.kind !== 'body') noRing.add(`${current}: ${label(d)}`);
    rows.push({ journey: current, what, landed: label(d), ok });
    console.log(`${ok ? 'ok  ' : 'BAD '} ${what} → ${label(d)}`);
    if (! ok) console.log('     on screen: ' + await p.evaluate(() => [...document.querySelectorAll('[role=dialog] .text-red-600, [role=dialog] .text-red-700, [role=dialog] [class*=text-red], [data-focus-notice]')].filter((e) => e.getClientRects().length).map((e) => e.innerText.trim().slice(0, 120)).join(' | ') + ' · dialogs open: ' + [...document.querySelectorAll('[role=dialog][aria-labelledby]')].filter((e) => e.getClientRects().length).length));
}
async function tabTo(p, re, max = 70, shift = false) {
    for (let i = 0; i < max; i++) {
        await p.keyboard.press(shift ? 'Shift+Tab' : 'Tab');
        const d = await describe(p);
        if (! d.visible && d.kind !== 'body') noRing.add(`${current}: ${label(d)}`);
        if (re.test(label(d))) return d;
    }
    throw new Error(`never reached ${re}`);
}
const enter = (p) => p.keyboard.press('Enter');

async function signIn(b, username, width) {
    const c = await b.newContext({ locale: 'en-GB', viewport: { width, height: width < 600 ? 844 : 900 } });
    const p = await c.newPage();
    await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await p.keyboard.type(username);
    await p.keyboard.press('Tab');
    await p.keyboard.type('password');
    await Promise.all([p.waitForURL((url) => ! url.pathname.startsWith('/login')), enter(p)]);
    await p.waitForLoadState('networkidle');
    return { c, p };
}
async function go(p, width, re) {
    await p.evaluate(() => document.activeElement?.blur());
    await p.keyboard.press('Tab');
    if (width < 1024) { await tabTo(p, /Open sidebar/); await enter(p); await p.waitForTimeout(350); }
    await tabTo(p, re);
    await enter(p);
    await p.waitForLoadState('networkidle'); await p.waitForTimeout(600);
    await p.keyboard.press('Tab'); // the skip link
    await enter(p); await p.waitForTimeout(150);
}
// The date field: the calendar with arrows from 640px, typed digits on a phone's native input.
async function pickDate(p, width, weeks, digits) {
    if (width < 640) { await p.keyboard.type(digits); return; }
    await enter(p); await p.waitForTimeout(300);
    for (let i = 0; i < weeks; i++) await p.keyboard.press('ArrowDown');
    // Run on a weekend, that lands on a day off, which a leave can't be made of.
    const day = new Date(Date.now() + weeks * 7 * 86400000).getDay();
    for (let i = 0; i < (day === 6 ? 1 : day === 0 ? 2 : 0); i++) await p.keyboard.press('ArrowLeft');
    await enter(p); await p.waitForTimeout(300);
}

async function leaveJourney(b, width) {
    current = 'Employee: leave request';
    const { c, p } = await signIn(b, 'kyaw.kyaw.naing', width);
    await go(p, width, /Time off/);
    await tabTo(p, /Request leave/);
    await enter(p);
    await moment(p, 'Request leave opens the modal', /select: Type of leave/);
    await p.keyboard.type('Annual');
    await p.keyboard.press('Tab');
    await pickDate(p, width, 4, '27112026');
    await tabTo(p, /Review/, 12);
    await enter(p);
    await moment(p, 'Review', /modal heading: Review your request/);
    await tabTo(p, /Back/, 4);
    await enter(p);
    await moment(p, 'Back', /select: Type of leave/);
    await tabTo(p, /Review/, 14);
    await enter(p);
    await moment(p, 'Review again', /modal heading: Review your request/);
    await tabTo(p, /Submit request/, 4);
    await enter(p);
    await moment(p, 'Submit closes it', /notice: Requested Annual leave/);
    await tabTo(p, /Request leave/, 6, true);
    await enter(p);
    await p.waitForLoadState('networkidle'); await p.waitForTimeout(400);
    await p.keyboard.press('Escape');
    await moment(p, 'Escape', /Request leave/);
    await enter(p); await p.waitForLoadState('networkidle'); await p.waitForTimeout(400);
    await tabTo(p, /^button: Close/, 12);
    await enter(p);
    await moment(p, 'Close button', /Request leave/);
    await tabTo(p, /Cancel Annual leave/, 30);
    await enter(p); await p.waitForTimeout(350);
    await moment(p, 'Cancel opens the confirmation', /Keep leave/);
    await p.keyboard.press('Escape');
    await moment(p, 'Escape on the confirmation', /Cancel Annual leave/);
    await enter(p); await p.waitForTimeout(350);
    await p.keyboard.press('Tab');
    await enter(p);
    await moment(p, 'Confirm the cancellation', /notice: Cancelled your Annual leave/);
    await c.close();
}

async function overtimeJourney(b, width) {
    current = 'Employee: overtime request';
    const { c, p } = await signIn(b, 'kyaw.kyaw.naing', width);
    await go(p, width, /Overtime/);
    await tabTo(p, /Request overtime/);
    await enter(p);
    await moment(p, 'Request overtime opens the modal', /Date/);
    await pickDate(p, width, 1, '06112026');
    await p.keyboard.press('Tab');
    if (width < 640) {
        await p.keyboard.type('1700');
        await tabTo(p, /To/, 3);
        await p.keyboard.type('1900');
    } else {
        await p.keyboard.type('05'); await p.keyboard.type('00'); await p.keyboard.type('p');
        await tabTo(p, /Hour/, 6);
        await p.keyboard.type('07'); await p.keyboard.type('00'); await p.keyboard.type('p');
    }
    await tabTo(p, /Review/, 12);
    await enter(p);
    await moment(p, 'Review', /modal heading: Review your request/);
    await tabTo(p, /Back/, 4);
    await enter(p);
    await moment(p, 'Back', /Date/);
    await tabTo(p, /Review/, 14);
    await enter(p);
    await moment(p, 'Review again', /modal heading: Review your request/);
    await tabTo(p, /Submit/, 4);
    await enter(p);
    await moment(p, 'Submit closes it', /notice:/);
    await c.close();
}

async function managerJourney(b, width) {
    current = 'Manager: approve and reject';
    const { c, p } = await signIn(b, 'aye.aye.mon', width);
    await go(p, width, /Approvals/);
    const decide = async (button, note) => {
        await tabTo(p, new RegExp(`^button: ${button}`));
        await enter(p);
        await moment(p, `${button} opens the dialog`, /Note \(optional\)|Reason for rejecting/);
        if (note) await p.keyboard.type(note);
        await tabTo(p, new RegExp(`^button: ${button}`), 8);
    };
    await decide('Approve', null);
    await enter(p);
    await moment(p, 'Approve, no note', /notice: Approved/);
    await decide('Approve', 'Fine by me');
    await enter(p);
    await moment(p, 'Approve, with a note', /notice: Approved/);
    await decide('Reject', null);
    await enter(p);
    await moment(p, 'Reject, no reason: refused, dialog stays', /^button: Reject/);
    await tabTo(p, /Reason for rejecting/, 4, true);
    await p.keyboard.type('Please move it a week');
    await tabTo(p, /^button: Reject/, 4);
    await enter(p);
    await moment(p, 'Reject, with a reason', /notice: Rejected/);
    await tabTo(p, /^button: Reject/);
    await enter(p);
    await p.waitForLoadState('networkidle'); await p.waitForTimeout(400);
    await p.keyboard.press('Escape');
    await moment(p, 'Escape on the dialog', /^button: Reject/);
    await enter(p); await p.waitForLoadState('networkidle'); await p.waitForTimeout(400);
    await tabTo(p, /^button: Close/, 6);
    await enter(p);
    await moment(p, 'Close button (an earlier message is on the page)', /^button: Reject/);
    await c.close();
}

async function adminFile(b, width, digits, weeks) {
    current = 'Admin: File for an employee';
    const { c, p } = await signIn(b, 'admin', width);
    await go(p, width, /Time off/);
    await tabTo(p, /File for an employee/);
    await enter(p);
    await moment(p, 'File for an employee opens the modal', /input: Employee/);
    await p.keyboard.type('Stress');
    await p.waitForLoadState('networkidle'); await p.waitForTimeout(700);
    await moment(p, 'Results arrive while typing', /input: Employee/);
    await p.keyboard.press('Tab');
    await p.keyboard.press('Tab');
    await moment(p, 'A result button', /Stress Test Person/);
    await enter(p);
    await moment(p, 'Choosing an employee', /modal heading|Change|Type of leave/);
    await tabTo(p, /Type of leave/, 4);
    await p.keyboard.type('Unpaid');
    await p.keyboard.press('Tab');
    await pickDate(p, width, weeks, digits);
    await tabTo(p, /Review/, 14);
    await enter(p);
    await moment(p, 'Review', /modal heading: Review leave/);
    await tabTo(p, /File and approve/, 4);
    await enter(p);
    await moment(p, 'File and approve closes it', /notice:|File for an employee/);
    await c.close();
}

async function policiesEdit(b, width, day) {
    current = 'Admin: Policies edit modal';
    const { c, p } = await signIn(b, 'admin', width);
    await p.goto(`${BASE}/policies?tab=holidays`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(300);
    await tabTo(p, /^button: Edit /);
    const editName = await describe(p);
    await enter(p);
    await moment(p, 'Edit opens the modal', /Date/);
    await p.keyboard.press('Escape');
    await moment(p, 'Escape', /^button: Edit /);
    await enter(p); await p.waitForLoadState('networkidle'); await p.waitForTimeout(400);
    await tabTo(p, /^button: (Save|Update)/, 10);
    await enter(p);
    await moment(p, 'Save, the row stays', new RegExp('^button: ' + editName.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    // A save that moves the row out of the year on screen: the trigger goes.
    await enter(p);
    await moment(p, 'Edit opens the modal again', /Date/);
    if (width < 640) {
        await p.keyboard.type(day);
    } else {
        await enter(p); await p.waitForTimeout(400);
        for (let i = 0; i < 60; i++) await p.keyboard.press('ArrowDown');
        await p.waitForTimeout(400);
        await enter(p); await p.waitForTimeout(400);
    }
    await tabTo(p, /^button: (Save|Update)/, 10);
    await enter(p);
    await moment(p, 'Save, the row leaves the list', /section heading: Holidays/);
    await c.close();
}

await withBrowser(async (b) => {
    // One width per run (WIDTH=1440 or 390), each on a fresh seed: a request one width
    // files holds its date, and each run uses up the manager's queue.
    for (const [width, fileDay, fileWeeks, holiday] of [[1440, '09122026', 6, ''], [390, '16122026', 0, '22062027']].filter(([w]) => ! process.env.WIDTH || String(w) === process.env.WIDTH)) {
        console.log(`\n## ${width}`);
        for (const [name, run] of [
            ['leave', () => leaveJourney(b, width)],
            ['overtime', () => overtimeJourney(b, width)],
            ['manager', () => managerJourney(b, width)],
            ['admin file', () => adminFile(b, width, fileDay, fileWeeks)],
            ['policies', () => policiesEdit(b, width, holiday)],
        ]) {
            try { await run(); } catch (e) { rows.push({ journey: current, what: `HARNESS ${name} @${width}`, landed: e.message.split('\n')[0].slice(0, 120), ok: false }); console.log(`BAD  HARNESS ${name}: ${e.message.split('\n')[0].slice(0, 200)}`); }
        }
    }
});
console.log(`\n${rows.filter((r) => ! r.ok).length} wrong of ${rows.length}`);
console.log(`stops with no visible focus: ${noRing.size ? [...noRing].join(' | ') : 'none'}`);
if (process.env.OUT_6D) fs.writeFileSync(`${process.env.OUT_6D}/keyboard.json`, JSON.stringify(rows, null, 1));
process.exit(rows.some((r) => ! r.ok) || noRing.size ? 1 : 0);
