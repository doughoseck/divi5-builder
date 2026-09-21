# Divi 5 presets, design variables and dynamic content

Read from the Divi 5.13.1 theme source (your own copy: the folder holding `style.css`). Source reading first, then
the checks below were run read-only against two live sites (Divi 5.9 and 5.13) on 2026-09-21.

## Checked live (what is confirmed, what is not)

| Claim | Result |
|---|---|
| Presets are their own option row `et_divi_builder_global_presets_d5`, top-level `module` / `group`, each with `default` + `items` keyed by id | CONFIRMED on both sites |
| Variables are their own row `et_divi_global_variables` | Row CONFIRMED (it was empty, `a:0:{}`, so the item structure in 2.1 is still source-only) |
| Divi 4 originals stay in `et_divi_builder_global_presets_ng` after conversion | CONFIRMED (5.13 site) |
| `divi/v1` has NO GET route for presets, colours or variables; the four `global-data` routes are POST-only | CONFIRMED from the live route index on both sites |
| `GET /divi/v1/settings-data/nonces` answers 200 to an Application Password | CONFIRMED. Whether a nonce obtained that way then validates on a write was NOT tested |
| Block shape `"groupPreset":{"<slot>":{"presetId":["<id>"],"groupName":"divi/…"}}`, `"modulePreset":["<id>"]` | CONFIRMED in builder-saved content. Slots seen: `designTitleText` (groupName `divi/font`) and `button` (groupName `divi/button`), so BOTH slot families occur |
| Colour token with `settings.opacity` | CONFIRMED in builder-saved content |
| Preset `created` / `updated` written by the builder | 13 digits = MILLISECONDS (PHP-generated presets use seconds). Resolves the open question in 1.2 |
| Tokens of type `number` / `font` / `image` vs `content` for `gvid-` ids | NOT checked: neither site uses a non-colour variable yet |
| Front-end class `preset--module--…`, `:root` declarations, `invalid_nonce` on a nonce-less POST | NOT checked |

**Reading what a site has:** `node scripts/wp.js <site> design-system` (mu-plugin >= 1.6, read-only) lists every preset
with its id, every variable and every global colour. `--full` adds each preset's attrs. There is deliberately NO write
command: Divi's own save routes replace the whole store (section 4, rule 11).
All paths below are relative to the Divi theme folder. Abbreviations used in citations:

- `B5/` = `includes/builder-5/server/`
- `GD/` = `includes/builder-5/server/Packages/GlobalData/`
- `DC/` = `includes/builder-5/server/Packages/Module/Layout/Components/DynamicContent/`
- `VBJS/` = `includes/builder-5/visual-builder/build/` (minified; only quoted strings are used)
- `MJ/` = `includes/builder-5/visual-builder/packages/module-library/src/components/<module>/module.json`

Every section separates **Proven by code** from **Inferred, not proven**.

---

## 0. How Divi options are stored (needed for everything below)

`et_get_option()` / `et_update_option()` have three storage modes. `epanel/custom_functions.php:211-256` and `:272-312`:

```php
} elseif ( $is_product_setting ) {
    $et_product_setting_name = 'et_' . $shortname . '_' . $option_name;
    $option_value = $force_default_value ? get_option( $et_product_setting_name, $default_value ) : get_option( $et_product_setting_name );
} elseif ( et_options_stored_in_one_row() ) {
    $et_theme_options_name = 'et_' . $shortname;
    ...
    $option_value = isset( $et_theme_options[$option_name] ) ? $et_theme_options[$option_name] : false;
```

- `$shortname = 'divi';` and `$et_store_options_in_one_row = true;` : `functions.php:16-17`.
- The 8th argument of `et_get_option` / 6th of `et_update_option` is `$is_product_setting`. When `true`, the value is its OWN `wp_options` row named `et_divi_<option_name>`, written with autoload disabled: `update_option( $et_product_setting_name, $new_value, false );` (`epanel/custom_functions.php:291`).
- When it is not a product setting, the value is a KEY inside the single `et_divi` options array.

Resulting storage map (Divi theme; the Divi Builder plugin would have a different `$shortname`, not checked):

| Data | Call in source | Real location |
|---|---|---|
| D5 presets | `et_get_option( self::option_name(), [], '', true, false, '', '', true )` `GD/GlobalPreset.php:195`; `option_name()` returns `'builder_global_presets_d5'` `:152-154` | own row **`et_divi_builder_global_presets_d5`** |
| D4 presets (legacy) | `et_get_option( 'builder_global_presets_ng', (object) [], '', true, false, '', '', true )` `GD/GlobalPreset.php:491` | own row **`et_divi_builder_global_presets_ng`** |
| "D4 presets imported" flag | `'builder_is_legacy_presets_imported_to_d5'`, product setting `GD/GlobalPreset.php:163-165, 222, 469`; value `'yes'` or `''` | own row **`et_divi_builder_is_legacy_presets_imported_to_d5`** |
| Global variables (numbers, strings, images, links, fonts, gradients) | `et_get_option( 'global_variables', [], '', true, false, '', '', true )` `GD/GlobalData.php:916`; write `:1034` | own row **`et_divi_global_variables`** |
| Global colours | `et_get_option( 'et_global_data' )` `GD/GlobalData.php:351`; write `et_update_option( 'et_global_data', $global_data )` `:704` (NOT a product setting) | key **`et_global_data`** inside the **`et_divi`** array, sub-key `global_colors` |
| D4 global colours (legacy) | `et_get_option( 'et_global_colors', false )` `GD/GlobalData.php:552` | key `et_global_colors` inside `et_divi` |
| Customizer colours / fonts | `et_get_option( 'accent_color' ...)` etc. `GD/GlobalData.php:404, 448` | keys inside `et_divi` (see 2.2) |

Side note (proven): `GlobalPreset::delete_data()` calls `et_delete_option( self::option_name() )` (`GD/GlobalPreset.php:173`), and `et_delete_option` has no product-setting branch (`epanel/custom_functions.php:316-329`), so it unsets a key in `et_divi` and does not remove the `et_divi_builder_global_presets_d5` row.

---

## 1. PRESETS

### 1.1 The two kinds (proven)

| | Module preset ("Element Preset") | Option group preset |
|---|---|---|
| `type` field | `'module'` | `'group'` |
| Keyed in storage by | `moduleName` e.g. `divi/text` | `groupName` e.g. `divi/font` |
| Scope | A whole module's attributes. Only usable by that module type. | One option group (font, border, spacing, button...). Reusable on any module that has a group of that `groupName`. |
| Referenced from a block by | `modulePreset` | `groupPreset[<groupId>]` |
| Extra item fields | `moduleName`; optional `groupPresets` (nested group preset references) | `groupId`, `groupName`, `moduleName`, optional `primaryAttrName`; nested refs live at `attrs.groupPreset` |

Sources: REST schema `GD/GlobalPresetController.php:381-427`; `GD/GlobalPreset.php:252-258` (`'module' === $preset_type` uses `$item['moduleName']`, `'group'` uses `$item['groupName']`).

A group preset records the module and group id it was AUTHORED in (`moduleName`, `groupId`); when applied elsewhere its attribute paths are remapped to the host group of the using module (`GD/GlobalPresetItemGroup.php:357-420`, and the comment at `GD/GlobalPreset.php:2551-2553`: "applying a `divi/image` preset authored under `image` to Blurb `imageIcon` should register nested refs as `imageIcon.*`").

### 1.2 Stored data structure (proven)

Option row `et_divi_builder_global_presets_d5`, PHP array:

```
[
  'module' => [
    '<moduleName e.g. divi/text>' => [
      'default' => '<preset id or empty string>',
      'items'   => [
        '<presetId>' => [ ...item... ],
      ],
    ],
  ],
  'group' => [
    '<groupName e.g. divi/font>' => [
      'default' => '<preset id or empty string>',
      'items'   => [ '<presetId>' => [ ...item... ] ],
    ],
  ],
]
```

Built by `GlobalPreset::prepare_data()` `GD/GlobalPreset.php:236-296`: `$prepared[ $preset_type ][ $preset_sub_type ] = [ 'default' => $default, 'items' => [] ]` then `['items'][ $item['id'] ] = $item`.

Item fields. The REST schema has `'additionalProperties' => false` (`GD/GlobalPresetController.php:362`), so this list is the complete set the sync route accepts:

| Field | Type | Required | Notes / source |
|---|---|---|---|
| `type` | string enum | yes | `'module'` or `'group'` `:257-261` |
| `id` | string, minLength 1 | yes | `:262-267`. PHP-generated ids are `\ET_Core_Data_Utils::uuid_v4()` `GD/GlobalPreset.php:2039` |
| `name` | string, minLength 1 | yes | `:268-273` |
| `priority` | integer | no | REST default `10` `:274-278`; read as `$this->_data['priority'] ?? 10` `GD/GlobalPresetItem.php:189-191` |
| `order` | integer | no | `:279-282` |
| `created` | integer | yes | `:283-286`. PHP-generated presets use `time()` (seconds) `GD/GlobalPreset.php:1921` |
| `updated` | integer | yes | `:287-290` |
| `version` | string | yes | `:291-296`. PHP uses `ET_BUILDER_VERSION` `GD/GlobalPreset.php:2008`; D4 conversion uses `ET_CORE_VERSION` `B5/Packages/Conversion/Conversion.php:2218, 2281` |
| `attrs` | object | no | `:297-300` |
| `renderAttrs` | object | no | `:301-304` |
| `styleAttrs` | object | no | `:305-308` |
| `groupPresets` | object keyed by groupId | no | each value `{ presetId: string[] (minItems 1), groupName: string, segmentBoundary?: integer>=0 }`, `additionalProperties false` `:309-340` |
| `moduleName` | string | yes (both kinds) | `:390-395`, `:413-418` |
| `groupId` | string | yes (group only) | `:401-406` |
| `groupName` | string | yes (group only) | `:407-412` |
| `primaryAttrName` | string | no (group only) | `:419-422` |

`attrs`, `renderAttrs`, `styleAttrs` that are empty or not arrays are REMOVED before saving (`GD/GlobalPreset.php:267-279`), then sanitised with `SavingUtility::sanitize_block_attrs` / `sanitize_group_attrs` (`:281-285`).

#### attrs vs renderAttrs vs styleAttrs (proven)

The split is driven by each field's `features.preset` value in the module's `module.json`. Observed values across all 115 module.json files: `"content"`, `["html"]`, `["script"]`, `["style"]`; default when absent is `[ 'style' ]` (`B5/Packages/Conversion/Conversion.php:2582`: `'preset' => $item['features']['preset'] ?? [ 'style' ]`).

D4 conversion shows the intended meaning (`B5/Packages/Conversion/Conversion.php:2271-2283`):

```php
'attrs'       => $converted_preset_attrs,
// 'attrs'    => self::get_preset_attrs( $converted_preset_attrs, [ 'style', 'html', 'script' ], $map ),
'styleAttrs'  => self::get_preset_attrs( $converted_preset_attrs, [ 'style' ], $map ),
'renderAttrs' => self::get_preset_attrs( $converted_preset_attrs, [ 'html', 'script' ], $map ),
```

- `attrs` = the full attribute package of the preset (same nested shape as block attrs: `<element>.<decoration|advanced|innerContent>...<breakpoint>.<state>`).
- `styleAttrs` = subset of fields tagged `style` (produce CSS).
- `renderAttrs` = subset tagged `html` or `script` (change rendered markup or front-end script data, e.g. blurb icon placement).

How each is consumed at render time:

- `renderAttrs` of all stacked module presets are merged in priority order and merged INTO the module's attributes before rendering: `GD/GlobalPreset.php:656-737`; final merge `array_replace_recursive( $default_attributes, $preset_render_attrs, $group_render_attrs, $block_attributes )` `B5/Packages/ModuleLibrary/ModuleRegistration.php:428-433`.
- Preset CSS is generated from `attrs`. `styleAttrs` is merged on top ONLY for a fixed module list: `divi/button`, `divi/cta`, `divi/group-carousel`, `divi/woocommerce-cart-products`, `divi/woocommerce-cart-totals`, `divi/social-media-follow-item` (`B5/Packages/ModuleUtils/ModuleUtils.php:3341-3354`, used at `B5/Packages/Module/Module.php:1631-1639`). For group presets `styleAttrs` is merged for all (`GD/GlobalPreset.php:834`, `ModuleRegistration.php:390, 420-424`).
- A preset "has content" when `attrs` or `styleAttrs` is non-empty (`GD/GlobalPresetItem.php:233-247`). A preset with only `renderAttrs` gets no CSS class.

### 1.3 How a block references presets (proven)

```json
"modulePreset": ["<presetId>", "<presetId2>"],
"groupPreset": {
  "<groupId>": { "presetId": ["<presetId>"], "groupName": "divi/font" }
}
```

- `modulePreset` is an array of ids since `5.0.0-public-beta.2` (`B5/Migration/PresetStackMigration.php`: "from string "1233" to array ["1233"]"). A bare string is still accepted: `normalize_preset_stack()` wraps it (`GD/GlobalPreset.php:3163-3171`).
- `groupPreset[groupId].presetId` likewise array (string tolerated).
- Divi's own embedded agent documentation (quoted string in `VBJS/ai-agent.js`): "Shape: `groupPreset[groupId] = { presetId?: string | string[], groupName: string, panel?: "...", label?: "..." }`" and "`groupName` is the preset family/type, while `groupId` is the concrete host path where that group preset is attached on a module."

#### Where the valid `groupPreset` keys (groupIds / "slots") come from (proven)

`GlobalPreset::get_group_preset_default_attr()` `GD/GlobalPreset.php:2775-2922` builds the map of valid groupIds for a module from its `module.json`. Two families of keys:

1. **Attribute-path ids**: `"{$attr_name}.{$attr_type}.{$group_id}"` where `$attr_type` is `decoration` or `advanced` (`:2790, 2883`). Examples: `module.decoration.background`, `title.decoration.font`, `button.decoration.button`. Sourced from `attributes.<attr>.settings.decoration|advanced.<groupId>` in module.json.
2. **Composite group ids**: keys of `settings.groups` in module.json whose `component.name` is `divi/composite` (or a form-field style component). Key used is `component.props.attrName` if set, else the group key itself (`:2894-2917`). Examples: `designTitleText` (heading), `designText` (text). Group name comes from `component.props.presetGroup`. Example from `MJ/` heading: `"designTitleText": { ... "component": { "name": "divi/composite", "props": { "presetGroup": "divi/font", "dynamicSubgroupHost": true } } }`.
3. `groupType: 'group-items'` entries register each item's `attrName` as the key (`:2839-2874`).

So for ANY module, read its `module.json` to list slots. Server copy of all metadata: `includes/builder-5/server/_all_modules_metadata.php`.

Special suffix: CSS ID & Classes groups use a groupId ending `-id-classes` (e.g. `module.advanced.htmlAttributes-id-classes`), stripped when resolving data (`GD/GlobalPresetItemGroup.php:374-378`).

#### groupName resolution order for a slot (proven, `GD/GlobalPreset.php:2796-2880`)

1. `component.props.presetGroup` if present
2. if no `groupType`: default map (below)
3. `groupType: 'group'`: `groupName` of the config, else default map; skipped entirely if `component.props.grouped === false`
4. `groupType: 'group-item'`: the referenced composite group's `presetGroup`, else component name when `useComponentNameAsPresetGroup`, else the item component name when it is a grouped `group` component
5. fallback: default map

Default map, complete (`GD/GlobalPreset.php:2934-2969`):

| attr type | group id | groupName |
|---|---|---|
| decoration | animation | `divi/animation` |
| decoration | background | `divi/background` |
| decoration | bodyFont | `divi/font-body` |
| decoration | border | `divi/border` |
| decoration | boxShadow | `divi/box-shadow` |
| decoration | button | `divi/button` |
| decoration | conditions | `divi/conditions` |
| decoration | disabledOn | `divi/disabled-on` |
| decoration | filters | `divi/filters` |
| decoration | font | `divi/font` |
| decoration | headingFont | `divi/font-header` |
| decoration | image | `divi/image` |
| decoration | layout | `divi/layout` |
| decoration | overflow | `divi/overflow` |
| decoration | position | `divi/position` |
| decoration | scroll | `divi/scroll` |
| decoration | sizing | `divi/sizing` |
| decoration | spacing | `divi/spacing` |
| decoration | sticky | `divi/sticky` |
| decoration | transform | `divi/transform` |
| decoration | transition | `divi/transition` |
| decoration | zIndex | `divi/z-index` |
| advanced | html | `divi/html` |
| advanced | htmlAttributes | `divi/id-classes` |
| advanced | loop | `divi/loop` |
| advanced | text | `divi/text` |

Component names that are used as their own preset group (`GD/GlobalPreset.php:3013`): `divi/form-field`, `divi/checkbox`, `divi/checkboxes`, `divi/radio`, `divi/radios`.

Additional groupName values found by scanning every module.json with a re-implementation of the rules above (script output, so treat as a survey, not as PHP output): `divi/alignment`, `divi/attributes`, `divi/charts-legend-layout`, `divi/charts-legend-markers`, `divi/charts-tooltip-box`, `divi/charts-tooltip-color-boxes`, `divi/dropdown`, `divi/font-chart-js`, `divi/icon`, `divi/tooltip`, `divi/visibility-settings`. A few modules also declare non-namespaced names: `background`, `bullet`, `circle`, `dividers`.

Survey of slots for common modules (same script; verify against module.json before relying on it):

- `divi/heading`: `module.decoration.{animation,background,border,boxShadow,conditions,disabledOn,filters,layout,overflow,position,scroll,sizing,spacing,sticky,transform,transition,zIndex}`, `module.advanced.{html,loop,text}`, `title.decoration.font` (divi/font), composite `designTitleText` (divi/font).
- `divi/text`: same `module.*` set; `module.advanced.text` resolves to `divi/font-body` here; `content.decoration.headingFont` (divi/font-header), `content.decoration.bodyFont` (divi/font-body), composite `designText` (divi/font-body).
- `divi/button`: `module.*` set (no background/border/sizing on module), plus `button.decoration.{background,border,boxShadow,button,font,sizing,spacing}`.
- `divi/blurb`: `module.*` set, `title.decoration.font`, `content.decoration.bodyFont`, `imageIcon.*` groups mapped to `divi/image`, composites `designSizing`, `designAnimation`.

#### Stacking and order (proven)

- Multiple ids are allowed in both arrays.
- Merge order is by each preset's `priority` ASCENDING (default 10), so the higher priority wins; ties keep array order, later wins (`usort` with `<=>` then `array_replace_recursive`): module presets `GD/GlobalPreset.php:3289-3307`; group presets `:791-798, 817-847`.
- For group presets on one groupId: nested presets (coming from a module preset's `groupPresets`) merge FIRST, explicit block-level `groupPreset` ids merge AFTER and win (`:779-799`, `:2470-2488`).
- Ids `''`, `'default'`, `'_initial'` are dropped from a stack (`:3166, 3181`).
- CAVEAT: `get_selected_preset()` returns only the LAST id of the stack (`$preset_id = end( $preset_ids );` `:593`) and falls back to the default preset if that last id does not exist (`:617-629`). It is used for parent/sibling lookups and the CSS selector item. Attribute merging (`get_merged_attrs`) and class names use the full stack.

### 1.4 Precedence (proven)

`GlobalPreset::get_merged_attrs()` `GD/GlobalPreset.php:3335`:

```php
$merged_attrs = array_replace_recursive( $module_presets_attrs, $group_presets_attrs, $group_presets_render_attrs, $module_attrs );
```

Lowest to highest:

1. module.json defaults
2. module preset(s): the explicit `modulePreset` stack, OR the module type's `default` preset if the stack is empty (`:3265-3268`)
3. option group presets, resolved as: module-defaults map, then nested references from module presets, then explicit `groupPreset` entries (`:2352-2503`)
4. attributes set directly on the block (always win)

`array_replace_recursive` means a block attr only overrides the exact leaf it sets. A few "mergeable" fields are combined rather than replaced (`ArrayUtility::apply_mergeable_fields_logic`, `:3339-3344`; e.g. `module.decoration.attributes`, per the docblock at `:3219-3222`).

Important: at render time, block attribute values that are IDENTICAL to the preset's values are stripped from the block (`B5/Packages/ModuleLibrary/ModuleRegistration.php:392-402`, "Strip block attributes before preset/default merge so baked import values are not re-injected"), but only when a preset is actually assigned.

**"default"**: each module type and each group name has a `default` pointer holding a real preset id. A block with no `modulePreset`, an empty one, or the literal values `"default"` / `"_initial"` gets that default preset. `is_preset_id_as_default()` `:3209-3211`:

```php
return '' === $preset_id || 'default' === $preset_id || '_initial' === $preset_id || $default_preset_id === $preset_id;
```

**"_initial"**: documented in that docblock as "'_initial' (for legacy presets)". Treated exactly like `default`.

Group default fallback: when a group slot has no explicit/nested preset, the group family's `default` preset is applied (`:2619-2665`), EXCEPT `divi/tabs` groupId `activeTab.decoration.background` (`:2624-2635`). If any custom preset of the same `groupName` is present on the module, the default for that groupName is dropped (`:2706-2720`).

**Referenced id does not exist** (proven):
- Module preset: a missing id is silently skipped in merges (`isset(...)` guards `:700, 3273`); if the LAST id is missing, `get_selected_preset` falls back to the default preset (`:617-629`). The class name for the missing id is STILL added to the element (`get_module_preset_class_names` does not check existence, `:919-936`), with no CSS behind it.
- Group preset: item is created with `isExist => false`, which yields empty attrs (`GD/GlobalPresetItemGroup.php:358-360`); every requested groupId is still returned as a placeholder (`GD/GlobalPreset.php:2748-2761`). No error, no styling.

### 1.5 Presets to CSS on the front end (proven)

Class names, `GD/GlobalPresetItemUtils.php:44-96`:

- Module preset: `preset--module--<module-name-kebab>--<presetId|default>` e.g. `preset--module--divi-text--default` (`sprintf( 'preset--%s--%s--%s', ...)` `:84`).
- Group preset: `preset--group--<module-kebab>--<group-kebab>--<presetId|default>`; if the groupId does not start with `module.` a host token `--h<6 base36 chars>` is inserted before the id (`:70-72, 107-130`); nested ones insert `--nested--` (`:75-77`).
- No class is produced for groups `divi/id-classes` and `divi/animation` (`:46, 60-63`).
- The id segment is the literal `default` whenever the preset is the type's default (`GD/GlobalPreset.php:921-922`).
- One class per stacked preset is added to the module and its wrapper (`B5/Packages/Module/Module.php:1095-1115`). With no assigned preset, the default class is added only if the default preset has content (`GD/GlobalPreset.php:894-913`).

CSS generation: during module render, `Module::render_styles_preset_group` (nested) then `render_styles_preset_module` then `render_styles_preset_group` (explicit) run in that order so later output wins the cascade (`B5/Packages/Module/Module.php:643-704`). Style groups are named `presetNested`, `preset`, `presetGroup` (`B5/FrontEnd/Module/Style.php:408`). The preset's attrs are rendered with the preset class as the selector instead of the module order class (`Module.php:1607-1645`). Each preset selector is rendered once per page (`Style::is_preset_selector_processed`, `Style.php:294`).

Caching: saving presets through Divi clears all static CSS: `ET_Core_PageResource::remove_static_resources( 'all', 'all', true, 'all', true, false, $skip_external_cache );` (`GD/GlobalPreset.php:451`). The same happens for colours (`GD/GlobalData.php:707`), variables (`:1037`) and fonts (`GD/GlobalDataController.php:174`).

### 1.6 REST route for presets (proven)

Only ONE preset route exists. There is NO GET route for presets.

| | |
|---|---|
| Namespace | `divi/v1` (`B5/VisualBuilder/REST/RESTRegistration.php:148`) |
| Route | `POST /wp-json/divi/v1/global-data/global-preset/sync` (`:1665-1672`) |
| Callback | `GlobalPresetController::sync` |
| Permission | `return UserRole::can_current_user_use_visual_builder() && current_user_can( 'edit_theme_options' );` (`GD/GlobalPresetController.php:465-467`) |

Arguments (`sync_args()` `:381-456`):

- `presets` (required object, `additionalProperties false`): `{ module?: Array<{ default: string, items: Item[] }>, group?: Array<{ default: string, items: Item[] }> }`. NOTE the REST shape is ARRAYS of records with `items` as an ARRAY; storage is keyed maps. `prepare_data()` converts, grouping by each item's `moduleName` / `groupName`.
- `actionType` (string, optional). Only `DELETE_MODULE_PRESET` and `DELETE_OPTION_GROUP_PRESET` are special-cased.
- Chunking: `isChunked` (bool, default false), `chunkIndex`, `totalChunks`, `isLastChunk`, `requestId`. If `isChunked` is false, none of the other four may be present (`:67-77`). Chunks are held in transient `et_global_preset_chunks_<userId>_<requestId>` for 300 s (`:34, 197-199`).

Semantics, critical: the payload REPLACES THE ENTIRE preset store. `$saved_data = GlobalPreset::save_data( $prepared_data );` (`:184`) writes exactly what was sent. There is no per-preset create/update endpoint. Response is the full saved data.

#### Nonce requirement and Application Passwords

Every `divi/v1` route registered through `RESTRoute` gets a `rest_request_before_callbacks` filter (`B5/Framework/Route/RESTRoute.php:92-152`):

```php
if ( self::NONCE_POLICY_WP_ONLY !== $nonce_policy ) {
    if ( ! wp_verify_nonce( $request->get_header( 'X-ET-Nonce' ), RESTController::get_nonce_name( $this->_namespace, $full_route, $request_method ) ) ) {
        return RESTController::response_error_nonce();
    }
}
```

Nonce action name = `<full route>--<METHOD>` (`B5/Framework/Controllers/RESTController.php:158-160`), error code `invalid_nonce` (`:131-133`). Default policy is `NONCE_POLICY_ET_AND_WP`, described in source as "Require both Divi `X-ET-Nonce` and WordPress cookie REST auth (default)" (`RESTRoute.php:27-34`).

Plain answer: **an Application Password alone does NOT pass.** The permission callback itself would pass for an administrator (capability checks only), but the request is rejected earlier with `invalid_nonce` unless a valid `X-ET-Nonce` header for that exact route+method is supplied. This applies to all four global-data routes and to `/dynamic-content/options` and `/dynamic-data`.

Only two routes opt out with `NONCE_POLICY_WP_ONLY`: `GET /divi/v1/settings-data/nonces` (`RESTRegistration.php:200-208`) and `POST /divi/v1/seo/rendered-content` (`:1306-1314`). `/settings-data/nonces` returns `Nonce::get_data()` (all route nonces) and its permission is only `UserRole::can_current_user_use_visual_builder()` (`B5/VisualBuilder/SettingsData/SettingsDataController.php:256-288`).

### 1.7 Limits (proven)

There is NO maximum preset count. `validate_preset_count()` (`GD/GlobalPreset.php:358-431`) is a DECREASE guard: unless `actionType` is `DELETE_MODULE_PRESET` or `DELETE_OPTION_GROUP_PRESET`, a sync whose module count, group count or total is LOWER than what is in the database is rejected with WP_Error `preset_count_decreased`, HTTP 400, message e.g. `CRITICAL: Module preset count decreased during sync! Current: %d, Sync: %d. Action: %s`. It counts only; it does not check that the same ids survive.

### 1.8 Divi 4 preset import (proven, brief)

- D4 presets live in `et_divi_builder_global_presets_ng`, shape `{ <d4_shortcode>: { default: <id>, presets: { <id>: { name, version, settings{...} } } } }` (`GD/GlobalPreset.php:486-530`).
- Conversion runs when VISUAL BUILDER settings data is built: `SettingsDataCallbacks::global_presets()` calls `GlobalPreset::maybe_convert_legacy_presets()` (`B5/VisualBuilder/SettingsData/SettingsDataCallbacks.php:638-651`). It re-runs later if new D4 presets appear (`GD/GlobalPreset.php:1776-1787`), then sets the imported flag to `'yes'`.
- Each D4 preset becomes a `type: 'module'` item keeping its D4 id, with `attrs`/`styleAttrs`/`renderAttrs` (`B5/Packages/Conversion/Conversion.php:2216-2285`). Defaults carried over per module (`:2171-2183`). Extra shortcode mappings: `et_pb_section_fullwidth` to `divi/fullwidth-section`, `et_pb_section_specialty` to `divi/specialty-section`, `et_pb_slide_fullwidth` to `divi/slide` (`:2238-2243`).
- Deleting a preset in D5 also removes it from the D4 option to prevent re-migration (`GD/GlobalPresetController.php:166-182`).
- Read-time migrations for presets exist in `B5/Migration/*PresetMigration.php` and are also applied at runtime (`_maybe_runtime_migrate_preset_data`, `GD/GlobalPreset.php:3358`).

### Inferred, not proven (presets)

- That `GET /divi/v1/settings-data/nonces` called with an Application Password returns nonces that then validate on later Application Password requests. Reasoning: `wp_create_nonce`/`wp_verify_nonce` bind to user id + session token, and both calls would be the same user with an empty session token. NOT verified: the nonces are created when routes are REGISTERED (`Nonce::add_data(...)` at `RESTRoute.php:85-90`), which may run before WordPress has authenticated the Application Password user, in which case they would be user-0 nonces and fail. The source comment for that route also says "WordPress REST cookie + `X-WP-Nonce` still apply". Must be tested live.
- Whether the JS client writes `created`/`updated` in seconds or milliseconds: not found (PHP-generated presets use `time()` seconds).
- The exact TypeScript interface `GlobalData.Presets.Items` referenced in docblocks is not in the distributed theme; structure above is reconstructed from PHP.
- `panel` and `label` keys inside a block's `groupPreset[groupId]` appear only in the quoted ai-agent.js text; no PHP reads them.
- The per-module slot survey in 1.3 came from a script approximating the PHP rules.

---

## 2. GLOBAL VARIABLES ("Design Variables")

### 2.1 Types, id prefixes, storage (proven)

Two separate stores:

**A. Global colours**: prefix `gcid-`. Stored at `et_divi['et_global_data']['global_colors']`, map keyed by id. Item fields as written by Divi (`GD/GlobalData.php:157-164`, example `:649-661`):

| Field | Notes |
|---|---|
| `color` | required, non-empty, else the entry is dropped (`:613-618`). May be a literal or a `$variable(...)$` token referencing another colour |
| `status` | `'active'` or `'inactive'` (docblocks also mention `temporary`) |
| `lastUpdated` | string, format `wp_date( 'Y-m-d\TH:i:s.v\Z' )` |
| `usedInPosts` | array of post ids |
| `label` | string (`''` on conversion, comment "not until D6") |
| `folder` | string (`''`; customizer colours report `'customizer'`) |

Sanitiser keeps any key, `sanitize_text_field` on all values, ids must start with `gcid-` and must not be `'undefined'` (`:602-634`). D4 source format was `{ color, active: 'yes'|'no' }` under `et_global_colors`, converted once when `et_global_data` does not exist (`:542-568`).

**B. Global variables**: prefix `gvid-`. Stored in row `et_divi_global_variables`, map of type to map of id to item. Types accepted on save, complete list (`GD/GlobalData.php:830`):

`numbers`, `strings`, `images`, `links`, `fonts`, `gradients`

`get_global_variables()` also returns an always-present `colors` key (`:922-930`), but `colors` is NOT in the save allow-list, so it is never persisted here. Colours live only in store A. Each type is returned cast to an object (`:977-980`).

Item fields (docblock `:878-889`, save example `:998-1014`):

| Field | Notes |
|---|---|
| `id` | the same `gvid-...` id repeated INSIDE the item. Front-end code reads `$value['id']` (`B5/FrontEnd/Module/Style.php:1470`, `DC/DynamicContentGlobalVariableOptions.php:85`), so it must be present |
| `label` | REQUIRED, entries with empty label are dropped (`:839`) |
| `value` | string |
| `order` | int |
| `status` | `'active'` or `'archived'` (`:883`) |
| `lastUpdated` | appears in the JS action (`VBJS/global-data.js`: `type:"ADD_GLOBAL_VARIABLE",id:S(e,s),label:t,value:r,groupKey:s,status:n,order:o,allowedActions:a,lastUpdated:i`) |
| `allowedActions` | added on READ only (`editLabel, editValue, reorder, remove`, all true, `:955-973`); stripped on save (`:849-851`) |

Value sanitising on save (`:853-862`): `strings` and `gradients` values keep their characters (only invalid UTF-8 and null bytes removed); `links` values go through `esc_url_raw`; everything else `sanitize_text_field`.

Gradient variable value: a token string `$variable({"type":"gradient","value":{"name":"gradient","settings":{...}}})$` where `settings` holds `type`, `direction`, `directionRadial`, `stops[]` (each with `color`), `repeat`, `length` (quoted from `VBJS/ai-agent.js`: `` `$variable(${JSON.stringify({type:"gradient",value:{name:"gradient",settings:o}})})$`),groupKey:"gradients" ``; PHP reader `GD/GlobalData.php:1349-1400`, keys used at `B5/FrontEnd/Module/Style.php:1526-1534`).

Id generation in the client (quoted `VBJS/global-data.js`): ``S=(e,t)=>e||("colors"===t?`gcid-${_()}`:`gvid-${_()}`)``. Example ids in PHP docblocks: `gvid-98eb727ac3`, `gcid-3cf7c9305a`, and uuid forms. Colour id pattern accepted by the parser: `/--gcid-([0-9a-z-]*)/` (`GD/GlobalData.php:778`), i.e. lowercase letters, digits, hyphen only.

A `gfid` prefix string exists in `VBJS/module-utils.js` (`i.startsWith("gcid")||i.startsWith("gvid")||i.startsWith("gfid")`) but has no PHP counterpart: not found in any `.php` file.

### 2.2 Built-in, Customizer-backed entries (proven)

Colours (`GD/GlobalData.php:58-84`), always injected fresh on read and never stored in `global_colors` (`:355-365`); writing one through `set_global_colors` updates the theme option instead (`:685-692`):

| Id | Label | `et_divi` key | Default |
|---|---|---|---|
| `gcid-primary-color` | Primary Color | `accent_color` | `#2ea3f2` |
| `gcid-secondary-color` | Secondary Color | `secondary_accent_color` | `#2ea3f2` |
| `gcid-heading-color` | Heading Text Color | `header_color` | `#666666` |
| `gcid-body-color` | Body Text Color | `font_color` | `#666666` |
| `gcid-link-color` | Link Color | `link_color` | `#2ea3f2` |

Fonts (`:93-104`), injected into `fonts` on read (`:934-952`), ids are literally the CSS custom property names INCLUDING the leading `--`:

| Id | Label | `et_divi` key | Default | order |
|---|---|---|---|---|
| `--et_global_heading_font` | Heading | `heading_font` | `Open Sans` | 1 |
| `--et_global_body_font` | Body | `body_font` | `Open Sans` | 2 |

### 2.3 Token syntax per type

General form: `$variable(<JSON>)$` with JSON `{"type":"<type>","value":{"name":"<id>","settings":{...}}}`. Parser regex everywhere: `/\$variable\((.+?)\)\$/` (`B5/Packages/StyleLibrary/Utils/Utils.php:98`, `B5/Packages/Module/Layout/Components/DynamicData/DynamicData.php:403`). Both the leading `$variable(` and the trailing `)$` are required. Escaped quotes `\"` inside are tolerated (`Utils.php:103-105`).

**Proven in PHP:**

- In a CSS/style context, ANY token with a `value.name` resolves to `var(--<name>)`, and only `type: "color"` gets extra processing (`Utils.php:120-130`):
  ```php
  $normalized_name = preg_replace( '/^--/', '', $name );
  $css_variable    = "var(--{$normalized_name})";
  switch ( $type ) { case 'color': return GlobalData::transform_state_into_global_color_value( $css_variable, $decoded['value']['settings'] ?? [] ); default: return $css_variable; }
  ```
- Types the content processor acts on: `content`, `shortcode`, `color`; plus the two customizer font names regardless of type (`DynamicData.php:311-341`). Type `image` is recognised by `Utils::is_global_image_variable` (`Utils.php:245`). Type `gradient` by `resolve_global_gradient_variable` (`GlobalData.php:1362`).
- PHP's own documented example for a gvid variable uses `type: "content"`: `$variable({"type":"content","value":{"name":"gvid-abc123"}})$` giving `var(--gvid-abc123)` (`Utils.php:77-78`), and `$variable({"type":"content","value":{"name":"gvid-bfhzpqo17e","settings":{}}})$` for a font family (`B5/FrontEnd/Assets/DetectFeature.php:1594`).

**Type names emitted by Divi's own JS** (quoted from `VBJS/ai-agent.js`): `switch(e){case"color":return Wj({type:"color",name:t});case"number":return Wj({type:"number",name:t});case"image":return Wj({type:"image",name:t});case"font":return iH("font",t);case"gradient":return iH("gradient",t);default:return Wj({type:"content",name:t})}`. Divi's embedded docs in the same file: "variable-driven numeric values use `$variable({"type":"number","value":{"name":"gvid-...","settings":{}}})$` and may be used inside expressions such as `calc($variable(...)$ + 10px)` when supported by the field."

Examples:

| Kind | Token |
|---|---|
| Global colour | `$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$` |
| Colour with adjustments | `$variable({"type":"color","value":{"name":"gcid-3cf7c9305a","settings":{"opacity":50,"lightness":-10}}})$` |
| Number | `$variable({"type":"number","value":{"name":"gvid-98eb727ac3","settings":{}}})$` (JS form) or `"type":"content"` (PHP-documented form); both resolve to `var(--gvid-98eb727ac3)` in CSS |
| Font | `$variable({"type":"font","value":{"name":"gvid-bfhzpqo17e","settings":{}}})$` (JS) or `"type":"content"` (PHP comment) |
| Customizer font | `$variable({"type":"content","value":{"name":"--et_global_heading_font","settings":{}}})$` resolves to `var(--et_global_heading_font)` (`DynamicData.php:311-313`) |
| Image | `$variable({"type":"image","value":{"name":"gvid-...","settings":{}}})$` |
| Gradient | `$variable({"type":"gradient","value":{"name":"gvid-...","settings":{}}})$` |
| String / link (text or URL fields) | `$variable({"type":"content","value":{"name":"gvid-...","settings":{}}})$`, resolved by the dynamic-content option whose name prefix is `gvid-` (`DC/DynamicContentGlobalVariableOptions.php:32-34, 137-162`) |

Colour `settings` keys, complete (`GD/GlobalData.php:187-191`): `hue`, `saturation`, `lightness` (numbers, added to the base as relative HSL), `opacity` (0-100). Output (`:221`):

```
hsl(from var(--gcid-x) calc(h + H) calc(s + S) calc(l + L) / 0.5)
```

If hue, saturation, lightness are all 0 and opacity is absent, plain `var(--gcid-x)` is returned (`:208-215`). An explicit `opacity: 100` prints `/ 1`. Negative saturation is wrapped in `max(0, ...)`.

**Nested colours / dependencies (proven).** A global colour's own `color` value may be a `$variable(...)$` token or contain `var(--gcid-...)`. `collect_global_color_dependencies()` (`GD/GlobalData.php:236-327`) walks these recursively (loop-protected) so that when a page uses colour A defined in terms of B, both `--gcid-A` and `--gcid-B` are emitted. When printed, a nested value is resolved with `Utils::resolve_dynamic_variable` (`B5/FrontEnd/Module/Style.php:1379`), so `--gcid-A: hsl(from var(--gcid-B) ...)`. Max resolution depth 10 (`Utils.php:386`, `GlobalData.php:1354`).

Content-context behaviour of a gvid token (`DC/DynamicContentGlobalVariableOptions.php:81-99`): `numbers` and `fonts` return `var(--<id>)` (fonts also enqueue the font); all other types return the raw stored `value`. `strings` are output through `wp_kses_post`, other types through `esc_html` (`:148-153`).

### 2.4 Front-end resolution (proven)

CSS custom properties on `:root`, not inlined values.

- Colours: `Style::get_global_colors_style()` builds `:root{--gcid-...: <value>;...}` (`B5/FrontEnd/Module/Style.php:1362-1398`).
  - Dynamic Assets ON: only colours detected in the page, plus their dependencies, are put in the page's dynamic CSS (`B5/FrontEnd/Assets/DynamicAssets/DynamicAssetsListBuilder.php:309, 601`).
  - Dynamic Assets OFF: ALL colours are printed in `wp_footer` as `<style class="et-vb-global-data et-vb-global-colors">` (`B5/FrontEnd/FrontEnd.php:111, 685-696`).
- Numbers, fonts, images, gradients: `Style::get_global_numeric_and_fonts_vars_style()` (`Style.php:1449-1554`), printed in `wp_footer` as `<style class="et-vb-global-data et-vb-global-numeric-vars">` (`FrontEnd.php:112, 729-788`). Property name is `--<id>` (no double `--` for the customizer fonts, `:1543`). Fonts are quoted, images wrapped in `url(...)`, gradients rendered to a gradient declaration.
  - Variable ids are detected in post content + active Theme Builder templates + appended canvases; when ids are found, those are printed REGARDLESS of `status`; when none are found but variables exist, all `active` ones are printed (`FrontEnd.php:736-780`, `Style.php:1474-1485`).
- `strings` and `links` are never CSS variables; they are substituted into HTML as content.

### 2.5 REST routes (proven)

All `POST`, namespace `divi/v1`, all require `X-ET-Nonce` (see 1.6). **There is no GET route for colours, variables or presets.** The builder receives them inlined in page settings data (`B5/Framework/Settings/Settings.php:223-226`, `B5/VisualBuilder/SettingsData/SettingsDataCallbacks.php:638-651`).

| Route | Arg | Permission callback | Behaviour |
|---|---|---|---|
| `/global-data/global-colors` (`RESTRegistration.php:1629-1636`) | `global_colors` (array, required; sanitised by `sanitize_global_colors_data`) | `current_user_can( 'edit_posts' )` (`GD/GlobalDataController.php:124-130`) | calls `set_global_colors( $global_colors, true )`; with `already_sanitized = true` the data is MERGED into existing colours via `array_merge` (`GD/GlobalData.php:694-701`), so this route cannot delete a colour. Customizer ids update theme options. Returns all colours. |
| `/global-data/global-variables` (`:1653-1660`) | `global_variables` (array, required; sanitised) | `current_user_can( 'edit_posts' )` (`GlobalDataController.php:350-356`) | REPLACES the whole store (`GD/GlobalData.php:1034`). Inner guard: silently does nothing unless `current_user_can( 'edit_theme_options' ) && et_pb_is_allowed( 'variables_manager' )` (`:1021-1023`); the route still returns 200 with current data. |
| `/global-data/global-fonts` (`:1641-1648`) | `heading_font`, `body_font` (both required) | `current_user_can( 'edit_posts' )` (`:243-249`) | writes `et_divi['heading_font']`, `et_divi['body_font']` |
| `/global-data/global-preset/sync` | see 1.6 | VB access + `edit_theme_options` | replaces whole preset store |

Application Password analysis: identical to 1.6. Capability checks would pass for an admin, the `X-ET-Nonce` check would not.

### Inferred, not proven (variables)

- That `"type":"number"` / `"font"` / `"image"` tokens and `"type":"content"` tokens are fully interchangeable for gvid ids. PHP's CSS resolver ignores the type for everything except `color`, which supports it, but the VB editor UI may only recognise a field as "variable-bound" for the type it writes. Safest is to copy the exact token form from a block the Visual Builder saved on the target site.
- Whether variable detection (`DetectFeature::get_global_variable_ids`) keys on the `gvid-` text or on the token type: regex not read.
- `status` values for colours other than `active`/`inactive` (`temporary` appears only in docblocks).
- Whether `lastUpdated` is persisted for gvid variables by the sanitiser: it keeps any key except `allowedActions`, so yes if sent; never generated server-side.

---

## 3. DYNAMIC CONTENT tokens (`type: "content"`)

Form: `$variable({"type":"content","value":{"name":"<option name>","settings":{...}}})$`. Resolved by `DynamicContentUtils::get_processed_dynamic_content` (`DC/DynamicContentUtils.php:482`). `value` may also carry `post_id`; if absent the current post id is injected (`DynamicData.php:307-309`). Quoted from `VBJS/module-utils.js`: `{type:"content",value:{name:e,post_id:r.id,settings:u}}`.

Common field sets:
- Loop options use `get_common_loop_fields()` = `before`, `after`, `loop_position` (`DC/DynamicContentUtils.php:271`).
- Date options use `get_date_format_fields()` = `date_format`, `custom_date_format` (`DC/DynamicContentUtils.php:393`).

Built-in option names. Source file is `DC/DynamicContentOption<Name>.php`; line is `get_name()`. "settings" lists field keys declared in the file plus keys the render code reads.

| Option name | Also registers | settings keys | Source |
|---|---|---|---|
| `post_title` | `loop_post_title` | before, after | PostTitle.php:31 |
| `post_excerpt` | `loop_post_excerpt` | before, after, words, read_more_label | PostExcerpt.php:31 |
| `post_date` | `loop_post_date`, `loop_product_post_date` | before, after, date_format, custom_date_format | PostDate.php:32 |
| `post_modified_date` | `loop_post_modified_date`, `loop_product_post_modified_date` | before, after, date_format, custom_date_format (before/after = first two common loop fields, `:71-72`) | PostModifiedDate.php:32 |
| `post_comment_count` | `loop_post_comment_count`, `loop_product_post_comment_count` | before, after, link_to_comments_page | PostCommentCount.php:31 |
| `post_categories` | `loop_post_terms`, `loop_product_terms` | before, after, link_to_term_page, separator, category_type (loop: taxonomy_type, links) | PostCategories.php:31 |
| `post_tags` | - | before, after, link_to_term_page, separator, category_type | PostTags.php:31 |
| `post_link` | `loop_post_link` | before, after, text, custom_text | PostLink.php:31 |
| `post_link_url` | `loop_product_post_link_url` | loop_position | PostLinkUrl.php:31 |
| `post_link_url_<post_type>` (name template `post_link_url_%1$s`) | - | post_id | CustomPostLinkUrl.php:33 |
| `any_post_link_url` | - | post_id | AnyPostLinkUrl.php:44 |
| `post_author` | `loop_post_author` | before, after, name_format, link, link_destination | PostAuthor.php:31 |
| `post_author_bio` | `loop_post_author_bio` | before, after | PostAuthorBio.php:29 |
| `post_author_url` | - | none | PostAuthorUrl.php:29 |
| `post_author_profile_picture` | `loop_post_author_profile_picture` | loop_position | PostAuthorProfilePicture.php:29 |
| `post_featured_image` | `loop_post_featured_image`, `loop_product_post_featured_image` | thumbnail_size, loop_position | PostFeaturedImage.php:32 |
| `post_featured_image_alt_text` | - | none ("doesn't have any settings") | PostFeaturedImageAltText.php:31 |
| `post_featured_image_title_text` | - | none | PostFeaturedImageTitleText.php:31 |
| (name `post_id`) | registers only `loop_post_id` | loop common | PostID.php:29 |
| `post_meta_key` | per-field `custom_meta_<meta_key>`; manual entry `custom_meta_manual_custom_field_value` | before, after, select_meta_key, meta_key, date_format, enable_html | PostMetaKey.php:45, 85, 225-290 |
| `loop_manual_custom_field` | `<prefix>manual_custom_field` variants | before, after, select_loop_meta_key, loop_meta_key, date_format, custom_date_format, loop_position, acf_type, enable_html | LoopPostMetaKey.php:36 |
| `acf_groups` | `loop_acf_<group>|||<field>` | loop common | ACFGroups.php:30, 147 |
| `site_title` | - | before, after | SiteTitle.php:29 |
| `site_tagline` | - | before, after | SiteTagline.php:29 |
| `site_logo` | - | none | SiteLogo.php:29 |
| `home_url` | - | none | HomeUrl.php:29 |
| `current_date` | - | before, after, date_format, custom_date_format | CurrentDate.php:31 |
| `term_description` | - | before, after | TermDescription.php:31 |
| `terms_groups` (container) | `loop_term_name`, `loop_term_description`, `loop_term_count`, `loop_term_permalink`, `loop_term_taxonomy`, `loop_term_featured_image` | loop common | TermsGroups.php:30, 63-90 |
| `users_groups` (container) | `loop_user_name`, `loop_user_username`, `loop_user_email`, `loop_user_avatar`, `loop_user_description`, `loop_user_url` | loop common | UsersGroups.php:30, 63-90 |
| `loop_menu_text`, `loop_menu_link`, `loop_menu_description`, `loop_menu_classes`, `loop_menu_xfn`, `loop_menu_menu_order`, `loop_menu_attr_title` | - | loop common | LoopMenu*.php:29 |
| `product_title` (name) | registers only `loop_product_title` | loop common | ProductTitle.php:29 |
| `product_price` | `loop_product_price_regular`, `loop_product_price_sale`, `loop_product_price_current` | before, after | ProductPrice.php:32 |
| `product_description` | `loop_product_description` | before, after | ProductDescription.php:32 |
| `product_short_description` | `loop_product_short_description` | before, after | ProductShortDescription.php:32 |
| `product_sku` | `loop_product_sku` | before, after | ProductSKU.php:31 |
| `product_reviews_count` | `loop_product_reviews_count` | before, after | ProductReviewsCount.php:31 |
| `product_reviews` | - | before, after, enable_title | ProductReviews.php:31 |
| `product_reviews_tab` | - | none | ProductReviewsTab.php:31 |
| `product_additional_information` | - | before, after, enable_title | ProductAdditionalInformation.php:32 |
| `product_breadcrumb` | - | before, after | ProductBreadcrumb.php:32 |
| (name `product_id`) | registers only `loop_product_id` | loop common | ProductID.php:29 |
| (name `product_stock_quantity`) | registers only `loop_product_stock_quantity` | loop common | ProductStockQuantity.php:29 |
| (name `product_stock_status`) | registers only `loop_product_stock_status` | loop common | ProductStockStatus.php:29 |
| `gvid-` (prefix match) | any global variable id | none | DynamicContentGlobalVariableOptions.php:32 |
| `loop_` (prefix handler) | all loop names; honours `settings.loop_position` (1-based) | - | DynamicContentLoopOptions.php:35, 130-134 |

Live, site-specific list (includes discovered custom fields, ACF, CPT link options): `GET /wp-json/divi/v1/dynamic-content/options?postId=<id>` (`RESTRegistration.php:715-725`); permission = VB access + `current_user_can( 'edit_post', $post_id )` (+ `et_pb_is_allowed( 'theme_builder' )` for Theme Builder layout posts) (`DC/DynamicContentOptionsController.php:144-164`). Requires `X-ET-Nonce` like the rest.

Divi's embedded guidance (quoted `VBJS/ai-agent.js`): "`$variable({...})` is invalid (missing trailing `$`)"; "Wrong: `$variable({"type":"post","value":{"name":"title","settings":{}}})$`. Correct: `$variable({"type":"content","value":{"name":"loop_post_title","settings":{}}})$`."

### Inferred, not proven (dynamic content)

- The settings-key column was extracted by script (declared `'key' => [ 'label' ...` entries plus `$settings['key']` reads). Defaults, allowed values (e.g. `thumbnail_size`, `name_format`, `date_format` options) were not extracted.
- Which WooCommerce options are registered when WooCommerce is inactive: not checked.
- The exact `<prefix>` variants of `manual_custom_field` (`$prefix . 'manual_custom_field'`) were not enumerated.

---

## 4. PRACTICAL RULES for the agent

1. Reference presets only by ids that exist in the target site's `et_divi_builder_global_presets_d5`. A missing id fails SILENTLY (no error, no style, dead class name on the element).
2. Write `modulePreset` as an ARRAY of ids and `groupPreset[<groupId>]` as `{ "presetId": ["<id>"], "groupName": "<divi/...>" }`. Never write a preset's attrs into the block "to be safe": identical values are stripped at render and differing values override the preset.
3. `groupName` must equal the preset's own `groupName`, and the key must be a groupId valid for THAT module (`<attr>.<decoration|advanced>.<group>` or a composite id such as `designTitleText`). Take both from the module's `module.json`, do not guess.
4. To use the site default preset, OMIT `modulePreset` (or use `["default"]`). Do not copy the default preset's real id.
5. Stack order: last id wins among equal `priority`; a preset with a higher `priority` wins regardless of position. Block-level attrs always win over every preset.
6. Reference a colour as `$variable({"type":"color","value":{"name":"gcid-...","settings":{}}})$`; optional `settings` keys are exactly `hue`, `saturation`, `lightness`, `opacity` (0-100). Never write `var(--gcid-...)` or a hex when the intent is a global colour.
7. Every token needs both `$variable(` and the trailing `)$`, with valid JSON between.
8. Built-in ids that always exist: `gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color`, `--et_global_heading_font`, `--et_global_body_font`. Changing them changes the Customizer theme options.
9. Do NOT expect the `divi/v1` REST routes to work with an Application Password: they require an `X-ET-Nonce` header, and there is no GET route for presets, colours or variables anyway. Read the data with `wp.js <site> design-system` (mu-plugin >= 1.6), which reads the options: `et_divi_builder_global_presets_d5`, `et_divi_global_variables`, `et_divi['et_global_data']['global_colors']`.
10. If presets/variables are ever written outside Divi's own functions (direct option write), Divi's static CSS cache is NOT cleared. Prefer calling `GlobalPreset::save_data()`, `GlobalData::set_global_variables()`, `GlobalData::set_global_colors()` server-side, which clear it.
11. Preset sync and variable save REPLACE THE WHOLE STORE. Always read, modify, write back the complete set. A preset sync with fewer presets than the database is rejected (`preset_count_decreased`) unless `actionType` is a DELETE action; a variables save has NO such guard and will silently delete whatever is omitted.
12. The colours route merges and cannot delete; `GlobalData::set_global_colors( $data )` with the default second argument replaces.
13. New variable items must contain `id` (same as the key, `gvid-` prefix), non-empty `label`, `value`, `status: "active"`, `order`. New colour items need a `gcid-` key matching `[0-9a-z-]` and a non-empty `color`.
14. A new preset item must include `type`, `id`, `name`, `moduleName`, `version`, `created`, `updated` (+ `groupId`, `groupName` for group presets). No other keys than those listed in 1.2 are accepted by the REST schema.
15. Do not create a module preset with only `renderAttrs`: it gets no CSS class and counts as having no content.
16. Do not invent dynamic content names. Use names from section 3; on loops use the `loop_*` names with `"type":"content"`.

---

## Things worth verifying on a live site (read-only)

1. `get_option('et_divi_builder_global_presets_d5')` exists, is an array with top-level keys `module` and/or `group`, each child having `default` and `items` keyed by preset id. Confirms 0 and 1.2.
2. `get_option('et_divi')['builder_global_presets_d5']` is NOT set. Confirms the product-setting branch.
3. Pick one item: keys are a subset of `type,id,name,priority,order,created,updated,version,attrs,renderAttrs,styleAttrs,groupPresets,moduleName,groupId,groupName,primaryAttrName`. Note whether `created` is 10 digits (seconds) or 13 (ms). Resolves an open question.
4. `get_option('et_divi_global_variables')` has only keys from `numbers,strings,images,links,fonts,gradients`; each item contains `id` equal to its key, `label`, `value`, `status`, `order`.
5. `get_option('et_divi')['et_global_data']['global_colors']` exists, keys start `gcid-`, and none of the five customizer ids are in it. `get_option('et_divi')['accent_color']` holds the primary colour.
6. In a page saved by the Visual Builder that uses a NUMBER variable and a FONT variable, read raw `post_content` and record the exact token `type` written (`number`/`font` vs `content`). Resolves the main inference in 2.
7. Same page: record the exact `groupPreset` keys the VB wrote for a Heading with a title font preset (`title.decoration.font` vs `designTitleText`). Confirms which slot family the VB itself uses.
8. View source of a front-end page: look for `<style class="et-vb-global-data et-vb-global-numeric-vars">:root{--gvid-...` in the footer, and `--gcid-` declarations in a `:root{}` block (inline footer style if Dynamic CSS is off, otherwise in the page's et-cache CSS).
9. On an element with a preset: class `preset--module--divi-<module>--<id>` (or `--default`) present, and a matching selector in the page CSS.
10. `GET /wp-json/divi/v1` (route index, read-only): confirm `/divi/v1/global-data/global-preset/sync`, `/global-data/global-colors`, `/global-data/global-variables`, `/global-data/global-fonts` are POST-only and that no GET counterpart exists.
11. With an Application Password, `POST /wp-json/divi/v1/global-data/global-colors` with an EMPTY body should return error code `invalid_nonce` (not a permission error, and nothing is written because the nonce check runs before the callback). Confirms 1.6.
12. With an Application Password, `GET /wp-json/divi/v1/settings-data/nonces`: does it return 200 with a `nonces` map? If yes, a follow-up test (a harmless GET such as `/divi/v1/dynamic-content/options?postId=<id>` with the returned `X-ET-Nonce`) shows whether app-password nonces validate. Resolves the biggest open question.
13. `get_option('et_divi_builder_is_legacy_presets_imported_to_d5')` is `'yes'` on a site whose D4 presets were converted, and `et_divi_builder_global_presets_ng` still holds the D4 originals.
