# GitHub Releases as the update source (plugin 2.5.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A site running divi5-builder is offered each new release in Dashboard > Updates and in the abilities, downloads it from the GitHub release asset, verifies its sha256 against the committed `plugin/update.json`, and installs it through WordPress's normal upgrader.

**Architecture:** `scripts/build-plugin-zips.js` writes `update.json` (version, tag, asset URL, sha256) from the reproducible zip; a new `plugin/divi5-builder/inc/updater.php` answers WordPress's `update_plugins_github.com` and `plugins_api` filters from a 12-hour-cached read of the raw json, and checks the checksum in `upgrader_pre_download`; the Settings page shows one status line. Docs and the release procedure change with it.

**Tech Stack:** PHP 7.4+ (WordPress 5.8+ Update URI mechanism), Node 22 build script, the repo's PHP stand-in test harness (`scripts/plugin-test-stubs.php`, `T_http`).

**Spec:** `docs/superpowers/specs/2026-11-05-github-updates-design.md`

## Global Constraints

- Plugin version `2.5.0` in: `plugin/divi5-builder/divi5-builder.php` (header and `D5B_VERSION`), `plugin/divi5-builder/readme.txt` (Stable tag and a `= 2.5.0 =` changelog entry), `scripts/plugin-anysite-test.php` (three pins), `scripts/build-plugin-zips-test.js`, `SECURITY.md` table row, `README.md` line "Run the latest plugin". `D5B_DB_VERSION` unchanged.
- Update file: `https://raw.githubusercontent.com/doughoseck/divi5-builder/main/plugin/update.json`. Asset URL form: `https://github.com/doughoseck/divi5-builder/releases/download/<tag>/divi5-builder.zip`. Tag form `^v\d{4}\.\d{2}\.\d{2}(\.\d+)?$`. Transient `d5b_update_json`, 12 h good / 1 h failed. Timeout 5 s. Error code `d5b_checksum`.
- The updater does nothing when `D5B_FILE` is inside `WPMU_PLUGIN_DIR`, and only for `plugin_basename( D5B_FILE )`.
- Stand-ins behave like WordPress core 6.4.1 (`C:\Users\doug\Documents\Good Elearning\backup\wp-includes\update.php` for the filter signature). `php -d max_execution_time=60`. Nothing in the background. Never the internet in tests. No function name defined in two files. No em dash. Fixtures never hold whole key-shaped strings. Commits end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- No new ability, no new switch, no consent step.

## Review Focus

1. A json whose `download_url` is on `github.com` but under another repository path (`/someone-else/divi5-builder/releases/download/...`): must be "no update" (Task 2 pins it).
2. A json `version` lower than or equal to `D5B_VERSION`: never an update, never a downgrade offer (Task 2).
3. A tampered asset: one byte changed, sha256 differs: `upgrader_pre_download` must return `WP_Error( 'd5b_checksum' )` and delete the temp file; a package that is not ours must pass through untouched as `false` (Task 3).
4. The 12-hour cache: a second check within the window makes no HTTP request; an HTTP failure is cached 1 hour and does not wipe the last good answer's offer (Task 2).
5. Must-use copy: no filter output, no HTTP, the Settings line says "must-use copy" (Tasks 2 and 4).

---

### Task 1: `update.json` written by the build, pinned by the build test

**Files:**
- Modify: `scripts/build-plugin-zips.js`
- Create: `plugin/update.json` (generated, committed)
- Modify: `scripts/build-plugin-zips-test.js`
- Modify: `CONTRIBUTING.md` (release procedure)

**Interfaces:**
- Produces: `plugin/update.json` with keys `slug, version, tag, download_url, sha256, requires, requires_php, tested, last_updated, changelog_url` (strings). `node scripts/build-plugin-zips.js [--out D] [--tag vYYYY.MM.DD[.N]]`: without `--tag` the tag already in `plugin/update.json` is kept; with no json and no `--tag` the build STOPs with a message.

- [ ] **Step 1: failing tests** in `build-plugin-zips-test.js`: `update.json` exists in `--out` and equals `plugin/update.json`; `version` equals the zip header, `D5B_VERSION` and the readme Stable tag; `sha256` equals `crypto` sha256 of the zip; `download_url === 'https://github.com/doughoseck/divi5-builder/releases/download/' + tag + '/divi5-builder.zip'`; `tag` matches the tag form; `requires`/`requires_php` equal the header's `Requires at least`/`Requires PHP`; two builds in a row give byte-identical zip and json; `--tag` with a bad form STOPs (exit 1) and writes nothing; the json is NOT inside the zip.
- [ ] **Step 2: run** `node scripts/build-plugin-zips-test.js`: FAIL (no json).
- [ ] **Step 3: implement** in `build-plugin-zips.js`: read `requires`/`requires_php`/`tested` from the header (`Tested up to` from readme.txt if present, else `'7.1'`), compute sha256 of the zip bytes, resolve the tag (`--tag` or the committed json's), write the json with 2-space indent and `\n` endings to both places. `last_updated` = today's date UTC `YYYY-MM-DD` ONLY when version or tag changed (so a rebuild is byte-identical).
- [ ] **Step 4: run** the test: PASS. Run `node scripts/build-plugin-zips.js --tag v2026.11.05 --out <scratch>` once so `plugin/update.json` exists for 2.5.0 (the version bump of Task 5 re-runs it).
- [ ] **Step 5:** CONTRIBUTING release procedure: bump, build with `--tag`, commit `plugin/update.json` with the bump, merge, build from main into the release folder, `gh release create <tag>` with zip and loader; "the json must point at the tag you are about to create".
- [ ] **Step 6: commit** `feat: build writes plugin/update.json (version, tag, asset URL, sha256) for the GitHub updater`.

### Task 2: the updater filter with cache and validation

**Files:**
- Create: `plugin/divi5-builder/inc/updater.php`
- Modify: `plugin/divi5-builder/divi5-builder.php` (load list: after `inc/settings.php`; hooks)
- Create: `scripts/plugin-updater-test.php`
- Modify: `scripts/plugin-test-stubs.php` only if a stand-in is missing (`wp_safe_remote_get` may alias `wp_remote_get`; `plugin_basename`; `version_compare` is PHP)
- Modify: `scripts/plugin-anysite-test.php` hook list (the updater adds `add_filter` calls; the anysite test counts actions in `T_actions` and filters may be elsewhere: read the stub before deciding)

**Interfaces:**
- Produces: `d5b_updater_fetch( bool $force = false ): array` returns `array( 'ok' => bool, 'json' => array|null, 'error' => string, 'checked' => int unix, 'from_cache' => bool )`; `d5b_updater_valid( $json ): bool`; `d5b_updater_filter( $update, array $plugin_data, string $plugin_file, $locales )` (the `update_plugins_github.com` callback); `d5b_updater_is_mu(): bool`; `d5b_updater_our_file(): string`.
- Consumes: `D5B_VERSION`, `D5B_FILE`.

- [ ] **Step 1: failing tests** `scripts/plugin-updater-test.php` (model on `plugin-abilities-write-core-test.php`'s bootstrap): with `T_http` answering the json URL: (a) newer version → the filter returns the array with `package` = download_url, `version`, `slug`, `id`, `requires`, `requires_php`; (b) same or lower version → `false`; (c) wrong host, wrong repo path (`/someone-else/divi5-builder/...`), http scheme, a `..` segment, sha256 of 63 chars, slug `divi5-builder-pro`, tag not matching the url → `false` and `d5b_updater_valid` false; (d) second call within 12 h → `T_http_calls` unchanged (cache); `$force` → one more call; (e) HTTP 500 / WP_Error → `ok false`, error text, cached 1 h, and the previous good json still answers the filter; (f) another plugin's file → `false` and no HTTP; (g) mu copy (`D5B_FILE` under `WPMU_PLUGIN_DIR`) → `false`, no HTTP; (h) invalid JSON body → `ok false`. Each guard with its own check so a mutation is named.
- [ ] **Step 2: run**: FAIL (file missing).
- [ ] **Step 3: implement** `inc/updater.php` + the two `add_filter` lines in the main file (`update_plugins_github.com`, 10, 4). Validation exactly as the spec. The transient holds `array( 'json' => ..., 'checked' => time(), 'ok' => bool, 'error' => '' )`; a failed fetch keeps the previous `json` under `last_good`.
- [ ] **Step 4: run** the new suite and `plugin-anysite-test.php`: PASS. Mutation-check: remove the host check, the repo path check, the version_compare, the mu check, the cache read: each must fail a named test.
- [ ] **Step 5: commit** `feat: updater answers update_plugins_github.com from plugin/update.json (12 h cache, strict validation, mu copies silent)`.

### Task 3: checksum before unpack, and the details modal

**Files:**
- Modify: `plugin/divi5-builder/inc/updater.php`
- Modify: `scripts/plugin-updater-test.php`
- Modify: `scripts/plugin-test-stubs.php` (`download_url()` stand-in writing `T_http` body to a temp file; `hash_file` is PHP; `plugins_api` stand-in already exists: it must run the `plugins_api` filter first as core does: check and extend)

**Interfaces:**
- Produces: `d5b_updater_pre_download( $reply, string $package, $upgrader, array $hook_extra )` (filter `upgrader_pre_download`, 10, 4); `d5b_updater_plugins_api( $result, string $action, $args )` (filter `plugins_api`, 10, 3).

- [ ] **Step 1: failing tests**: (a) package equals the cached json's `download_url`, body sha256 matches → returns a readable temp file path whose content is the body; (b) body differs by one byte → `WP_Error` code `d5b_checksum`, message names both hashes' first 12 characters, the temp file is gone; (c) package is another URL → returns `$reply` unchanged (`false`), no HTTP; (d) no cached json (never fetched) → package ours by URL form but no sha256 known → `WP_Error( 'd5b_checksum' )` (refuse rather than trust); (e) `plugins_api( 'plugin_information', slug divi5-builder )` returns an object with `name`, `version` (the json's), `download_link`, `sections.changelog` containing the readme's newest entry text and the release URL, `requires_php`; (f) other slugs untouched.
- [ ] **Step 2: run**: FAIL.
- [ ] **Step 3: implement**; the compare uses `hash_equals`.
- [ ] **Step 4: run**: PASS. Mutation: compare removed, temp file kept on mismatch, other package intercepted: each fails a named test.
- [ ] **Step 5: commit** `feat: sha256 of the release asset checked before WordPress unpacks it; View details modal from the readme`.

### Task 4: the Settings line and "Check now"

**Files:**
- Modify: `plugin/divi5-builder/inc/settings.php`
- Modify: `plugin/divi5-builder/inc/updater.php` (`d5b_updater_status_line(): string`, `admin_post_d5b_update_check` handler)
- Modify: `scripts/plugin-settings-test.php`, `scripts/plugin-anysite-test.php` (hook list: `admin_post_d5b_update_check`)

- [ ] **Step 1: failing tests** in `plugin-settings-test.php`: the Settings page HTML contains "Updates: from GitHub Releases", the installed version, the newest version and "checked ... ago" when a good json is cached; "could not read the update file" plus the error when the last fetch failed; "must-use copy" when mu; the "Check now" link carries a nonce; the handler without the nonce dies with 403 (as the switches handler does), with it clears `d5b_update_json`, calls `wp_update_plugins()` (recorded in `T_update_checks`) and redirects back.
- [ ] **Step 2: run**: FAIL. **Step 3: implement.** **Step 4: run** settings and anysite suites: PASS.
- [ ] **Step 5: commit** `feat: Settings shows where updates come from, the newest version and a Check now link`.

### Task 5: version 2.5.0, docs, skill note, readme changelog

**Files:**
- Modify: `plugin/divi5-builder/divi5-builder.php`, `plugin/divi5-builder/readme.txt`, `scripts/plugin-anysite-test.php`, `scripts/build-plugin-zips-test.js`, `SECURITY.md`, `README.md`, `CONTRIBUTING.md`, `references/site-care/wp-site-updates.md`, `plugin/update.json` (rebuilt with `--tag v2026.11.05`)

- [ ] **Step 1:** version `2.5.0` everywhere listed in Global Constraints; readme changelog `= 2.5.0 =` in the voice of the earlier entries (two or three bullets: updates arrive in Dashboard > Updates from GitHub; the checksum; mu copies; first install still an upload).
- [ ] **Step 2:** README install section rewritten (first install upload or MainWP; updates from then on; the "no update checker" sentence removed). SECURITY.md: table row 2.5.0 + a section "Where updates come from" (json URL, what is checked, what is not, mu, protected `main`). `wp-site-updates.md`: a short paragraph that divi5-builder updates itself from GitHub since 2.5.0 and that `plugins-update` covers it with a kept copy.
- [ ] **Step 3:** `node scripts/build-plugin-zips.js --tag v2026.11.05 --out <scratch>` so `plugin/update.json` says 2.5.0 with the right sha256; run EVERY PHP and Node suite from CONTRIBUTING plus the new one; `ds-write-test.php` on both Divi copies is NOT needed (no design-system change).
- [ ] **Step 4: commit** `Plugin 2.5.0: updates from GitHub Releases; docs and release procedure`.

### Task 6 (controller, after review): live proof on internetupstart

- Build a throwaway zip with header and constant `2.4.99` (sed on a copy of the plugin folder in the scratchpad, never committed) and have the maintainer install it; merge, release `v2026.11.05` with the real zip from main; on the site: `updates-check`, `plugins-status` must offer `2.5.0` for `divi5-builder/divi5-builder.php`; `plugins-update` with the kept copy; `status` says 2.5.0; the Settings line agrees. Then point a copy of the json with a wrong sha256 at a dev site's transient (`d5b_update_json` set through `appearance`? no: through a one-off `--namespace`-free test is impossible; instead the stub test in Task 3 is the tamper proof, and the live run proves the happy path).
