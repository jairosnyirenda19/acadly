const fs = require('fs');
const path = require('path');
const opentype = require('opentype.js');

const dir = path.join(__dirname, 'node_modules', '@fontsource', 'archivo', 'files');
const load = (w) => {
  const buf = fs.readFileSync(path.join(dir, `archivo-latin-${w}-normal.woff`));
  return opentype.parse(buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength));
};
const bold = load(800);
const regular = load(400);

function fit(font, text, { height, left, right, bottom, spacing = 0 }) {
  const size = 1000;
  let x = 0;
  const parts = [];
  for (const ch of text) {
    const g = font.charToGlyph(ch);
    parts.push(g.getPath(x, 0, size));
    x += (g.advanceWidth / font.unitsPerEm) * size + spacing * size;
  }
  let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
  for (const p of parts) {
    const b = p.getBoundingBox();
    x1 = Math.min(x1, b.x1); y1 = Math.min(y1, b.y1); x2 = Math.max(x2, b.x2); y2 = Math.max(y2, b.y2);
  }
  const k = height / (y2 - y1);
  const dx = (left !== undefined ? left : right - (x2 - x1) * k) - x1 * k;
  const dy = bottom - y2 * k;
  const f = (v, o) => (v * k + o).toFixed(1);
  let d = '';
  for (const p of parts) for (const c of p.commands) {
    if (c.type === 'M') d += `M${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'L') d += `L${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'C') d += `C${f(c.x1, dx)} ${f(c.y1, dy)} ${f(c.x2, dx)} ${f(c.y2, dy)} ${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'Q') d += `Q${f(c.x1, dx)} ${f(c.y1, dy)} ${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'Z') d += 'Z';
  }
  return d;
}

const INK = '#0C2340', CUT = '#FFFFFF', QUIET = '#A9BBD1';
const BOOK = 'M70 308q0-44 44-44h284q44 0 44 44v118q0 44-44 44H114q-44 0-44-44z';

const outline = `
  <path d="M96 300V84q0-40 40-40h196l84 84v172" fill="${CUT}" stroke="${INK}" stroke-width="22" stroke-linejoin="round"/>
  <path d="M332 44v54q0 30 30 30h54" fill="none" stroke="${INK}" stroke-width="22" stroke-linejoin="round"/>`;

const rows = {
  // Two rows and a third closed with a register stroke.
  stroke: `<g stroke="${INK}" stroke-width="18" stroke-linecap="round" fill="none"><path d="M152 138h136M152 186h136M152 234h60"/><path d="M254 248l32-38"/></g>`,
  // Three plain rows, as in the eOffice mark.
  plain: `<g stroke="${INK}" stroke-width="18" stroke-linecap="round" fill="none"><path d="M152 138h136M152 186h136M152 234h76"/></g>`,
  // Three rows, each closed with a register cell: present, present, absent.
  cells: `<g stroke="${INK}" stroke-width="18" stroke-linecap="round" fill="none"><path d="M152 138h104M152 186h104M152 234h104"/></g>
  <rect x="284" y="124" width="28" height="28" fill="${INK}"/><rect x="284" y="172" width="28" height="28" fill="${INK}"/>
  <rect x="289" y="225" width="18" height="18" fill="none" stroke="${INK}" stroke-width="10"/>`,
};

const variants = {
  'c1-current': { rows: 'stroke', ribbon: 'M352 250h44v96l-22-20-22 20z', a: { height: 152, left: 98, bottom: 448 }, iqx: { height: 34, right: 416, bottom: 448 } },
  'c2-long-marker': { rows: 'stroke', ribbon: 'M360 250h48v150l-24-24-24 24z', a: { height: 160, left: 96, bottom: 450 }, iqx: { height: 30, right: 344, bottom: 448 } },
  'c3-page-edge': { rows: 'stroke', ribbon: 'M352 250h44v90l-22-20-22 20z', a: { height: 136, left: 100, bottom: 424 }, iqx: { height: 30, right: 416, bottom: 424 },
    extra: `<path d="M104 446h338" stroke="${CUT}" stroke-width="8" stroke-linecap="round"/>` },
  'c4-spine': { rows: 'stroke', ribbon: 'M352 250h44v96l-22-20-22 20z', a: { height: 150, left: 128, bottom: 448 }, iqx: { height: 32, right: 416, bottom: 448 },
    extra: `<path d="M104 264v206" stroke="${CUT}" stroke-width="8"/>` },
  'c5-plain-rows': { rows: 'plain', ribbon: 'M352 250h44v96l-22-20-22 20z', a: { height: 152, left: 98, bottom: 448 }, iqx: { height: 34, right: 416, bottom: 448 } },
  'c6-register-cells': { rows: 'cells', ribbon: 'M352 250h44v96l-22-20-22 20z', a: { height: 152, left: 98, bottom: 448 }, iqx: { height: 34, right: 416, bottom: 448 } },
};

const out = process.argv[2];
fs.mkdirSync(out, { recursive: true });
for (const [name, v] of Object.entries(variants)) {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-labelledby="t">
  <title id="t">Acadly IQ-X</title>${outline}
  ${rows[v.rows]}
  <path d="${BOOK}" fill="${INK}"/>
  <path d="${v.ribbon}" fill="${CUT}"/>
  ${v.extra ?? ''}
  <path d="${fit(bold, 'a', v.a)}" fill="${CUT}"/>
  <path d="${fit(regular, 'IQ-X', { ...v.iqx, spacing: 0.06 })}" fill="${QUIET}"/>
</svg>
`;
  fs.writeFileSync(path.join(out, `${name}.svg`), svg);
}

const labels = {
  'c1-current': 'C1 · As shown before',
  'c2-long-marker': 'C2 · Long marker, IQ-X beside the letter',
  'c3-page-edge': 'C3 · Page edge at the foot',
  'c4-spine': 'C4 · Spine line at the left',
  'c5-plain-rows': 'C5 · Plain rows, no register stroke',
  'c6-register-cells': 'C6 · Rows closed with register cells',
};
const cells = Object.keys(variants).map((n) => `<div><img class="big" src="${n}.svg" alt=""><span>${labels[n]}</span><p><img src="${n}.svg" width="64" alt=""><img src="${n}.svg" width="32" alt=""><img src="${n}.svg" width="16" alt=""></p></div>`).join('\n');
fs.writeFileSync(path.join(out, 'index.html'), `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Acadly mark · C variations</title>
<style>body{margin:0;font-family:system-ui;display:grid;grid-template-columns:repeat(3,1fr);background:#fff;color:#0C2340}
div{padding:18px 12px;display:flex;flex-direction:column;align-items:center;gap:8px;font-size:13px;font-weight:700;text-align:center}
div:nth-child(even){background:#F1F4F6}img.big{width:210px;height:210px}p{margin:0;display:flex;align-items:flex-end;gap:12px}</style></head><body>
${cells}
</body></html>
`);
console.log('written', Object.keys(variants).join(', '));
