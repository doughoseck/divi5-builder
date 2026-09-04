# Divi 5 — verified serializations (from live VB builds, v5.9.0)

Every block below was built in the Visual Builder by the author and read back via REST —
so these keys are **confirmed**, not inferred. All values are per-breakpoint
`{bp}.value`; only `desktop` shown unless responsive matters. Styling via
`*.decoration.*`; text via `content.decoration.headingFont/bodyFont` (see
divi5-format.md). Colours = gcid tokens.

## Layout

### CSS Grid — lives on the ROW (default for card/pricing/gallery/loop grids)
```
row.module.advanced.flexColumnStructure.desktop.value = "css-grid-grids_<N-1>"   // grid mode flag; N = child column count
row.module.advanced.columnStructure.desktop.value     = "1_6,1_6,…"              // legacy metadata
row.module.decoration.layout.desktop.value = {
  display:"grid", gridColumnCount:"3", gridAutoColumns:"3", gridColumnWidths:"equal",
  gridOffsetRules:{ rules:[ {id, adminLabel, targetOffset:"first-child"|"3n"|…, offsetValues:{columnSpan:"2"}} ] }
}
row.module.decoration.layout.tablet.value = { gridColumnCount:"2", gridAutoColumns:"2" }
row.module.decoration.layout.phone.value  = { gridColumnCount:"1", gridAutoColumns:"1", gridColumnWidths:"auto" }
```
- **Responsive = set `gridColumnCount` per breakpoint** (e.g. 3/2/1). Grid is
  child-agnostic (add/remove/loop items freely).
- **Span** = a `gridOffsetRules.rules[]` entry on the ROW targeting by pseudo
  (`first-child`, `3n`, `nth-child`…), NOT on the item.
- ⚠️ **GOTCHA (the author):** a `columnSpan` rule lives in `desktop.value` and is NOT
  auto-reset on phone — a spanned item **won't collapse to 1 column on mobile**
  even at `gridColumnCount:1`. Avoid spans if you need clean mobile collapse, or
  the layout breaks on phones.

### Fractional flex columns (default for simple content rows)
Row is a normal flex row (`flexColumnStructure: equal-columns_N`, `layout.flexWrap`
per bp). Each COLUMN carries per-breakpoint `flexType` (grid is 24-based):
```
column.module.decoration.sizing.desktop.value.flexType = "8_24"   // 1/3   (12_24=1/2, 6_24=1/4, 24_24=full, 16_24=2/3)
column…tablet…flexType = "12_24"    // 1/2 on tablet
column…phone…flexType  = "24_24"    // full on phone
column.module.advanced.type.desktop.value = "1_3"                  // legacy label
```
Responsive flex rows: `row.module.decoration.layout.<bp>.value.flexWrap` =
`nowrap` (desktop/tablet) → `wrap` (phone) [or wrap at tablet for 3-4 col]. Rows
also carry `display:"flex"` per bp when responsive.

## Presets — discover / apply / (can't) create
- **Discover:** registry lives in wp_option `et_divi_builder_global_presets_d5`
  (each entry: id, name, moduleName, values). Read it via the mu-plugin:
  `wp.js postinfo <anyPageId> --scan global_presets` → lists `moduleName | name | id`
  (e.g. `divi/button | Button Preset 1 | idb30q6e8l`).
- **Apply an option-GROUP preset (CONFIRMED):**
  ```
  <block>.groupPreset = { "<designSlot>": { "presetId":["c0je2rf0rg"], "groupName":"divi/font" } }
  ```
  presetId is an ARRAY (stacked presets supported). Compiler: any text/heading/button
  spec takes `preset:{slot,id,group}`.
- **Apply a whole-ELEMENT preset (e.g. "Button Preset 1"): likely a module-level
  `presetId`, but [verify-live]** — dump a module with an element preset applied to
  confirm the exact attr before trusting it.
- **Create a preset via REST: NO clean way.** The store is one complex serialized
  option; no official route. Writing it via the mu-plugin is fragile (structure +
  cache) — don't. Create presets in the builder once, then REFERENCE by id via REST.

## Interactions — verified effects
- `toggleVisibility` (documented earlier). 
- **`togglePreset`**: `effect:"togglePreset"`, `presetId:"6qoh5emc5s"`,
  `replaceExistingPreset:true`, target = a module's `et-interaction-target-<id>`.
- **Scroll-to, two ways:** (a) simplest = a plain **anchor link** — button
  `linkUrl:"#<id>"` where the target carries a custom id attribute
  (`module.decoration.attributes.desktop.value.attributes:[{name:"id",value:"scrollhere"}]`).
  (b) native **Scroll To Element** interaction (confirmed): a button with
  `module.decoration.interactions…interactions[]` where `effect:"scrollToElement"`,
  `trigger:"click"`, `target.targetClass:"et-interaction-target-<id>"` (the target
  module/row carries that `interactionTarget`). No `linkUrl` needed.

## Native modules — exact content keys (block name → where content lives)

| Module | Block | Content keys |
|---|---|---|
| Heading | `divi/heading` | `title.innerContent.desktop.value` = text; level via `title.decoration.font…headingLevel`; style via `title.decoration.font.font…` |
| Call To Action | `divi/cta` | `title.innerContent…value` (plain), `content.innerContent…value` (HTML), `button.innerContent…value{text,linkUrl}` |
| Toggle | `divi/toggle` | `title.innerContent…value`, `content.innerContent…value` (HTML); open by default: `module.advanced.open.desktop.value:"on"` |
| Tabs | `divi/tabs` (empty parent) + `divi/tab` child | per tab: `title.innerContent…value`, `content.innerContent…value` (HTML) |
| Testimonial | `divi/testimonial` | `author.innerContent…value`, `jobTitle.innerContent…value`, `company.innerContent…value.text`, `content.innerContent…value` (HTML — NOT plain text), `portrait.innerContent…value{src,id,alt,width,height}` |
| Pricing Tables | `divi/pricing-tables` (parent, holds shared styling) + `divi/pricing-table` child | child: `title.innerContent…value`, `subtitle.innerContent…value`, `price.innerContent…value` (number only, e.g. "1500"), `currencyFrequency.innerContent…value{currency:"R",per:"month"}`, `button.innerContent…value{text,linkUrl}`, `content.innerContent…value` = features as **newline list with `+ ` (included) / `- ` (excluded)** prefixes; featured: `module.advanced.featured.desktop.value:"on"`. Parent styles `title/price/featuredTitle/content.advanced.showBullet`. |
| Bar counters | `divi/counters` (parent, `barProgress.advanced.usePercentages`) + `divi/counter` child | child: `title.innerContent…value`, `barProgress.innerContent…value` = percent (e.g. "99") |
| Circle counter | `divi/circle-counter` | `title.innerContent…value`, `number.innerContent…value` (0–100) |
| Number counter | `divi/number-counter` | `title.innerContent…value`, `number.innerContent…value`; `number.advanced.enablePercentSign.desktop.value` |
| Countdown timer | `divi/countdown-timer` | `title.innerContent…value`, `content.advanced.dateTime.desktop.value` = "YYYY-MM-DD HH:MM" |

## More native modules (verified live, batch 2)

| Module | Block | Content keys / notes |
|---|---|---|
| Hero | `divi/fullwidth-header` | `title.innerContent…value`, `content…value` (HTML), `image.innerContent…value{src,id,alt}`, `logo.innerContent…value{src,id}`, `buttonOne`/`buttonTwo.innerContent…value{text,linkUrl}`. **Full height = module setting `module.advanced.headerFullscreen.desktop.value:"on"`** (not row sizing; the row just sets width/maxWidth 100%). |
| Slider | `divi/slider` (empty parent) + `divi/slide` | slide: `title`/`content`(HTML)/`button{text,linkUrl}`/`image.innerContent…value{src,id}`; slide bg image+gradient via `module.decoration.background`. |
| Person | `divi/team-member` | `name.innerContent…value`, `position.innerContent…value`, `content…value` (HTML), image at `image.innerContent…value{url,id}` (**`url`, not `src`**). |
| Map + pin | `divi/map` + `divi/map-pin` | map: `map.innerContent…value{lat,lng,address,zoom}`; pin: `pin.innerContent…value{lat,lng,address,zoom}` + `title` + `content`(HTML). |
| Tooltip | `divi/tooltip` | `content.innerContent…value` (HTML) + `module.advanced.tooltip.value{trigger,placement,positionMode,…}`. |
| Timeline | `divi/timeline` (advanced.timeline{direction,position,startFrom}) + `divi/timeline-item` | item: `marker.innerContent…value{unicode,type,weight}`, `date.innerContent…value`, `title…value`, `content…value` (HTML). |
| Post carousel / portfolio | `divi/fullwidth-portfolio` | `portfolio.innerContent…value{type:"belt", includedCategories:["all"]}` + advanced.showDate/layout. Query-driven (no loop). |
| Group carousel | `divi/group-carousel` + `divi/group` | carousel: `module.advanced.auto`, `slidesToShow` per bp (3/2/1), `arrows.advanced.position`. The `divi/group` child holds the loop + inner modules. |

## Loop Builder + dynamic content (data-driven sections) — CONFIRMED
- **Loop** goes on a container (`divi/column` or `divi/group`):
  ```
  module.advanced.loop.desktop.value = { enable:"on", loopId:"loop-xxxx",
    subTypes:[{value:"belt",label:"Belts"}], queryType:"post_types",
    orderBy:"date", order:"ascending", postPerPage:"99",
    includePostWithSpecificTerms:"", excludePostWithSpecificTerms:"",
    includeSpecificPosts:"", excludeSpecificPosts:"" }
  ```
  The container renders once per queried post. Combine with a grid column (column
  `layout.display:"grid"`/flex) or a group-carousel for cards.
- **Dynamic content** = a `$variable(...)$` token of `"type":"content"` placed in any
  content value:
  ```
  $variable({"type":"content","value":{"name":"loop_post_title","settings":{...}}})$
  $variable({"type":"content","value":{"name":"loop_post_featured_image","settings":{"thumbnail_size":"large"}}})$   // use as image src
  $variable({"type":"content","value":{"name":"loop_post_excerpt","settings":{...}}})$
  // CUSTOM FIELD:
  $variable({"type":"content","value":{"name":"loop_post_meta_key_manual_custom_field","settings":{"select_loop_meta_key":"loop_post_meta_key_<yourmetakey>"}}})$
  ```
  compiler helper `dc(name, extraSettings)` emits these; custom field via
  `dc('loop_post_meta_key_manual_custom_field', {select_loop_meta_key:'loop_post_meta_key_<key>'})`.

## Divi Plugins FilterGrid (third-party — very useful)

Block **`dp-dfg/filtergrid`** (from diviplugins.com; needs the **Divi FilterGrid**
plugin installed — a commercial add-on from diviplugins.com). A
query-driven CPT grid with built-in **content & video popups**, filters, skins, and
easy output customisation. Every setting sits at `<key>.innerContent.desktop.value`.
Verified from belt page #223309:
```
custom_query:"advanced"  multiple_cpt:"belt"  post_number:"99"  order:"ASC"
thumbnail_action:"popup"        // built-in popup on thumb click (content/lightbox/video/link)
thumbnail_size:"1024x1024"
items_layout:"dp-dfg-layout-flex"  items_width_flex:"30%"  row_gutter_flex:"3%"  justify_content:"space-around"
items_skin:"dp-dfg-skin-default dp-dfg-skin-midnight"
show_filters/show_pagination/show_post_meta/use_overlay:"off"
```
Also styleable via `module.decoration.*` + a `css.desktop.value.entryHeader` custom-CSS
field + `dpdfgEntryTitleFont` typography.

**Video / popup — keys CONFIRMED (dumped live):** `show_video_preview:"on"`
(auto-finds a post video) + **`video_action:"popup"`** (play in popup; other value
plays in place). Compiler named props: `video:true` → `show_video_preview:"on"`,
`videoAction:"popup"` → `video_action`. Content popups use *Popup Template* (Design ▸
Popup Options, v2.6+ — Default or a Divi Library layout); its attr key not yet dumped,
set via `settings:{popup_template:"…"}` and confirm live if needed.
Compiler: `{type:'filtergrid', cpt, count, order, thumbnailAction, thumbnailSize,
layout, widthFlex, gutterFlex, justify, skin, showFilters, video, videoAction, ...}`
+ raw `settings:{}` passthrough for any other option.
Docs: https://diviplugins.com/documentation/divi-filtergrid/ (video-preview, popup-template,
custom-content, thumbnail-size pages).

Note: the author rates the **hand-built native pricing** (columns + text + buttons + our
monthly/annual toggle) as nicer than `divi/pricing-tables` — keep the custom one as
the recommended pricing approach; `divi/pricing-table` is the quick/native option.
