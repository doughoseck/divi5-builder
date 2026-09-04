# Divi 5 research digest — 2026-07 (for the divi5-builder skill)

Compiled from official Divi Developer Docs, elegantthemes.com release notes/help,
and the community reference 16wells.github.io/divi-docs. Tags: **[confirmed]**
official; **[likely]** official-but-indirect/community; **[verify-live]** must dump a
real VB-created instance before trusting exact JSON. Divi 5 moves weekly — re-check
periodically.

## Status
- Divi 5 is **GA since 2026-02-26**; current **5.9** (2026-07-13), **weekly** updates.
  5.9 shipped **Variable Fonts + CSS Grid Editor**. Target 5.9 as stable. [confirmed]

## Reality-checks vs our lived experience (our evidence wins)
- Community "playbook" says REST-*created* blocks are **not VB-editable** (only
  *modifications* to VB-made blocks are). **Contradicted live:** the author opened the
  a fully REST-built page in the Visual Builder and edited every card. So
  correctly-serialized, placeholder-wrapped block markup IS builder-editable on a
  real 5.9 site. Keep the skill's promise; treat that claim as stale/malformed-input.
- Our model is confirmed authoritative: every Divi 5 module is a **WordPress dynamic
  block** (`dev.elegantthemes.com/docs/.../anatomy-of-module`); buckets are
  **content / decoration / advanced**; values are per-breakpoint `{bp}.value` with
  hover under `{bp}.hover`. [confirmed]
- Canonical schema source for any new module = its **`module.json`** and the official
  examples repo **github.com/elegantthemes/d5-extension-example-modules**. [confirmed]

## Layout — flex fractions vs 100%+flexWrap vs CSS Grid
- ET's **documented** native responsive method = **fractional Column Class per
  breakpoint** (e.g. `1/3` desktop → `1/2` tablet → `1/1` phone) + Layout Wrapping.
  Notably the author's own VB-edited Starter card used fractional `flexType` per breakpoint
  (`8_24` desktop → `24_24` phone), matching this — not our "100%+flexWrap" default. [confirmed/observed]
- **NEW: CSS Grid Editor (5.9)** on Section/Row/Column/Group. **Container-level and
  child-agnostic** → add/remove/duplicate/**loop** items freely; true 2-D (span,
  overlap, dense). Responsive = change **Number of Columns per breakpoint**
  (e.g. 4/2/1). Best tool for **card grids, pricing tiers, galleries, loops**. [confirmed]
- Guidance for the skill: equal content rows → fractional Column Class per breakpoint;
  structured/2-D/looped grids (our pricing!) → **CSS Grid**; keep 100%+flexWrap only
  for dynamic-count auto-reflow. (Decision pending with the author.)

## Breakpoints — up to 7, default 3
Order: phone, phoneWide, tablet, tabletWide, **desktop (base, no media query)**,
widescreen, ultraWide. Only **Desktop/Tablet/Phone on by default**; the four others
are opt-in per site (wide ones use `max-width`). **Only emit values for breakpoints
the target site has enabled**; never hard-code pixel widths (site-configurable). [confirmed/[verify-live] key spellings for widescreen/ultraWide]

## Interactions — full vocabulary (we only used toggleVisibility)
- Triggers: Click, Mouse Enter/Exit, Viewport Enter/Exit, **Load**, **Breakpoint
  Enter**; support delays + stacking. [confirmed]
- Effects: Toggle Visibility, Show, Hide, Toggle/Add/Remove **Preset**, Toggle/Add/
  Remove **Attribute**, Toggle/Add/Remove **Cookie**, **Scroll To Element**, **Mirror
  Mouse Movement**. [confirmed]
- Wiring: one Trigger + one Effect + one Target (picked by **Admin Label** — so always
  set adminLabels in generated specs). Many-to-one = multiple interactions naming the
  same target (exactly our toggle). ET's Interactions doc uses **pricing tables** as the
  canonical example. Preset-toggle is a cleaner re-theme effect than attribute-swap. [confirmed]

## Canvases (popups/off-canvas/portals)
- Detached **Canvas** (Global = sitewide reusable, or Local) appended on the front end
  when an Interaction targets it. **Canvas Portal** injects canvas content inline. This
  is the officially-blessed popup/off-canvas primitive — offer it as default for native
  popups. (For **self-hosted video autoplay-on-open**, our WPCode `<video>`+play() snippet
  still wins — Divi's video module won't auto-play on show.) [confirmed]

## Globals → "Design Variables" (D5 rename)
- 7 variable types: Numbers, Strings, Images, Links, Colors, Gradients, Fonts. Emitted
  as dynamic-content `$variable({...gcid-...})$` tokens (what we already write). Managed
  in the Variable Manager; a Variable Generator can build relative palettes / fluid
  `clamp()` scales. [confirmed]
- **REST: reference-only.** No documented route/option to CREATE or map gcid→hex via
  REST; storage key unconfirmed. Keep referencing existing gcid tokens; do NOT try to
  create palettes via REST — set them in the builder. [likely / verify-live]

## Native modules we don't emit yet (add; verify keys live before trusting)
Highest value: **`divi/pricing-tables`** (native pricing — we hand-built it!), **`divi/tabs`**,
`divi/toggle`, `divi/call-to-action`, `divi/testimonial` (⚠ its innerContent renders as
PLAIN TEXT, not HTML), number/circle/bar counters. Also new standalone `divi/heading`
and `divi/hero`. Full set is 70+ modules. Confirm each module's content keys by dumping
one VB-created instance (community key tables are self-flagged unverified / possibly
D4-legacy). [confirmed names for pricing-tables/tabs; rest [inferred]/[verify-live]]

## Other mechanics worth adopting
- **Semantic Elements** (assign proper HTML tag per element) — use for a11y instead of
  generic divs. [confirmed]
- Loop Builder + Conditions (display logic) + Dynamic Content + ACF (incl. repeaters)
  all shipped — future capability for data-driven sections. [confirmed]
- Keep: native-modules > code; globals/presets > per-module overrides; lean specs.
  Divi 5's architecture explicitly rewards this philosophy. [confirmed]
