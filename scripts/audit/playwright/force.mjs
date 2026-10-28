import { BASE, OUT, login, themedContext, withBrowser, measure } from './lib.mjs';
await withBrowser(async (b) => { const { state, landed } = await login(b, 'audit.khmer'); console.log('landed', landed);
 for (const t of ['light','dark']) { const c = await themedContext(b, state, t); const p = await c.newPage();
  for (const w of [390,1024,1440]) { await p.setViewportSize({width:w,height:w<600?844:900}); await p.goto(`${BASE}/password/change`, {waitUntil:'networkidle'}); await p.screenshot({path:`${OUT}/password-change-kh-${w}-${t}.png`, fullPage:true}); const m = await p.evaluate(measure); if (w===390&&t==='light') console.log(m.small44, (await p.locator('body').innerText()).replace(/\s+/g,' ').slice(0,300)); }
  await c.close(); } });
