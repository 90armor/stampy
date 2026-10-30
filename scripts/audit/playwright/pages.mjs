import { shootAll, withBrowser } from './lib.mjs';

const T = '2026-10-28', MS = '2026-10-01';

export const sets = {
    admin: ['admin', [
        ['dashboard', '/dashboard'],
        ['employees', '/employees'],
        ['employee-show', '/employees/2'],
        ['employee-show-leaver', '/employees/40'],
        ['employee-show-nologin', '/employees/3'],
        ['employee-show-longname', '/employees/41'],
        ['attendance', '/attendance'],
        ['attendance-month', `/attendance?from=${MS}&to=${T}`],
        ['attendance-empty', '/attendance?q=zzzz'],
        ['attendance-calendar', '/attendance/2'],
        ['attendance-table', '/attendance/2?view=table'],
        ['attendance-calendar-khmer', '/attendance/42'],
        ['my-attendance', '/my-attendance'],
        ['time-off', '/time-off'],
        ['approvals', '/approvals'],
        ['overtime', '/overtime'],
        ['policies-schedules', '/policies?tab=schedules'],
        ['policies-holidays', '/policies?tab=holidays'],
        ['policies-leave-types', '/policies?tab=leave-types'],
        ['policies-overtime', '/policies?tab=overtime'],
        ['org-departments', '/organization'],
        ['org-positions', '/organization?tab=positions'],
        ['reports-attendance', '/reports?tab=attendance'],
        ['reports-leave', '/reports?tab=leave'],
        ['reports-overtime', '/reports?tab=overtime'],
        ['profile', '/profile'],
        ['404', '/no-such-page'],
        ['404-model', '/employees/99999'],
    ]],
    m1: ['aye.aye.mon', [
        ['dashboard', '/dashboard'],
        ['employees', '/employees'],
        ['attendance', '/attendance'],
        ['attendance-calendar', '/attendance/9'],
        ['approvals', '/approvals'],
        ['reports-attendance', '/reports?tab=attendance'],
        ['reports-leave', '/reports?tab=leave'],
        ['time-off', '/time-off'],
        ['overtime', '/overtime'],
        ['my-attendance', '/my-attendance'],
        ['403-employee', '/employees/4'],
        ['403-attendance', '/attendance/4'],
        ['403-policies', '/policies'],
        ['profile', '/profile'],
    ]],
    m2a: ['audit.manager.none', [
        ['dashboard', '/dashboard'],
        ['employees', '/employees'],
        ['attendance', '/attendance'],
        ['approvals', '/approvals'],
        ['reports-attendance', '/reports?tab=attendance'],
    ]],
    m2b: ['audit.manager.gone', [
        ['dashboard', '/dashboard'],
        ['employees', '/employees'],
        ['attendance', '/attendance'],
        ['reports-attendance', '/reports?tab=attendance'],
    ]],
    e1: ['audit.new.joiner', [
        ['dashboard', '/dashboard'],
        ['time-off', '/time-off'],
        ['my-attendance', '/my-attendance'],
        ['overtime', '/overtime'],
        ['403-employees', '/employees'],
    ]],
    e2: ['kyaw.kyaw.naing', [
        ['dashboard', '/dashboard'],
        ['my-attendance', '/my-attendance'],
        ['my-attendance-table', '/my-attendance?view=table'],
        ['time-off', '/time-off'],
        ['overtime', '/overtime'],
        ['profile', '/profile'],
    ]],
    kh: ['audit.khmer', [
        ['dashboard', '/dashboard'],
        ['time-off', '/time-off'],
        ['my-attendance', '/my-attendance'],
    ]],
    e4: ['audit.leaver', [
        ['dashboard', '/dashboard'],
        ['my-attendance', '/my-attendance'],
        ['time-off', '/time-off'],
    ]],
    a1: ['audit.admin', [
        ['dashboard', '/dashboard'],
        ['time-off', '/time-off'],
        ['overtime', '/overtime'],
        ['my-attendance', '/my-attendance'],
    ]],
};

// Imported by axe-6d.mjs for the page list; run directly, it shoots.
if (process.argv[1]?.endsWith('pages.mjs')) {
const wanted = process.argv.slice(2);
await withBrowser(async (browser) => {
    const groups = (wanted.length ? wanted : Object.keys(sets)).map((k) => [k, ...sets[k]]);
    // three personas at a time
    for (let i = 0; i < groups.length; i += 3) {
        await Promise.all(groups.slice(i, i + 3).map(([k, user, pages]) => shootAll(browser, k, user, pages)));
    }
});
}
