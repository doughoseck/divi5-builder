# Globalising an existing Divi 5 site

How to move a site that was built (or converted from Divi 4) with hard-coded values onto its design system: global
colours, a sensible default, and module presets, WITHOUT changing what a visitor sees.

Worked out on a 40-page site converted from Divi 4 (1,630 blocks, 618 modules of the preset-able types). Result:
704 colour literals became global colour references, 382 duplicate pins were removed, 22 presets now style 384 modules,
and a computed-style comparison of 7,285 elements showed zero differences. Every rule below cost a failed attempt.

This is the REPAIR. The way to never need it is to build design system first (SKILL.md, "Design system first"):
colours and presets before the first page, pages that carry content and placement only.

Tools: `scripts/globalize.js` (all steps), `scripts/wp.js ds-*` (design-system writes, mu-plugin >= 1.8),
`assets/snapshot-instrument.js` (the verification). Format details: `divi5-presets-variables.md`.

## The order that works

| Step | What | Command |
|---|---|---|
| 0 | Prove the plugin on this site | `wp.js <site> ds-selftest` must say `different: 0`; `ds-live-test.js <site> all` must pass |
| 1 | Inventory: what is hard-coded, how often | `globalize.js <site> inventory` |
| 2 | Create the global colours | builder, or `wp.js <site> ds-color-set` |
| 3 | BEFORE snapshot, then a control diff | `assets/snapshot-instrument.js` |
| 4 | Colour literals become references | `globalize.js <site> colors --map map.json` |
| 5 | Make the default what the site really uses, strip the pins | see "Defaults" |
| 6 | Find preset candidates | `globalize.js <site> signatures` |
| 7 | Build, create, assign the presets | `presets-build`, `presets-create`, `presets-assign` |
| 8 | After EVERY write step: no-op CSS save, warm, diff | see "Verifying" |

Do the steps in this order. Colours first means every preset made later holds references, not hex values. Presets last
because they depend on the other two being clean.

## 1. Inventory

Read-only. Tells you which colours and font values are pinned and in which role. Typical finding on a converted site:
five colours are 80% of all uses, and weight and uppercase are pinned hundreds of times because the theme default is
not what the site uses. The biggest win is usually REMOVING pins, not mapping them.

## 2 and 4. Colours

- One global colour per real colour. Black at different opacities is ONE colour: a reference carries
  `"settings":{"opacity":40}`. `map.json` takes `"black": "<gcid>"` for that.
- The script refuses to run if a colour in the map does not exist on the site. A reference to a missing colour fails
  silently.
- Left alone on purpose: Custom CSS strings, gradient stops, content, a global layout's local overrides, third-party
  blocks, fully transparent values, one-off colours.
- A font group's `link` holds the colour of links and IS converted; `module.advanced.link` is the module's own link
  and is not. (A first version skipped every key named `link` and missed the accordions' link colour.)
- Expect a NOTATION difference in the diff, not a real one: `rgba(0,0,0,0.33)` becomes `color(srgb 0 0 0 / 0.33)`.

## 5. Defaults: where uppercase, bold and sizes should live

- Divi's font style is toggle-only: uppercase is on or unset. There is no "normal case". A site-wide uppercase rule
  therefore cannot be cancelled per module in the builder. Put it in the module type's DEFAULT PRESET instead
  (it only reaches that module type), and offer an opt-out CSS class for the exceptions:
  `.normalcase h1, .normalcase h2 { text-transform: none !important; }`.
- Read presets with `design-system --full`. The summary view hides their settings; "the default presets are empty" was
  wrong for all three on the reference site.
- A pin that equals the theme default or the default preset is a duplicate and can go. Count before you claim: a
  heading whose text sits in a child element (`<h4><span>..`) has no text of its own, and a count that skips those
  reported "every H4 is uppercase" when six were not.
- Stripping a font pin while other settings of the same font group stay on the module worked (text-transform,
  font-size and text-align are independent CSS). That is an exception to rule 3 below, proven by the diff for fonts
  only. Do not assume it for sizing, layout, spacing or buttons.

## 6 and 7. Presets

`signatures` groups modules by identical design and names a real example of each group. Write `defs.json` from it.
A preset should hold the LOOK. Placement that differs per module (a button's margin, alignment) goes in `omit`.

**Rules**

1. **Stack on the default preset, but only when the two set DIFFERENT things.** A module with its own preset no
   longer gets the type's default. When the default holds settings the preset does not set (uppercase headings in a
   Text default, next to a preset that only centres them), assign `"modulePreset":["<default id>","<preset id>"]`.
   - When BOTH set the same setting (a Section default with a transparent background, a preset with a black one), do
     NOT stack. Their CSS rules are equally strong, so the one written last wins. On a published page the default is
     written first and the stack looks right. In a DRAFT PREVIEW Divi writes the Theme Builder header's CSS after the
     page's, the default's rule appears a second time, and the black section was transparent.
   - `presets-assign` decides this per preset and prints which ones it stacked and which it did not.
   - A module taken off the stack loses whatever else the default held. Check that list (the script prints it) and
     put into the preset what the modules still need.
2. **A module matches when EVERY setting of the preset is in the module with the same value.** It loses exactly those
   settings and keeps the rest. The preset's settings are read from the SITE, not from the local spec, because Divi may
   have removed something on the way in (a divider's "show line" counts as content).
3. **Never split an option group between a preset and a module. If it would split, the module does not get the preset.**
   Divi writes a module's CSS from the module's own settings and a preset's CSS from the preset's own settings,
   separately. Settings that only work together stop working when split.
   - Seen: a Text module kept `maxWidth` and got `alignment: center` from its preset. `margin-left: auto` was no
     longer written and the block sat left.
   - Group = the path up to and including `<breakpoint>.<state>`, e.g. `module.decoration.sizing.desktop.value`.
   - `layout` and `sizing` of one element are ONE group: the alignment CSS depends on `layout.display`.
   - Putting the preset's values back on the module as duplicates DOES NOT HELP: at render time Divi removes from a
     block every setting identical to its preset's value.
   - A setting EVERY matching module shares inside a touched group joins the preset (`presets-build` does this:
     a button's `icon.enable: off`).
   - A cluster of modules with the same extra setting gets a second, fuller preset. `presets-build` prints the count
     as "would SPLIT".
4. **Content never enters a preset.** The plugin removes every `<element>.innerContent` and `module.meta`. Interactions,
   conditions, links, ids and classes belong to one module: the plugin warns when a preset carries them.
5. **A preset is made in a context.** Presets taken from a site converted from Divi 4 come from modules inside
   block-layout columns. Inside a flex column (what Divi 5 and this compiler build by default) a centred block with a
   max-width shrinks to the width of its content. Presets for NEW pages are best made from modules built the new way.
6. **What the builder does differently.** "New preset from current styles" moves EVERY design setting of that module
   into the preset, margins and visibility flags included. Fine for one module, wrong as a shared preset. If a preset
   made that way is slimmed later, the module it was made from has lost those settings: give them back to it.

## 8. Verifying: the only judge is a computed-style comparison

`assets/snapshot-instrument.js`, pasted into a browser tab on the site's own origin. It loads every URL in a hidden
iframe and records, per element, colour, background, font size/weight/transform/line-height, borders, padding, margin,
text-align, max-width/width and display.

1. Warm every URL twice (`globalize.js - warm --urls urls.json`), take the BEFORE snapshot.
2. Run a diff with nothing changed. It must report zero. A new or changed instrument is not trusted before this.
3. Tamper with one stored value per field and diff that page. It must report exactly those. An instrument that can
   only say "zero" has not been tested.
4. Pilot the change on one or two pages, diff those, then roll out.
5. After the LAST write: no-op save of the site CSS, warm every URL twice, THEN diff.

**Gotchas of the instrument**
- Any save clears Divi's generated page CSS. A page loaded before it is warmed renders without module CSS: about 40
  false differences per page. Never write while a snapshot or diff job is running.
- Key elements by their Divi order class (`et_pb_text_3`), never by position in the document. Popups move after load.
- Wait 3.5 s after load and switch transitions off before reading styles.
- A browser tool call times out after about 45 s: start the job, then poll. A full run of 33 URLs takes about 10 minutes
  in a background tab.
- Known noise: countdown timer digits, a header's scroll-state background.
- Blind spots: hover states, pseudo-elements (button icons), background images, and every width but the one you
  snapshot. Check tablet and phone by EXPECTED VALUE for each preset that holds responsive settings. Rule 3 exists for
  what the snapshot cannot see.
- A rule in the theme with `!important` can make a value look wrong that was wrong before too. Read the matched CSS
  rules before blaming the change.
- The snapshot only sees PUBLISHED pages, and only as a visitor. Build one draft page from the presets and look at
  its preview as well: that is where the stacking order problem of rule 1 showed, not in 7,285 measured elements.

## Safety rules for any bulk write

- Dry run by default, `--write` to apply, and the dry run must print the same numbers the write will.
- Backups are written ONCE per source and never replaced. (A dry run after the write once overwrote them.)
- Refuse to write a source if anything OUTSIDE block comments changed.
- Run the write a second time: it must find nothing to do.
- A one-time repair step belongs behind its own flag. On a second run a script cannot tell a module the builder
  changed from one it changed itself.
- Serialise block attributes the way WordPress does (`--`, `<`, `>`, `&`, `\"` as `\u00xx`).
- A Theme Builder header or footer write is a site-wide publish. `tb-set` needs the hash from `tb-get`.
- Creating 20 presets in a row pushes the first backup out of a newest-10 list: the plugin (>= 1.8.1) also keeps the
  store as it was before the first write of each day.
- Option group presets written by the plugin have NOT been run live or compared with builder-made ones, and a
  variable's format has not been compared with a builder-made one. Have one made by hand and compare it with a
  `--dry-run` before relying on them.

## What is usually left over

Modules whose design occurs only once or twice, hero modules with many small variants, and the clusters rule 3 kept
out. They stay exactly as they were. Globalising 60% of a site without a visible change is a good result; chasing the
rest means changing the design, which is a decision for the site owner.
