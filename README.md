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
- **`assets/divi5-builder-rest.php`** — a tiny must-use plugin that bridges the few
  gaps the REST API leaves (see below).

## Requirements

- A WordPress site running **Divi 5** (5.x). Divi 4 sites use `[et_pb_*]`
  shortcodes and are **not** supported — `wp.js <site> divi-check` will tell you.
- A WordPress **Application Password** for a user who can edit pages
  (Administrator/Editor). App passwords inherit the user's capabilities.
- **Node.js** 18+ (uses only built-ins — no `npm install`).
- The bundled **mu-plugin** installed on the target site (one-time).

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

## The mu-plugin (one-time per site)

Divi 5 flags a page as "a Divi page" with **protected `_et_*` post-meta** that core
REST won't write, and Divi's own builder REST routes reject Application-Password
auth (they need a cookie-session nonce). `assets/divi5-builder-rest.php` exposes
just those specific meta keys (and a canvas-link helper) to REST **behind an
`edit_post`/`manage_options` capability check** — so only authenticated editors can
use them. Copy it to `wp-content/mu-plugins/` (create the folder if needed); mu-plugins
auto-activate. It does **not** modify Divi.

Confirm it's active: `node scripts/wp.js <site> check-plugin` → `pluginActive:true`.

### Theme Builder headers, footers and body layouts (plugin 1.5+)

Core REST does not expose Divi's Theme Builder post types, so from 1.5 the plugin adds
routes to **list** every template and layout, **read** a layout's raw content, **write**
one back, and **restore** the previous version (`wp.js tb-list | tb-get | tb-set |
tb-restore`). A header or footer is on every page, so the write route is deliberately
fussy: it needs `edit_theme_options` + `unfiltered_html`, the md5 of the content you
last read (an edit made in the Visual Builder meanwhile is never overwritten), balanced
blocks, and it reads the save back and reverts automatically if the block tree changed.
`node scripts/wp.js <site> plugin-version` tells you what a site is running; upgrading
is re-uploading the file.

## Quickstart

```bash
node scripts/wp.js mysite whoami          # 200 + your name  → creds OK
node scripts/wp.js mysite divi-check      # verdict:"divi5"  → right engine
node scripts/wp.js mysite check-plugin    # pluginActive:true → mu-plugin OK
node scripts/wp.js mysite global-colors   # discover gcid global-colour ids

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
whatever is posted. The mu-plugin calls the same Divi functions from inside WordPress instead,
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

## Media and large-file audit: any WordPress site

Old sites carry years of uploads nobody uses. This part does not need Divi at all: it works on any WordPress site
with an administrator's application password.

```bash
node scripts/media-audit.js scan   <site> --dir audit     # needs assets/wp-media-audit.php in mu-plugins (read-only)
node scripts/media-audit.js crawl  <site> --dir audit     # loads every public page: the cross-check
node scripts/media-audit.js report --dir audit            # REPORT.md + CSV lists, biggest first
node scripts/media-audit.js db     <site> --dir audit     # what takes the space in the database (read-only)
```

- **Two findings, kept apart:** media library items nothing refers to, and files on disk that belong to no library
  item. Plus the biggest files, local video and audio, broken references, and the size of plugins, themes and the
  site root.
- **Every library item gets a status:** USED, MAYBE (only a bare number matched; kept as used), BACKGROUND (only
  revisions, trash or an old builder copy refer to it) or UNUSED. When in doubt, used.
- **It finds references where they hide:** escaped JSON, serialized PHP, shortcode attributes, block attributes,
  custom fields, options, theme files, every plugin table. A size, a `-scaled` copy or a `.webp` copy counts for
  its image.
- **The crawl is the cross-check.** A file a public page loads can never be on the unused list, and the report says
  how many such files the database scan alone would have missed. On the first real run that number exposed a form
  of reference the scan did not know (Divi 5's nested gallery IDs), which is now covered.

**Nothing deletes.** To remove files, quarantine them:

```bash
node scripts/media-quarantine.js verify  <site> --dir audit                 # baseline: what is already missing
node scripts/media-quarantine.js plan    --dir audit --batch unused --status UNUSED
node scripts/media-quarantine.js move    <site> --dir audit --batch unused  # dry run; add --write
node scripts/media-quarantine.js verify  <site> --dir audit                 # newly missing files, and on which page
node scripts/media-quarantine.js restore <site> --dir audit --batch unused --needed --write
```

`assets/wp-media-quarantine.php` moves the named files to `wp-content/media-audit-quarantine/<batch>/` (closed to
the web, with a manifest) and can move them back. It has no delete and does not touch the database. The permanent
step, deleting the quarantine folder, is the site owner's. First real run: a 2.78 GB uploads folder went to 0.8 GB
with no file missing on any public page. Playbook: `references/wp-media-audit.md`.

## Updating plugins, themes and WordPress: any WordPress site

`scripts/site-updates.js` with `assets/wp-site-updates.php` (a small mu-plugin you upload once per site). It runs
WordPress's own updater, the code behind the Updates screen, one update per request.

```bash
node scripts/site-updates.js status <site>                       # what is out of date (asks the update servers first)
node scripts/site-updates.js update <site> --all                 # the plan only; nothing changes without --write
node scripts/site-updates.js update <site> --all --write         # careful: one at a time, pages checked after each
node scripts/site-updates.js update <site> --all --fast --write  # small brochure site: all in one go
node scripts/site-updates.js clear  <site>                       # Divi's generated CSS and the page cache
```

- **Careful mode** (the default) loads a set of pages before the run and again after every update, and stops at the
  first update that fails or the first page that got worse (an error page, a printed PHP error, a page cut off or one
  that lost more than half its content). **Fast mode** runs everything and compares once at the end.
- After every update the site clears Divi's generated CSS and the page cache by itself. On Divi 5, stale static CSS
  after a plugin, theme or WordPress update is the usual reason a site looks broken afterwards.
- A new WordPress release (6.8 to 6.9) needs `--major`; maintenance releases are taken. Premium items with no
  download on offer (no active licence) are listed as blocked and never tried.
- An update counts as done only when the installed version, read back from disk, is the new one.
- There is no rollback: a bad update is undone from a backup. The page check sees errors and broken pages, not a
  layout that shifted. Playbook: `references/wp-site-updates.md`.

Status when published: plugin updates, the plan, the forced fresh check and the cache clearing have run on a real
site. Theme updates, WordPress core updates, translations, fast mode and multisite are covered by the tests against a
fake WordPress only; try them first on a site where a failure does not matter.

## When a Divi page is slow: measure, don't guess

`scripts/hook-profiler.js` generates a small, temporary, **key-gated, read-only** mu-plugin that times every callback on the
hooks a front-end request goes through and prints the slowest as one HTML comment. It also compares WordPress core's block
parser with the parser the site actually runs, on the page's real content, and runs raw CPU benchmarks so you can tell a slow
server from slow code.

```bash
node scripts/hook-profiler.js gen ./prof           # d5b-profiler.php + a random key (both git-ignored)
php  scripts/hook-profiler-test.php ./prof         # 9 local checks, no WordPress needed
# upload d5b-profiler.php to wp-content/mu-plugins/, then:
node scripts/hook-profiler.js run https://example.com/some-page/ --key-file ./prof/d5b-profiler.key
# delete the file from the site when you are done
```

Why it exists: on one converted Divi 4 site, any Divi **interaction** took pages from ~5 s to 14–17 s. Three plausible theories
(a slow query, run-time content migrations, OPcache) were each wrong; the profiler found it in a few runs — a per-block cost in
the theme's own front-end block parser, multiplied by the extra passes interactions trigger. Another site showed no such cost,
so the advice in `references/divi5-interactions-canvases.md` is simply: time a page before and after your first interaction,
and there is a native-section + tiny-script fallback if it jumps.

## Extending it to new modules

The reliable loop (no guessing):

1. Build the module once in the Divi Visual Builder on a scratch page.
2. `node scripts/wp.js <site> dump-blocks <pageId>` — prints every block's exact
   attribute JSON.
3. Add a small builder in `divi.js` from what you see.

Divi has 115 modules; the compiler covers the common ones and the catalogue describes the rest. PRs adding more (via the loop
above) are very welcome.

## Tests

None of these needs a site, except the last one.

```bash
node scripts/catalog-test.js          # the module catalogue and its lint
node scripts/preset-spec-test.js      # modulePreset in a page spec
node scripts/compile-test.js          # link, swiper-posts, buildModule()
php  scripts/media-audit-test.php     # media audit: what counts as a reference to a file or an attachment ID
node scripts/media-audit-test.js      # media audit: the classification, the quarantine plan, the report files
php  scripts/media-quarantine-test.php   # the quarantine plugin: moves real files in a temp folder and back
php  scripts/site-updates-test.php    # the updates plugin against a fake WordPress: who may, what it refuses, each kind of update
node scripts/site-updates-test.js     # the update plan and the before/after page comparison
php  scripts/ds-write-test.php "<path to a Divi 5 theme folder>"   # the mu-plugin's design-system writes
node scripts/ds-live-test.js <site> all   # the same writes on a real site; puts every store back
```

## Security

- **Found a vulnerability? Report it privately** - see [SECURITY.md](SECURITY.md).
  Run the latest mu-plugin (**1.8.3** fixes a Contributor-level option read; update now).
- Use the lowest role that works: page building needs an **Editor** Application Password.
- Never commit `~/.web-creds.txt` or any Application Password.
- The mu-plugin exposes existing Divi meta keys, the global colour palette and (1.5+)
  Theme Builder layout read/write, each gated by the WordPress capability Divi itself
  requires for that thing — review it before installing.
- Prefer **draft-first**; treat publish / live-page edits / homepage changes as
  visible actions.

## License

[MIT](LICENSE).

## Credits

Reverse-engineered and built against real Divi 5 sites. "Divi" is a trademark of
Elegant Themes; "FilterGrid" of Divi Plugins — this project is independent of both.
