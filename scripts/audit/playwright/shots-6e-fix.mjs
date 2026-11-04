// AUDIT-ONLY (Phase 6e follow-up): the follow-up's screenshots at 390 and 1440,
// light and dark, into docs/audit/shots/6e-fix/. Run on a fresh demo seed +
// seed-personas.php + stress-employees.php + setup-6e.php, with the last seven
// days built (attendance:build-daily, as the nightly run would). Env: JOINER_ID
// and JOINER_MONTH (YYYY-MM) from setup-6e.php; MISSING_FROM / MISSING_TO, a
// range holding its missing row. Read-only.
import { BASE, OUT, login, runAxe, themedContext, withBrowser } from './lib.mjs';
import fs from 'node:fs';

const DIR = `${OUT}/6e-fix`;
fs.mkdirSync(DIR, { recursive: true });
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };

await withBrowser(async (b) => {
    const admin = (await login(b, 'admin')).state;
    const e1 = (await login(b, 'audit.new.joiner')).state;

    for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
        const tag = `${width}-${theme}`;
        const open = async (state) => {
            const c = await themedContext(b, state, theme);
            const p = await c.newPage();
            await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
            return { c, p };
        };
        const full = async (p, name) => {
            const h = await p.evaluate(() => { const m = document.querySelector('main'); return m.scrollHeight + (window.innerHeight - m.clientHeight); });
            await p.setViewportSize({ width, height: Math.min(h, 7000) }); await p.waitForTimeout(300);
            await p.screenshot({ path: `${DIR}/${name}-${tag}.png` });
            await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        };

        { // Needs attention, collapsed, then opened with the keyboard (what's in view then).
            const { c, p } = await open(admin);
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' }); await p.waitForTimeout(900);
            const toggle = p.locator('button[aria-controls="needs-attention-list"]');
            await p.evaluate(() => document.getElementById('needs-attention-list').closest('.order-1, [class*="order-1"]')?.scrollIntoView({ block: 'start' }));
            await p.screenshot({ path: `${DIR}/needs-attention-collapsed-${tag}.png` });
            await toggle.focus(); await p.keyboard.press('Enter'); await settle(p, 500);
            await p.screenshot({ path: `${DIR}/needs-attention-expanded-in-view-${tag}.png` });
            const violations = await runAxe(p);
            console.log(tag, 'expanded: axe', violations.length ? JSON.stringify(violations) : 'clean', '·', await p.evaluate(() => document.querySelectorAll('#needs-attention-list > li').length), 'listed');
            await full(p, 'needs-attention-expanded-page');
            await c.close();
        }
        { // E1's dashboard balance card.
            const { c, p } = await open(e1);
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
            await full(p, 'e1-dashboard-balance');
            await c.close();
        }
        { // A calendar for someone who joined mid-month.
            const { c, p } = await open(admin);
            await p.goto(`${BASE}/attendance/${process.env.JOINER_ID}?month=${process.env.JOINER_MONTH}`, { waitUntil: 'networkidle' });
            await full(p, 'calendar-mid-month-joiner');
            await c.close();
        }
        { // Daily attendance: today (no notice), and a range with a missing row.
            const { c, p } = await open(admin);
            await p.goto(`${BASE}/attendance`, { waitUntil: 'networkidle' });
            await p.screenshot({ path: `${DIR}/daily-attendance-no-notice-${tag}.png` });
            await p.goto(`${BASE}/attendance?from=${process.env.MISSING_FROM}&to=${process.env.MISSING_TO}`, { waitUntil: 'networkidle' });
            await p.screenshot({ path: `${DIR}/daily-attendance-notice-${tag}.png` });
            await c.close();
        }
    }
});
console.log('done');
