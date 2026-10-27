# One Plugin With Switches: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the four WordPress plugins with one plugin, `divi5-builder` 2.0.0. Page building is always on. Media audit, media clean-up and updates each sit behind an admin-only switch that is off by default. The plugin takes over from the old plugins automatically.

**Architecture:**
- A folder plugin: a small loader, plus `inc/` for the switches, the gate, the takeover and the settings page, plus `parts/` holding the four existing plugins' code. Each part's route registration is wrapped in a named function.
- One gate on `rest_api_init` (priority 99) registers a part's routes only when:
  - its switch is on (page building has no switch), and
  - no old copy already provides it, detected by its constant or by its probe route.
- Nothing else changes in the parts: same namespaces, routes, capability checks and behaviour.

**Tech Stack:** PHP 7.4+ WordPress plugin (no Composer). Node 18 built-ins for scripts and tests. PHP tests run on hand-written WordPress stand-ins, as today.

**Spec:** `docs/superpowers/specs/2026-10-27-one-plugin-design.md` (approved 2026-10-27; one line corrected while planning: no live POSTs, see Task 8).

## Global Constraints

- **Slug, version and identity:**
  - slug `divi5-builder`; main file `divi5-builder/divi5-builder.php`
  - `Version: 2.0.0`; constant `D5B_VERSION` = `'2.0.0'`
  - header `Update URI: https://github.com/doughoseck/divi5-builder`
  - `Plugin Name: Divi 5 Builder`
  - Description: `Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.`
- **Never reuse the old constants** (`D5B_REST_VERSION`, `WPMA_VERSION`, `WPMQ_VERSION`, `WPSU_VERSION`). Detecting them is how the takeover works.
- **Part keys, used everywhere:** `page_building`, `media_audit`, `media_clean_up`, `updates`.
- **Switch option `d5b_switches`:**
  - shape: `{ <part>: { on: bool, changed_at: ISO-8601 UTC, changed_by: user_login } }`
  - not autoloaded; a site option on multisite
  - never `register_setting(... show_in_rest ...)`; no REST route, WP-CLI command or script writes it
- **Who flips a switch:** only the wp-admin form, nonce action `d5b_switches`.
  - capability `manage_options`, or `manage_network_options` on multisite
  - on multisite, every switch is network-level and the page is in Network Admin > Settings
- **Defaults:** all three switches off; `page_building` has no switch.
- **Risk notes, verbatim:**
  - **media_audit:** "Lists every uploaded file, its size, and where the site uses it. It changes nothing, but it shows a lot about your site to anyone with an administrator login. Switch it off when the audit is done."
  - **media_clean_up:** "Moves uploaded files out of your uploads folder into a quarantine folder. Anything moved disappears from the pages that use it until it is moved back. Take a backup first, and check the site after every move."
    - on multisite, append: " On a network, check the sites that share these files."
  - **updates:** "Updates plugins, themes and WordPress itself. An update can break a site. Take a backup first, and check your key pages after every update."
    - on multisite, append: " On a multisite network an update applies to every site in it at once."
- **Script advice when a switch is off:** "Switch <Label> on in Settings > Divi 5 Builder (an administrator must do it in wp-admin)". Labels: `Media audit`, `Media clean-up`, `Updates`.
- **Old plugin files:** they move to `assets/legacy/` and are no longer zipped. `double-load-test.php` keeps covering them.
- **Repo rules** (from CONTRIBUTING and SECURITY):
  - no credentials, client names or site names in committed files
  - Node scripts use built-ins only
  - merges wait for the maintainer's OK
  - content writes stay hash-guarded

## Review Focus

1. **An old must-use copy plus the new plugin on the same site** (the maintainer's real multisite network). The routes must be registered exactly once, by the old file, and the notice must name the file.
   - *Test:* Task 3, `legacy mu file present: part stands aside, routes once, notice names file`.
2. **A switch saved while an old `wp-site-updates` is still active.** The page must not suggest Updates is off and safe.
   - *Test:* Task 3, `old updates plugin loaded: red line says live although switch off`.
3. **The plugin loaded twice** (a must-use copy and a normal copy). No fatal, and the second copy does nothing.
   - *Test:* Task 5, the double-load case for the folder plugin.
4. **A site with no Divi, or with Divi 4.** The plugin loads, the status route says `divi5: false`, and nothing fatals on `init` or `rest_api_init`.
   - *Test:* Task 5, `no Divi: loads, status divi5 false`.
5. **Saving the switch form without a nonce, or as an editor.** Refused, and the option is unchanged.
   - *Test:* Task 4, `save without nonce refused` and `save as editor refused`.

---

### Task 1: Move the four plugins into `parts/`, unchanged in behaviour

**Files:**
- Move (git mv): `assets/divi5-builder-rest.php`, `assets/wp-media-audit.php`, `assets/wp-media-quarantine.php`, `assets/wp-site-updates.php` → `assets/legacy/` (same names, content untouched)
- Create: `plugin/divi5-builder/parts/page-building.php`, `parts/media-audit.php`, `parts/media-clean-up.php`, `parts/updates.php`
- Modify: `scripts/permissions-test.php`, `scripts/media-audit-test.php`, `scripts/media-quarantine-test.php`, `scripts/site-updates-test.php`, `scripts/tree-guard-test.php`, `scripts/ds-write-test.php` (point them at the parts), `scripts/double-load-test.php` (default list = `assets/legacy/*.php`)

**Interfaces:**
- Produces, for each part file:
  - the old file's body, minus its header docblock, its `ABSPATH` guard and its version constant
  - its `add_action( 'rest_api_init', function () {...} )` becomes a named function
  - `function_exists` guards stay as they are
- The named functions:

  | Part | Function | Notes |
  |---|---|---|
  | page building | `d5b_routes_page_building(): void` | both of today's `rest_api_init` closures, in order |
  | page building | `d5b_init_page_building(): void` | today's `init` closure: the meta registration |
  | media audit | `d5b_routes_media_audit(): void` | |
  | media clean-up | `d5b_routes_media_clean_up(): void` | |
  | updates | `d5b_routes_updates(): void` | |

- Each named function sits **outside** the part's `if ( ! function_exists(...) )` block, inside `if ( ! function_exists( 'd5b_routes_<part>' ) )`.
- The version route returns `D5B_VERSION`.

- [ ] **Step 1: Repoint the existing PHP tests.** Each test requires the part file (keeping the env override: `WPMA_PLUGIN_FILE`, `WPMQ_PLUGIN_FILE`, `D5B_PLUGIN_FILE`, and the argv path in `permissions-test.php`), defines `D5B_VERSION` = `'2.0.0'`, then calls the part's named functions where the old file ran its closures.
- [ ] **Step 2: Run them against the moved-but-not-yet-created parts.** Expected: FAIL (file not found).
- [ ] **Step 3: Create the four part files.** Move the code; change nothing inside the closures.
- [ ] **Step 4: Prove the move.** Run every PHP suite:
  - Command: `php scripts/permissions-test.php && php scripts/media-audit-test.php && php scripts/media-quarantine-test.php && php scripts/site-updates-test.php && php scripts/double-load-test.php && php scripts/tree-guard-test.php`
  - Expected: all pass.
  - Also check that the route closures are textually identical between `assets/legacy/X.php` and its part: diff with the docblock and closure wrapper stripped. The only lines that may differ are the wrapper lines and `D5B_REST_VERSION` → `D5B_VERSION` in the version route.
- [ ] **Step 5: Commit** "Move the four plugins into plugin/divi5-builder/parts (no behaviour change); old files to assets/legacy (#37)".

### Task 2: Switches, the gate, the status route, and the two security tests

**Files:**
- Create: `plugin/divi5-builder/inc/parts.php`, `inc/switches.php`, `inc/gate.php`
- Create: `scripts/plugin-test-stubs.php`: shared WordPress stand-ins that record every `register_rest_route`, `update_option`, `update_site_option`, `register_setting` and `deactivate_plugins` call, with switchable `is_multisite()` and user capabilities
- Create: `scripts/plugin-switches-test.php`

**Interfaces:**
- Consumes: the Task 1 named functions.
- Produces:
  - `d5b_parts(): array`, keyed by part. Each value: `label`, `has_switch` (bool), `routes` (callable name), `legacy_const`, `legacy_slug`, `probe` (one route the old copy always registers), `risk` (string or ''). The values:

    | Part | Label | Has switch | Legacy constant | Legacy slug | Probe |
    |---|---|---|---|---|---|
    | `page_building` | Page building | no | `D5B_REST_VERSION` | `divi5-builder-rest` | `/divi5-builder/v1/version` |
    | `media_audit` | Media audit | yes | `WPMA_VERSION` | `wp-media-audit` | `/wp-media-audit/v1/info` |
    | `media_clean_up` | Media clean-up | yes | `WPMQ_VERSION` | `wp-media-quarantine` | `/wp-media-quarantine/v1/batches` |
    | `updates` | Updates | yes | `WPSU_VERSION` | `wp-site-updates` | `/wp-site-updates/v1/info` |

  - `d5b_risk_note(string $part): string`: the Global Constraints copy, with the multisite sentence appended when `is_multisite()`.
  - `d5b_switches(): array`: the stored option, with missing parts filled as off.
  - `d5b_switch_is_on(string $part): bool`: `page_building` is always true.
  - `d5b_switch_set(string $part, bool $on, string $user_login): void`: refuses `page_building` (throws `InvalidArgumentException`). Writes with `add_option(..., 'no')` / `update_option`, or the site-option pair on multisite.
  - `d5b_gate(): void`, hooked on `rest_api_init` at priority 99. For each part:
    - if `legacy_const` is defined, or `rest_get_server()->get_routes()` already has `probe`, record the stand-aside in `$GLOBALS['d5b_stood_aside'][ $part ]`, with the value `'constant'` or `'route'`;
    - else, if the part's switch is on, call its routes function.
    - Then register `GET /divi5-builder/v1/status` (`edit_posts`), always.
  - Status route response: `{ version: D5B_VERSION, divi5: defined('ET_BUILDER_5_DIR'), switches: { media_audit: bool, media_clean_up: bool, updates: bool }, stood_aside: { <part>: 'constant'|'route' } }`.

- [ ] **Step 1: Write the failing tests in `scripts/plugin-switches-test.php`.**
  - `switch off means no routes`: with every switch off, run `d5b_gate()`. The recorded routes include every `divi5-builder/v1` route and `/status`, and none under `wp-media-audit/v1`, `wp-media-quarantine/v1` or `wp-site-updates/v1`.
  - `switch on means all its routes`: turn each switch on alone. Exactly that part's namespace appears, with the same route list the part registers when called directly.
  - `no REST path flips a switch`:
    - with all switches on, call every recorded route's `permission_callback` and `callback` as an administrator with empty params, catching errors
    - the recorded `update_option` / `update_site_option` / `add_option` calls never name `d5b_switches`
    - no `register_setting` call names `d5b_switches`
  - `page_building cannot be switched`: `d5b_switch_set('page_building', false, 'x')` throws.
  - `multisite stores a site option`: with `is_multisite()` true, `d5b_switch_set` writes `update_site_option`, not `update_option`.
  - `status route answers`: as a contributor-level `edit_posts` user, it returns the shape above with all switches false. As a user without `edit_posts`, `permission_callback` returns false.
- [ ] **Step 2: Run them.**
  - Command: `php scripts/plugin-switches-test.php`
  - Expected: FAIL (`d5b_gate` undefined).
- [ ] **Step 3: Implement `inc/parts.php`, `inc/switches.php` and `inc/gate.php`** as specified in Interfaces.
- [ ] **Step 4: Run them.** Expected: all pass.
  - Mutation check: make `d5b_switch_is_on` return true always. The first test must fail.
  - Mutation check: register `d5b_switches` with `show_in_rest`. The third test must fail.
- [ ] **Step 5: Commit** "Switches off by default, gate registers only switched-on parts, status route (#37)".

### Task 3: Takeover from the old plugins

**Files:**
- Create: `plugin/divi5-builder/inc/takeover.php`
- Test: `scripts/plugin-takeover-test.php` (uses `plugin-test-stubs.php`)

**Interfaces:**
- Consumes: `d5b_parts()`, `$GLOBALS['d5b_stood_aside']`.
- Produces:
  - `d5b_legacy_basenames(string $part): array`, which returns `['<slug>.php', '<slug>/<slug>.php']`.
  - `d5b_takeover_deactivate(bool $network): array`
    - deactivates, through `deactivate_plugins( $list, true, $network )`, every old basename that is active: in `active_plugins`, or in `active_sitewide_plugins` when `$network`
    - saves option `d5b_takeover` = `{ done_at, deactivated: [...] }`
    - returns the list
  - Activation: `register_activation_hook( D5B_FILE, fn( $network_wide ) => d5b_takeover_deactivate( (bool) $network_wide ) )`.
  - Must-use mode: on `admin_init`, if the plugin file is under `WPMU_PLUGIN_DIR`, the user can `activate_plugins`, and there is no `d5b_takeover` option yet, call `d5b_takeover_deactivate( is_multisite() )` once.
  - `d5b_legacy_report(): array` of `{ part, how: 'mu'|'plugin'|'other', file: string|null, live_while_off: bool }`. It works outside REST requests:
    - `legacy_const` defined plus a file in `WPMU_PLUGIN_DIR` named `<slug>.php` gives `mu`
    - `legacy_const` defined plus an active basename gives `plugin`
    - `legacy_const` defined otherwise, or `d5b_legacy_seen` lists the part, gives `other`
    - `live_while_off` = the part has a switch and that switch is off
  - `d5b_gate()` (Task 2) also keeps option `d5b_legacy_seen` = the stood-aside parts that were detected by `'route'`. It writes only when the set changes, never on every request.
  - `d5b_takeover_lines(): array` of `{ text, red: bool }`. The texts:
    - the replaced-plugins line: `"Replaced by Divi 5 Builder, you can delete them: <list>"`
    - mu: `"The old file wp-content/mu-plugins/<file> is still in charge of <Label>. Delete it to finish the move."`
    - plugin: `"The old plugin <basename> is active again and is in charge of <Label>. Deactivate and delete it."`
    - other: `"An older copy (perhaps a code snippet) is already providing <Label>. Remove it to finish the move."`
    - and, when `live_while_off`, the red line: `"<Label> is LIVE on this site through the old copy, even though its switch here is off."`

- [ ] **Step 1: Write the failing tests.**
  - `activation deactivates old normal plugins`: given active `wp-site-updates/wp-site-updates.php` and `divi5-builder-rest.php`, plus an unrelated plugin, both old ones are passed to `deactivate_plugins`, and the unrelated one is not.
  - `network activation deactivates network-active old plugins only when network-wide`.
  - `mu mode takes over once on admin_init`: with no `d5b_takeover`, it runs. On a second call, nothing more happens.
  - `legacy mu file present: part stands aside, routes once, notice names file`: define `WPMQ_VERSION`, put `wp-media-quarantine.php` in a temp `WPMU_PLUGIN_DIR`, and pre-register `/wp-media-quarantine/v1/batches`. Then the gate does not call `d5b_routes_media_clean_up`, and a line contains `wp-content/mu-plugins/wp-media-quarantine.php`.
  - `snippet copy detected by route`: no constant, but `/divi5-builder/v1/version` is pre-registered. Page building stands aside with `'route'`, and the "code snippet" line shows.
  - `old updates plugin loaded: red line says live although switch off`: define `WPSU_VERSION` with the `updates` switch off. A red line containing `Updates is LIVE` appears.
  - `old plugin reactivated later`: after takeover, `wp-media-audit.php` is active again and `WPMA_VERSION` is defined. The part stands aside, nothing is deactivated again, and the "active again" line shows.
- [ ] **Step 2: Run them.**
  - Command: `php scripts/plugin-takeover-test.php`
  - Expected: FAIL.
- [ ] **Step 3: Implement `inc/takeover.php`, and the `d5b_legacy_seen` write in `d5b_gate()`.**
- [ ] **Step 4: Run them, plus `plugin-switches-test.php`.** Expected: all pass.
  - Mutation check: drop the probe-route check from the gate. The snippet test must fail.
- [ ] **Step 5: Commit** "Takeover: deactivate old plugins, stand aside for must-use or snippet copies, red warning when live while off (#37)".

### Task 4: Settings page and admin notices

**Files:**
- Create: `plugin/divi5-builder/inc/settings.php`
- Test: `scripts/plugin-settings-test.php`

**Interfaces:**
- Consumes: `d5b_parts()`, `d5b_switches()`, `d5b_switch_set()`, `d5b_risk_note()`, `d5b_takeover_lines()`.
- Produces:
  - **The menu.** On a single site, `add_options_page( 'Divi 5 Builder', 'Divi 5 Builder', 'manage_options', 'divi5-builder', 'd5b_settings_render' )`. On multisite, the same through `add_submenu_page( 'settings.php', ... 'manage_network_options' ... )` on `network_admin_menu`, and no per-site page.
  - **`d5b_settings_render(): void`**, which echoes:
    - the version
    - "Divi 5 detected" or "Divi 5 not detected: page building is idle"
    - for each switchable part: a checkbox, the risk note, and "Last switched <on|off> <changed_at> by <changed_by>" when set (one entry per switch: its latest change)
    - the takeover lines
    - all output escaped
  - **`d5b_settings_save(): void`**, the form handler: `admin_post_d5b_save_switches` on a single site, `network_admin_edit_d5b_save_switches` on multisite. It:
    - checks `check_admin_referer( 'd5b_switches' )` and the capability
    - calls `d5b_switch_set` only for parts whose state changed, with `wp_get_current_user()->user_login`
    - redirects back with `updated=1`
  - **`d5b_admin_notice(): void`** on `admin_notices` and `network_admin_notices`. It shows the takeover lines (red lines with class `notice-error`, others `notice-warning`) to users with `activate_plugins`, or `manage_network_plugins` on multisite.

- [ ] **Step 1: Write the failing tests.**
  - `save without nonce refused`: `check_admin_referer` stub fails, so the option is unchanged.
  - `save as editor refused`: no `manage_options`, so the option is unchanged.
  - `save as admin flips only changed parts and logs who`.
  - `multisite page is network-only and needs manage_network_options`.
  - `render shows each risk note verbatim, and the multisite sentence on multisite`.
  - `render escapes changed_by`: a stored login `<b>x</b>` appears escaped.
  - `notice shows red line with notice-error`.
- [ ] **Step 2: Run them.**
  - Command: `php scripts/plugin-settings-test.php`
  - Expected: FAIL.
- [ ] **Step 3: Implement `inc/settings.php`.**
- [ ] **Step 4: Run them.** Expected: all pass.
- [ ] **Step 5: Commit** "Settings page with risk notes and a switch log; admin notice for leftovers (#37)".

### Task 5: Loader, double load, any site, must-use loader, zip

**Files:**
- Create: `plugin/divi5-builder/divi5-builder.php`: the header per Global Constraints, then:
  - `if ( defined( 'D5B_VERSION' ) ) { return; }`
  - define `D5B_VERSION` and `D5B_FILE`
  - require `inc/*.php` and `parts/*.php`
  - `add_action( 'init', ... )` calls `d5b_init_page_building()` unless `D5B_REST_VERSION` is defined
  - `add_action( 'rest_api_init', 'd5b_gate', 99 )`
  - the takeover and settings hooks
- Create: `plugin/divi5-builder/readme.txt` (short), `plugin/mu-loader/divi5-builder-loader.php` (`<?php require __DIR__ . '/divi5-builder/divi5-builder.php';` plus a header comment on the must-use install)
- Modify: `scripts/double-load-test.php`, which also loads `plugin/divi5-builder/divi5-builder.php` from two copies of the folder. Its stubs gain `register_activation_hook`, `add_options_page`, `add_submenu_page` and `is_multisite`.
- Modify: `scripts/build-plugin-zips.js`: builds only `divi5-builder.zip` from `plugin/divi5-builder/` (every file, sorted, under `divi5-builder/`) plus `LICENSE`, keeping the fixed timestamps. It no longer zips `assets/`.
- Test: `scripts/plugin-anysite-test.php`

- [ ] **Step 1: Write the failing tests** in `plugin-anysite-test.php`:
  - `no Divi: loads, status divi5 false`: no `ET_BUILDER_5_DIR`; load the loader, fire `init` and `rest_api_init`; no error, and the status gives `divi5: false`.
  - `Divi 5 present: status divi5 true`.
  - `multisite: loads, switches read from site option`.
  - `loaded twice: second copy returns early`: `D5B_FILE` stays the first copy's path.
  - In `double-load-test.php`, the folder plugin case.
- [ ] **Step 2: Run them.**
  - Command: `php scripts/plugin-anysite-test.php && php scripts/double-load-test.php`
  - Expected: FAIL.
- [ ] **Step 3: Implement the loader, the must-use loader and the zip change.**
- [ ] **Step 4: Run all PHP suites, and build the zip twice.**
  - Command: `node scripts/build-plugin-zips.js --out <tmpA> && node scripts/build-plugin-zips.js --out <tmpB>`
  - Expected: `divi5-builder.zip` is byte-identical in both runs, and holds `divi5-builder/divi5-builder.php`, `inc/`, `parts/`, `readme.txt` and `LICENSE`.
- [ ] **Step 5: Commit** "divi5-builder 2.0.0 loader, must-use loader, one zip (#37)".

### Task 6: Scripts tell you which switch to flip

**Files:**
- Create: `scripts/switch-advice.js`. It exports `adviceFor(part, status) -> string`, where `status` is the parsed status route or `null`:
  - a switch that is off gives the Global Constraints sentence with its label
  - `null` (no combined plugin) gives `Install divi5-builder.zip from the latest release (Plugins > Add New > Upload Plugin), then switch <Label> on in Settings > Divi 5 Builder.`
  - for `page_building`, `Install divi5-builder.zip from the latest release.`
- Modify:
  - `scripts/media-audit.js` (the 404 messages at today's lines ~300 and ~361)
  - `scripts/media-quarantine.js` (~91)
  - `scripts/site-updates.js` (~109)
  - `scripts/wp.js`: `set-builder`'s "meta did NOT stick" hint, `check-plugin`, `plugin-version`, and a new command `status` (GET `divi5-builder/v1/status`, prints JSON)
  - On a 404, each script runs `wp.js <site> status`, passes the result (or `null` on its own 404) to `adviceFor`, and prints that.
- Test: `scripts/switch-advice-test.js`, and a case in `scripts/stale-guard-test.js`: the fake WordPress gains a status route, and a `site-updates.js` run with the switch off prints the switch sentence.

- [ ] **Step 1: Write the failing tests.**
  - `off -> switch sentence`, for each label
  - `null -> install sentence`
  - `page_building null -> install zip`
  - the stale-guard CLI case
- [ ] **Step 2: Run them.**
  - Command: `node scripts/switch-advice-test.js && node scripts/stale-guard-test.js`
  - Expected: FAIL.
- [ ] **Step 3: Implement `switch-advice.js` and wire the four scripts.**
- [ ] **Step 4: Run every Node suite.** Expected: all pass.
- [ ] **Step 5: Commit** "Scripts: say which switch to flip, never flip one (#37)".

### Task 7: Docs, SECURITY, private copy

**Files:**
- Modify: `README.md` (install section), `SKILL.md` (the prerequisites and install text near lines 161-190, and every "upload assets/<file> to mu-plugins" line), `references/wp-media-audit.md`, `references/wp-site-updates.md`, and the other references that name the old files (find them with `grep -rln "divi5-builder-rest.php\|wp-media-audit.php\|wp-media-quarantine.php\|wp-site-updates.php" SKILL.md README.md references scripts`)
- Modify: `SECURITY.md`:
  - a supported-versions row `divi5-builder` 2.0.0
  - the four old plugins marked "replaced; security fixes only until 2027-01-31; in assets/legacy/"
  - a migration note: install `divi5-builder.zip`, then delete what the notice lists
- Modify: `CONTRIBUTING.md` (the test list gains `plugin-switches-test.php`, `plugin-takeover-test.php`, `plugin-settings-test.php`, `plugin-anysite-test.php`, `switch-advice-test.js`; the "add a route" note points to `plugin/divi5-builder/parts/`)

- [ ] **Step 1: Update the docs.** Every install instruction names `divi5-builder.zip`, or the must-use loader plus folder. One line points to `assets/legacy/` for old sites.
- [ ] **Step 2: Verify.**
  - Command: `grep -rn "mu-plugins/divi5-builder-rest.php\|upload assets/wp-" SKILL.md README.md references scripts`
  - Expected: no hits outside `assets/legacy/` and outside sentences about old sites.
- [ ] **Step 3: Run every Node and PHP suite.** Expected: all pass.
- [ ] **Step 4: Sync the private skill** (`~/.claude/skills/divi5-builder/`): copy `plugin/`, `assets/legacy/` and the changed scripts. Keep the private-only files. Prose goes through `merge-public.js` (prose only), as usual.
- [ ] **Step 5: Commit** "Docs and SECURITY for divi5-builder 2.0.0 (#37)".

### Task 8: Live check on the dev site, then PR

**Files:**
- Create: `scripts/plugin-live-check.js <site>`. It is read-only and makes **no POST of any kind**. It prints:
  - the `/wp-json/` namespaces present
  - the status route's response
  - whether `d5b_switches` appears in `/wp/v2/settings`
  - it exits 1 if a switched-off part's namespace is present, or if the option appears in settings

**Live steps:** the maintainer uploads; the agent reads and checks.

- [ ] **Step 1: Fresh install.** The maintainer installs `divi5-builder.zip` on the dev site and activates it.
  - Run `node scripts/plugin-live-check.js <site>`.
  - Expected: only the `divi5-builder/v1` namespace among the plugin's, `divi5: true`, and the option absent from settings.
- [ ] **Step 2: Each switch.** The maintainer turns each switch on and then off, one at a time. After each change, run the live check.
  - Expected: only that part's namespace appears or disappears.
  - Also run the matching script's read-only command (`media-audit.js <site> scan`, `media-quarantine.js <site> batches`, `site-updates.js <site> status`). With the switch on it works; with it off it prints the switch sentence.
- [ ] **Step 3: Takeover from a normal plugin.**
  - The maintainer deactivates the new plugin and installs the old `wp-site-updates.zip` (from release v2026.10.27.2) as a normal plugin.
  - Then the maintainer reactivates the new plugin.
  - Expected: the old plugin is deactivated, the notice says it can be deleted, and the live check shows no `wp-site-updates/v1` while the switch is off.
- [ ] **Step 4: Takeover from a must-use file.**
  - The maintainer uploads `assets/legacy/wp-media-quarantine.php` to `wp-content/mu-plugins/`.
  - Expected:
    - the notice names the file, with the red "LIVE" line
    - the status shows `stood_aside.media_clean_up: "constant"`
    - the routes are there once, from the old file
  - Then the maintainer deletes the file. Expected: the notice is gone, and the namespace is gone while the switch is off.
- [ ] **Step 5: Push the branch and open the PR.**
  - The PR body lists every test and each live step's result.
  - It does not merge without the maintainer's OK. Then a release with `divi5-builder.zip` follows, when asked.
