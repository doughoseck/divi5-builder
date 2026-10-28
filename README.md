# divi5-builder Claude skill

Build and edit **Divi 5** WordPress pages programmatically, over the WordPress
REST API — from a compact JSON spec, using **native Divi modules** so pages stay
fully editable in the Divi Visual Builder afterwards. No paid AI addon, no browser
automation: just Node.js + the WP REST API.

It ships as a [Claude Code](https://docs.anthropic.com/claude-code) **skill**, but
the two scripts (`scripts/wp.js`, `scripts/divi.js`) are plain Node and work
standalone in any pipeline.

Other than the skill, it requires an MU plugin installed on your site and a "web-creds"
file that you populate on your own machine with login info/password. And your site
must use Divi 5 [will not work with Divi 4 sites].

> ⚠️ **Independent, community project — not affiliated with, endorsed by, or
> supported by Elegant Themes / Divi or Divi Plugins.** Divi 5 is evolving fast;
> the block format was reverse-engineered from real sites and verified live, but it
> can change between releases. Use on staging first. No warranty.

## Why

Divi 5 stores pages as **WordPress block markup** (`<!-- wp:divi/* -->` with a
nested-JSON attributes object), not Divi 4 shortcodes. That means a page can be
built by writing the right block markup into a post's `content` via REST. This tool
gives you:

- **`scripts/divi.js`** — a spec → block-markup **compiler**. You author a small
  JSON page spec; it emits correct, builder-editable Divi 5 blocks (sections, rows,
  columns, and ~26 module types), with **no inline CSS** — everything via module
  settings + global colour/preset variables.
- **`scripts/wp.js`** — a secure WP REST **client** (pages, media, "Divi mode"
  meta, canvases, block inspection).
- **`plugin/divi5-builder/`** — one WordPress plugin (`divi5-builder.zip`) that bridges the few
  gaps the REST API leaves (see below).

## Requirements

- A WordPress site running **Divi 5** (5.x). Divi 4 sites use `[et_pb_*]`
  shortcodes and are **not** supported — `wp.js <site> divi-check` will tell you.
- A WordPress **Application Password** for a user who can edit pages
  (Administrator/Editor). App passwords inherit the user's capabilities.
- **Node.js** 18+ (uses only built-ins — no `npm install`).
- The **divi5-builder plugin** installed on the target site (one-time).

## Install

**As a Claude Code skill:**
```bash
git clone https://github.com/doughoseck/divi5-builder ~/.claude/skills/divi5-builder
```
Claude Code will discover it automatically. Or use the scripts directly:
```bash
node scripts/wp.js <site> whoami
node scripts/divi.js compile examples/spec.json --out content.html
```

## Credentials

Create `~/.web-creds.txt` (or point `WEB_CREDS_PATH` at another file). INI format,
one `[section]` per site — `pass` is a **WordPress Application Password**:

```ini
[mysite]
url  = https://example.com
user = your-wp-username
pass = xxxx xxxx xxxx xxxx xxxx xxxx
```

See `.web-creds.example.txt`. **Never commit this file** — it's in `.gitignore`.
`wp.js` reads it at runtime, builds the Basic-auth header in memory, and never
prints the password. No password ever reaches the Claude prompt!

## The plugin (one-time per site)

Divi 5 flags a page as "a Divi page" with **protected `_et_*` post-meta** that core
REST won't write, and Divi's own builder REST routes reject Application-Password
auth (they need a cookie-session nonce). The `divi5-builder` plugin exposes
just those specific meta keys (and a canvas-link helper) to REST **behind an
`edit_post`/`manage_options` capability check** — so only authenticated editors can
use them. It does **not** modify Divi.

Install it either way (not both at once):

- **From the WordPress admin (no FTP):** download `divi5-builder.zip` from the
  [latest release](https://github.com/doughoseck/divi5-builder/releases/latest), then
  **Plugins → Add New → Upload Plugin**, and **Activate**. There is no update checker:
  to update, upload the newer zip and choose **Replace current with uploaded**. Any admin
  can deactivate a normal plugin, and the tool stops working until it is active again.
- **As a must-use plugin (FTP):** unzip `divi5-builder.zip` and copy its `divi5-builder/`
  folder into `wp-content/mu-plugins/` (create the folder if needed), and put
  `divi5-builder-loader.php` next to it in the same folder. Both are in the
  [latest release](https://github.com/doughoseck/divi5-builder/releases/latest): the loader
  is a file of its own next to the zip (in the repository it is
  `plugin/mu-loader/divi5-builder-loader.php`). The folder must be named `divi5-builder`;
  under any other name the loader does nothing. Must-use plugins are always on and cannot
  be switched off from the admin, which is why this is the sturdier option.

Page building is always on. The three site-maintenance parts, **Media audit**, **Media
clean-up** and **Updates**, each have a switch in **Settings > Divi 5 Builder** (on
multisite: Network Admin > Settings). All three are **off by default**, each shows a note
about its risk, and only an administrator in wp-admin can flip one (the scripts cannot).
While a switch is off its REST routes do not exist. When you finish a job, switch it off
again. The scripts print "Switch ... on in Settings > Divi 5 Builder" when one is off.
On multisite, network-activate the plugin (or activate it on the main site): Network Admin
only loads those, so a plugin activated on one subsite alone cannot show its switches, and
that subsite tells super admins so. Deleting the plugin (Plugins > Delete) removes the
options it stored, so a reinstall starts with every switch off.

Coming from the four old single-file plugins (`divi5-builder-rest.php`,
`wp-media-audit.php`, `wp-media-quarantine.php`, `wp-site-updates.php`)? On activation the
new plugin deactivates old normal copies. Old must-use files cannot be deactivated from
code: the plugin stands aside for that part and shows a notice naming the file to delete.
The old files are kept in `assets/legacy/` (security fixes only until 2027-01-31) and are
no longer built into zips. Build the zip yourself with `node scripts/build-plugin-zips.js`
(writes `dist/`).

Confirm it's active: `node scripts/wp.js <site> plugin-version` → the version number, or
`node scripts/wp.js <site> status` for the plugin and its three switches.

### Theme Builder headers, footers and body layouts (plugin 1.5+)

Core REST does not expose Divi's Theme Builder post types, so from 1.5 the plugin adds
routes to **list** every template and layout, **read** a layout's raw content, **write**
one back, and **restore** the previous version (`wp.js tb-list | tb-get | tb-set |
tb-restore`). A header or footer is on every page, so the write route is deliberately
fussy: it needs `edit_theme_options` + `unfiltered_html`, the md5 of the content you
last read (an edit made in the Visual Builder meanwhile is never overwritten), balanced
blocks, and it reads the save back and reverts automatically if the block tree changed.
`node scripts/wp.js <site> plugin-version` tells you what a site is running; upgrading
is uploading the newer zip (there is no update checker).

## Quickstart

```bash
node scripts/wp.js mysite whoami          # 200 + your name  → creds OK
node scripts/wp.js mysite divi-check      # verdict:"divi5"  → right engine
node scripts/wp.js mysite check-plugin    # pluginActive:true → plugin OK
node scripts/wp.js mysite global-colors   # which gcids pages already use (palette: design-system)

node scripts/divi.js compile examples/spec.json --out content.html
node scripts/wp.js mysite create-page --title "New page" --content-file content.html --status draft
node scripts/wp.js mysite set-builder <id>     # flip into "Divi mode"
node scripts/wp.js mysite rendered <id>        # verify it renders as native Divi
```

Read `SKILL.md` for the full workflow, the (non-negotiable) styling rules, module
inventory, popups/interactions, and data-driven Loop Builder support. The exact
serialization is documented in `references/`.

## Every Divi module: the catalogue

The compiler has hand-written builders for the common modules. For all the rest there is
a **catalogue generated from your own copy of Divi**: the `module.json` definitions the
Visual Builder itself is built from (115 modules in Divi 5.13, WooCommerce included).

```bash
node scripts/catalog.js build "/path/to/unzipped/Divi"   # once per Divi version
node scripts/catalog.js list                # every module, its children / parents
node scripts/catalog.js show accordion      # elements, fields, allowed option values, defaults
node scripts/catalog.js find "overlay"      # which modules have a field like this
node scripts/catalog.js lint content.html   # check block markup BEFORE writing it to a site
```

The lint catches unknown modules and elements, values that are not one of a field's
options, child modules outside their parent, invalid JSON and unbalanced blocks. It was
tuned until it raised no false errors on 3,333 builder-written blocks from two real sites.

The catalogue is derived from Divi, which is GPL, so **it is not shipped here**: you
generate it from the copy your Elegant Themes licence gives you, and `catalog/` is
git-ignored. Divi itself is never redistributed by this project.

Also new: every interaction trigger and effect (`references/divi5-interactions-canvases.md`,
any module can be a trigger on 5.13), and how presets and design variables are stored and
referenced (`references/divi5-presets-variables.md`, plus a read-only
`wp.js <site> design-system` that lists the presets, variables and colours a site has).

## Presets, global colours and variables: create them from outside the builder (plugin 1.8+)

Divi's own routes for these need a logged-in browser session and replace the whole store with
whatever is posted. The plugin calls the same Divi functions from inside WordPress instead,
one item at a time:

```bash
node scripts/wp.js <site> ds-selftest          # first, on every site: must say "different: 0"
node scripts/wp.js <site> ds-color-set --label "Brand" --color "#112233" [--dry-run]
node scripts/wp.js <site> ds-preset-set --file preset.json [--dry-run]
node scripts/wp.js <site> ds-backups           # the store as it was before each write
node scripts/wp.js <site> ds-restore --store presets --index 0
```

Add-only (nothing is ever deleted), same name updates, the previous store is kept, and the
write is read back. A preset's style and markup parts are worked out by Divi's own code;
`ds-selftest` proves that against the presets already on the site without writing anything.
Prove all of it on a new site with one command. It creates test items, checks Divi's own
store after every step, and puts each store back exactly as it was:

```bash
node scripts/ds-live-test.js <site> all        # staging, while nobody is editing
```

Module presets, a design variable and an option group preset were each compared with one made
by hand in the builder. What is still unproven is listed in the pull request that added this
and in `references/divi5-presets-variables.md`, section 5: above all, option group presets
are proven for one group only (Border on a Text module).

## Design system first: the recommended way to build

Colours and presets exist before the first page, and a page carries content and placement, not
design. Any section, row, column or module in a page spec takes a preset:

```json
{"type":"button","text":"Book now","url":"https://example.com","modulePreset":"<preset id>"}
```

Create the presets with `ds-preset-set`, read their ids with `design-system`, reference them in
the spec. `SKILL.md` has the rules that are easy to get wrong (one option group has one owner,
when to stack a preset on the default one). `node scripts/preset-spec-test.js` tests the
compiler side without a site.

## Globalising an existing site

`scripts/globalize.js` moves a site with hard-coded values onto global colours and presets
without changing what a visitor sees: inventory, colour literals to references, preset
candidates, build, create, assign, restore. Every step is a dry run until `--write`.
`assets/snapshot-instrument.js` is the proof: a computed-style comparison of every element on
every page, before and after. The order, the rules and everything that went wrong the first
time are in `references/divi5-globalize-site.md`.

## Site care: media audit, updates, go-live, performance

Separate from page building, and for any WordPress site (Divi or not). Four tools: a read-only media audit, a reversible
media clean-up (never deletes), a plugin/theme/WordPress updater with page checks, and a go-live kit plus a slow-page
profiler. The first three need their switch on in Settings > Divi 5 Builder (off by default). Start at
[`references/site-care/README.md`](references/site-care/README.md).

## Extending it to new modules

The reliable loop (no guessing):

1. Build the module once in the Divi Visual Builder on a scratch page.
2. `node scripts/wp.js <site> dump-blocks <pageId>` — prints every block's exact
   attribute JSON.
3. Add a small builder in `divi.js` from what you see.

Divi has 115 modules; the compiler covers the common ones and the catalogue describes the rest. PRs adding more (via the loop
above) are very welcome.

## Verified on

Divi ships often, so this table says what was actually checked on which version, and what was not. Reports from
other sites and versions are welcome. Per release, the checks are in
[references/divi-release-checklist.md](references/divi-release-checklist.md).

| Divi version | Date | What was checked |
|---|---|---|
| 5.9 | 2026-09-21 | The page format and the module serialisations were taken from live builds. Round trip: the site stores what the compiler sends, byte for byte (`roundtrip-test.js`), and every stored page closes with the placeholder wrapper (9 of 9). Presets and variables read from a live site, read-only. |
| 5.13 | 2026-09-21 | Round trip byte for byte, wrapper closer on 38 of 38 stored items. Presets and variables read from a live site, read-only. Interactions proven live (any module can be a trigger). |
| 5.13.1 | 2026-09-21 to 2026-10-12 | Module catalogue (115 modules) built from the theme source, and the presets, variables and interactions code read from it. Loop Builder, timeline, popups, background video and header patterns built on live converted sites. No separate round-trip run is recorded for this version. |
| 5.15.1 | 2026-10-28 | Round trip byte for byte with the extended round-trip spec (2707 characters, including an icon blurb, the regression case for the empty-group fix). **Visual Builder re-save with no changes:** same 35 blocks in the same tree, every attribute equal as data, rendered HTML identical. The builder only reformats the stored text (attribute key order, leaf modules saved self-closing, whitespace between blocks), so the content hash changes and the stale guard reports STALE afterwards; that is expected. Found: a blurb with an icon was stored with `"module":[]` instead of `{}`; the compiler no longer writes empty groups (#21). Components and Slots placement over REST proven live 2026-10-12 ([notes](references/divi5-components-slots.md)). Compare tool: `scripts/vb-resave-check.js`. |
| 5.18.0 | 2026-10-28 | Variable math, nested variables and mixed text read from a page and variables made in the Visual Builder, and from the theme source (#45, [notes](references/divi5-presets-variables.md)). `ds-live-test.js all` passed live with plugin 2.0.1: colours, variables, presets, and nested variables (accepted and stored as the builder stores them; missing, self, cyclic and cross-type references refused; archiving a variable in use refused), every store left exactly as it was. The plugin's design-system writes also pass `ds-write-test.php` against the 5.18.0 source. |

Sources: [references/divi5-format.md](references/divi5-format.md) ("What a save does to your content"),
[references/divi5-presets-variables.md](references/divi5-presets-variables.md),
[references/divi5-interactions-canvases.md](references/divi5-interactions-canvases.md) and the git history.

## Tests

None of these needs a site, except the last one.

```bash
node scripts/catalog-test.js          # the module catalogue and its lint
node scripts/preset-spec-test.js      # modulePreset in a page spec
node scripts/compile-test.js          # link, swiper-posts, buildModule()
node scripts/variable-refs-test.js    # Divi 5.18 variables in values: {{gvid-}} and {{dc:}} byte for byte vs a builder-saved page; the math-operator warning
node scripts/stale-guard-test.js      # page/post/canvas writes refuse when the content changed since the read; nested-variable placeholders and refusals
node scripts/spec-check-test.js       # unknown spec keys are reported; every documented example checks clean
node scripts/blocks-test.js           # block-level edits leave the rest of the page byte for byte
node scripts/vb-resave-check-test.js  # the Visual Builder re-save comparison, on a real Divi 5.15.1 before/after pair
php  scripts/permissions-test.php     # who may call each page-building route
php  scripts/double-load-test.php     # the plugin loaded twice (must-use + normal, two folders) never fatals
php  scripts/media-audit-test.php     # media audit: what counts as a reference to a file or an attachment ID
node scripts/site-care/media-audit-test.js      # media audit: the classification, the quarantine plan, the report files
php  scripts/media-quarantine-test.php   # the quarantine plugin: moves real files in a temp folder and back
php  scripts/site-updates-test.php    # the updates plugin against a fake WordPress: who may, what it refuses, each kind of update
node scripts/site-care/site-updates-test.js     # the update plan and the before/after page comparison
php  scripts/plugin-switches-test.php   # the three switches: off by default, who may flip them, routes absent while off
php  scripts/plugin-takeover-test.php   # old copies of the four plugins: deactivated, or a notice naming the file to delete
php  scripts/plugin-settings-test.php   # the Settings > Divi 5 Builder page (and Network Admin)
php  scripts/plugin-anysite-test.php    # the loader on any site, also when loaded twice
php  scripts/plugin-uninstall-test.php  # deleting the plugin removes its options (every site of a network too)
php  scripts/plugin-abilities-switches-test.php   # the AI abilities switches: master off by default, who may flip them
php  scripts/plugin-abilities-gate-test.php       # the run wrapper: capability check, write-ahead audit row, secret scrubbing
php  scripts/plugin-abilities-audit-test.php      # the audit log: redaction, 30-day pruning, a missing table never fatal
php  scripts/plugin-abilities-content-test.php    # the content abilities: per-object permission, paging, no secrets
php  scripts/plugin-abilities-site-test.php       # the site abilities: settings allow-list, users without secrets, the debug log cap
php  scripts/plugin-abilities-divi-test.php       # the Divi and history abilities
node scripts/switch-advice-test.js    # what the scripts say when a switch is off
node scripts/plugin-live-check-test.js  # the read-only live check (plugin-live-check.js <site>): GET only, exit 0/1/2
node scripts/abilities-live-check-test.js  # the read-only abilities check (abilities-live-check.js <site>): GET only, exit 0/1/2
node scripts/build-plugin-zips-test.js  # the zip build and the must-use loader next to it
php  scripts/ds-write-test.php "<path to a Divi 5 theme folder>"   # the plugin's design-system writes, nested variables included: run it against a 5.18 AND a 5.15 copy
node scripts/ds-live-test.js <site> all   # the same writes on a real site; puts every store back (plugin >= 2.0.1; the "nested" part needs Divi 5.18)
```

## Security

- **Found a vulnerability? Report it privately** - see [SECURITY.md](SECURITY.md).
  Run the latest plugin (**divi5-builder 2.1.0**; the old single-file 1.8.3 and newer already fixed a Contributor-level option read).
- Use the lowest role that works: page building needs an **Editor** Application Password.
- Never commit `~/.web-creds.txt` or any Application Password.
- The plugin exposes existing Divi meta keys, the global colour palette and (1.5+)
  Theme Builder layout read/write, each gated by the WordPress capability Divi itself
  requires for that thing — review it before installing.
- Prefer **draft-first**; treat publish / live-page edits / homepage changes as
  visible actions.
- Page, post and canvas writes are **stale-guarded**: they need the content hash from your read
  and refuse (exit 3) if someone saved the page in the Visual Builder since, so a REST write never
  silently overwrites their work. Each content write first keeps what it replaces in
  `~/.divi5-builder/backups/<site>/` (or `WP_BACKUP_DIR`). That folder holds real page content:
  keep it private, and clear it out now and then.

## License

[MIT](LICENSE).

## Credits

Reverse-engineered and built against real Divi 5 sites. "Divi" is a trademark of
Elegant Themes; "FilterGrid" of Divi Plugins — this project is independent of both.
