# Divi 5 Components and Slots (Divi 5.15.0+, released 2026-10-09)

Read from the Divi 5.15.1 source (`includes/builder-5/server`, paths below are relative to it) and
compared with 5.13.1, 2026-10-12. Source of the feature list: the Elegant Themes release post
"Introducing Components and Slots for Divi 5" and `changelog.txt` (5.15.0, line 42 on).
**CONFIRMED** = read in the code. **LIVE** = seen on a real site (a live site, Divi 5.15.1,
2026-10-12: a section Component with text, image, button and a Slot, placed twice on a draft page, heading text and image unsynced, heading overridden on copy 2, text in copy 2's Slot).
**INFERRED** = follows from the code but not seen yet. Reading, rendering, placing an instance and setting
per-copy values over REST are PROVEN ("Write tests" below); creating/editing a Component over REST is not:
it needs a mu-plugin route that does not exist yet.

Naming clash: in the rest of this skill a "slot" is a **preset slot** (`groupPreset` key). A Divi
**Slot** is the new `divi/global-slot` module described here. Say "Slot module" when you mean the new one.

## What it is

Components are Divi's old **Global Elements renamed and extended** (changelog: "Global modules are now
components"). A Component is a saved design (a module, row, section or a nested layout) whose
instances stay in sync with it. What 5.15 adds:

- **Selective sync everywhere:** any attribute of any nested module can be unsynced. An unsynced
  ("detached") attribute INHERITS the Component's value until an instance overrides it; resetting
  returns it to inheriting. Before 5.15 an unsynced field could be left empty on the instance.
- **Detach a nested module** (it becomes local to that instance, reattach to undo), or **detach a whole
  instance** (it becomes plain local modules).
- **Slot module:** a free-form area inside a Component whose contents are per instance. A Component can
  hold several Slots; a Slot can hold other Components (no circular references).
- **Nesting:** Components inside Components.
- **Instance mode / Component mode** in the builder: local edits are saved with the page; editing the
  Component changes every instance.
- **Local presets** on an instance, stacked on the Component's presets.
- Components Manager, import/export (also inside exported layouts and canvases), preloading, and the
  Divi AI Agent can create, convert, edit and delete them.

## Where a Component lives (CONFIRMED)

- A post of type **`et_pb_layout`** (the Divi Library) with the `scope` term **`global`**
  (`Packages/GlobalLayout/GlobalLayout.php:37-57`). No new post type, no new meta keys.
- **LIVE (a section Component made in the 5.15.1 builder, read from its export):**
  meta `_et_pb_built_for_post_type: page`, **`_et_pb_template_type: section`**, `_et_pb_use_divi_5: on`;
  terms `scope: global`, `layout_type: section`, `module_width: regular`. (The code also knows
  `_et_pb_module_type`, `_et_pb_row_layout` and Divi 4's `_et_pb_excluded_global_options`; this
  builder-made Component had none of them.) New option `_et_pb_component_manager_order` (the Manager's order).
- Content: block markup in `post_content`, wrapped in `divi/placeholder`; the first block is the root
  (here `divi/section`) and has **no** `syncNodeId`.
- **Every NON-root node carries a `syncNodeId`** (uuid). Instances match their snapshot children to the
  source by it (by position only as a fallback).
- **The unsynced fields are listed on the node they belong to** (LIVE), as `localAttrsMap`:
  - "Make editable on instance" on a Text module's content →
    `"localAttrsMap":[{"attrName":"content.innerContent"}]` on that text block.
  - the same on an Image →
    `"localAttrsMap":[{"attrName":"image.innerContent","subName":"src"}]`: **only the image URL** is
    unsynced. Alt text and the media id stay synced, so an instance with its own image keeps the
    Component's alt text unless that is unsynced too.
  - `attrName` is the attribute group, `subName` one key inside its value; the instance's override PATH
    is the full leaf (`content.innerContent.desktop.value`, `image.innerContent.desktop.value.src`).
- `"localChildren": false` appeared on the column and the button after the Elements-list cube icon was
  used in Component mode; `true` would mean "children are per instance". Not yet clear which click set
  `false` (the cube is a toggle).
- **`et_pb_layout` is not in the core REST API** (`public=>false`, no `show_in_rest`), and Divi's own
  `divi/v1/divi-library`, `/save`, `/create-item` routes need the `X-ET-Nonce` header, so an Application
  Password alone cannot read or write a Component. Same wall as Theme Builder: needs a mu-plugin route.

**Export** (Components Manager > Import & Export Components, LIVE): the classic Divi Library file,
`{"context":"et_builder_layouts","data":{"<post id>":{post_title, post_content, post_type:"et_pb_layout",
post_meta, terms, …}},"presets","global_colors","global_variables","canvases","images","thumbnails"}`,
with each image embedded as base64 under `images` (a 1-image Component made a 157 KB file). No
`components` key in this export; that key is for page/layout exports that carry the Components they use.
An export is the easiest way to READ a Component's source today, since REST cannot.

## What an instance looks like in a page (CONFIRMED keys, LIVE example below)

Block **`divi/global-layout`**:

| Attribute | Meaning |
|---|---|
| `globalModule` | The Component's post id **as a string**. Divi detects Components with the regex `"globalModule":\s*"(\d+)"` (`FrontEnd/Assets/DetectFeature.php:490`): an integer is missed |
| `blockName` | The real block, e.g. `divi/section`, `divi/text` |
| `builderVersion` | as usual |
| `localAttrs` | a FULL snapshot of the instance's attributes (not only the overrides) |
| `localAttrOverridePaths` | sorted list of exact paths this instance owns, e.g. `module.decoration.background.desktop.value.color` |
| `syncNodeId` | only when this instance is itself nested inside another Component |

Inner blocks (when not self-closing) are a snapshot of the children, each keeping its `syncNodeId` and
its own `localAttrOverridePaths`. A child with **`"localModule": true`** is a detached nested module:
its own attributes replace the source's entirely. `localModulePreset` / `localGroupPreset` stack local
presets on top of the source's.

**The merge rule** (`FrontEnd/BlockParser/BlockParserStore.php:367-468`): a value from `localAttrs`
is used ONLY if its path is in `localAttrOverridePaths` AND the Component's `localAttrsMap` allows that
field. Everything else comes from the Component, read fresh from the database at render. A missing
`localAttrOverridePaths` (older content) is inferred from `localAttrs` + the map.

**Slot module:** `divi/global-slot` with `slotId`. The Component holds an empty Slot shell; the
instance's snapshot holds a `divi/global-slot` with the same `slotId` and that instance's content
inside it, grafted in at render (`Packages/ModuleLibrary/GlobalSlot/GlobalSlotUtils.php:31-93`). An
empty Slot renders nothing; otherwise it renders like a Group.

**LIVE, what the builder saved** (copy 2 of the test, long attribute values shortened with `…`):

```
<!-- wp:divi/global-layout {"globalModule":"4321","blockName":"divi/section","builderVersion":"…","localAttrs":{"module":{…the section's attributes…}},"localAttrOverridePaths":[]} -->
<!-- wp:divi/row {…,"syncNodeId":"f9fd1724-e880-4b0c-b832-29e0d28ec81f","localAttrOverridePaths":[]} -->
<!-- wp:divi/column {…,"syncNodeId":"62ec9f31-…","localAttrOverridePaths":[]} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<h1>Second Heading</h1>"}}},"builderVersion":"5.15.1","syncNodeId":"e243282e-…","localAttrOverridePaths":["content.innerContent.desktop.value"]} /-->
<!-- wp:divi/image {…,"syncNodeId":"9803343d-…","localAttrOverridePaths":[]} /-->
<!-- wp:divi/button {…,"syncNodeId":"6ccd4512-…","localAttrOverridePaths":[]} /-->
<!-- wp:divi/global-slot {"builderVersion":"5.15.1","slotId":"2f95d1d0-9d3c-403c-af37-d1120e1762ef","localAttrOverridePaths":[]} -->
<!-- wp:divi/text {"content":{…"slot content"…},"builderVersion":"5.15.1","localAttrOverridePaths":[]} /-->
<!-- /wp:divi/global-slot -->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/global-layout -->
```

What the live markup settles:
- **An override lives on the node that owns it.** Overriding the heading put
  `"localAttrOverridePaths":["content.innerContent.desktop.value"]` on the nested **text block**;
  the outer `global-layout` list stayed `[]`. Paths are relative to that node's own attributes.
- **Every node in the snapshot carries its full attributes** (copy 1's text block holds "Heading",
  copy 2's "Second Heading"), plus its `syncNodeId` and its own (possibly empty) override list.
- **`syncNodeId`s belong to the Component:** both copies use the same ids for the same nodes.
- **Not self-closing:** the builder writes the full snapshot even when nothing is overridden.
- **Slot module:** in a copy with an empty Slot it is a self-closing `divi/global-slot` with only
  `builderVersion`, `slotId` and `[]`; in a copy with content it wraps that content. Content inside a
  Slot has no `syncNodeId` (it is purely local). The `slotId` is the same in both copies.
- **Rendering** (`wp.js rendered`): copy 1 shows "Heading", copy 2 "Second Heading"; the Slot content
  renders inside `<div class="et_pb_global_slot_N et_pb_global_slot … et_pb_group …">`; the EMPTY Slot
  renders nothing at all; no block comments leak.
- `builderVersion` on the snapshot's root/row/column can be an old value copied from wherever the
  section came from (here an alpha of 5.0), while the modules carry 5.15.1. Harmless; do not "fix" it.

Short form (what the Divi 4 converter writes): `<!-- wp:divi/global-layout {"globalModule":"123","blockName":"divi/text","localAttrs":{...}} /-->`

Rendering: every `divi/global-layout` is expanded before parsing, nested ones recursively, with a guard
against a Component containing itself. Expansion is skipped while saving and on REST update requests,
so a page saved over REST keeps the reference unexpanded (correct). If the Component is missing, not
published, or not an `et_pb_layout`, the block is left as it is and renders nothing useful (INFERRED).

## Divi 4 Global Elements on converted sites (CONFIRMED)

Same mechanism. `global_module=` shortcodes convert to `divi/global-layout` blocks;
`_et_pb_excluded_global_options` becomes `localAttrsMap`. A one-time migration
(`Migration/GlobalModuleSyncNodeMigration.php`, release 5.15.0) adds `syncNodeId`s and infers
`localAttrOverridePaths`. It runs on render, on builder load and on import, and only touches nodes whose
`builderVersion` is **below 5.15.0**. Any site ported from Divi 4 may already hold Components.

## Rules for this skill

1. **Look first.** Before changing a page, `wp.js <site> dump-blocks <id> --tree` and look for
   `global-layout`. Then you know the page holds Components.
2. **Never rebuild a page that holds Components from a spec.** The compiler does not know them, and a
   whole-page write replaces the content: the instances and their local overrides are gone. Edit such a
   page by taking its raw content and changing only the blocks you mean to, keeping every
   `divi/global-layout` block (and everything inside it) byte for byte.
3. **Changing an instance's value means TWO edits, on the node that owns the field** (PROVEN over
   REST, see "Write tests"): the value in that node's attributes AND the full leaf path in THAT node's
   `localAttrOverridePaths` (for a nested module: on the nested block, not on the outer
   `global-layout`), and the Component's `localAttrsMap` on the same node must allow that field.
   A value alone does nothing, silently; a path for a field the Component keeps synced does nothing,
   silently.
4. **Changing the shared design means editing the Component** (the `et_pb_layout` post), not the
   instances. We have no route for that yet (see "To do"): ask the user to do it in the builder's
   Component mode, or with the Divi AI Agent.
5. **Globalising (`globalize.js`) does not reach Components yet.** It reads published pages, Theme
   Builder layouts and canvases, not the Divi Library. It will change literals inside instance snapshots,
   which only matters for overridden paths, and miss the Component itself, which is what renders. On a
   site with Components: inventory them first, and treat their colours/presets as a separate step.
6. **Design-system order** (SKILL.md, "Design system first"): global colours and variables, then presets,
   then Components for repeated BLOCKS (a hero, a testimonial card, a CTA band), then pages. A design
   repeated on several pages that differs only in text/image is a Component with those fields unsynced,
   or with a Slot for free-form content.
7. **Inserting an instance** (PROVEN over REST): a self-closing block renders the Component exactly as
   saved:
   `<!-- wp:divi/global-layout {"globalModule":"<id>","blockName":"divi/section","builderVersion":"5.15.1","localAttrs":{},"localAttrOverridePaths":[]} /-->`
   (`globalModule` a STRING; WordPress stores the empty `{}` as `[]`, which is harmless). To give it
   its own values or Slot content, write the full snapshot instead (copy a builder-made instance:
   every node with its `syncNodeId`, the Slot with its `slotId`) and set the overrides as in rule 3.
   When the builder next saves the page it rewrites the block in its own full form.
8. **Creating a Component** (INFERRED, needs a mu-plugin route): an `et_pb_layout` post with `scope=global`,
   the right `layout_type` term and `_et_pb_template_type` meta (what the builder wrote), `_et_pb_use_divi_5=on`, content wrapped in
   `divi/placeholder`, and a unique `syncNodeId` on EVERY non-root node. Writing 5.15+ blocks without
   `syncNodeId`s is permanent: the migration skips 5.15+ nodes, so they never get one.
9. **Permissions:** Divi Role Editor "Edit Global Items" (`edit_global_library`) gates editing a Component;
   moving them between sites also needs `divi_library` + `save_library`.

## Other 5.14 / 5.15 changes that touch this skill (CONFIRMED by diff 5.13.1 -> 5.15.1)

- No attribute-shape change in any existing module's `module.json` (CSS selector changes only). The one
  new module is `divi/global-slot`. The compiler's output is unaffected.
- **Global variables:** `GlobalData::set_global_variables()` now runs under a lock, accepts an optional
  "expected" snapshot (returns `global_variables_stale` 409 if the store changed), and **rejects the whole
  save** with `global_variable_ids_missing` if any existing `gvid-` id is missing from what is sent. A
  variable is deleted by setting `status: "archived"`. mu-plugin 1.8.4 handles all of it (passes on the
  error, sends the expected snapshot, restore archives instead of dropping): `divi5-presets-variables.md`
  section 5.0.
- `divi/code` with a standalone SVG is now sanitised on save.
- Option names, Theme Builder and canvas storage: unchanged.

## Write tests over REST (LIVE, a live site, Divi 5.15.1, 2026-10-12)

On the draft test page, with an Application Password through `wp.js update-page`, content backed up
first and restored byte-identical afterwards. Each prediction made before the write:

| Test | Written over REST | Predicted | Rendered |
|---|---|---|---|
| A | copy 1's heading "REST Heading" + `content.innerContent.desktop.value` in the TEXT block's override list | override shows | "REST Heading" ✅ |
| B | a new minimal self-closing instance (rule 7) | Component as saved | "Heading", image, button ✅ |
| C | a new full-snapshot copy, heading "NOPATH Heading", override list left `[]` | ignored | "Heading" ✅ |
| D | same copy, button "REST Button" WITH a path, but button text is not unsynced | ignored | "Click Here" ✅ |

So REST can place Components and set per-copy values, and the merge rule (value + path + permission)
is exactly as the code reads. The only change WordPress made on save: `"localAttrs":{}` stored as `[]`.

Not yet tested: what the builder writes for "detach instance" and for a detached nested module
(`localModule`), what the Elements-list cube writes when switched ON (`localChildren: true`), and
creating or editing a Component (its `et_pb_layout` post) over REST.

## To do (not built)

- mu-plugin routes: list Components, read one, write one (hash-checked like `tb-set`), so the skill can
  inventory, globalise and edit them.
- `globalize.js`: include Components as a source.
- `divi.js`: an optional `{"type":"component","id":123}` that emits an instance (after the live check).
