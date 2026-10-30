// AUDIT-ONLY (Phase 6d): the employee leave journey and the manager approve
// journey by keyboard alone, at 1440 and 390. Logs every stop: its name, whether
// a focus indicator is visible, and where focus lands after a modal closes.
// It submits and decides real requests on the dev database: reseed afterwards.
import { BASE, withBrowser } from './lib.mjs';
import fs from 'node:fs';

const log = [];
const say = (s) => { log.push(s); console.log(s); };

// After the controls' 150ms colour transition, or the ring is still transparent.
const describe = async (p) => { await p.waitForTimeout(200); return p.evaluate(() => {
    const e = document.activeElement;
    if (! e || e === document.body) return { name: '<body>', body: true, visible: false };
    const cs = getComputedStyle(e);
    const r = e.getBoundingClientRect();
    const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0 && ! /rgba\(.*, 0\)$/.test(cs.outlineColor);
    // Tailwind's ring is a box-shadow with spread; an unset one has 0 spread or a transparent colour.
    const ring = (cs.boxShadow.match(/rgba?\([^)]*\) -?[\d.]+px -?[\d.]+px -?[\d.]+px -?[\d.]+px( inset)?/g) ?? [])
        .some((s) => ! /rgba\([^)]*, 0\)/.test(s) && parseFloat(s.split(') ')[1].split(' ')[3]) > 0);
    const inView = r.bottom > 0 && r.top < innerHeight && r.right > 0 && r.left < innerWidth;
    const name = (e.getAttribute('aria-label') || e.labels?.[0]?.innerText || e.innerText || e.id || '').trim().replace(/\s+/g, ' ').slice(0, 48);
    return { name: `${e.tagName.toLowerCase()}:${name}`, body: false, visible: outline || ring, inView, tag: e.tagName.toLowerCase() };
}); };

async function stop(p, journey, stats) {
    const d = await describe(p);
    stats.stops++;
    if (d.body) stats.lost.push(journey);
    if (! d.visible) stats.noRing.add(d.name);
    if (d.inView === false) stats.offscreen.add(d.name);
    return d;
}

async function tabUntil(p, re, journey, stats, max = 60, shift = false) {
    const seen = [];
    for (let i = 0; i < max; i++) {
        await p.keyboard.press(shift ? 'Shift+Tab' : 'Tab');
        const d = await stop(p, journey, stats);
        seen.push(d.name + (d.visible ? '' : ' [no ring]'));
        if (re.test(d.name)) { say(`  ${seen.length} stop(s) to ${d.name}`); stats.trail.push(...seen); return d; }
    }
    throw new Error(`${journey}: never reached ${re} — ${seen.slice(-12).join(' | ')}`);
}

const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const after = async (p, label, stats) => { await settle(p); const d = await stop(p, label, stats); say(`  focus after ${label}: ${d.name}${d.body ? ' ← FOCUS LOST' : ''}${d.visible || d.body ? '' : ' [no ring]'}`); return d; };

async function signIn(b, username, width) {
    const c = await b.newContext({ viewport: { width, height: width < 600 ? 844 : 900 } });
    const p = await c.newPage();
    await p.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await p.keyboard.type(username); // autofocus
    await p.keyboard.press('Tab');
    await p.keyboard.type('password');
    await p.keyboard.press('Enter');
    await settle(p);
    return { c, p };
}

async function openPage(p, width, href, stats) {
    // Skip link first on the page we're on, then to the sidebar link.
    await p.evaluate(() => { document.activeElement?.blur(); });
    await p.keyboard.press('Tab');
    const first = await stop(p, 'first stop', stats);
    say(`  first stop: ${first.name}${first.visible ? '' : ' [no ring]'}`);
    if (width < 1024) {
        await tabUntil(p, /Open sidebar/, 'to menu', stats);
        await p.keyboard.press('Enter'); await p.waitForTimeout(350);
    }
    await tabUntil(p, href, 'to page', stats);
    await p.keyboard.press('Enter'); await settle(p, 800);
    await p.keyboard.press('Tab');
    const again = await stop(p, 'first stop', stats);
    say(`  first stop on the new page: ${again.name}`);
    await p.keyboard.press('Enter'); await p.waitForTimeout(150);
    const main = await describe(p);
    say(`  skip → ${main.name.slice(0, 24)}`);
}

async function employeeJourney(b, width, day) {
    const stats = { stops: 0, lost: [], noRing: new Set(), offscreen: new Set(), trail: [] };
    say(`\n## Employee leave journey, ${width}`);
    const { c, p } = await signIn(b, 'kyaw.kyaw.naing', width);
    await openPage(p, width, /Time off/, stats);
    await tabUntil(p, /Request leave/, 'to Request leave', stats);
    await p.keyboard.press('Enter'); await settle(p, 500);
    say(`  modal opens on: ${(await stop(p, 'modal open', stats)).name}`);

    await p.keyboard.type('Annual'); await p.waitForTimeout(300);
    await p.keyboard.press('Tab');
    say(`  next: ${(await stop(p, 'from', stats)).name}`);
    if (width < 640) {
        await p.keyboard.type(day.native); // the native input's segments, From
        await tabUntil(p, /^input:To|To$/, 'to To', stats, 6);
        await tabUntil(p, /Reason/, 'to Reason', stats, 6);
    } else {
        await p.keyboard.press('Enter'); await p.waitForTimeout(300);
        say(`  calendar opens on: ${(await stop(p, 'calendar', stats)).name}`);
        for (let i = 0; i < day.arrows; i++) await p.keyboard.press('ArrowDown');
        await p.waitForTimeout(100);
        say(`  arrowed to: ${(await stop(p, 'calendar', stats)).name}`);
        await p.keyboard.press('Enter'); await p.waitForTimeout(300);
        say(`  picked, focus back on: ${(await stop(p, 'calendar', stats)).name}`);
        await tabUntil(p, /^button:To/, 'to To', stats, 6);
        await tabUntil(p, /Reason/, 'to Reason', stats, 8);
    }
    await p.keyboard.type('Keyboard pass');
    await tabUntil(p, /Review/, 'to Review', stats, 4);
    await p.keyboard.press('Enter'); await settle(p, 700);
    say(`  review step, focus on: ${(await stop(p, 'review', stats)).name}`);
    const review = await p.locator('body').innerText();
    if (/Submit/.test(review)) await tabUntil(p, /Submit/, 'to Submit', stats, 60);
    await p.keyboard.press('Enter');
    await after(p, 'submit (modal closed)', stats);

    // Cancel, keep it: the confirmation, then Escape.
    await tabUntil(p, /^button:Cancel/, 'to Cancel', stats, 40);
    await p.keyboard.press('Enter'); await settle(p, 300);
    say(`  confirm opens on: ${(await stop(p, 'confirm', stats)).name}`);
    await p.keyboard.press('Escape');
    await after(p, 'Escape on the confirmation', stats);
    report(stats);
    await c.close();
}

async function managerJourney(b, width) {
    const stats = { stops: 0, lost: [], noRing: new Set(), offscreen: new Set(), trail: [] };
    say(`\n## Manager approve journey, ${width}`);
    const { c, p } = await signIn(b, 'aye.aye.mon', width);
    await openPage(p, width, /Approvals/, stats);

    await tabUntil(p, /^button:Approve/, 'to Approve', stats);
    await p.keyboard.press('Enter'); await settle(p, 500);
    say(`  approve dialog opens on: ${(await stop(p, 'dialog', stats)).name}`);
    await p.keyboard.type('Fine by me');
    await tabUntil(p, /^button:Approve/, 'to confirm', stats, 6);
    await p.keyboard.press('Enter');
    await after(p, 'approve (dialog closed)', stats);

    await tabUntil(p, /^button:Reject/, 'to Reject', stats);
    await p.keyboard.press('Enter'); await settle(p, 500);
    say(`  reject dialog opens on: ${(await stop(p, 'dialog', stats)).name}`);
    await p.keyboard.press('Escape');
    await after(p, 'Escape on the reject dialog', stats);
    await p.keyboard.press('Enter'); await settle(p, 500);
    await p.keyboard.type('Please move it a week');
    await tabUntil(p, /^button:Reject/, 'to confirm', stats, 6);
    await p.keyboard.press('Enter');
    await after(p, 'reject (dialog closed)', stats);
    report(stats);
    await c.close();
}

function report(stats) {
    say(`  stops: ${stats.stops}; focus lost: ${stats.lost.length ? stats.lost.join(', ') : 'never'}`);
    say(`  no visible ring: ${stats.noRing.size ? [...stats.noRing].join(' | ') : 'none'}`);
    say(`  off screen when focused: ${stats.offscreen.size ? [...stats.offscreen].join(' | ') : 'none'}`);
}

await withBrowser(async (b) => {
    for (const [width, day] of [[1440, { arrows: 4, native: '' }], [390, { arrows: 0, native: '30112026' }]]) {
        try { await employeeJourney(b, width, day); } catch (e) { say(`  HARNESS: ${e.message.slice(0, 400)}`); }
        try { await managerJourney(b, width); } catch (e) { say(`  HARNESS: ${e.message.slice(0, 400)}`); }
    }
});
if (process.env.OUT_6D) fs.writeFileSync(`${process.env.OUT_6D}/keyboard.txt`, log.join('\n') + '\n');
