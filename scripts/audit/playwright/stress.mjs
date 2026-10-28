import { BASE, OUT, login, themedContext, withBrowser, fullShot } from './lib.mjs';
await withBrowser(async (b) => { const { state } = await login(b, 'admin'); const c = await themedContext(b, state, 'light'); const p = await c.newPage();
 for (const w of [390, 1440]) { await p.setViewportSize({ width: w, height: w < 600 ? 844 : 900 }); await p.goto(`${BASE}/employees`, { waitUntil: 'networkidle' }); await fullShot(p, `${OUT}/employees-100plus-admin-${w}-light.png`, w);
   console.log(w, (await p.locator('nav[role=navigation], nav[aria-label]').last().innerText().catch(()=>'')).replace(/\s+/g,' ')); }
 await p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' }); await p.getByRole('button', { name: /file for an employee/i }).click(); await p.waitForTimeout(600);
 await p.fill('#leave_employee_search', 'Stress'); await p.waitForTimeout(1200);
 console.log('picker matches', await p.locator('[role=listbox] li').count()); await p.screenshot({ path: `${OUT}/journey-picker-many-admin-390-light.png` }); });
