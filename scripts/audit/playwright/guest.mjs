import { BASE, OUT, DATA, fullShot, measure, runAxe, login, themedContext, withBrowser } from './lib.mjs';
import fs from 'node:fs';
const res = [];
await withBrowser(async (b) => {
  for (const theme of ['light', 'dark']) {
    const ctx = await b.newContext({ colorScheme: theme }); await ctx.addInitScript((t) => localStorage.setItem('theme', t), theme);
    const p = await ctx.newPage();
    for (const w of [390, 1024, 1440]) {
      await p.setViewportSize({ width: w, height: w < 600 ? 844 : 900 });
      for (const [slug, path, act] of [['login', '/login'], ['login-error', '/login', async () => { await p.fill('#email', 'admin'); await p.fill('#password', 'wrong'); await p.click('button[type=submit]'); await p.waitForLoadState('networkidle'); }], ['forgot-password', '/forgot-password']]) {
        await p.goto(BASE + path, { waitUntil: 'networkidle' }); if (act) await act();
        const m = await p.evaluate(measure); const axe = w === 1024 ? null : await runAxe(p);
        await p.screenshot({ path: `${OUT}/${slug}-guest-${w}-${theme}.png`, fullPage: true });
        res.push({ slug, w, theme, ...m, axe });
      }
    }
    await ctx.close();
  }
  // E4 (deactivated): what Time off and Overtime let them do
  const { state } = await login(b, 'audit.leaver'); const c = await themedContext(b, state, 'light'); const p = await c.newPage();
  await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' });
  console.log('E4 time-off buttons', await p.getByRole('button').allInnerTexts());
  await p.getByRole('button', { name: /request leave/i }).first().click(); await p.waitForTimeout(700);
  await p.selectOption('#leave_type', { label: 'Medical' });
  await p.locator('#leave_start').click(); await p.waitForTimeout(300);
  const dis = await p.locator('[role=gridcell][aria-disabled=true]').count(); console.log('E4 disabled days in picker', dis);
  await p.screenshot({ path: `${OUT}/journey-e4-request-leave-1440-light.png` });
  await p.goto(`${BASE}/overtime`, { waitUntil: 'networkidle' });
  console.log('E4 overtime buttons', await p.getByRole('button').allInnerTexts());
});
fs.writeFileSync(`${DATA}/guest.json`, JSON.stringify(res, null, 1));
for (const r of res) console.log(r.slug, r.w, r.theme, 'ovf', r.overflowX, '<44', r.small44Count, r.small44.slice(0,3).join(';'), 'axe', (r.axe || []).map((a) => a.id + ':' + a.count).join(','));
