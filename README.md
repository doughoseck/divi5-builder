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

## Security

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
