# Acadly IQ-X brand files

Status: approved by the owner on 15 November 2026.

## The mark

A sibling of the eOffice IQ-X mark. The construction is the same.

| Part | eOffice IQ-X | Acadly IQ-X |
|---|---|---|
| Thin-line object behind | A document with three lines | A sheet that carries a graduation cap |
| Solid object in front | A folder | A closed book with a ribbon marker |
| Letter cut out of the solid | `e` | `a`, from Archivo ExtraBold |
| Small quiet signature | IQ-X | IQ-X, from Archivo Regular |
| Colour | One ink | One ink: `#0C2340` |

## Files

Each file has a `-dark` twin for use on ink or a dark ground.

| File | Use |
|---|---|
| `acadly-iqx-logo-wide.svg` | The mark with the name beside it. Letterheads, sign-in, reports, documents |
| `acadly-iqx-logo-stacked.svg` | The mark with the name under it. Square and narrow spaces |
| `acadly-iqx-mark.svg` | The mark alone, at 48 pixels and above |
| `acadly-iqx-icon.svg` | The small-size icon: sheet, book and letter only, with heavier lines. Browser tab, home screen, anything below 48 pixels |
| `preview.html` | Every file, with the small sizes side by side |

The apps serve them as `brand-logo.svg`, `brand-mark.svg` and `brand-icon.svg`, each with a `-dark` twin where it exists.

## The name

ACADLY IQ-X, set in Archivo at its narrowest width (62), weight 800, uppercase. ACADLY is in ink. IQ-X is in teal (`#0A7775` on light, `#2FD6CF` on ink). In the stacked logo the name is exactly as wide as the book.

## Rules

- Use the icon below 48 pixels. The cap and the signature do not hold at that size.
- Keep clear space of one outline width on every side.
- Do not recolour or redraw the parts. Use the dark files on a dark ground.
- All letters are outlines. The files need no font.
- The graduation cap is drawn for this mark: a board, the cap body with a clear gap under it, and one bold tassel. It follows the proportions of the cap icon the owner supplied (`graduation-cap-svgrepo-com.svg`, from SVG Repo) but uses none of its path data.

## Regenerating

`make-marks.cjs` writes every file. It needs `opentype.js` and `@fontsource/archivo` installed beside it, and `archivo-narrow-800.ttf`, which is Archivo cut at width 62 and weight 800 with fontTools. Archivo is licensed under the SIL Open Font License 1.1.

```bash
node make-marks.cjs .
```

Earlier options are kept in `variations/` for reference.
