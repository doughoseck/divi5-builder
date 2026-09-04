---
name: divi5-builder
description: >
  Build or edit Divi 5 pages on any WordPress site via the WP REST API — using
  native Divi 5 modules so pages stay editable in the Divi visual builder, with
  a custom-HTML "code" module fallback. Use whenever the user wants to create,
  build, edit, redesign, or lay out a page/section/hero/pricing/landing page on a
  Divi 5 WordPress site, set a page as the
  homepage, insert sections/rows/columns/modules, upload images to the media
  library, or "build a page from this design brief". Handles credentials from
  the shared .web-creds.txt securely. Trigger even if the user just says "add a
  section to the site", "update the homepage", or names a site + a page change.
argument-hint: "[site] [what to build] (e.g. mysite 'a pricing section')"
allowed-tools: Bash, Read, Write, Edit
---

# Divi 5 site builder

Create and edit Divi 5 pages through the WordPress REST API. Divi 5 stores pages
as WordPress block markup (`<!-- wp:divi/* -->`), so you build a page by writing
that markup into `content` and POSTing it. This skill gives you a **spec → markup
compiler** (`scripts/divi.js`) and a **secure REST client** (`scripts/wp.js`) so
you rarely touch raw markup or credentials directly.

Default strategy (agreed with the author): **native modules first** — real
`divi/section/row/column/text/heading/button/image/blurb/icon-list` modules that
stay fully editable in the visual builder. Fall back to a `divi/code` module only
for a section whose layout the native set can't express.

## Styling rules — NON-NEGOTIABLE

These come straight from how the author builds; follow them exactly. The point of Divi is
that everything is editable in the builder and re-themeable from one place — inline
CSS and hard-coded per-module values destroy that.

1. **NEVER use inline CSS.** No `style="..."` attributes in any module's HTML
   content — ever. It's the hardest thing to find and edit and it defeats the
   builder. Module content holds clean **semantic HTML only** (`<h1>`, `<h2>`,
   `<p>`, `<ul>`…). Style through **module settings** (the `decoration.*` objects
   the compiler emits), never through markup. `scripts/divi.js` enforces this — do
   not hand-write blocks that reintroduce inline styles. (The one exception is a
   `code` module, which is intentionally raw HTML/CSS; keep those rare.)
2. **Type & colour come from GLOBAL variables/presets, not literals — and DON'T
   set what you don't need to.** Reference global colours by gcid name
   (`{"color":"gcid-heading-color"}`, `bg:"gcid-secondary-color"`) and global font
   families by name (`"Outfit"`). Discover gcids with `wp.js global-colors`.
   **Critical:** only set a typography prop when it must DEVIATE from the module's
   default/preset. Assigning a value — *even one equal to the global default* —
   pins it on the module, so when the global/preset changes later, that module
   **won't update**. So a body paragraph that should just inherit gets **no**
   `family`/`size`/`color` at all (omit them); a heading that only needs a
   different weight+colour sets *just* those two, not `family`/`size`. Lean spec =
   fewer overrides = the site stays re-themeable from one place. That's the goal.
3. **Layout: fractional flex for rows, CSS Grid for grids** (verified 5.9, updated).
   - **Simple content rows → fractional flex (the compiler default).** Columns get
     a per-breakpoint `flexType` (1/3 desktop → 1/2 tablet → full phone), ET's
     documented native method. Just `{"layout":"1-1"|"1-1-1"|"1-1-1-1", …}` — the
     compiler sets the fractions + wrap breakpoints. (`mode:"flexwrap"` = the old
     100%-columns+wrap, for dynamic-count reflow.)
   - **Card / pricing / gallery / looped grids → CSS Grid.** Put `grid` on the row:
     `{"grid":{"cols":{"desktop":3,"tablet":2,"phone":1},"spans":[{"target":"first-child","span":2}]}, "columns":[…]}`. Child-agnostic (add/remove/loop freely).
     ⚠ a `span` rule does NOT reset on mobile — a spanned item won't collapse to 1
     column on phone; avoid spans if you need clean mobile stacking.
   - Still never hand-write `1/2`/`1/3` or inline widths — use these spec fields.
4. **Reach for the right native module.** `blurb` for icon+title+text cards
   (icon chip via the blurb's Custom-CSS field, not inline), `button` styled via
   its settings + global colours, `accordion` for FAQs (never a code module),
   `divider` for spacing. A common trick: an invisible `divider` (line off, fixed
   height) holds a column's height so a **column background image** can `cover`
   the space — set `bgImage` on the column instead of using an image module.
   **Cards = a styled column**, not a wrapper div: set `background`/`padding`/
   `radius`/`border` on the column, and `hover` (`{borderColor, shadow:{blur}}`)
   for a hover lift — hover states serialize under a `hover` key beside `desktop`.

## Prerequisites — required before you can do anything

Do not start building until **all three** are true for the target site. Confirm
them first (step 2 below checks them); if any is missing, stop and tell the user
exactly which — building without them produces a page that can't be written,
renders broken, or is in the wrong format entirely.

0. **The site must be on Divi 5.** This skill emits Divi 5 **block** markup
   (`wp:divi/*`); **Divi 4 uses `[et_pb_*]` shortcodes — a totally incompatible
   format**, so building on a Divi 4 site produces garbage. Check with
   `node scripts/wp.js <site> divi-check` → must report `verdict:"divi5"`. If it
   says `divi4`, STOP — the site must be upgraded to Divi 5 first (Divi 4 stores
   pages as `[et_pb_*]` shortcodes, which this skill cannot produce).

1. **Access details in `~/.web-creds.txt` (or `$WEB_CREDS_PATH`)** — a `[site]` section with
   `url`/`user`/`pass` (a WordPress **Application Password**). No creds → no API
   access at all. `node scripts/wp.js <site> whoami` must return 200 + your name.

2. **The `divi5-builder-rest.php` mu-plugin installed & active on that site**
   (bundled in `assets/`; drop it in `wp-content/mu-plugins/`). This is what makes
   a page actually render as Divi — without it you can POST block content but you
   **cannot** set the `_et_pb_use_builder` flag (protected meta; core REST drops
   it and Divi's own routes reject app-password auth with `invalid_nonce`), so the
   page stays a non-Divi sidebar page. Confirm with
   `node scripts/wp.js <site> whoami` **and** a probe write: if `set-builder`
   later reports the meta "did NOT stick", the plugin isn't active — installing it
   is a one-time step per site. **A new site needs BOTH set up before first use.**

## Credentials — handle exactly like this

Site credentials live in `~/.web-creds.txt` (override with `$WEB_CREDS_PATH`; INI: `[site]` sections
with `url`/`user`/`pass`; `pass` is a WordPress **Application Password**).

- **Never** print, echo, log, or hardcode the password — not in chat, not in a
  script, not in a tool argument, not in output. `scripts/wp.js` reads the file
  at runtime, builds the auth header in memory, and only ever prints results.
  Always go through it; never read the raw pass yourself into the transcript.
- Site name is the section header (e.g. `mysite`, `clientsite`). Confirm which
  site if ambiguous.

## Workflow

1. **Identify the site and intent.** Which `[site]`? New page, or edit an
   existing one? Get the design brief (or a site-specific design skill/brief for
   colours, fonts, voice).

2. **Check BOTH prerequisites + detect the site's format (do this first):**
   ```bash
   node scripts/wp.js <site> whoami            # prereq 1: 200 + your name = creds OK
   node scripts/wp.js <site> divi-check        # prereq 0: verdict:"divi5" (STOP if "divi4")
   node scripts/wp.js <site> check-plugin      # prereq 2: pluginActive:true = Divi mode possible
   node scripts/wp.js <site> list-pages        # find target page ids/status
   node scripts/wp.js <site> builder-version   # use this builderVersion in the spec
   node scripts/wp.js <site> global-colors     # discover gcid global-color IDs
   ```
   If `whoami` fails → creds missing/wrong (see Prerequisites). If `check-plugin`
   reports `pluginActive:false` → stop and have the user install
   `assets/divi5-builder-rest.php`; you can build block content but the page won't
   render as Divi until it's active.

3. **Decide colours the "globals if present, else brief" way.** If a `global-colors`
   gcid clearly matches a brand role you need (primary/secondary/etc.), reference it
   in the spec via `{"colorVar":"gcid-..."}` so the page inherits the theme. Otherwise
   emit literal hex from the brief with `{"color":"#RRGGBB"}`. When unsure of a gcid's
   actual hue, prefer literal hex — a wrong global is worse than a right literal.

4. **Author a page spec** (compact JSON) rather than raw block markup. The spec
   shape and every module type are documented in the header of `scripts/divi.js`.
   Quick reference:
   - Sections carry `background` (`color` / `colorVar` / `image`+`gradientOverlay`)
     and `padding`. Rows carry `layout` (`"1"`,`"1-1"`,`"1-1-1"`,`"1-1-1-1"`) and
     `columns`. Columns carry `modules`.
   - Modules: `heading`, `text` (HTML), `button` (`text`+`url`), `image`
     (`src`+`alt`+`id`), `blurb` (`title`+`html`+`icon`), `iconlist`
     (`items`+`icon`), `code` (raw HTML/CSS fallback), and `raw` (escape hatch to
     emit any `divi/*` block with hand-written attrs).
   - Set `builderVersion` to what step 2 reported.
   - For anything native can't do, use a `code` module — it's a first-class Divi
     module, not a hack, and renders reliably.

5. **Compile and preview locally:**
   ```bash
   node scripts/divi.js compile <spec.json> --out content.html
   ```

6. **Write to the site.** Default to a **draft** first when building something new,
   so you can verify before it's public:
   ```bash
   node scripts/wp.js <site> create-page --title "..." --content-file content.html --status draft
   # editing an existing page instead:
   node scripts/wp.js <site> update-page <id> --content-file content.html
   ```
   Publishing, or changing an existing live page, is a visible change — get the
   user's OK before flipping a page to `publish` or editing a live page.

7. **Flip the page into "Divi mode" (essential — do this for every page you build).**
   Writing Divi blocks into `content` is NOT enough on its own: a page only
   renders as Divi (full-width, no theme sidebar, module CSS enqueued, "Divi"
   badge in the Pages list) when it carries the meta `_et_pb_use_builder = on`.
   Without it the theme wraps the page in its default sidebar template and the
   modules look unstyled/broken.
   ```bash
   node scripts/wp.js <site> set-builder <id>            # layout defaults to no-sidebar
   node scripts/wp.js <site> set-builder <id> --layout et_full_width_page
   ```
   **Prerequisite (one-time per site):** these are protected `_et_*` meta that
   core REST won't write and Divi's own REST routes gate behind a cookie-session
   nonce (an Application Password can't satisfy it). So the site must have the
   bundled `assets/divi5-builder-rest.php` installed (drop it in
   `wp-content/mu-plugins/`), which exposes those meta keys to REST behind an
   edit-capability check. If `set-builder` reports the meta "did NOT stick", the
   mu-plugin isn't installed — point the user at `assets/divi5-builder-rest.php`.
   Verify what's set with `node scripts/wp.js <site> page-meta <id>`.

8. **Verify it renders (don't just trust the POST).** Confirm the blocks
   round-tripped and Divi rendered them as native modules:
   ```bash
   node scripts/wp.js <site> get-page <id> --raw   # blocks preserved?
   node scripts/wp.js <site> rendered <id>          # real Divi HTML, no leaked <!-- wp:divi comments
   ```
   Rendered output should contain the actual text/module wrapper classes
   (`et_pb_section` etc.) and none of the literal block comments. If block
   comments leak into the rendered HTML, the markup was malformed — check escaping
   and nesting against `references/divi5-format.md`.

9. **Homepage (only if asked).** `node scripts/wp.js <site> set-homepage <id>`
   sets `show_on_front=page`. This changes the live front page — confirm first.
   `reset-homefront` reverts to the blog index.

10. **Report** the page id + link and what you built. Offer next edits.

## Images

Reuse a media URL already in the library when you can. To add a new one:
```bash
node scripts/wp.js <site> upload-media <path/to/img.png> --alt "description"
```
It prints `{id, url}` — put the `url` in an image module's `src` and the `id` in
`id` (Divi likes both). Get the user's OK before uploading their files anywhere.

## Popups (Divi 5 Canvas + Interaction)

> Note: for video/lightbox popups the author usually prefers a dedicated popup **plugin**
> — don't default to building one. The Canvas approach below is a proven,
> plugin-free capability; offer it, but only build it when the user actually wants
> a native Divi popup.
>
> **Self-hosted (.mp4) video popup with autoplay-on-open:** neither the Canvas nor a
> YouTube-only popup plugin can do this — a self-hosted clip needs a native
> `<video>` + a `video.play()` call fired from the user's click (a user gesture,
> so it plays *with sound*; page-load autoplay would be blocked). Use the ready
> reusable snippet **`assets/video-popup-snippet.html`** (a WPCode "HTML Snippet",
> Site-Wide Footer). It's generic: any element with `data-tsm-video="<mp4 url>"`
> (optional `data-tsm-poster`) opens+autoplays it; on a Divi 5 button set that via
> Advanced ▸ Attributes and link the button to `#`.

A Divi 5 popup is a separate **Canvas** (an `et_pb_canvas` post) triggered by an
**Interaction** on a button — fully reproducible over REST. Pattern:

1. **Compile the popup canvas** — a full-screen hidden overlay section:
   ```bash
   node scripts/divi.js compile-canvas <popup-spec.json> --out popup.html
   ```
   `popup-spec.json` = `{ "targetId":"tourpop", "overlayColor":"rgba(8,32,20,.92)",
   "width":"min(1000px,90%)", "modules":[ {"type":"video","src":"...mp4","frame":{...}},
   {"type":"icon","unicode":"&#x51;","iconType":"divi","absolute":true} ] }`.
   The compiler makes the section hidden-by-default (`disabledOn` all breakpoints),
   `position:fixed`, `100vh`, `zIndex 9999999`, overlay bg, `interactionTarget`
   = targetId, and click-overlay-to-close.
2. **Create the canvas post:** `node scripts/wp.js <site> create-canvas --title "..." --content-file popup.html`
3. **Add a trigger** on the page: a module with `"popup":"<targetId>"`. **It must be
   a `button`** — `divi/text` modules carry the interaction attrs but Divi does
   **not** render interactions on them; only buttons (and similar) emit the
   `data-interaction-trigger` the front-end JS binds to. To style the button to
   look like something else, target `a[data-interaction-trigger="t<targetId>"]` in
   a `code`-module `<style>` (a stable selector — no custom-class needed).
4. **Link the canvas to the page** (essential — without it Divi won't append the
   canvas): `node scripts/wp.js <site> link-canvas <canvas_id> <page_id>`. This
   writes the meta the builder uses (`_divi_canvas_parent_post_id` on the canvas +
   `_divi_off_canvas_data` on the page). Requires the mu-plugin ≥ 1.2.
5. **Verify:** `rendered <page_id>` should now contain the canvas content (e.g. the
   video `src`) appended, plus `et-interaction-target-<targetId>` and the button's
   `data-interaction-trigger`.

The link is by meta, not post_parent. One popup per page via `link-canvas` as
written (single `_divi_off_canvas_data` pointer). Full serialization in
`references/divi5-format.md`.

## Visibility toggles (monthly/annual pricing, "show more", etc.)

Do these **natively with Interactions**, never with hand-rolled JS. Pattern (from
the author's pricing toggle): split the content into **state-A** and **state-B** element
sets; give every toggle-able element a unique `interactionTarget` id; start
state-B elements `disabledOn` all breakpoints (hidden). Provide **two small
control buttons** ("show monthly" / "show annual") — one per state, the inactive
one also `disabledOn`. Each control button carries an `interactions` array with a
`click → toggleVisibility` effect for **every** target class in BOTH sets (incl.
the two controls themselves), so one click flips the whole group. The real CTA
buttons are separate (one per state, with the matching link, e.g.
`?cycle=monthly` vs `?cycle=annual`) and toggle too. `module.decoration`
carries `interactionTarget`, `interactionTrigger`, and `interactions.desktop.value.
interactions[]`; see `references/divi5-format.md` for the exact shape. (Only
buttons/interactive modules render triggers — a `divi/text` won't.)

**Compiler support (use these props — no hand-wiring):**
- Any module: `"toggleId":"<id>"` makes it a toggle **target**; `"hidden":true`
  starts it hidden (`disabledOn` all breakpoints) — the "state B" elements.
- A `button`: `"toggles":["idA","idB",…]` (+ optional `"trigger":"<id>"`) makes it a
  **control** that flips every listed target on click. Give both control buttons
  the **same full target list** (include both controls' own ids so they swap too).
- Cards: style the **column** — `background`/`padding`/`radius`/`border` +
  `"hover":{"borderColor":"gcid-…","shadow":{"blur":"18px"}}` for a hover lift.
- **Anti-jump (important):** when you toggle two stacked elements (e.g. the two
  CTA buttons, or the two control buttons), put them in their **own nested
  row/column with `rowGap:"0px"`** so hiding one doesn't shift heights. In a spec,
  nest with a `row` module: `{"type":"row","layout":"1","columns":[{"rowGap":"0px",
  "modules":[btnMonthly, btnAnnual]}]}`. Give the control-buttons column `rowGap:"0px"`
  too. (A column has one rowGap for all children, so isolate the zero-gap pair.)

A 9-tier pricing toggle can be generated this way: each card has
monthly/annual price `text` + a nested `rowGap:0` row holding monthly/annual CTA
`button`s (annual ones `hidden`), each with a unique `toggleId`; two control
buttons carry `toggles` = all 38 ids.

## Prefer native modules — code/HTML is the LAST resort

Build with native Divi modules **wherever you can**, and lean hard toward native
before reaching for a `code` module. Almost everything that looks like it "needs
HTML" doesn't: a pricing table is `blurb`/`text`+`button` cards in flex columns; a
feature grid is blurbs; an FAQ is a native `accordion`; tabs/toggles are the
Toggle/Tabs modules; icon rows are `icon-list`. Pure HTML in a `code` module is
un-editable in the builder, un-themeable from presets, and brittle — treat it as a
genuine last resort for something with no native equivalent (a bespoke SVG
animation, a third-party embed). When you do use one, keep it small and isolated.

**Pitfall (learned the hard way):** never split interdependent JS/CSS across
separate `code` modules. A pricing monthly/annual toggle's `<script>` was bundled
into a *different* section's code module; when that section was later rebuilt
natively, the code module — and the script — vanished, silently breaking the
toggle. Any `<script>`/`<style>` must live in the **same** `code` module as the
markup it drives. Better still: do it natively so there's no loose JS to lose.
(And interactive toggles are exactly the kind of thing to keep native or in a
dedicated plugin rather than hand-rolled JS.)

## Module inventory & data-driven (verified 5.9)

The compiler emits these module `type`s (all verified from live builds; keys in
`references/divi5-modules-verified.md`):
- **Content:** heading, text, button, image, blurb, icon, iconlist, code, video, divider.
- **Native compound:** accordion, tabs, toggle, cta, testimonial, pricing (pricing-tables),
  counters (bar), circle-counter, number-counter, countdown, heading-module.
- **Batch 2:** hero (`fullwidth-header`, `fullHeight:true` = fullscreen), slider (+slides),
  person (`team-member`; image uses `url`), map (+pins), tooltip, timeline (+items),
  post-carousel (`fullwidth-portfolio`), group + group-carousel.
- **Layout:** section/row/column; row `mode` fractional (default) / flexwrap; row `grid`.
- **Compound modules are CONTAINERS.** `hero` takes `modules:[…]` and nests them
  inside the header, rendering below its own title/content — that is how you put
  a linked image or an extra line of copy in a hero (verified live). The tell is
  the serialization: a module written with a plain `-->` opener plus a
  `<!-- /wp:divi/… -->` closer accepts children; a `/-->` self-close does not.
  `dump-blocks` before assuming any module is a leaf. To drop a built-in button,
  pass `button1:{text:'',url:''}` — emptying is what the builder itself does.
  ⚠ `builder-version` reads the FIRST EXISTING BLOCK's stamp, not the installed
  Divi, so it goes stale after an upgrade. Both detailed in the format reference.
- **Third-party:** `filtergrid` (Divi Plugins `dp-dfg/filtergrid` — requires that
  commercial plugin installed). Query-driven CPT grid with built-in content/video
  popups + skins; verified props + a raw `settings:{}` passthrough for any option.

**Data-driven (Loop Builder + dynamic content):**
- Put `loop:{ postTypes:["belt"], orderBy, order, postPerPage }` on a **column** or a
  **group** → it repeats per queried post.
- Bind fields with the `dc()` helper in any content value: `dc('loop_post_title')`,
  `dc('loop_post_featured_image',{thumbnail_size:'large'})` (as image `src`),
  `dc('loop_post_excerpt')`, and **custom fields**
  `dc('loop_post_meta_key_manual_custom_field',{select_loop_meta_key:'loop_post_meta_key_<key>'})`.
- Common pattern: a `group-carousel` whose `group` has a `loop` + `dc()`-bound modules
  (looped CPT cards), or a grid column with `loop`.

**Adding any other module:** build it once in the VB on a scratch page,
run `node scripts/wp.js <site> dump-blocks <id>` to read its exact keys, then add a
builder — 2-minute loop. Don't guess; dump.

## Reference

`references/divi5-format.md` — the Divi 5 block serialization format, exact
per-module content keys, escaping rules, column-structure presets, and a minimal
valid page. Read it if you need to hand-write a `raw` module or debug rendering.
It was reverse-engineered from real Divi 5 pages and verified by a live
round-trip (build draft → confirm native render → delete), so trust it over
general web docs.

## Safety notes

- One shared creds file, many sites — always pass the right `<site>`.
- Prefer draft-first; treat publish, live-page edits, homepage changes, and media
  uploads as visible actions needing a nod from the user.
- Clean up throwaway/test pages with `delete-page <id> --force`.
