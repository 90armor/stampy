import { BASE, OUT, login, themedContext, withBrowser } from './lib.mjs';
await withBrowser(async (b) => {
  const { state } = await login(b, 'kyaw.kyaw.naing');
  const ctx = await themedContext(b, state, 'light'); const page = await ctx.newPage();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${BASE}/time-off`); await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: /request leave/i }).first().click(); await page.waitForTimeout(600);
  await page.selectOption('#leave_type', { label: 'Annual' });
  await page.fill('#leave_start-native', '2026-11-05'); await page.waitForTimeout(300);
  console.log('half options', await page.locator('#leave_half option').allInnerTexts());
  await page.selectOption('#leave_half', 'am');
  await page.getByRole('button', { name: /review/i }).click(); await page.waitForTimeout(800);
  await page.screenshot({ path: `${OUT}/journey-halfday-review-e2-390-light.png` });
  console.log('review', (await page.locator('[role=dialog]').last().innerText()).replace(/\s+/g,' '));
  await page.getByRole('button', { name: /^submit/i }).click(); await page.waitForTimeout(900);
  await page.screenshot({ path: `${OUT}/journey-halfday-submitted-e2-390-light.png`, fullPage: false });
  console.log('flash', (await page.locator('main').innerText()).replace(/\s+/g,' ').slice(0,300));
  // the reset-with-email page
  const g = await b.newContext({ viewport: { width: 390, height: 844 } }); const gp = await g.newPage();
  await gp.goto(`${BASE}/forgot-password`); await gp.fill('input[name=email]', 'kyaw.kyaw.naing@example.com');
  await gp.click('button[type=submit]'); await gp.waitForLoadState('networkidle');
  console.log('reset', (await gp.locator('body').innerText()).replace(/\s+/g,' ').slice(0,300));
  await gp.screenshot({ path: `${OUT}/journey-forgot-email-sent-390-light.png` });
});
