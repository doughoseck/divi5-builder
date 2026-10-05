# Divi 5 interactions and canvases

Read from the Divi 5.13.1 source and proven on a live 5.13 site (2026-09-21). Where something was only read and not
run, it says so. Source paths are relative to the Divi theme folder.

## Measure before you build popups with interactions

On one converted Divi 4 site (Divi 5.13.1, 172 blocks per page) every page took **14 to 17 s** as soon as ANY interaction existed
anywhere in the page, header or footer, and 5 to 6 s without. Measured cause: Divi's own front-end block parser
(`ET\Builder\FrontEnd\BlockParser\BlockParser`) cost about 10 ms PER BLOCK there (1.7 s for one parse of page + header + footer;
WordPress core's parser did the same bytes in 2 ms; an attribute-free copy was just as slow; no plugin was attached to the parser
hooks; the server's CPU was normal), and with interactions present `DynamicAssets::pre_initial_setup` and
`FrontEnd::enqueue_global_numeric_and_fonts_vars` each re-parse the whole content three times through
`OffCanvasHooks::extract_interaction_target_ids_from_content()`. A second site (Divi 5.9, other host) showed no such cost, so this is
NOT universal: **measure it.**

1. Time a page 3 times (use the 3rd) before adding the first interaction, and again after. `scripts/hook-profiler.js` names the slow
   callbacks if it jumps.
2. If it jumps, do not use interactions on that site. The fallback keeps everything else native:
   - the popup is a normal Divi section (in the page, or in the Theme Builder header for a site-wide one), NOT `disabledOn`;
   - hidden by its own free-form CSS: `selector:not(.open) { display: none; }` and `.et-fb selector:not(.open) { display: flex; }`;
   - triggers and the section carry a custom attribute (`data-menu="open"` / `data-menu="popup"`, see the format reference);
   - a code module INSIDE the popup holds the few lines that toggle `.open` on click, close on Escape, on the overlay and on the X,
     and ignore links whose href is `#` (sub-menu parents). The script lives with the markup it drives.
   - give the close icon a `zIndex`: a full-width first menu link otherwise covers it and the click goes to that link.

## Interactions

An interaction is "when THIS happens to the trigger element, do THAT to the target element". Both ends are plain
attributes under `module.decoration`, available on 111 of 115 modules (every module except `global-layout`,
`map-pin`, `signup-custom-field`, `shortcode-module`).

| Attribute | On | Meaning |
|---|---|---|
| `module.decoration.interactionTarget` = `"<id>"` | the target | Front end adds class `et-interaction-target-<id>` and `data-interaction-target="<id>"` |
| `module.decoration.interactionTrigger` = `"<id>"` | the trigger | Front end adds `data-interaction-trigger="<id>"` |
| `module.decoration.interactions.desktop.value.interactions` = `[ … ]` | the trigger | The list of effects this trigger fires |

Ids: letters and digits only. Divi recovers a missing trigger id with `/et-interaction-trigger-([a-zA-Z0-9]+)/`
(`includes/builder-5/server/Packages/Module/Module.php`, around line 519), so a hyphen or underscore would break it.

**Any module can be a trigger, not only buttons.** The trigger attribute is printed by the generic module wrapper
(`Module.php` lines 502-536), not by the button. Proven live: a `divi/text` trigger toggled a hidden panel both ways.
(On Divi 5.9 text modules did not render as triggers. That limit is gone in 5.13. On an older site, check first.)

### One interaction (every field Divi reads)

`includes/builder-5/server/Packages/Module/Options/Interactions/InteractionsScriptData.php`, lines 146-172:

```json
{ "id": "tmenui0", "enableInteraction": "on",
  "trigger": "click", "effect": "toggleVisibility",
  "target": { "targetClass": "et-interaction-target-mainmenu", "label": "Popup", "moduleId": "", "targetType": "module" },
  "triggerClass": "et-interaction-trigger-tmenu",
  "timeDelay": "0ms",
  "attributeName": "", "attributeValue": "",
  "cookieName": "", "cookieValue": "",
  "presetId": "", "replaceExistingPreset": false,
  "sensitivity": 50, "mouseMovementType": "translate",
  "breakpointName": "" }
```

**Triggers (8):** `click`, `mouseEnter`, `mouseExit`, `viewportEnter`, `viewportExit`, `load`, `breakpointEnter`,
`breakpointExit` (the last two use `breakpointName`).

**Effects (14):**

| Effect | Builder label | Uses |
|---|---|---|
| `toggleVisibility` / `addVisibility` / `removeVisibility` | Toggle Visibility / Show Element / Hide Element | target |
| `togglePreset` / `addPreset` / `removePreset` | Toggle / Add / Remove Preset | `presetId`, `replaceExistingPreset` |
| `toggleAttribute` / `addAttribute` / `removeAttribute` | Toggle / Add / Remove Attribute | `attributeName`, `attributeValue` |
| `toggleCookie` / `addCookie` / `removeCookie` | Toggle / Add / Remove Cookie | `cookieName`, `cookieValue` |
| `scrollToElement` | Scroll To Element | target |
| `mirrorMouseMovement` | Mirror Mouse Movement | `sensitivity` 0-100, `mouseMovementType`: `translate` `scale` `opacity` `tilt` `rotate` |

There is no `showElement` or `hideElement`: show is `addVisibility`, hide is `removeVisibility`.

Proven live: `click` + `toggleVisibility` (both directions), `click` + `addAttribute` (the attribute appeared on the
target). Read but not run: every other trigger and effect.

Useful pairings, none of them needing JavaScript:
- popup or mega menu: button `click` → `toggleVisibility` on a hidden section (in the same canvas or another one);
- "stuck header" styling: a sentinel element `viewportExit` → `addAttribute` (or `addPreset`) on the header,
  `viewportEnter` → `removeAttribute`. This is the native replacement for a jQuery scroll-class script;
- show-once notices: `click` → `addCookie`, paired with a display condition on that cookie;
- one trigger, many targets: repeat the interaction object per target.

### Compiler support

Any module built through `applyCommon` (heading, text and the modules that accept `toggleId`), and `button`:

```json
{ "type": "text", "html": "<p>Open</p>", "triggerId": "openmenu",
  "interactions": [ { "trigger": "click", "effect": "toggleVisibility", "target": "mainmenu" } ] }
```

`target` is the other element's `toggleId`; `targets: [...]` repeats the effect. Extra keys: `attributeName`,
`attributeValue`, `cookieName`, `cookieValue`, `presetId`, `replaceExistingPreset`, `timeDelay`, `breakpoint`,
`sensitivity`, `mouseMovementType`. Unknown trigger or effect names stop the compile with the allowed list. The older
button-only shortcuts (`popup`, `toggles`, `scrollTo`, `togglePreset`) still work. For a `raw` module, write the
`module.decoration` attributes above by hand.

## Canvases

A canvas is a second (third, …) block document that belongs to a page or a Theme Builder layout: post type
`et_pb_canvas`, exposed by core REST at `/wp/v2/et_pb_canvas`. It is what popups, off-canvas menus and mega menus
are built from.

### How a canvas is attached

Read from a canvas made in the Visual Builder on a Theme Builder HEADER, and identical to a page-level popup on
another site:

| Where | Meta | Value |
|---|---|---|
| canvas | `_divi_canvas_id` | a uuid |
| canvas | `_divi_canvas_parent_post_id` | the id of the page, **or of the header/body/footer layout** |
| canvas | `_divi_canvas_created_at` | ISO date |
| canvas | `_divi_canvas_append_to_main` | empty, or `above` / `below` |
| canvas | `_divi_canvas_z_index` | empty or a number |
| parent | `_divi_off_canvas_data` | serialized `{activeCanvasId: <uuid>, mainCanvasName: "Main Canvas"}` |

`post_parent` stays 0: the link is meta only. A canvas with NO parent id is a **global canvas**, usable from any
layout (read in `OffCanvasHooks.php`, not yet tried).

### When a canvas reaches the front end

`includes/builder-5/server/VisualBuilder/OffCanvas/OffCanvasHooks.php`, `detect_and_process_off_canvas_interactions()`
(line 1567) and `_process_off_canvas_content_for_targets()` (line 1964):

1. While rendering, Divi looks at every Divi block's `module.decoration.interactions`.
2. Targets that live in the SAME document are ignored. Nothing is appended for them.
3. For a target that lives in another canvas, that canvas's content is appended to the page.
4. Separately, a canvas whose `append_to_main` is `above` or `below` is always output.
5. Theme Builder is handled: `_get_theme_builder_layout_post_id()` (line 1886) resolves the layout being rendered,
   so a canvas whose parent is a header is found while that header renders.

Consequence, confirmed live: a canvas that nothing targets is **not in the page at all**. A freshly made header canvas
was absent from two front-end pages until something pointed at it. So "my popup is missing" usually means the trigger's
target id does not match an `interactionTarget` inside the canvas.

### Building a popup

1. Canvas content: one section that is the overlay: `interactionTarget` = the popup id, hidden by default
   (`disabledOn` on every breakpoint), `position` fixed, full height, high `zIndex`. `divi.js compile-canvas` emits
   exactly this, including click-the-overlay-to-close.
2. `wp.js create-canvas`, then `wp.js link-canvas <canvas_id> <parent_id>`. For a header popup the parent is the header
   layout's id (writing meta on a layout was read from the source and from a builder-made example; the skill's
   `link-canvas` against a layout id has not been run yet).
3. Trigger: any module with an interaction whose target is the popup id.
4. `wp.js list-canvases | get-canvas <id> [--raw | --out f] | update-canvas <id> --content-file f` to work on it after.
5. Check the front end: the page HTML must now contain `et-interaction-target-<id>`.

A canvas attached to a header is output on every page that header serves. Treat writing to it as a site-wide change.

## Loop Builder on an accordion (proven 2026-09-25, Divi 5.13.1, a converted site)

One `divi/accordion` holding ONE `divi/accordion-item` whose `module.advanced.loop` is enabled repeats the item per
queried post (each rendered item gets `data-loop-item` / `data-loop-source="<loopId>"`). Bind the title to
`loop_post_title`. **There is no post-content dynamic-content option** (only excerpt, which strips HTML): put the
body in a custom field (ACF WYSIWYG) and bind `loop_post_meta_key_manual_custom_field` with
`select_loop_meta_key:"loop_post_meta_key_<metakey>"` and **`enable_html:"on"`** (default `off` escapes the HTML).
Taxonomy filter shape: `includePostWithSpecificTerms:[{categoryId:"<taxonomy>",categoryName:"…",selectedOptions:[{value:"<termId>",label:"…"}]}]`.
`postPerPage` defaults to 10; a CPT without `page-attributes` never stores `menu_order`, so order by `date`.
Interaction triggers: a `divi/column` works as a click trigger (bubbled clicks from its children fire it). A trigger
with two effects (`toggleVisibility` A + `removeVisibility` B) works. If a click seems dead, watch the target with a
MutationObserver: a legacy jQuery `slideToggle` bound to an old class on the same element makes it open-then-close.
Bulk post saves over REST clear Divi's static-CSS cache on every save and can leave a page's dynamic CSS file 404'd:
finish the import, then do a no-op `css-set` and load the page once.
**Animating a reveal:** `toggleVisibility` shows the target instantly (no slide), but right after showing it Divi runs
`et_animate_element` on the target's own **Animation** setting (`module.decoration.animation.desktop.value =
{style:"slide",direction:"top",intensity:{slide:"2"},duration:"500ms",delay:"0ms",speedCurve:"ease-in-out",repeat:"once",startingOpacity:"0%"}`),
so set that on the hidden section for a slide/fade-in. Hiding stays instant. Accordion styling notes: the toggle icon
cannot be switched off, make it invisible with `closedToggleIcon` colour `rgba(0,0,0,0)` + `useSize:"on",size:"1px"`;
Divi already puts a 10px gap between items, so item margin 0; the title `lineHeight` attr is overridden by Divi's own
toggle CSS (stays 1.4em).
**Accordion all-closed by default (Divi 5.13 still forces item 0 open, no setting):** put a static, empty
`divi/accordion-item` FIRST with `css.desktop.value.mainElement = "display:none !important;"`, then the real (or looped)
items. Server marks the dummy `et_pb_toggle_open et_pb_toggle_empty`, every real item renders `et_pb_toggle_close`.
**Custom toggle icon:** the module's Custom CSS `freeForm` works on pages: `selector .et_pb_toggle_title:before
{ content:"" !important; display:inline-block !important; position:static !important; width/height; background:url(..)
center/contain no-repeat !important; font-size:0 !important }` and `selector .et_pb_toggle_open .et_pb_toggle_title:before
{ transform:rotate(180deg) !important }`. **Accordion Border setting styles every ITEM**, not the list: use a 1px
`divi/divider` (line color/weight under `divider.advanced.line.desktop.value`) above the module for a single rule line.
Module width/centring: `module.decoration.sizing.desktop.value = {maxWidth:"850px", alignment:"center"}` renders
`max-width` + `margin-left/right:auto` (proven on accordion and divider). Zeroing a module's default border needs
`styles.all.width:"0px"` alongside the one side you want (`styles.bottom`), or Divi's default 1px top border stays.
Divi 5 gives `.et_pb_toggle_title` a 50px left padding for its icon; with a custom `:before` icon zero it in the same
freeForm CSS (`selector .et_pb_toggle_title { padding-left:0 !important }`), it is not exposed as a module setting.

## Flip cards without a plugin (replacing Supreme `[dsm_flipbox]`), verified on a converted site, 2026-09-28, 5.13.1
Divi 5 has no flip module. Recipe: in the tile column put the photo (`divi/image`, forceFullwidth), an optional
absolute overlay (`divi/divider` line off, sizing 100%/100%, background rgba), then TWO sibling `divi/group`s with CSS
classes (`decoration.attributes.desktop.value.attributes = [{id, name:"class", value:"flip-face flip-front"}]`) holding
the front modules (heading + text) and the back modules (image + heading). The column's Custom CSS does the flip:
`selector { perspective:1000px }`, `selector .flip-face { position:absolute; top:50%; left:50%; width:100%;
transform:translate(-50%,-50%); backface-visibility:hidden; transition:transform .6s ease-in-out }`,
`selector .flip-back { transform:translate(-50%,-50%) rotateY(180deg) }`, `selector:hover .flip-front { transform:
translate(-50%,-50%) rotateY(-180deg) }`, `selector:hover .flip-back { transform:translate(-50%,-50%) rotateY(0) }`.
Axis per Supreme's `flipbox_effect`: left/right = rotateY (sign flips), up/down = rotateX; the plugin default is
"right". Groups inside a column render fine and Divi emits the column's `selector` CSS once per column. Column
`position:relative` + `overflow:hidden` keeps the faces inside the tile. Verify transforms with transitions disabled
(`*{transition:none!important}`) when the browser pane is not painting, or the computed transform reads as identity.
The same column doubles as an interaction trigger (click -> toggleVisibility on a hidden section) with no conflict;
add `cursor:pointer` in the column CSS because a trigger column gets no `et_clickable` class.

**Reveal groups: one open at a time.** A trigger's `interactions.desktop.value.interactions[]` takes several entries
that all fire on the same click, so give each tile `toggleVisibility` on its own section plus `removeVisibility` on
every other section of the group (ids `<trigger>i0`, `i1`, ...). Verified 5.13.1 (a page with six reveal tiles):
click 1 -> 2 -> 6 leaves only the last section open; removeVisibility on an already-hidden target is a no-op. Without
this, users end up with several sections stacked open, which the old jQuery reveal scripts never allowed.

**"Scrolled" header state without JS (a converted site, 2026-09-28, 5.13.1).** Divi's sticky styles refuse position
absolute/fixed (`StickyUtils` incompatible_positions), and a fixed header must stay fixed to overlay the hero. Native
alternative: a zero-net-height sentinel section first in the header layout (`sizing.height:"50px"`, margin-bottom
`-50px`, transparent, Custom CSS `selector { pointer-events:none }`) carrying `interactionTrigger` with
`viewportExit -> addAttribute` (`attributeName:"data-scrolled"`, `attributeValue:"1"`) and `viewportEnter ->
removeAttribute` on each fixed section (`interactionTarget`). The scrolled look is the section's own Custom CSS:
`selector[data-scrolled] { ... !important }` plus `transition` on the rest state. Effect names for attributes are
`addAttribute` / `removeAttribute` / `toggleAttribute`; the value is a space-separated token list on that attribute.
`viewportExit` fires when the element is fully out (IntersectionObserver default threshold). VERIFYING: IO callbacks
never fire in the desktop app's browser pane, and Chrome does not deliver them in a hidden/background tab -- so test
the EFFECT + CSS half without the observer: `window.et_execute_interaction_effect(interaction, targetEl, 'add'|'remove')`
with an entry from `window.diviElementInteractionsData` (disable transitions first), and leave the observer half to
the user's eyes. Header sections: Divi emits `.et-l--header > .et_builder_inner_content .et_pb_section.<order>
{ background-color: transparent !important }` (0,4,0), so a scrolled-state background needs a stronger,
placement-independent selector such as `body selector.et_pb_section.et_pb_section--fixed[data-scrolled]` (0,4,1);
padding/width/logo rules have no such competitor. Fixed header sections also need an explicit `zIndex` (e.g. 100):
Divi's frontend can move them to `<body>`, where with z-index auto the page hero paints over them.

**Scroll to the revealed section: native effect `scrollToElement`** (frontend `script-library-interactions.js`:
`target.scrollIntoView({behavior:"smooth", block:"start", inline:"nearest"})`, same attr shape as the visibility
effects, target = the section's `interactionTarget` class). Put it AFTER `toggleVisibility` in the trigger's list;
the show sets `display:block !important` inline synchronously, so the target has a position when the scroll runs.
The effect list per trigger is therefore: toggle own, remove others, scrollToElement own. A fixed header did NOT
need a `scroll-margin-top` on the target in practice (one site, 169px fixed header: the site owner tested the
offset and had it removed), so do not add one unprompted; offer it only if the landing looks wrong. Verify with an
instant `scrollIntoView` in a non-painting browser pane: smooth scrolling never progresses there (not even
`window.scrollTo({behavior:"smooth"})`), so scrollY staying at 0 proves nothing.

## Popups: stacking, click outside, Esc (proven 2026-09-29, Divi 5.13.1)

**A popup opens UNDER the header on the first load of a page, and is fine after a reload.** Two facts cause it:
1. Divi wraps each Theme Builder area in `.et_builder_inner_content` with its own z-index (`header` box 2, the others
   1). A popup canvas is printed inside the page or footer box, so its own z-index (however high) never beats the
   header. The fix is a few lines of script that move every popup to be a direct child of `<body>`.
2. After ANY save Divi clears its CSS cache. On the first load of each page it prints the module CSS INLINE AT THE
   BOTTOM of the body (`<style id="et-core-unified-…-cached-inline-styles-2">`), below footer scripts; from the second
   load on it is a file in the `<head>`. A script that asks `getComputedStyle(el).position === 'fixed'` gets "no" on
   that first load, skips the popup, and the popup opens under the menu with its close button unreachable.
**Rule: a front-end script must never decide anything from computed styles at load time.** Read what is in the HTML:
Divi puts the class `et_pb_section--fixed` on every fixed section. To test a fix, clear the cache with a no-op
`css-set` and load the page ONCE: that load is the cold one (`style[id*="cached-inline-styles"]` exists in the body).

**Close by clicking beside the video: native.** Make the popup's overlay section (the interaction target) a click
trigger as well, with ONE effect, `removeVisibility` on itself (`interactionTrigger:"bg<target>"` + `interactions`).
Safe because Divi's click handler calls `stopPropagation` (the close button's click never reaches the section), a
click inside a YouTube/Vimeo iframe never reaches the page, and `removeVisibility` on a hidden target does nothing.
Only for popups that hold nothing but a video and a close button: in a menu popup a click on a parent menu item, and
in a form popup a click in a field, would close it.

**Divi stops the video itself** whenever an interaction hides a target: `<video>` is paused and a YouTube/Vimeo/
Dailymotion/Facebook iframe gets its `src` emptied and set again 100 ms later, without `autoplay`. No script needed.

**Esc: no native trigger.** A `keydown` listener that, for every visible popup, clicks the popup's own close trigger
(`[class*="et-interaction-trigger-close"]`), so Divi closes it the normal way. Name close triggers `close<Target>`.

**Logged-in users:** a popup fixed to the top ignores the admin bar. Inline `top`, `height`, `min-height` and
`max-height` with `!important` (the popup's own `min-height:100vh` otherwise runs past the bottom of the screen).
Detect the bar by the `admin-bar` class on `<body>`, which is there from the start; `#wpadminbar` may be printed later.

**The canvas settings "append to main canvas" and z-index do not solve the stacking**: the z-index goes on the canvas
wrapper, which is still inside the page or footer box.

## Who owns a popup canvas, and what it costs (2026-10-01)

- A canvas belongs to the post in `_divi_canvas_parent_post_id`: a page, or a Theme Builder layout. Owned by the
  global footer layout it is available on every page.
- **Ownership makes no measurable difference to speed.** Divi prints a canvas only on a page where something targets
  it, whoever owns it. Timed on five pages with 16 extra video popups owned by the footer and then by one page (6
  loads each, first 2 dropped): within noise on every page, and none of the 16 was in any other page's HTML. So own a
  popup by the page that uses it, and keep site-wide ones (the menu) on the header or footer.
- **What does cost:** the page that uses them. 16 popups + a 21-item timeline added about 0.9 s of server time to
  that page. YouTube players inside hidden popups are not loaded at page load when a lazy loader holds iframes back.
- `link-canvas <canvas> <post>` also rewrites the POST's `_divi_off_canvas_data` (the builder's note of the last
  open canvas). On a header or footer layout that is a site-wide layout's meta: ask the user first. Linking a canvas
  to a footer and back leaves that note pointing at the last canvas linked.
- **A popup's overlay colour may not come from its preset.** The preset's background rule is written as
  `.et_builder_inner_content .et_pb_section.preset--…`. A popup that a script moves to `<body>` (to sit above the
  header) is no longer inside that wrapper, so the rule stops matching and the overlay turns white. Give the overlay
  its colour in site CSS by the preset's class, `.et_pb_section.preset--module--divi-section--<id> { background-color:
  … !important }`, not by a list of popup names: new popups then need no CSS.
- **One popup per video** is the native pattern: clone a working popup canvas, change the target name and the video.
  A button inside any module (a timeline card, for example) can be the trigger.

## A dropdown in the header that closes on a click elsewhere (verified 2026-10-01, Divi 5.13.1)

Built as a search dropdown under a magnifier icon; the pattern fits any small panel. All native interactions, in the
same column as the trigger:

| Element | Hidden at start | Role |
|---|---|---|
| Open icon (`divi/icon`) | no | target `xOpen`; click: show panel, show layer, show close icon, hide itself |
| Close icon (X) | yes | target `xShut`; click: hide panel, hide layer, hide itself, show open icon |
| Click-outside layer (`divi/divider`, line hidden) | yes | target `xBg` AND a click trigger: hide panel, layer and close icon, show open icon. Custom CSS: `position: fixed; top:0; left:0; width:100vw; height:100vh; z-index:5` (and `selector:before{display:none}`) |
| Panel (here a `divi/search`) | yes | target `x`; Custom CSS: `position:absolute; top:calc(100% + 12px); right:0; width:360px; max-width:86vw; z-index:6` |

Lessons, each one met the hard way:

- **Use `addVisibility` / `removeVisibility`, not `toggleVisibility`, when several elements must stay in step.** A
  toggle reads the element's current state; while a fade-in was still at opacity 0 it read "hidden" and showed the
  panel again, leaving the panel open with the icons saying closed. Explicit show/hide cannot drift.
- **Divi's Animation setting moves an absolutely positioned element sideways while it plays** (here 59 px left, then
  a jump back when the animation ends). For such an element use a CSS animation in its own Custom CSS instead:
  `selector { animation: panelIn .35s ease-out; } @keyframes panelIn { from { opacity:0; transform:translateY(-14px);} to { opacity:1; transform:none; } }`.
  It replays on every show because the element goes from `display:none` to shown. `@keyframes` is accepted in the
  `freeForm` field. (Divi's Animation is fine for a normal in-flow row or section.)
- Hidden-by-default works on any module, not only sections: `disabledOn` on every breakpoint + an `interactionTarget`.
- The layer is inside the fixed header and still covers the window (`position: fixed` works there; checked at the top
  of the page and scrolled). Give the icons `position:relative; z-index` above the layer if they must stay clickable.
- Name the layer's trigger without the word "close" unless the site's Esc script should click it: that script clicks
  `[class*="et-interaction-trigger-close"]` inside visible popups.
- No new speed cost on a site that already uses interactions in its header.

## A strip above a sticky header that scrolls away (built 2026-10-05, Divi 5.13.1)

Wanted: an announcement strip (date, logo, button) above the header; the strip scrolls out with the page, the header
then sticks to the top as before. The header was a `position: fixed` section whose "scrolled" look came from a small
sentinel section with two native interactions (`viewportExit` -> `addAttribute data-scrolled` on the header,
`viewportEnter` -> `removeAttribute`).

- **Let the strip BE the sentinel.** Put the strip first in the header layout as a normal (not fixed) section, move
  the sentinel's two interactions onto it and delete the sentinel. The moment the strip has left the screen is exactly
  the moment the header must stick.
- **Header not fixed until then**, in the header section's own Custom CSS:
  `body selector.et_pb_section:not([data-scrolled]) { position: absolute !important; top: auto !important; }`.
  Absolute with `top: auto` keeps the header at its place in the flow, right under the strip and over the hero as
  before; when `data-scrolled` arrives Divi's own fixed position applies again. No script.
- The strip's height may differ per breakpoint (stacked on a phone it is much taller): nothing to configure, the
  trigger is the strip itself.
- Centre item truly centred between a left and a right item: three columns, the outer two
  `flex: 1 1 0 !important; width: auto !important; min-width: 0`, the middle one `flex: 0 0 auto`; right column
  `justify-content: flex-end`. Phone stack in the row's Custom CSS: `flex-direction: column`, columns `width: 100%`,
  `order: -1` on the one that goes first, text centred.
- A logo exported at 1x looks soft on dense screens: ask for 3x (or SVG) and show it at the design height with CSS;
  WordPress then serves a fitting size through `srcset`.

**What cannot be verified from an automated browser:** viewport triggers. Divi's `viewportEnter`/`viewportExit` use
IntersectionObserver, which does not fire while the page is hidden (`document.visibilityState === 'hidden'`): true
for the built-in browser pane when it is not displayed AND for a background tab in the user's Chrome. Scrolling by
script then changes nothing and proves nothing. Verify the two halves you can (layout at each width; set the
attribute by hand and check the header is fixed at top 0), say plainly that the trigger itself is unverified, and
ask the user to scroll once on desktop and phone. Same family as: autoplay and CSS animations in a hidden pane.

**Matching a design you only have as a screenshot:** measure it. Take the content width in the screenshot and in the
page, scale, and compare element WIDTHS (text run width via a Range, logo, button), not guessed font sizes. On this
build the first guess (site scale: 32px / 15px, logo 76px) was visibly off; the measured values (28px / 13px, logo
94px, button text 18px) were approved at once. Check the user's own screenshot for display scaling first (a 1900px
wide capture of a 1536px viewport is 125%). And when sizes are corrected for desktop, do not touch a breakpoint the
user has already approved: ask, or change desktop only.

## Third-party gallery module: file names on hover

A dynamic-gallery module with the overlay on and an EMPTY "overlay data" list still prints each image's title (the
file name) in the overlay: the empty list is dropped on save and the module's default shows the title. Hide it in the
module's Custom CSS (`selector .dp-ddg-title { display: none; }`); the overlay must stay on when a click opens the
lightbox.
