// AUDIT-ONLY (Phase 6d): summarises axe-6d.mjs's JSON by rule id, by impact.
// Usage: node axe-6d-report.mjs before/axe.json [after/axe.json]
import fs from 'node:fs';

// The design system allows these: a day outside the shown month is decorative
// slate-300 (docs/DESIGN_SYSTEM.md, Semantic roles).
const decorative = (id, n) => id === 'color-contrast' && /text-slate-300\.min-h-16/.test(n.target);

function summarise(file) {
    const data = JSON.parse(fs.readFileSync(file));
    const rules = {};
    for (const c of data) for (const v of c.axe) {
        const key = v.id;
        rules[key] ??= { impact: v.impact, nodes: 0, captures: 0, decorative: 0, where: new Set(), sample: new Set() };
        const real = v.nodes.filter((n) => ! decorative(v.id, n));
        rules[key].decorative += v.nodes.length - real.length;
        rules[key].nodes += real.length;
        if (real.length) { rules[key].captures++; rules[key].where.add(`${c.slug}@${c.width}/${c.theme}`); real.slice(0, 2).forEach((n) => rules[key].sample.add(n.target + ' :: ' + n.summary)); }
    }
    return { rules, total: data.length };
}

const [a, b] = process.argv.slice(2).map(summarise);
const ids = [...new Set([...Object.keys(a.rules), ...Object.keys(b?.rules ?? {})])].sort((x, y) => (rank(a.rules[x] ?? b.rules[x]) - rank(a.rules[y] ?? b.rules[y])) || x.localeCompare(y));
function rank(r) { return { harness: -1, critical: 0, serious: 1, moderate: 2, minor: 3 }[r.impact] ?? 4; }

console.log(`captures: ${a.total}${b ? ' → ' + b.total : ''}\n`);
console.log('| rule | impact | before: nodes (captures) | after: nodes (captures) |');
console.log('|---|---|---|---|');
for (const id of ids) {
    const r = a.rules[id], s = b?.rules[id];
    console.log(`| ${id} | ${(r ?? s).impact} | ${r ? `${r.nodes} (${r.captures})` : '0'} | ${s ? `${s.nodes} (${s.captures})` : '0'} |`);
}
for (const [name, x] of [['before', a], ['after', b]]) {
    if (! x) continue;
    const dec = Object.values(x.rules).reduce((n, r) => n + r.decorative, 0);
    console.log(`\n${name}: decorative calendar numbers excluded: ${dec}`);
    if (process.env.DETAIL) for (const [id, r] of Object.entries(x.rules)) {
        if (! r.nodes) continue;
        console.log(`\n[${id}] ${[...r.where].slice(0, 12).join(', ')}`);
        for (const t of [...r.sample].slice(0, Number(process.env.DETAIL))) console.log('   ' + t);
    }
}
