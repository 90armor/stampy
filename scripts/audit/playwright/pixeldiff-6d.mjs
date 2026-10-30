// AUDIT-ONLY (Phase 6d): compares axe-6d.mjs's before/after screenshots; lists
// each that differs by more than a sliver, and where. Needs pixelmatch and pngjs.
// Usage: node pixeldiff-6d.mjs <dir>/before/shots <dir>/after/shots
import fs from 'node:fs';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const [a, b] = process.argv.slice(2);
let same = 0, sized = [], differ = [];
for (const file of fs.readdirSync(a).filter((f) => f.endsWith('.png'))) {
    if (! fs.existsSync(`${b}/${file}`)) { differ.push([file, 'missing']); continue; }
    const x = PNG.sync.read(fs.readFileSync(`${a}/${file}`));
    const y = PNG.sync.read(fs.readFileSync(`${b}/${file}`));
    if (x.width !== y.width || x.height !== y.height) { sized.push(`${file} ${x.width}x${x.height} → ${y.width}x${y.height}`); continue; }
    const diff = new PNG({ width: x.width, height: x.height });
    const n = pixelmatch(x.data, y.data, diff.data, x.width, x.height, { threshold: 0.1 });
    if (n === 0) { same++; continue; }
    let top = Infinity, bottom = 0, left = Infinity, right = 0;
    for (let yy = 0; yy < y.height; yy++) for (let xx = 0; xx < y.width; xx++) {
        const i = (yy * y.width + xx) * 4;
        if (diff.data[i] === 255 && diff.data[i + 1] === 0) { top = Math.min(top, yy); bottom = Math.max(bottom, yy); left = Math.min(left, xx); right = Math.max(right, xx); }
    }
    differ.push([file, `${n}px in x ${left}–${right}, y ${top}–${bottom}`]);
}
console.log(`identical: ${same}; size changed: ${sized.length}; pixels differ: ${differ.length}`);
sized.forEach((s) => console.log('SIZE ' + s));
differ.sort().forEach(([f, d]) => console.log(`DIFF ${f}: ${d}`));
