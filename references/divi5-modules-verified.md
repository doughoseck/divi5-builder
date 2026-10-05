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

## Divi Plugins Dynamic Gallery (third-party, Divi 5 native block `dp-ddg/dynamic-gallery`, plugin 2.0.4, verified 2026-09-28)

Masonry/grid image gallery with Magnific lightbox; a commercial add-on from diviplugins.com. Every setting is
`<key>.innerContent.desktop.value` (tablet/phone beside desktop where responsive). Verified props:
`galleryIds` (comma-separated attachment ids; `orderby:"post__in"` keeps that order), `itemsLayout` `"masonry"|"grid"`,
`columns` per breakpoint (strings), `gap` per breakpoint (px number as string), `imageSize` (`"large"` etc.),
`showPagination`/`imagesPerPage`, `showTitle`/`showCaption`/`showDescription`, **`showOverlay` must be `"on"` for any
click action — the `.dp-ddg-overlay` div is the click target**, `overlayAction` `"lightbox"|"gallery"|"url"|"link"|"none"`
(`gallery` = lightbox with prev/next + counter), `overlayColor`, `showOverlayIcon`, `lightboxData`/`overlayData` = array of
`"title"|"caption"|"description"` (use `["caption"]` to show nothing when captions are empty; `[]` falls back to title).
`catalog.js lint` warns "not a Divi block, not checked" — expected. Filters (`showFilters`, taxonomy) and dynamic sources
(ACF gallery field, product gallery) exist but are unverified.

## Loop Builder findings, a converted site, 2026-09-28 (all verified on a throwaway page, Divi 5.13.1)
- A background image bound to `loop_post_featured_image` (or `post_featured_image`) on a looped column renders NOTHING
  (no CSS, no inline style). Per-post images must be a `divi/image` module with `src` = `dc('loop_post_featured_image',
  {thumbnail_size:'full'})`. Crop it with the module's Custom CSS (`selector img { height:520px; object-fit:cover }`).
- `post_link_url` inside a loop resolves to the current PAGE. The per-post URL for a button/link is
  `dc('loop_post_link', {text:'permalink'})` (returns the bare permalink; `text:'custom'` also returned the URL).
  Never place it alone in a paragraph: WordPress auto-embeds a bare post URL into a blockquote/iframe.
- `divi/group` renders fine inside a column (catalogue only lists it under group-carousel) and takes position
  absolute + gradient + flex: the natural "text over image" overlay container in a loop.
- Native loop pagination EXISTS in 5.13 (earlier note here said otherwise -- wrong): the "Pagination" module
  `divi/post-nav` with `module.advanced.targetLoop.desktop.value` = the loop's `loopId` (e.g. `"loop-news"`).
  Server (`PostNavigationModule::get_loop_pagination`) emits `.nav-previous`/`.nav-next` links to `?<loopId>=N`,
  omitting Newer on page 1 and Older on the last page; with WP-PageNavi active it renders numbered links instead.
  Labels: `links.advanced.prevText/nextText`; link styling under `links.decoration.{font,background,spacing,border}`
  (selector = the `<a>`). `catalog.js lint` reports the targetLoop value as "not an option" because the VB fills
  that select from the loops on the page -- the one lint error to ignore. The module ships
  `script-library-pagination.js`, which scrolls to the loop after a page change.
- `buildModule({type:'image'})` returns TWO lines (opener + closer); patch `image[0]` accordingly.
- Loop + CSS grid = repeating editorial layouts (verified on a news archive, 5.13.1): the looped columns render as
  DIRECT siblings inside `.et_pb_row`, so row Custom CSS `selector > .et_pb_column:nth-child(5n+1) { grid-column:
  span 2; grid-row: span 2 }` etc. gives "first post full width, then staggered halves" that repeats per page. Use
  `span`, never fixed grid lines (items with a definite column are placed BEFORE auto items and break DOM order).
  Native row grid keys (StyleLibrary/Declarations/Layout/Layout.php): `layout.desktop.value = {display:"grid",
  gridColumnCount:"2", gridColumnWidths:"equal", gridAutoFlow:"row", rowGap, columnGap}` -> `grid-template-columns:
  repeat(var(--column-count), minmax(0,1fr))`. `gridTemplateColumns` is NOT a row key (ignored -> default 3 cols).
  Give rows `grid-auto-rows: minmax(<min>, auto)` and keep the text overlay in normal flow (flex column,
  justify-content flex-end, image module absolute behind it) so long titles grow the cell instead of clipping.
  A per-card hover overlay = looped column Custom CSS `selector::after` (absolute inset 0, z-index 0, opacity 0)
  + `selector:hover::after { opacity:1 }`; keep the text group `position:relative; z-index:1`. Divi emits the
  rules once per loop clone (`.et_pb_column_0`, `_1`, ...), so `selector` is safe inside a loop.
- Button padding override needs a 5-class selector: Divi's global `.et_button_no_icon.et_button_icon_visible
  .et_button_left .et_pb_button { padding: .3em 1em !important }` is 4 classes + !important, so
  `selector.et_pb_button` (2) and even `.et_pb_button_module_wrapper selector.et_pb_button` (3) lose. Use
  `.et_pb_section .et_pb_button_module_wrapper selector.et_pb_button.et_pb_module { padding: ... !important }`.
- Text module `lineHeight` in the heading font attrs is ignored on the front end (also seen on accordion titles);
  set it with Custom CSS `selector h2 { line-height: 38px !important }`.
- MEASURING GOTCHA: a browser-pane tab that is not painting (screenshots time out) freezes CSS transitions, so
  getComputedStyle on a transitioned property (Divi buttons have `transition: all .2s`) returns the START value
  forever, even for inline `style="...!important"`. Inject `*{transition:none!important}` before measuring, or
  measure a property that does not transition, or let the user's browser be the instrument.

## Timeline module (divi/timeline + divi/timeline-item), proven 2026-10-01 on Divi 5.13.1

A native vertical or horizontal timeline. Built a 21-item "20 years" page with it.

- **Markup:** `divi/timeline` is a container; each `divi/timeline-item` is a child WITH its own children:
  `<!-- wp:divi/timeline {…} --><!-- wp:divi/timeline-item {…} -->…child modules…<!-- /wp:divi/timeline-item --><!-- /wp:divi/timeline -->`
- **An item renders, in this order inside its card:** date, title, content (rich text), THEN its child modules. So a
  card can hold any module: a text used as a tag, a button, an absolutely positioned number. Leave `content` empty
  and use child text modules when something must sit between the title and the paragraph.
- **Item content:** `date.innerContent.desktop.value`, `title.innerContent.desktop.value` (renders as h3),
  `content.innerContent.desktop.value`.
- **Layout is CONTENT, not style:** `module.advanced.timeline.desktop.value = { direction: "vertical", position:
  "alternating" | "left" | "right", startFrom: "left" | "right" }`, responsive (`phone.value.position = "right"` for one
  column on phones). `ds-preset-set` strips these from a preset (`strippedContent`): put them on the module.
- **Design goes in a preset on the parent** (elements `connector`, `marker`, `card`, `date`, `title`, each with
  `decoration`): line colour = `connector.decoration.background`, dot = `marker.decoration.{background,border,boxShadow}`,
  card = `card.decoration.{background,spacing,layout.rowGap}`, fonts = `date|title.decoration.font.font`.
- **What it has no setting for** goes in the preset's own Custom CSS (`css.desktop.value.freeForm`, the word
  `selector` stands for the module): line width (the module emits 2px; `selector .et_pb_timeline_connector { width:
  3px !important; }`), a card border with a hover colour, a branch from card to dot as `.et_pb_timeline_card::before`.
  Cards are on the left for `:nth-child(odd)` items and on the right for even ones when alternating from the left;
  on phones (position right) every card is right of the line, so the branch needs a `@media (max-width: 767px)` rule.
- **Geometry (desktop, alternating):** the dot sits at the top of the item, level with the card's top edge; 27px
  between a card's edge and the dot's centre.
- A module inside a card can be positioned in a corner: `module.decoration.position.desktop.value = { mode:
  "absolute", origin: { absolute: "top right" }, offset: { vertical: "28px", horizontal: "28px" } }` once the card
  has `position: relative` (Custom CSS above).

### Fullwidth header: details learnt on the same page
- `buttonOne.innerContent.desktop.value = { text, linkUrl }`; `scrollDown.decoration.icon.desktop.value.show = "on"`
  gives the native scroll-down arrow; `title.innerContent` keeps simple HTML (`<span>`, `<br>`), so one h1 can hold a
  small label, a word, and a second word in another colour.
- A NEW section on a site converted from Divi 4 must carry `module.decoration.layout.desktop.value.display = "block"`
  to behave like the site's converted hero sections. Without it the section is flex and the header's text column
  shrinks to a few hundred pixels (a 90px title wraps letter-group by letter-group).

## Background video, per breakpoint (verified 2026-10-01, Divi 5.13.1)

`module.decoration.background.<breakpoint>.value.video = { mp4, width, height, pauseOutsideViewport }` on a section
(or any module with a background). Source: `server/Packages/Module/Options/Background/BackgroundComponentVideo.php`.

- **One video per breakpoint works.** Divi prints one `<span class="et-pb-background-video[_tablet|_phone]">` per
  breakpoint that has an `mp4`; CSS shows only the one for the current width, and the front-end script copies
  `data-src` to `src` only for the visible one, so a phone never downloads the desktop file. Proven: desktop 1080p
  file at `desktop`, a small 960x720 centre-crop at `tablet` (which phones inherit).
- **It plays on phones.** The `<video>` is `autoplay loop muted playsinline`; Divi 5 has no "not on mobile" rule.
  (An iPhone in Low Power Mode shows the first frame.)
- **It always covers and centre-crops**: the script sizes the video to fill the section and centres it, so a
  landscape file in a portrait hero loses its sides. A 4:3 crop of the source is a good phone file.
- Keep `image` in the same background: it shows until the video starts. Use the video's exact first frame
  (`ffmpeg -i in.mp4 -frames:v 1 first.jpg`) and nothing flashes.
- Encoding that worked for a busy 30 s clip: `-an -vf scale=1920:1080 -c:v libx264 -preset slow -crf 26 -maxrate 2800k
  -bufsize 5600k -movflags +faststart` (10.6 MB), and `scale=-2:720,crop=960:720 … -crf 27 -maxrate 1200k` (4.5 MB).
- A browser pane that is hidden does not autoplay (`document.visibilityState === 'hidden'`): `paused: true` there
  proves nothing. Check `readyState`, the chosen `currentSrc`, and ask the user to look.
- The page's HTML time does not change (the video loads after the page).

## Fullwidth header: logo not centred

With text orientation "center" the logo image can still sit left: `.header-content` is a flex column and the logo is
a block with a max-width. Add `margin-left: auto; margin-right: auto;` to the module's Custom CSS field for the logo
(`css.desktop.value.logo`). Measure `rect.left + rect.width/2 - viewport/2` before and after at three widths.

## Search module, Blog module as a search results grid

See `references/wp-site-search.md`.

## Tool limits met on shared hosting

- `upload-media` can fail with HTTP 413 for files of a few MB (the REST request is refused by the server, 4.5 MB did
  not pass on one host). The user uploads large files through the Media Library (or FTP); a background video only
  needs the URL.
- Theme Builder TEMPLATES cannot be created with the tool, only layouts edited (`tb-set`). The user creates the
  template and its empty body; `tb-list` then shows the new body layout id.

## Divi FilterGrid (third-party, block `dp-dfg/filtergrid`, plugin 4.3.3, verified 2026-10-05 on Divi 5.13)

A post grid with filters, pagination and a built-in popup. Every setting is a top-level attribute shaped
`<name>: { innerContent: { desktop: { value } } }` (responsive ones add `tablet` / `phone`); font groups are
`dpdfgEntryTitleFont`, `dpdfgEntryMetaFont`, `dpdfgPaginationFont`, ... as `{ decoration: { font: { font: {...} } } }`.
A saved module only holds what differs from the defaults, so read the names from the plugin itself:
`modules-json/filtergrid/module-default-render-attributes.json` (all defaults) and the option list in
`d4/includes/modules/DPDFG_FilterGrid/DPDFG_FilterGrid.php` (labels, allowed values). Ask the user for the plugin zip.

- **Any post type, one term:** `custom_query: advanced`, `multiple_cpt: <post type>`, `use_taxonomy_terms: on`,
  `multiple_taxonomies: <taxonomy>`, `include_terms: <term id>`. It ignores the main query.
- **Columns:** `items_layout: dp-dfg-layout-grid`; `items_width` becomes `repeat(auto-fill, minmax(X, 1fr))`, so 30% = 3
  columns, 21% = 4, 40% = 2. On phones the plugin goes to ONE column whatever the value (force a grid in CSS if needed).
  `column_gutter` / `row_gutter` take a unit (`0em`).
- **Page size and paging:** `post_number`, `show_pagination: on`, `pagination_type: paged` (AJAX, no reload).
- **Card content:** `show_title`, `show_post_meta` + `show_terms: on` + `show_terms_taxonomy: <taxonomy>` +
  `terms_links: off` for a taxonomy line, `show_author/date/comments: off`, `thumbnail_size: dfg_full`.
- **Card with text over the image:** the item is `article.dp-dfg-item > figure.dp-dfg-image + div.dp-dfg-header +
  div.dp-dfg-meta`. Make the item a one-column CSS grid (`grid-template-rows: 1fr auto auto`), the figure
  `grid-row: 1 / -1`, header row 2 and meta row 3 with `z-index` and their own gradient background; an arrow is
  `.dp-dfg-item::after` on `grid-row: 2 / 4`. No wrapper element and no script needed.
- **Popup of the post:** `thumbnail_action: popup` (other values: none, link, popup_v, lightbox, lightbox_gallery,
  gallery_cf). The popup is an IFRAME of the post's own address with `?dp_action=dfg_popup_fetch`; with
  `popup_template: default` it renders the post's Theme Builder body, header and footer hidden. So "design the popup"
  = build a Theme Builder template for that post type, and the same layout serves a direct visit.
  - `popup_width`, `popup_height`, `popup_max_width` need a UNIT (`80%`, `1080px`); a bare number is ignored.
  - The popup is a 16:9 box (`.mfp-iframe-scaler` with `padding-top: 56.25%`). For a tall popup:
    `.dp-dfg-popup .mfp-content { height: 85vh !important; } .dp-dfg-popup .mfp-iframe-scaler { height: 100% !important;
    padding-top: 0 !important; } .dp-dfg-popup iframe#dp-dfg-popup-modal-iframe { margin-top: 40px; height: calc(100% - 40px); }`
    (the 40px keeps room for the close button).
  - Inside the iframe the content sits in a `.container` of 80%: `.dp-dfg-modal-content > #page-container > .container
    { width: 100% !important; max-width: none !important; margin: 0 !important; }` in the template's own CSS.
  - White flash while loading: `.dp-dfg-popup iframe#dp-dfg-popup-modal-iframe, .dp-dfg-popup .mfp-iframe-scaler
    { background-color: #000 !important; }`. Loading animation off: `selector .dp-dfg-loader, selector
    .dp-dfg-loader-wrapper { display: none !important; }` and `.dp-dfg-popup .mfp-preloader { display: none !important; }`.
  - The popup hangs on `<body>`, outside the module: in the module's Custom CSS those rules must NOT start with
    `selector` (rules without it are printed as written).
- In a module's Custom CSS a CSS escape such as `content: "\35"` arrived on the page as another character: write the
  character itself (`content: "5"`).

## A single-post template driven by custom fields (ACF), every part hidden when empty (verified 2026-10-05)

Built as a Theme Builder body for a custom post type; fields are plain ACF fields (URL, image returning an id).

- **Post text:** `divi/post-content`. **Title / taxonomy inline in one Text module:** tokens work INSIDE the HTML
  of a text module, several per module, also inside an `href`: `<h1>$variable({"type":"content","value":{"name":
  "post_title",...}})$</h1><p>...post_categories token with settings { category_type: "<taxonomy>", separator: " / ",
  link_to_term_page: "off" }...</p>`.
- **Video module from a field:** `video.innerContent.desktop.value.src` = the `custom_meta_<field>` token. Works for a
  YouTube address (oEmbed iframe) although the field does not advertise dynamic content in module.json.
- **Icon module:** glyph AND link live together in `icon.innerContent.desktop.value = { unicode, type, weight, url,
  target: "on" }`; colour and size in `icon.advanced.color` / `icon.advanced.size` (the module's defaults, 96px and
  the accent colour, beat Custom CSS). `url` takes a `custom_meta_<field>` token. Font Awesome (`type: "fa"`): brand
  icons weight `400`, solid icons weight `900` (a solid glyph with 400 renders nothing). Divi's Font Awesome has no X
  logo; Divi's own font does: `unicode: "&#xe094;", type: "divi"` (the glyph the Social Media Follow module uses).
  Add `aria-label` / `title` through `module.decoration.attributes`.
- **Gallery from N image fields = native Slider:** one `divi/slide` per field, the picture as the slide background
  (`module.decoration.background.desktop.value.image = { url: <custom_meta_image_N token>, size: "contain" }`), a
  display condition on each slide. Slides whose field is empty are not rendered and the slider runs with the rest
  (7, 2, 0 tested). `module.advanced.auto: on`, `autoSpeed: "5000"`.
- **Hide when empty:** display condition `customField` + `isAnyValue` on each optional module; on a ROW or a heading
  that belongs to several fields, one condition per field with `operator: "OR"`. Rows accept conditions.
- A Theme Builder template may have its header and footer switched off (`_et_header_layout_enabled: 0`): then a
  direct visit to the post has no site header. Tell the user; a popup plugin hides them itself.
- **Checking it:** fetch the post addresses as a visitor and count by class. Module classes in a Theme Builder body
  are `et_pb_<module>_<n>_tb_body` FIRST, then `et_pb_<module>` (a pattern that expects `et_pb_module et_pb_video`
  finds nothing and reports "all hidden"). Test data: a few posts with every field, a few with deliberate gaps.

## Button presets and icons (verified 2026-10-05)

- A button preset saved with the icon OFF prints `body #page-container .et_pb_section .preset--...::before
  { display: none !important }`: a button using that preset cannot show an icon unless its CSS out-ranks that rule
  (`body #page-container .et_pb_section selector.et_pb_button::before { display: inline-block !important; }`).
- A preset that switches the icon ON without naming a glyph prints Divi's default arrow with rules that beat the
  button's own glyph. For "big button with an always-visible icon" presets: leave the icon out of the preset
  (`button: { enable: "on" }` only), put size, padding, flex layout and the `::before` placement in the preset's Custom
  CSS, and let each button carry the full icon object:
  `button.decoration.button.desktop.value = { enable: "on", icon: { enable: "on", settings: { unicode, type, weight },
  placement: "left", onHover: "off" } }`.
- A flex section spaces its rows with `module.decoration.layout.desktop.value.rowGap` (60px on this site): that, not
  row padding, is the gap between a heading row and the row below.
- The first page load after a save can lack the page's own module CSS (the static CSS file is being rebuilt): load
  the page twice before measuring computed styles.

## Tool notes

- `wp.js rest-post wp/v2/<route> --data-file body.json`: POST to core content routes (custom post types, `{"acf":
  {...}}`, taxonomy terms). Used to create a term and fill ACF fields on a custom post type.
- `wp.js tb-list` without `--json` printed "Theme Builder posts: undefined" and crashed on one run while `--json`
  worked: look at the non-JSON branch before relying on it.
- Editing a script through a shell heredoc that contains `\n` inside a JS string breaks the string: use the editor.
