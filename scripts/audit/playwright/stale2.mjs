import { BASE, OUT, login, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';
await withBrowser(async (b) => {
  const { state } = await login(b, 'aye.aye.mon'); const c = await themedContext(b, state, 'light'); const p = await c.newPage();
  await p.setViewportSize({ width: 390, height: 844 });
  await p.goto(`${BASE}/approvals`); await p.waitForLoadState('networkidle');
  execSync(`docker compose exec -T -e CANCEL=24 app php artisan tinker --execute="require 'scripts/audit/stale-setup.php';"`, { stdio: 'inherit' });
  const item = p.locator('li', { hasText: 'Tue 17 Nov' }).first();
  console.log('item still shown', await item.count());
  await item.getByRole('button', { name: 'Approve' }).click(); await p.waitForTimeout(900); await p.screenshot({ path: OUT + '/journey-stale-open-m1-390-light.png' }); console.log('after open', (await p.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 200), '| forms', await p.locator('form[wire\\:submit=decide]').count());
  await p.locator('form[wire\\:submit=decide] button[type=submit]').click(); await p.waitForTimeout(1000);
  await p.screenshot({ path: `${OUT}/journey-stale-approve-m1-390-light.png` });
  console.log('page', (await p.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 260));
  const dlg = p.locator('form[wire\\:submit=decide]');
  console.log('dialog open', await dlg.count(), (await dlg.allInnerTexts()).join(' ').replace(/\s+/g, ' ').slice(0, 300));
});
