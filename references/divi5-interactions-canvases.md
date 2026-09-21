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
