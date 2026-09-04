# Divi 5 serialization format (ground truth)

Divi 5 abandoned Divi 4's shortcodes. A Divi 5 page's `post_content` is
**WordPress block markup** — HTML block comments named `wp:divi/*`, each
carrying a nested-JSON attributes object. This is what you POST to
`/wp-json/wp/v2/pages` in `content` (raw). Divi renders it server-side, and the
Divi Visual Builder can edit every native module afterward.

Everything below was extracted from real, working pages on a live Divi 5 site
(a published homepage and a native draft), not from docs. Trust it.

## Block grammar

```
<!-- wp:divi/NAME {ATTRS_JSON} -->
  ...inner blocks or nothing...
<!-- /wp:divi/NAME -->
```

Rules learned from live pages:
- A Divi 5 page **must start with** `<!-- wp:divi/placeholder -->` (self-closing,
  no attrs, no closing tag). Without it the builder can misbehave.
- Nesting is **section › row › column › module**. Modules never sit directly in a
  section or row.
- **Every** block (except `placeholder`) carries `"builderVersion":"5.9.0"` (or
  whatever the site is on — read it from an existing page; homepage was `5.9.0`,
  older draft `5.0.0-public-beta*`; either renders. Use the site's current one).
- Attrs are single-line JSON. Content-bearing values are HTML strings.

### Comment-safe JSON escaping (critical)

The attrs JSON lives inside an HTML comment, so `<`, `>`, `&` (and `'`) must be
hex-escaped or they break block parsing (`-->` could appear, tags could close
the comment). WordPress's `serialize_block` uses `wp_json_encode` with
`JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`. Replicate at minimum:

```
JSON.stringify(attrs)
  .replace(/</g,'\\u003c').replace(/>/g,'\\u003e')
  .replace(/&/g,'\\u0026').replace(/'/g,'\\u0027')
```

`scripts/divi.js` does this for you. Structural `"` stays as `"`; string-internal
`"` may be `\"` (valid JSON, parses fine) — Divi's own output uses `"`, both work.

## Responsive + styling convention

Almost every visual value is wrapped per breakpoint and per state:

```json
"decoration": { "spacing": { "desktop": { "value": { "padding": {...} } } } }
```

- Breakpoints: `desktop`, `tablet`, `phone`. Set `desktop`; add others only to override.
- Styling lives under `module.decoration.*`
  (`spacing`, `border`, `background`, `boxShadow`, `sizing`, `layout`) and
  `module.advanced.*` (e.g. `text.text.<bp>.value.orientation` for alignment).
- Free-form CSS: a sibling top-level `"css": { "<bp>": { "value": { "mainElement": "..." } } }`.

## Global colors (`$variable(...)$`)

The theme's global colors are referenced, not inlined:

```
"$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$"
```

(after escaping, `"` → `"`). `settings` may carry `{"opacity":50}`. Known
example gcids: `gcid-primary-color`, `gcid-secondary-color`, and hashed ones
like `gcid-5k6eyl31lg`, `gcid-txdazhvcvl`, `gcid-un0o5t9ffp`. There is no public
REST endpoint that maps gcid→hex, so **discover** available gcids by scanning
existing pages (`node scripts/wp.js <site> global-colors`) and map by role, or
just emit literal hex from the brief. Prefer a global when one clearly matches.

## Native modules — exact content keys

The hard part is *where the actual content lives*. Verified keys:

| Module | Block name | Content location |
|---|---|---|
| Section | `divi/section` | container only; bg in `module.decoration.background` |
| Row | `divi/row` | container; column layout in `module.advanced.flexColumnStructure` |
| Column | `divi/column` | container; width in `module.decoration.sizing.flexType` |
| Text | `divi/text` | `content.innerContent.desktop.value` = HTML (`<h2>…</h2><p>…</p>`) |
| Button | `divi/button` | `button.innerContent.desktop.value` = `{text, linkUrl}` |
| Image | `divi/image` | `image.innerContent.desktop.value` = `{src, id, alt}` |
| Blurb | `divi/blurb` | `title.innerContent.desktop.value.text` + `content.innerContent.desktop.value` (HTML) + optional `imageIcon.innerContent.desktop.value{src,id,alt}` |
| Icon list | `divi/icon-list` | container for items |
| Icon list item | `divi/icon-list-item` | `content.innerContent.desktop.value` = text; `icon.innerContent.desktop.value` = `{unicode:"&#xf00c;",type:"fa",weight:"900",target:"off"}` |
| Code | `divi/code` | `content.innerContent.desktop.value` = raw HTML/CSS (escaped) |

### Headings
Headings are a **text module containing `<h1>`/`<h2>`** styled via settings (below),
not a separate heading module. `divi.js`'s `heading` spec item emits a `divi/text`
with clean `<hN>…</hN>` and the typography in `content.decoration.headingFont`.

## Styling = module SETTINGS + global variables (NEVER inline CSS)

Reverse-engineered from the author's own build. Content values are clean semantic HTML;
all appearance lives in `decoration` objects, and colours/fonts reference the
site's **globals** so one edit re-themes everything. **No `style="…"` anywhere.**

- **Text/heading typography** — under the block's `content.decoration`:
  ```json
  "headingFont":{"h1":{"font":{"desktop":{"value":{
     "family":"Outfit","weight":"800","size":"44px","letterSpacing":"1px",
     "color":"$variable({...gcid-heading-color...})$","capitalization":"uppercase"}}}}},
  "bodyFont":{"body":{"font":{"desktop":{"value":{"size":"18px","lineHeight":"1em"}}}}}
  ```
  Text alignment / light-dark scheme: `module.advanced.text.text.desktop.value`
  = `{orientation:"center", color:"dark"|"light"}`.
- **Button** — `button.decoration`: `font.font…value{color(global),size,weight}`,
  `background…value.color`(global), `border…value.radius`, `button…value.icon.enable`.
  Padding via `module.decoration.spacing.padding`.
- **Blurb** — icon via `imageIcon.innerContent.desktop.value{useIcon:"on",
  icon:{unicode,type:"fa",weight}}`; `imageIcon.advanced.placement` + `.decoration.
  sizing.iconFontSize`; title level via `title.decoration.font.font…value.headingLevel`;
  the icon "chip" via the module's Custom-CSS field `css.desktop.value.blurbImage`
  (a setting, not inline HTML).
- **Accordion** (native FAQ) — `divi/accordion` › `divi/accordion-item`
  (`title.innerContent…value`, `content.innerContent…value`,
  `module.advanced.open…value:"on"` to start open). Toggle colours/fonts via the
  accordion's `title`/`openToggle`/`closedToggle`/`closedToggleIcon` decoration.
- **Colours** everywhere use `gcid-*` global tokens; **fonts** use global family
  names (Outfit, Plus Jakarta Sans). Get gcids with `wp.js global-colors`.

### Columns: 100% + flex-wrap (NOT fixed fractions)
Do **not** set `1_2`/`8_24` fractional column widths. Every column is 100%
(`module.decoration.sizing.flexType:"24_24"`); the row defines the count and flex
lays them side-by-side, wrapping (stacking) at a chosen breakpoint:

| Layout | row `flexColumnStructure` | each column `flexType` | stacking |
|---|---|---|---|
| 1 col | `equal-columns_1` | `24_24` | — |
| 2 col | `equal-columns_2` | `24_24` | row `module.decoration.layout.<bp>.value.flexWrap:"wrap"` |
| 3 col | `equal-columns_3` | `24_24` | — same, wrap at `phone` (or `tablet`) |
| 4 col | `equal-columns_4` | `24_24` | — |

Default: nowrap desktop/tablet, `flexWrap:"wrap"` on `phone`. **Column-bg trick:**
give a column a background image (`module.decoration.background…image` with
`size:"cover"`) and an invisible `divi/divider` (line off, fixed `height`) to hold
the column's height, instead of an image module.

### Cards, hover states, and anti-jump (verified from the author's build)
- **A card is a styled column** (`background`/`spacing.padding`/`border.radius`/
  `border.styles.all`), not a wrapper div.
- **Hover states live under the BREAKPOINT**, beside `value`:
  `border.desktop.hover.styles.all.color`, `boxShadow.desktop.hover.{vertical,blur}`.
  A top-level `border.hover`/`boxShadow.hover` (outside `desktop`) renders **nothing** —
  a real gotcha. `divi.js` `col.hover:{borderColor, shadow:{blur,vertical,spread}}`
  emits the correct `desktop.hover` form.
- **Anti-jump:** a column has ONE `layout.rowGap` for all its children. When you
  toggle two stacked items on/off (CTA pair, control pair), the gap makes the
  layout shift. Fix: nest just those two in their **own row → column with
  `rowGap:"0px"`** (Divi allows a `divi/row` inside a `divi/column`), and set the
  control column's `rowGap` to `0px` as well.

## Minimal valid page (native)

```
<!-- wp:divi/placeholder -->
<!-- wp:divi/section {"builderVersion":"5.9.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"flexColumnStructure":{"desktop":{"value":"equal-columns_1"}}}},"builderVersion":"5.9.0"} -->
<!-- wp:divi/column {"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.9.0"} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<h1>Hello</h1><p>World</p>"}}},"builderVersion":"5.9.0"} -->
<!-- /wp:divi/text -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
```

`scripts/divi.js compile <spec.json>` generates all of this from a compact spec —
you should almost never hand-write block markup.

## Making a page actually render as Divi (the builder flag)

Writing the blocks above into `content` is necessary but **not sufficient**. A
page renders as Divi — full-width, no theme sidebar, module CSS enqueued, "Divi"
badge in the admin Pages list — only when it has the post-meta
**`_et_pb_use_builder = on`** (plus layout meta `_et_pb_page_layout`,
`_et_pb_side_nav`, `_et_builder_version`). Without it the theme renders the page
with its default sidebar template and the modules look unstyled/broken, even
though the blocks themselves DO server-render (verified: `content.rendered`
contains `et_pb_section` output regardless of the flag).

These are **protected `_et_*` meta**. Two REST facts confirmed live on
a live Divi 5 site (2026-07-22):
- **Core REST** exposes only `_acf_changed` and `footnotes` as writable page
  meta — no `_et_*`. A `meta:{_et_pb_use_builder:'on'}` write returns 200 but is
  silently dropped.
- **Divi's own REST routes** exist and are the "right" mechanism —
  `/divi/v1/outside-vb/posts/set-layout` (args `post_id`,
  `source_layout_post_id`, `layout_content`) is literally "enable the builder on
  this post from outside the VB". But every `divi/v1` route (even read-only GETs
  like `page-manager`) rejects with `{"code":"invalid_nonce"}`. They require a
  cookie-session nonce; an Application Password can't produce one that validates.
  Route-specific nonces from `/divi/v1/settings-data/nonces` don't help either.

**Solution used by this skill:** the bundled mu-plugin
`assets/divi5-builder-rest.php` registers those `_et_*` keys for REST behind an
`edit_post` capability check. Once it's in the site's `wp-content/mu-plugins/`,
`wp.js set-builder <id>` sets the flag via a normal core-REST meta write — no
nonce, no cookie, app-password-friendly. The mu-plugin only exposes existing meta
keys; it doesn't modify Divi.

## Popups: Canvas + Interaction (reverse-engineered, verified live)

A Divi 5 popup = a separate **`et_pb_canvas`** post (the popup layout) + an
**Interaction** on a trigger module (opens it). Both are REST-reproducible.

**Trigger (on the page).** A module's `module.decoration` carries:
```json
"interactionTrigger":"t<id>",
"interactions":{"desktop":{"value":{"interactions":[{
  "id":"t<id>i","enableInteraction":"on","trigger":"click","effect":"toggleVisibility",
  "target":{"targetClass":"et-interaction-target-<id>","label":"Popup","moduleId":"","targetType":"module"},
  "replaceExistingPreset":false,"sensitivity":50,"mouseMovementType":"translate",
  "cookieName":"","cookieValue":"","triggerClass":"et-interaction-trigger-t<id>","presetId":"","timeDelay":"0ms"
}]}}}
```
Divi renders this as `data-interaction-trigger="t<id>"` on the element — **but
only for buttons and similar, NOT `divi/text`** (text modules keep the attrs in
markup but Divi emits no trigger, so the click does nothing). Use a `divi/button`.

**Popup canvas (the `et_pb_canvas` post).** Content = one section with
`module.decoration.interactionTarget:"<id>"`, `disabledOn` on for
desktop/tablet/phone (hidden by default), `position.mode:"fixed"`,
`sizing` 100vh, `zIndex 9999999`, a semi-transparent `background.color`, and its
own click→`toggleVisibility` interaction (so clicking the overlay closes it).
Inside: a `divi/video` (`video.innerContent.desktop.value.src`) + a `divi/icon`
close button (`icon.innerContent.desktop.value{unicode:"&#x51;",type:"divi"}`,
positioned absolute).

**The page↔canvas link (the crux — it is META, not `post_parent`).** The builder
writes, and `wp.js link-canvas` replicates:
- on the **canvas**: `_divi_canvas_id` (a UUID), `_divi_canvas_parent_post_id`
  (the page id), `_divi_canvas_created_at`.
- on the **page**: `_divi_off_canvas_data` (serialized
  `{activeCanvasId:<uuid>, mainCanvasName:"Main Canvas"}`) + `_et_pb_use_divi_5:"on"`.
Divi appends the canvas to the page at render based on this. Without the link a
bare published canvas does **not** append (verified). These `_divi_*` meta are not
REST-writable directly, hence the mu-plugin's `link-canvas` route (≥ v1.2).

Divi's own `/divi/v1/*` REST routes (incl. `outside-vb/posts/set-layout`,
`page-manager`) all reject Application-Password auth with `invalid_nonce` — they
need a cookie session — which is why this skill uses the mu-plugin bridge instead.

---

## Global colours in Divi 5 — "Global Variables" (verified 5.9.0, 2026-08-11)

Reverse-engineered on a fresh a live Divi 5 site install. Every claim below was
measured on a rendered page, not inferred from markup.

### What resolves, and what silently does not

A colour reference in block markup looks like:

```
$variable({"type":"color","value":{"name":"gcid-yrk1yosmyh","settings":{}}})$
```

Divi resolves it by emitting CSS custom properties into the page:

```css
--gcid-primary-color: #2ea3f2;  --gcid-secondary-color: #2ea3f2;
--gcid-heading-color: #666666;  --gcid-body-color: #666666;
--gcid-link-color: #2ea3f2;     --gcid-yrk1yosmyh: #ffffff;
```

Two rules follow, and the second one is a trap:

1. **Five built-in slots exist** — `gcid-primary-color`, `gcid-secondary-color`,
   `gcid-heading-color`, `gcid-body-color`, `gcid-link-color`. Their NAMES are
   stable across sites and their VALUES are editable in the Variable Manager.
   A fresh install shows Divi's factory values (`#2ea3f2` / `#666666`), which is
   what first made them look unchangeable — they are not.
2. **An unknown gcid name resolves to nothing.** The property is simply never
   emitted, so `background-color: var(--gcid-surface)` computes to
   `rgba(0,0,0,0)` — a transparent section, no error, no warning. Inventing
   semantic names like `gcid-surface` or `gcid-background` produces a page that
   looks flat and gives no clue why.

### `et_global_colors` is Divi 4 and is IGNORED by Divi 5

Writing `et_global_colors` (the Divi 4 palette: `gcid => {color, active}`)
changes nothing. Measured: with that option holding `gcid-primary-color =
#2B5C7A`, a section bound to `gcid-primary-color` still rendered `#2ea3f2`.

`et_divi_global_variables` exists as an option but stayed `a:0:{}` even after
three variables were created and saved in the Variable Manager. The definitions
are NOT in that option, NOT in post meta, and NOT a custom post type
(`wp/v2/types` shows none). They live in an option VALUE or a custom table —
note the mu-plugin's `postinfo --scan` matches option NAMES only, so it cannot
find them; a value search would be needed to go further.

### The practical workflow — EDIT THE FIVE, don't invent names

Do not try to create global variables over REST, and do not invent gcid names.

1. In the Visual Builder's **Variable Manager**, edit the five built-in colours
   to the brand's values. Their names are stable, so a spec written against
   `gcid-primary-color` themes correctly on any site set up this way.
2. Bind specs to those names: `{"colorVar":"gcid-primary-color"}`.
3. Only reach for CUSTOM variables where the five are genuinely not enough.
   Those get GENERATED ids (`gcid-yrk1yosmyh`) which cannot be chosen — to use
   one, apply it once in the builder, save, then read the id off the rendered
   page (the `--gcid-<random>` custom properties in the inline `<style>`) and
   record the name→id map in the project repo. Per site, unavoidably.

Verified on a live Divi 5 site, which does exactly this: `--gcid-primary-color:
#133c0b`, `--gcid-secondary-color: #e5c052` — edited built-ins, brand colours,
stable names.

### Buttons: use a module PRESET, not per-button styling

the author's rule, and it is the better pattern: create one button preset (e.g.
"Yellow button"), apply it to every button, and restyle every button on the site
by editing the preset. Presets live in `et_divi_builder_global_presets_d5` and
can reference gcids inside them, so preset + global colour compose — one edit
re-themes everything. Styling buttons individually, even with globals, gives up
half the benefit.

### When to skip globals

Only when the five built-ins have not been set up on that site yet. A gcid that
is not defined renders as NOTHING — invisible until someone looks at the live
page — whereas a literal hex is always what was asked for.
`a live Divi 5 site/terms-and-privacy` shipped on literal hex for exactly that
reason, and should move onto the five once they are set.

---

## Compound modules are CONTAINERS, not leaves (verified live 2026-08-19)

the author rebuilt the a live Divi 5 site hero by hand and it exposed a wrong assumption
in this skill: `fullwidth-header` was treated as a leaf whose only slots are
`title`, `content`, `buttonOne`, `buttonTwo`. **It is a container.** Arbitrary
modules nest between its open and close tags:

```html
<!-- wp:divi/fullwidth-header {…} -->            ← OPEN tag, note: no slash
  <!-- wp:divi/image {…} /-->                     ← nested child
  <!-- wp:divi/text  {…} /-->                     ← nested child
<!-- /wp:divi/fullwidth-header -->
```

**The tell is in the markup you already have:** a module serialized with a
plain `-->` opener and a matching `<!-- /wp:divi/… -->` closer accepts
children. One serialized `/-->` (self-closing) does not. Before assuming a
module is a leaf, `dump-blocks` a page that uses it and look at which form
Divi wrote.

**Children render AFTER the module's own title/content/buttons**, in document
order. Verified on the live homepage — rendered byte offsets ran hero title
(667) → nested badge image (1319) → nested text line (1822).

`mHero` now takes `modules: [...]`, same shape as `mGroup`.

### Removing a built-in button

The visual builder does **not** delete `buttonOne` when you remove the hero's
button — it empties it:

```json
"buttonOne":{"innerContent":{"desktop":{"value":{"text":"","linkUrl":"","linkTarget":"on"}}},
             "decoration":{ …styling left behind… }}
```

Nothing renders (`et_pb_button_one` is absent from the rendered HTML), and the
decoration survives so re-adding a button keeps its styling. So to drop a
built-in button from a spec, pass `button1:{text:'',url:''}` rather than
omitting the key — that matches what the builder itself writes.

### Image module: link, sizing, alt

- **Link** lives inside the image value, not on the module:
  `image.innerContent.desktop.value.linkUrl` + `linkTarget`.
- **Sizing/alignment — the builder and this compiler disagree.** The builder
  writes `module.advanced.sizing.desktop.value.maxWidth` and
  `module.advanced.align.desktop.value`. `mImage`'s `frame.maxWidth` writes
  `module.decoration.sizing…`. Both render. Prefer the builder's `advanced`
  form when editing a page a human also edits, so a round-trip doesn't churn.
- **Alt/title get written twice** by Divi 5.11: once in the image value, and
  again as `module.decoration.attributes.attributes[]` entries carrying
  `targetElement:"image"` — the builder's Attributes feature. Writing only the
  value form is fine; just don't be surprised by the duplication.

### ⚠ `builder-version` reports a STALE version

`wp.js builder-version` "scans first page that has a divi block for its
builderVersion" — so it reports whatever was stamped on **existing content**,
not the installed Divi. On a live Divi 5 site it reported `5.9.0` while the
builder was stamping new modules `5.11.0`. After a Divi upgrade it will keep
handing you the old number indefinitely.

Not fatal — an older `builderVersion` on a block still renders — but if you
need the real version, check a module a human edited recently rather than
trusting this command.

### Content values are JSON strings — a raw `"` destroys the module SILENTLY

Verified the hard way 2026-08-20, on three live pages. Module content lives in
a JSON string inside an HTML comment, so **every** `<`, `>`, `"` and `&` in the
content must be escaped (`<`, `>`, `"`, `&`) — not just the
tags you are adding, but anything inside them.

Appending `<a href="/page/">text</a>` with the quotes left raw terminated the
attribute JSON early. Divi did not error: the module simply **stopped
rendering**, taking the paragraph that was already there with it. The page
still returned 200 and still looked broadly fine, which is exactly why it is
worth a guard.

When writing content programmatically: escape the whole string, ampersand
first (or you double-escape the escapes), and assert the result contains no
bare `<`, `>` or `"` before writing. Then check the RENDERED page for the text
you added — a successful `update-page` proves nothing about whether the module
survived.
