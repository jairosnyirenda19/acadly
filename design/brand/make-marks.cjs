/*
 * Generates every Acadly IQ-X brand file.
 *
 *   node make-marks.cjs <output folder>
 *
 * Needs: opentype.js, @fontsource/archivo, and archivo-narrow-800.ttf beside
 * this file (Archivo cut at width 62, weight 800, with fontTools).
 */
const fs = require('fs');
const path = require('path');
const opentype = require('opentype.js');

const read = (file) => {
  const buf = fs.readFileSync(file);
  return opentype.parse(buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength));
};
const dir = path.join(__dirname, 'node_modules', '@fontsource', 'archivo', 'files');
const bold = read(path.join(dir, 'archivo-latin-800-normal.woff'));
const regular = read(path.join(dir, 'archivo-latin-400-normal.woff'));
const narrow = read(path.join(__dirname, 'archivo-narrow-800.ttf'));

/** Outline of `text`, fitted to a height and placed by its left (or right) edge and its bottom. */
function fit(font, text, { height, left, right, bottom, spacing = 0 }) {
  const size = 1000;
  let x = 0;
  const parts = [];
  for (const ch of text) {
    const g = font.charToGlyph(ch);
    if (ch !== ' ') parts.push(g.getPath(x, 0, size));
    x += (g.advanceWidth / font.unitsPerEm) * size + spacing * size;
  }
  // Height is measured on a flat capital so every word shares one cap height.
  const ref = font.charToGlyph(/[a-z]/.test(text) ? 'x' : 'H').getPath(0, 0, size).getBoundingBox();
  let x1 = Infinity, x2 = -Infinity;
  for (const p of parts) {
    const b = p.getBoundingBox();
    x1 = Math.min(x1, b.x1); x2 = Math.max(x2, b.x2);
  }
  const k = height / (ref.y2 - ref.y1);
  const width = (x2 - x1) * k;
  const dx = (left !== undefined ? left : right - width) - x1 * k;
  const dy = bottom - ref.y2 * k;
  const f = (v, o) => (v * k + o).toFixed(1);
  let d = '';
  for (const p of parts) for (const c of p.commands) {
    if (c.type === 'M') d += `M${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'L') d += `L${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'C') d += `C${f(c.x1, dx)} ${f(c.y1, dy)} ${f(c.x2, dx)} ${f(c.y2, dy)} ${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'Q') d += `Q${f(c.x1, dx)} ${f(c.y1, dy)} ${f(c.x, dx)} ${f(c.y, dy)}`;
    else if (c.type === 'Z') d += 'Z';
  }
  return { d, width, right: dx + x2 * k };
}

const light = { ink: '#0C2340', cut: '#FFFFFF', quiet: '#A9BBD1', sheetFill: '#FFFFFF', accent: '#0A7775' };
const dark = { ink: '#F1F4F6', cut: '#0C2340', quiet: '#4B5D75', sheetFill: 'none', accent: '#2FD6CF' };

const BOOK = 'M70 308q0-44 44-44h284q44 0 44 44v118q0 44-44 44H114q-44 0-44-44z';
const RIBBON = 'M352 250h44v96l-22-20-22 20z';

/** The graduation cap: a board, the cap body with a clear gap under it, and one bold tassel. */
const cap = (ink) => `<g transform="translate(126 60) scale(3.5)" fill="${ink}" stroke="${ink}" stroke-linejoin="round" stroke-linecap="round">
    <path d="M32 12.4L59 24.75L32 37.1L5 24.75Z" stroke-width="2.6"/>
    <path d="M15.6 35.2L32 42.7L48.4 35.2V39.6C48.4 48.4 15.6 48.4 15.6 39.6Z" stroke-width="2.2"/>
    <path d="M7.4 27.6V40.2" fill="none" stroke-width="2.6"/>
    <path d="M5.2 41.2h4.4v8.4a2.2 2.2 0 0 1-4.4 0z" stroke-width="1.2"/>
  </g>`;

/** The full mark, as the inside of a 512-unit square. */
function markBody(c) {
  return `<path d="M96 300V84q0-40 40-40h196l84 84v172" fill="${c.sheetFill}" stroke="${c.ink}" stroke-width="22" stroke-linejoin="round"/>
  <path d="M332 44v54q0 30 30 30h54" fill="none" stroke="${c.ink}" stroke-width="22" stroke-linejoin="round"/>
  ${cap(c.ink)}
  <path d="${BOOK}" fill="${c.ink}"/>
  <path d="${RIBBON}" fill="${c.cut}"/>
  <path d="${fit(bold, 'a', { height: 152, left: 98, bottom: 448 }).d}" fill="${c.cut}"/>
  <path d="${fit(regular, 'IQ-X', { height: 34, right: 416, bottom: 448, spacing: 0.06 }).d}" fill="${c.quiet}"/>`;
}

/** The small-size icon: the sheet, the book and the letter only, with heavier lines. */
function iconBody(c) {
  const a = fit(bold, 'a', { height: 178, left: 0, bottom: 452 });
  const centred = fit(bold, 'a', { height: 178, left: 256 - a.width / 2, bottom: 452 });
  return `<path d="M100 300V92q0-44 44-44h176l92 92v160" fill="${c.sheetFill}" stroke="${c.ink}" stroke-width="34" stroke-linejoin="round"/>
  <path d="M320 48v52q0 40 40 40h52" fill="none" stroke="${c.ink}" stroke-width="34" stroke-linejoin="round"/>
  <path d="M56 300q0-48 48-48h304q48 0 48 48v132q0 48-48 48H104q-48 0-48-48z" fill="${c.ink}"/>
  <path d="${centred.d}" fill="${c.cut}"/>`;
}

const svg = (w, h, body) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" role="img" aria-labelledby="t">
  <title id="t">Acadly IQ-X</title>
  ${body}
</svg>
`;

/** Mark on the left, the name on the right. */
function wide(c) {
  const cap_ = 196;
  const bottom = 354;
  const name = fit(narrow, 'ACADLY', { height: cap_, left: 580, bottom });
  const iqx = fit(narrow, 'IQ-X', { height: cap_, left: name.right + 62, bottom });
  const width = Math.ceil(iqx.right + 24);
  return svg(width, 512, `${markBody(c)}
  <path d="${name.d}" fill="${c.ink}"/>
  <path d="${iqx.d}" fill="${c.accent}"/>`);
}

/** Mark on top, the name under it. */
function stacked(c) {
  // The name is sized so that it is exactly as wide as the book (372 units).
  const trial = 100;
  const t1 = fit(narrow, 'ACADLY', { height: trial, left: 0, bottom: 0 });
  const t2 = fit(narrow, 'IQ-X', { height: trial, left: 0, bottom: 0 });
  const cap_ = (trial * 372) / (t1.width + trial * 0.3 + t2.width);
  const probe = fit(narrow, 'ACADLY', { height: cap_, left: 0, bottom: 0 });
  const probe2 = fit(narrow, 'IQ-X', { height: cap_, left: 0, bottom: 0 });
  const gap = cap_ * 0.3;
  const total = probe.width + gap + probe2.width;
  const left = 256 - total / 2;
  const bottom = 512 + 40 + cap_;
  const name = fit(narrow, 'ACADLY', { height: cap_, left, bottom });
  const iqx = fit(narrow, 'IQ-X', { height: cap_, left: name.right + gap, bottom });
  return svg(512, bottom + 24, `${markBody(c)}
  <path d="${name.d}" fill="${c.ink}"/>
  <path d="${iqx.d}" fill="${c.accent}"/>`);
}

const out = process.argv[2];
fs.mkdirSync(out, { recursive: true });
const files = {
  'acadly-iqx-mark.svg': svg(512, 512, markBody(light)),
  'acadly-iqx-mark-dark.svg': svg(512, 512, markBody(dark)),
  'acadly-iqx-icon.svg': svg(512, 512, iconBody(light)),
  'acadly-iqx-icon-dark.svg': svg(512, 512, iconBody(dark)),
  'acadly-iqx-logo-wide.svg': wide(light),
  'acadly-iqx-logo-wide-dark.svg': wide(dark),
  'acadly-iqx-logo-stacked.svg': stacked(light),
  'acadly-iqx-logo-stacked-dark.svg': stacked(dark),
};
for (const [name, content] of Object.entries(files)) fs.writeFileSync(path.join(out, name), content);

fs.writeFileSync(path.join(out, 'preview.html'), `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Acadly IQ-X brand files</title>
<style>body{margin:0;font-family:system-ui;font-size:13px;color:#0C2340}
section{display:grid;grid-template-columns:1fr 1fr}
div{padding:24px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px}
.w{background:#fff}.p{background:#F1F4F6}.d{background:#0C2340;color:#fff}
p{margin:0;display:flex;align-items:flex-end;gap:16px}h2{margin:0;padding:10px 24px;font-size:12px;letter-spacing:.14em;text-transform:uppercase;background:#E2E8EE}</style></head><body>
<h2>Logo, wide</h2><section><div class="w"><img src="acadly-iqx-logo-wide.svg" height="120" alt=""></div><div class="d"><img src="acadly-iqx-logo-wide-dark.svg" height="120" alt=""></div></section>
<h2>Logo, stacked</h2><section><div class="p"><img src="acadly-iqx-logo-stacked.svg" height="220" alt=""></div><div class="d"><img src="acadly-iqx-logo-stacked-dark.svg" height="220" alt=""></div></section>
<h2>Mark</h2><section><div class="w"><img src="acadly-iqx-mark.svg" height="200" alt=""></div><div class="d"><img src="acadly-iqx-mark-dark.svg" height="200" alt=""></div></section>
<h2>Small-size icon beside the full mark: 64, 32, 24 and 16 pixels</h2><section>
<div class="w"><p><img src="acadly-iqx-icon.svg" width="64" alt=""><img src="acadly-iqx-icon.svg" width="32" alt=""><img src="acadly-iqx-icon.svg" width="24" alt=""><img src="acadly-iqx-icon.svg" width="16" alt=""></p><span>Icon</span>
<p><img src="acadly-iqx-mark.svg" width="64" alt=""><img src="acadly-iqx-mark.svg" width="32" alt=""><img src="acadly-iqx-mark.svg" width="24" alt=""><img src="acadly-iqx-mark.svg" width="16" alt=""></p><span>Full mark, for comparison</span></div>
<div class="d"><p><img src="acadly-iqx-icon-dark.svg" width="64" alt=""><img src="acadly-iqx-icon-dark.svg" width="32" alt=""><img src="acadly-iqx-icon-dark.svg" width="24" alt=""><img src="acadly-iqx-icon-dark.svg" width="16" alt=""></p><span>Icon</span></div></section>
</body></html>
`);
console.log('written', Object.keys(files).length, 'files');
