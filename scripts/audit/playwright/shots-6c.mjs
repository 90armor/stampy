// AUDIT-ONLY (Phase 6c): the Reports page's one visible change — a tab not
// opened yet shows a loading state until its report is computed — at 390 and
// 1440, light and dark, into docs/audit/shots/6c/: the page as it loads (the
// open tab's report), and another tab mid-load (the round trip held back so
// the state can be captured), then loaded. Needs the demo seed on the dev
// database. Run from the repo root.
import { BASE, OUT, login, themedContext, withBrowser, fullShot } from './lib.mjs';
import fs from 'node:fs';

const DIR = `${OUT}/6c`;
fs.mkdirSync(DIR, { recursive: true });
const settle = async (p, ms = 400) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };

await withBrowser(async (b) => {
    const { state } = await login(b, 'admin');

    for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
        const tag = `${width}-${theme}`;
        const c = await themedContext(b, state, theme);
        const p = await c.newPage();
        await p.setViewportSize({ width, height: width < 600 ? 844 : 900 });

        await p.goto(`${BASE}/reports?tab=attendance`); await settle(p);
        await fullShot(p, `${DIR}/reports-initial-${tag}.png`, width);

        // Hold the tab's first round trip back, so its loading state stays on screen.
        let release;
        const held = new Promise((resolve) => { release = resolve; });
        await p.route('**/livewire/update', async (route) => { await held; await route.continue(); });
        await p.getByRole('button', { name: 'Leave', exact: true }).click();
        await p.getByText('Loading the leave report…').waitFor();
        // The tab bar's colours are a 150ms transition.
        await p.waitForTimeout(400);
        await fullShot(p, `${DIR}/reports-leave-loading-${tag}.png`, width);
        release();
        await p.getByRole('heading', { name: 'Leave report' }).waitFor();
        await p.unroute('**/livewire/update');
        await settle(p);
        await fullShot(p, `${DIR}/reports-leave-loaded-${tag}.png`, width);
        await c.close();
    }
});
