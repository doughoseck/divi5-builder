# Design: install and update divi5-builder from GitHub Releases (issue #61, plugin 2.5.0)

Date 2026-11-05. Decided with the maintainer: GitHub Releases are the update source (not a folder on one of our sites, not wordpress.org); updates are manual by default (WordPress's own per-plugin auto-update toggle stays available); the newest version is read from a committed `plugin/update.json`, never from the GitHub API.

## Intent

Every site running divi5-builder sees a new release in Dashboard > Updates and in the abilities (`plugins-status`, `plugins-update`), and installs it through WordPress's normal upgrader, so the kept copy, the page check and the rollback of 2.4.x cover the plugin's own updates. Releases stop being "download a zip, upload it on each site".

## Goals

- A site on 2.5.0 is offered 2.5.1 the day it is released, from GitHub, with no token and no rate limit.
- The downloaded zip is checked against a checksum published with the release before WordPress unpacks it.
- The release procedure cannot produce a release whose `update.json`, zip header, readme and `D5B_VERSION` disagree.
- Multisite, single site and the abilities all work from the same mechanism; nothing new to host.

## Non-goals

- Publishing on wordpress.org.
- Updating must-use copies (WordPress never updates mu-plugins): the updater stays silent for an mu copy and the Settings page says why.
- A self-install exception in `plugins-install`. **Dropped on 2026-11-05 with a reason:** the ability runs inside the plugin on the target site, so wherever it exists the plugin is already installed. First install stays Plugins > Add New > Upload, or a MainWP push; documented, not coded.
- Automatic (unattended) updates by default. WordPress shows its "Enable auto-updates" link for any plugin with update data; the site owner decides.

## Anti-goals

- Anything that reads `api.github.com` on page loads or cron: unauthenticated calls are limited to 60 an hour per IP, and shared hosts share IPs.
- Trusting a download because of where it came from. The repo is the trust root; the checksum is what the site checks.
- A second copy of the version number maintained by hand.

## How it works

### 1. `plugin/update.json`, written by the build, committed with the version bump

```json
{
  "slug": "divi5-builder",
  "version": "2.5.0",
  "tag": "v2026.11.05",
  "download_url": "https://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip",
  "sha256": "<sha256 of divi5-builder.zip>",
  "requires": "6.0",
  "requires_php": "7.4",
  "tested": "7.1",
  "last_updated": "2026-11-05",
  "changelog_url": "https://github.com/doughoseck/divi5-builder/releases/tag/v2026.11.05"
}
```

- `scripts/build-plugin-zips.js` writes it next to the zip (`--out`) always, and into `plugin/update.json` in the repo **only when `--tag v2026.11.05` is given** (a release build; changed after the final review so a build between releases cannot move the committed checksum away from the released asset). The zip build is reproducible (sorted names, fixed times, every text file LF, only git-tracked files, a STOP on uncommitted files under the plugin folder), so the sha256 of a zip built from a commit equals the sha256 of the release asset built from the same commit. A change under `plugin/` reaches `main` only together with a version bump and a `--tag` build: the build test enforces that when the header version equals the json's, the json's sha256 equals a build of the commit.
- `update.json` is NOT inside the zip (WordPress does not need it there, and it would change the zip it describes).
- `build-plugin-zips-test.js` checks: the json exists in `--out` and in the repo, `version` equals the zip header, `D5B_VERSION` and the readme Stable tag, `sha256` equals the zip's, `download_url` is the fixed release-asset form for `tag` (host `github.com`, path `/doughoseck/divi5-builder/releases/download/<tag>/divi5-builder.zip`), `tag` matches `^v\d{4}\.\d{2}\.\d{2}(\.\d+)?$`, and two builds in a row produce byte-identical zips and json.
- Release procedure (CONTRIBUTING): bump the version, run the build with `--tag`, commit `plugin/update.json` with the bump, merge, build again from main into the release folder (identical bytes), `gh release create <tag> ... divi5-builder.zip divi5-builder-loader.php`. The json points at an asset URL that exists minutes later; a site asking in between gets a 404 on "Update now" and tries again next time, nothing worse.

### 2. The updater: `plugin/divi5-builder/inc/updater.php` (new file, in the load list)

- Hooks `update_plugins_github.com` (WordPress 5.8+, the `Update URI` header already names `https://github.com/doughoseck/divi5-builder`). The callback runs only for our own plugin file (`plugin_basename( D5B_FILE )`), and never when the plugin is an mu copy (`D5B_FILE` inside `WPMU_PLUGIN_DIR`).
- Reads `https://raw.githubusercontent.com/doughoseck/divi5-builder/main/plugin/update.json` with `wp_safe_remote_get`, timeout 5 s, `Accept: application/json`, a User-Agent naming the plugin and its version (no site identity beyond what WordPress sends anyway). The answer, good or bad, is cached in the site transient `d5b_update_json`: a good answer counts as fresh for 11 hours (so a release shows within a day even against WordPress's own 12-hour cron), a failure for 1 hour, and a failed fetch keeps the last good answer, so a check costs one request per site per half day.
- Validates before use: `slug` equals `divi5-builder`; `version` matches `^\d+\.\d+\.\d+$`; `download_url` parses to `https`, host exactly `github.com`, path exactly `/doughoseck/divi5-builder/releases/download/<tag>/divi5-builder.zip` with `<tag>` the json's `tag`; `sha256` is 64 hex characters; `requires` and `requires_php` are version strings. Anything else is "no update" and is logged nowhere visible to visitors (a Settings line, see 4).
- Answers WordPress with the array it expects (`id` = the Update URI, `slug`, `version`, `url` = the release page, `package` = `download_url`, `requires`, `requires_php`, `tested`, `icons` empty) when `version` is newer than `D5B_VERSION` by `version_compare`; otherwise `false` (no update). It never lowers the version: a json older than the installed plugin is "no update".
- `plugins_api` filter for `plugin_information` with our slug: name, version, author, homepage, `download_link`, `sections` = `description` (from the readme) and `changelog` (the readme's newest changelog entry plus a link to the release), `requires`, `requires_php`, `tested`. That is what the "View version x details" link in wp-admin opens; without it WordPress asks wordpress.org and shows "not found".

### 3. Integrity: the checksum is checked before WordPress unpacks

- `upgrader_pre_download` filter: when `$package` equals the cached json's `download_url` exactly, the plugin downloads it itself with `download_url()`, computes `hash_file( 'sha256' )`, compares with the json's `sha256` (constant-time compare), and returns the temp file path on a match or a `WP_Error( 'd5b_checksum' )` with the two hashes on a mismatch (the temp file is deleted). Any other package is left to WordPress untouched.
- The upgrader honours a WP_Error from that filter by aborting, so a tampered or truncated asset never reaches the plugins folder. The abilities' `plugins-update` runs through the same upgrader, so it gets the same check and reports `update_failed` with that message.
- Nothing is signed: the trust root is the repository and GitHub's TLS. SECURITY.md says what that means (whoever can push to `main` can ship code to every site; `main` is protected and the maintainer's account has 2FA).

### 4. What the site shows

- Settings > Divi 5 Builder, top: one line "Updates: from GitHub Releases. Installed 2.5.0, newest 2.5.0 (checked 3 hours ago)." or "could not read the update file (HTTP 404), last good answer 2 days ago" or "must-use copy: update by replacing the folder". A "Check now" link that clears the transient and runs `wp_update_plugins()` (admin only, nonce).
- `plugins-status` needs no change: WordPress's stored list carries our row like any other plugin's once the filter has run. `updates-check` already refreshes the list (and 2.4.3 hooks vendor updaters first); our filter runs in every context, admin or not, so it needs no hooking.

### 5. Versions and docs

- Plugin 2.5.0 (a feature). No `D5B_DB_VERSION` change, no consent step.
- README: the install section says "first install: upload the zip (or push it with MainWP); from then on updates arrive in Dashboard > Updates"; the "there is no update checker" sentence goes.
- SECURITY.md: a section "Where updates come from": the json URL, the checksum, what is and is not verified, the mu exception, the protected-branch note.
- CONTRIBUTING: the release procedure with the `--tag` step and the rule that `plugin/update.json` is committed with the version bump.
- readme.txt changelog for 2.5.0.
- The skill: `references/site-care/wp-site-updates.md` gets a short "divi5-builder updates itself from GitHub since 2.5.0" note; the private copy is synced as usual.

## Rules the builder must keep (from earlier releases)

- Stand-ins behave like core (WordPress 6.4.1 at `C:\Users\doug\Documents\Good Elearning\backup\wp-includes` for `update_plugins_{$hostname}`, `plugins_api`, `upgrader_pre_download`, `download_url`); `php -d max_execution_time=60`; nothing in the background; never the internet in tests (`T_http` answers every fetch); no function name defined in two files; every new error code with a non-500 HTTP status where it reaches an ability; fixtures never hold whole key-shaped strings; no em dash anywhere.
- Mutation-check every guard (a wrong host, a wrong path, a short sha256, an older version, a non-github package, an mu copy).

## Verification that counts (controller, live)

The first real update through the mechanism can only be observed with an installed version LOWER than the json's. Procedure on internetupstart (the maintainer's own site): build 2.5.0 locally with the header and constant temporarily at `2.4.99` (a throwaway build, never committed), install it, release 2.5.0 on GitHub with the real json, run `updates-check`, see `plugins-status` offer 2.5.0 for divi5-builder, run `plugins-update` (kept copy, page check), see 2.5.0 installed and the Settings line agree. Then the same site is on the real 2.5.0 and the next release is the first ordinary one.

## Done when

- Every suite passes; the live procedure above went through with a kept copy; a tampered zip (one byte changed in a local copy served from `T_http` in the test, and once for real by pointing a dev site's json at a wrong sha256) is refused with `d5b_checksum`.
- README, SECURITY.md, CONTRIBUTING, readme.txt and the skill note say what this document says.
