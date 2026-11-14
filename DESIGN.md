---
name: Acadly IQ-X
description: The register book. A school system drawn like the paper every teacher already knows.
colors:
  ink: "#0C2340"
  paper: "#F1F4F6"
  sheet: "#FFFFFF"
  field: "#E2E8EE"
  rule: "#C7D5E3"
  rule-dashed: "#8496AC"
  text-muted: "#4B5D75"
  on-ink-soft: "#C9D6E4"
  on-ink-muted: "#A9BBD1"
  ink-rule: "#3A5173"
  teal: "#0A7775"
  teal-bright: "#2FD6CF"
  teal-mid: "#8FD6D2"
  teal-wash: "#DDF3F1"
  marigold: "#FFB703"
  marigold-wash: "#FFF6DC"
  red-pen: "#D12B1F"
  red-text: "#B0231A"
  red-wash: "#FDF1EF"
  disabled: "#D5DDE6"
typography:
  display:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "6.5rem"
    fontWeight: 800
    lineHeight: 0.84
    letterSpacing: "-0.01em"
    fontVariation: "'wdth' 62"
  headline:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "2.125rem"
    fontWeight: 800
    lineHeight: 1
    fontVariation: "'wdth' 62"
  title:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 800
    lineHeight: 1.05
    fontVariation: "'wdth' 62"
  numeral:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "7.5rem"
    fontWeight: 800
    lineHeight: 0.86
    fontVariation: "'wdth' 62"
    fontFeature: "'tnum'"
  body:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 400
    lineHeight: 1.5
  body-strong:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 800
    lineHeight: 1.3
  label:
    fontFamily: "Archivo, system-ui, sans-serif"
    fontSize: "0.625rem"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "0.14em"
  ref:
    fontFamily: "ui-monospace, Menlo, Consolas, monospace"
    fontSize: "0.6875rem"
    fontWeight: 600
    lineHeight: 1.3
rounded:
  none: "0px"
spacing:
  hair: "3px"
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  page: "40px"
  touch: "44px"
  row: "56px"
components:
  button-primary:
    backgroundColor: "{colors.teal}"
    textColor: "{colors.sheet}"
    rounded: "{rounded.none}"
    height: "44px"
    padding: "0 18px"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    height: "44px"
    padding: "0 18px"
  button-disabled:
    backgroundColor: "{colors.disabled}"
    textColor: "{colors.text-muted}"
    rounded: "{rounded.none}"
    height: "44px"
  field:
    backgroundColor: "{colors.field}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    height: "44px"
    padding: "0 12px"
  mark-present:
    backgroundColor: "{colors.teal}"
    textColor: "{colors.sheet}"
    rounded: "{rounded.none}"
    size: "44px"
  mark-absent:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.red-pen}"
    rounded: "{rounded.none}"
    size: "44px"
  mark-late:
    backgroundColor: "{colors.marigold}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    size: "44px"
  mark-excused:
    backgroundColor: "{colors.sheet}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    size: "44px"
  tile-selected:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.sheet}"
    rounded: "{rounded.none}"
  tag-attention:
    backgroundColor: "{colors.marigold}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: "2px 6px"
---

<!-- Source: the approved design canvas "Acadly IQ-X Screens" (11 screens), approved by the owner on 14 November 2026 (DECISIONS.md D-022). No UI code exists yet. Re-run /impeccable document once there is code, to confirm these tokens against the build and to write the .impeccable/design.json sidecar. -->

# Design System: Acadly IQ-X

## Overview

**Creative North Star: "The Register Book"**

Every teacher in the country knows the paper class register: a ruled page, one row per child, one column per day, a stroke for present, a circle for absent, a red margin line, and totals at the foot. Acadly is drawn from that book. The interface does not imitate paper with textures; it borrows the register's *grammar*: rows, ruled lines, square cells, drawn marks, a margin, and totals that are large enough to read across a room.

The character is a balance of boldness and professionalism. The boldness lives in three places only: very large condensed numerals, heavy condensed capitals for headings, and solid fields of navy, teal and marigold that own whole regions. Everything else is plain, calm and exact. Nothing floats, nothing glows, nothing is rounded.

The system is a sibling of eOffice IQ-X (square, ruled, paper and ink) and is not a copy of it. eOffice is a government file room in warm grey and green. Acadly is a school register in cool paper, navy ink and a red pen.

**Key Characteristics:**
- Drawn marks carry meaning: `/` present, `O` absent, `L` late, `E` excused; in nursery `/`, `//`, `///` for the three steps.
- A red margin line runs down every ledger. Row numbers sit to its left.
- Square everywhere. Structure is drawn with ruled lines, never with shadows or cards.
- Numbers are the loudest thing on a screen. Words stay quiet.
- Each colour has one job.

## Colors

Cool paper and navy ink, with three working colours that each mean one thing.

### Primary
- **Register Ink** (`ink`): text, heavy rules, the navigation rail, and the solid block behind the most important number on a screen. On ink, secondary text uses `on-ink-soft` and labels use `on-ink-muted`.
- **Done Teal** (`teal`): the present stroke, the primary action, a finished step, a submitted register. On ink it becomes `teal-bright`. `teal-mid` and `teal-wash` exist only as steps of a scale (attendance heat, nursery steps).

### Secondary
- **Marigold** (`marigold`): "look here". Offline, in progress, waiting for you, the place where a new item lands, a sight permission. `marigold-wash` tints a whole row.

### Tertiary
- **Red Pen** (`red-pen`): the absent circle, the margin line, a blocked or refused thing, a value that is wrong. Text on a tint uses `red-text`; the tint is `red-wash`.

### Neutral
- **Cool Paper** (`paper`): the ground of every page.
- **Sheet** (`sheet`): a ruled sheet on the paper: ledgers, forms, tables.
- **Field** (`field`): the fill of an input and of a quiet band.
- **Rule** (`rule`): the thin line between rows. **Dashed rule** (`rule-dashed`): something not there yet: an unmarked cell, a future week, a step not reached.
- **Muted text** (`text-muted`): supporting text and labels on paper or sheet.

### Named Rules
**The One Job Rule.** Teal means done. Marigold means look here. Red means absent, blocked or wrong. None of them is ever decoration, and none borrows another's job.

**The Mark Plus Word Rule.** A state is never shown by colour alone. It has a drawn mark, a letter or a word as well.

**The Red Pen Rule.** Red is the teacher's red pen. It never fills a button, never colours a heading, and is never used for something that is merely unfinished.

## Typography

**Display Font:** Archivo, variable, at its narrowest width (`'wdth' 62`), weight 800
**Body Font:** Archivo at normal width
**Label/Mono Font:** the system monospace, for student numbers, times, hashes and codes

**Character:** One family with two voices. Narrow and heavy for what must be seen from across a room; normal and plain for what must be read. It is the voice of a school notice board over the handwriting-free clarity of a printed form.

### Hierarchy
- **Display** (800, narrow, uppercase, 88–104px on a page, 56px on a phone, line-height 0.84–0.92): the one name of the screen: TODAY, TERM 3, FORM 2 EAST, a learner's name.
- **Numeral** (800, narrow, tabular, 72–120px): the totals. One to five per screen, in a ruled band.
- **Headline** (800, narrow, uppercase, 34px): a section of a page.
- **Title** (800, narrow, uppercase, 20–26px): a form step, a tile, a column head with weight.
- **Body strong** (800, 15–16px): the line that names a row: a learner, an action, a decision.
- **Body** (400, 15px, line-height 1.5; 16px in phone lists): everything read as a sentence. Keep prose under about 70 characters a line.
- **Label** (800, 10px, 0.14em, uppercase): what a thing is, above the thing.
- **Ref** (monospace, 600, 11–14px): identifiers and times.

### Named Rules
**The Loud Number Rule.** The largest type on any screen is a number or the screen's one name. Never a sentence.

**The Plain Sentence Rule.** Sentences are short, in sentence case, and say what happened and what to do next. No exclamation marks.

## Layout

- **A page is full-bleed and ruled.** It owns its own padding (`page` at desktop, `lg` on a phone). Sections are divided by a 2px ink rule; rows by a 1px rule.
- **Console pages:** a fixed-width ink rail on the left; the content is a head (label, display name, primary action), then a totals band, then one or two ruled columns. A second column is divided from the first by a 2px ink rule, never by a gap and a card.
- **The totals band** is a grid of two to five cells divided by 2px rules. The first cell is solid ink. A cell that needs attention is solid marigold.
- **The ledger** is the default list: a sheet, a red margin line, a number or time to the left of the line, the content to the right, the action at the far right.
- **Phones:** one column. The head is a solid ink block with the totals as its foot. Rows are 56px or taller; touch targets are 44px or larger; the primary action sits at the bottom.
- **At phone width** the rail stacks above the content, bands wrap, and a wide ledger scrolls sideways inside its own frame.
- **Spacing** steps: 4, 8, 12, 16, 24, 40. Grid gaps between mark cells are 3–4px.

## Elevation & Depth

There is none. No shadows, no blur, no glass, no overlap for effect. Depth is said with a rule, a fill, or a solid block.

### Named Rules
**The Nothing Floats Rule.** A thing that must stand out gets a 2px ink edge or a solid fill. It never gets a shadow.

## Shapes

- **Radius is 0 everywhere.** Cells, buttons, fields, tiles, tags, the rail, avatars.
- **Two rule weights:** 1px `rule` between rows; 2px `ink` between sections and around anything that must be seen. A 3px ink edge marks "this one" (today, the selected step). A 4px edge marks the current item in navigation and tabs.
- **Dashed means not yet.** A dashed edge is an empty cell, a future week or a step not reached. A dotted edge is the excused mark.
- **The margin line** is 2px `red-pen`, full height of the ledger, 38–92px from the left depending on what sits beside it.
- **Marks are square cells:** 24–28px in a history strip, 44–56px where they are tapped.

## Components

### Buttons
- **Shape:** square, 44px high (48px for the main action of a phone screen), weight 800, sentence case.
- **Primary:** solid `teal`, white text. One per region.
- **Secondary:** transparent with a 2px `ink` edge. On ink: a 2px white edge.
- **Blocked:** `disabled` fill, muted text, and the reason in words beside it. A blocked button is never left unexplained.
- **Focus:** a 2px `ink` outline offset 2px (on ink: `teal-bright`). Hover deepens the fill; it never moves the element.

### Mark cells (signature)
- **Present** `/`: solid `teal`, white stroke.
- **Absent** `O`: white, 3px `red-pen` edge, red circle.
- **Late** `L`: solid `marigold`, 2px `ink` edge.
- **Excused** `E`: white, 2px dotted `ink` edge.
- **Not marked:** white, 2px dashed edge, empty.
- **History strip:** small cells on `field`, the mark in ink (red for `O`).
- **Nursery steps:** `/` on `teal-wash`, `//` on `teal-mid`, `///` on `teal` with white strokes. Always shown with their word.
- **A granted permission** in the roles matrix is the present mark.

### Totals band
- Label on top, the numeral, one plain sentence under it. First cell solid `ink` with a `teal-bright` numeral.

### Ledger rows
- Number or time left of the margin line; label, strong line, supporting line; action on the right.
- **Needs attention:** `marigold-wash` row. **Blocked or refused:** `red-wash` row and a red label. **Selected:** `field` fill with an inset 6px `ink` edge.

### Inputs / Fields
- **Style:** `field` fill, no side or top border, a 2px `ink` baseline, 44px high. The label is a `label` above it.
- **Focus:** the baseline turns `teal`.
- **Error:** the baseline is 3px `red-pen`, the value turns red, and a sentence below says what is wrong and what is allowed.

### Top bar
The frame of every console screen, in the family of the eOffice IQ-X header: a `paper` band with a 2px `ink` rule under it, sticky at the top of the content, to the right of the rail.
- **Left:** the go bar. It grows to a maximum of about 620px and never fills the whole bar.
- **Right, in this fixed order:** a state tag when one applies (ACTING, VIEWING AS, SUPPORT ACCESS, in `marigold`) · Help (`?`) · Notices (bell, with a square `red-pen` count) · the theme switch (light / dark, two square halves, the chosen half solid `ink`) · the identity block.
- **Keys** are 44px squares with a 2px `ink` edge and a square-capped line icon. Never a round button, never a filled circle behind an icon.
- **Identity block:** name over role and scope, right-aligned, in a **fixed** width (176px) so the keys sit in the same place for every person; then a 44px solid `ink` square with the initials in narrow capitals. The whole block opens the profile.
- **Phone width:** the bar wraps; a menu key appears at the far left and opens the rail; the identity text hides and the initials square stays.
- The rail no longer carries the person; it ends with Sign out.

### Themes
Light is the first theme and the one all approved screens show. Dark is the same tokens with a second set of values, switched on `<html data-theme>`. The rail and solid `ink` blocks stay dark in both themes. Dark values are set when the first screens are built, not guessed here.

### Go bar (search everything)
- The one fully bordered field in the system, so it reads as the app's command line and not as a form input. It lives at the left of the top bar on every console screen.
- **Style:** 44px high, `sheet` fill, 2px `ink` edge, a square-capped magnifier on the left, a monospace `Ctrl K` key hint on the right.
- **Results panel:** a sheet with a 2px `ink` edge that hangs directly under the bar. One `field` band per group (label left, count right), then ledger-style hits: strong title, monospace reference, muted subtitle, a `label` word of state in `teal` on the right. The hit under the cursor has the `field` fill and an inset 6px `ink` edge.
- **A group the reader may not search is absent.** No empty group, no lock, no count of hidden things.
- **Foot of the panel:** one muted sentence that says what search never looks inside.

### Choice tiles
- A large square button with a narrow uppercase title and one plain sentence. Unselected: white with a 2px `rule-dashed`-colour solid edge. Selected: solid `ink`.

### Navigation
- **Rail:** solid `ink`. The wordmark in narrow capitals, "IQ-X" in `teal-bright`. A scope block ("You are working in": school, division, campus) under it. Items are plain text; the current item is white, weight 800, with a 4px `teal-bright` edge on its left. Never a filled row.
- **Tabs and phone bottom navigation:** text only; the current one has a 4px edge (`ink` on a sheet, `teal-bright` at the bottom of a phone).

### Journey ladder
- A ruled list of steps. Done: a small `teal` cell with a stroke. Now: a `teal-bright` cell with a 3px ink edge, on a `field` row. Later: a dashed cell.

### Tags
- Square, `label` type. Marigold for attention (OFFLINE, SIGHT). Never used as decoration.

## Do's and Don'ts

### Do:
- **Do** draw state with the register's marks, and always pair a mark or colour with a word.
- **Do** put one screen name in display type and let numerals be the loudest thing after it.
- **Do** divide with rules: 1px between rows, 2px between sections.
- **Do** run the red margin line down every ledger, with the row number or time to its left.
- **Do** say why a thing is blocked, in words, next to the thing.
- **Do** keep every touch target at 44px or more and every row at 56px or more on a phone.
- **Do** take the words Learner, Class, Term and their kin from the division's vocabulary.

### Don't:
- **Don't** round a corner. Radius is 0.
- **Don't** use a shadow, a blur, a gradient or a floating card.
- **Don't** fill a navigation row to show where the user is. Use the edge.
- **Don't** use red for anything but absent, blocked, refused or wrong.
- **Don't** use marigold or teal as decoration.
- **Don't** show a state by colour alone.
- **Don't** set a sentence in display type, or a label in a decorative face.
- **Don't** show a nursery family a mark, a rank or another child.
