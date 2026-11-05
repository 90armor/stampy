// AUDIT (Phase 6h): the slice's screenshots at 390 and 1440, light and dark,
// into docs/audit/shots/6h/, with axe on each state — avatars for Khmer,
// Myanmar-script and Latin names, the dashboard trend at 390 with Off days,
// a day with a voided and a re-added punch, the reason hint under Add punch
// and Void, and the employee's own view of that corrected day. It adds a
// Myanmar-script employee and corrects a punch (with reasons) on Kyaw Kyaw
// Naing's attendance: run on the demo seed + seed-personas.php, reseed
// afterwards.
import { BASE, OUT, REPO, login, runAxe, themedContext, withBrowser } from './lib.mjs';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const DIR = `${OUT}/6h`;
fs.mkdirSync(DIR, { recursive: true });
const axeHits = [];
const axe = async (p, what) => { const v = await runAxe(p); if (v.length) axeHits.push([what, v]); };
const settle = async (p, ms = 500) => { await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(ms); };
const height = (width) => (width < 600 ? 844 : 900);
const sql = (query) => execSync(`docker compose exec -T mysql mysql -uattendance -psecret stampy -N -e ${JSON.stringify(query)}`, { cwd: REPO, stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
const tinker = (code) => execSync(`docker compose exec -T app php artisan tinker --execute=${JSON.stringify(code)}`, { cwd: REPO }).toString().trim().split('\n').pop();
const nativeFill = (p, id, value) => p.evaluate(([i, v]) => { const el = document.getElementById(`${i}-native`); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, [id, value]);

// A Myanmar-script name (the demo seed's names are romanised).
if (sql("select count(*) from employees where employee_code = 'MYA-001'") === '0') {
    tinker("App\\Models\\Employee::create(['employee_code' => 'MYA-001', 'full_name' => 'ကျော်ကျော်နိုင်', 'department_id' => App\\Models\\Department::value('id'), 'position_id' => App\\Models\\Position::value('id'), 'join_date' => '2025-01-06']); echo 'ok';");
}
const ids = {
    latin: sql("select id from employees where employee_code = 'EMP-0002'"),
    khmer: sql("select id from employees where full_name = 'សុខ សុភាព'"),
    myanmar: sql("select id from employees where employee_code = 'MYA-001'"),
};

// Kyaw Kyaw Naing's first Present day this month: a punch added, voided and
// added again, each with its reason — the history the day modal now keeps.
const kyaw = ids.latin;
const day = sql(`select work_date from daily_attendances where employee_id = ${kyaw} and status = 'present' and work_date >= date_format(curdate(), '%Y-%m-01') order by work_date limit 1`);

await withBrowser(async (b) => {
    const states = {};
    for (const u of ['admin', 'aye.aye.mon', 'kyaw.kyaw.naing', 'audit.khmer']) states[u] = (await login(b, u)).state;
    let corrected = false;

    for (const theme of ['light', 'dark']) for (const width of [1440, 390]) {
        const tag = `${width}-${theme}`;
        const open = async (user) => { const c = await themedContext(b, states[user], theme); const p = await c.newPage(); await p.setViewportSize({ width, height: height(width) }); return { c, p }; };
        const shot = (p, name) => p.screenshot({ path: `${DIR}/${name}-${tag}.png` });

        { // Avatars: profile headers for a Latin, a Khmer and a Myanmar-script name; the Khmer user's topbar.
            const { c, p } = await open('admin');
            for (const [script, id] of Object.entries(ids)) {
                await p.goto(`${BASE}/employees/${id}`, { waitUntil: 'networkidle' }); await settle(p, 300);
                await shot(p, `avatar-${script}`);
                await axe(p, `avatar ${script} ${tag}`);
            }
            await c.close();
            const k = await open('audit.khmer');
            await k.p.goto(`${BASE}/time-off`, { waitUntil: 'networkidle' }); await settle(k.p, 300);
            await shot(k.p, 'avatar-khmer-topbar');
            await k.c.close();
        }

        { // The trend's Off markers: the manager's team, whose weekend is Off (the
            // stress employees have no weekend rows, so the admin's isn't).
            const { c, p } = await open('aye.aye.mon');
            await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' }); await settle(p, 1200);
            const card = p.locator('#attendance-trend-chart').locator('xpath=ancestor::*[contains(@class, "rounded-xl")][1]');
            await card.scrollIntoViewIfNeeded(); await p.waitForTimeout(300);
            await card.screenshot({ path: `${DIR}/trend-off-days-${tag}.png` });
            await axe(p, `dashboard ${tag}`);
            await c.close();
        }

        { // The reason hint, then a voided and a re-added punch.
            const { c, p } = await open('admin');
            await p.goto(`${BASE}/attendance/${kyaw}`, { waitUntil: 'networkidle' });
            await p.locator(`[wire\\:click="openDay('${day}')"]`).first().click(); await settle(p, 600);
            await p.getByRole('dialog').getByRole('button', { name: 'Add punch' }).click(); await settle(p, 500);
            await p.locator(`#new_punch_reason_${day}`).scrollIntoViewIfNeeded();
            await shot(p, 'reason-hint-add-punch');
            await axe(p, `add punch hint ${tag}`);
            if (! corrected) {
                await nativeFill(p, `new_punch_time_${day}`, '12:58'); await settle(p, 300);
                await p.fill(`#new_punch_reason_${day}`, 'Forgot to punch back in after lunch');
                await p.getByRole('dialog').getByRole('button', { name: 'Save punch' }).click(); await settle(p, 900);
                const first = sql(`select id from attendance_logs where employee_id = ${kyaw} and reason = 'Forgot to punch back in after lunch'`);
                await p.getByRole('dialog').getByRole('button', { name: /^Void the In punch at 12:58 PM/ }).click(); await settle(p, 600);
                await p.fill(`#void_reason_${first}`, 'Entered on the wrong day');
                await p.locator(`#void_reason_${first}`).scrollIntoViewIfNeeded();
                await shot(p, 'reason-hint-void');
                await axe(p, `void hint ${tag}`);
                await p.getByRole('dialog').getByRole('button', { name: 'Void punch' }).click(); await settle(p, 900);
                await p.getByRole('dialog').getByRole('button', { name: 'Add punch' }).click(); await settle(p, 500);
                await nativeFill(p, `new_punch_time_${day}`, '12:58'); await settle(p, 300);
                await p.fill(`#new_punch_reason_${day}`, 'It was this day after all: confirmed with the guard');
                await p.getByRole('dialog').getByRole('button', { name: 'Save punch' }).click(); await settle(p, 900);
                corrected = true;
            } else {
                // The void form's hint, on the re-added punch (then cancelled).
                const live = sql(`select id from attendance_logs where employee_id = ${kyaw} and reason like 'It was this day%'`);
                await p.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click(); await settle(p, 400);
                await p.locator(`[wire\\:click="startVoiding(${live})"]`).click(); await settle(p, 600);
                await p.locator(`#void_reason_${live}`).scrollIntoViewIfNeeded();
                await shot(p, 'reason-hint-void');
                await axe(p, `void hint ${tag}`);
                await p.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click(); await settle(p, 400);
            }
            await p.getByRole('dialog').getByText('Raw punches').scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
            await shot(p, 'day-voided-and-re-added');
            await axe(p, `day history ${tag}`);
            await c.close();
        }

        { // The employee's own view of the corrected day.
            const { c, p } = await open('kyaw.kyaw.naing');
            await p.goto(`${BASE}/my-attendance`, { waitUntil: 'networkidle' });
            await p.locator(`[wire\\:click="openDay('${day}')"]`).first().click(); await settle(p, 600);
            await p.getByRole('dialog').getByText('Raw punches').scrollIntoViewIfNeeded(); await p.waitForTimeout(200);
            await shot(p, 'employee-corrected-day');
            await axe(p, `employee day ${tag}`);
            await c.close();
        }
    }
});
console.log(`day ${day} → ${DIR}`);
console.log(axeHits.length ? `axe: ${JSON.stringify(axeHits, null, 1)}` : 'axe: no violations on the new states');
