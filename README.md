# divi5-builder

Build and edit **Divi 5** WordPress pages programmatically, over the WordPress
REST API — from a compact JSON spec, using **native Divi modules** so pages stay
fully editable in the Divi Visual Builder afterwards. No paid AI addon, no browser
automation: just Node.js + the WP REST API.

It ships as a [Claude Code](https://docs.anthropic.com/claude-code) **skill**, but
the two scripts (`scripts/wp.js`, `scripts/divi.js`) are plain Node and work
standalone in any pipeline.

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
prints the password.

## The mu-plugin (one-time per site)

Divi 5 flags a page as "a Divi page" with **protected `_et_*` post-meta** that core
REST won't write, and Divi's own builder REST routes reject Application-Password
auth (they need a cookie-session nonce). `assets/divi5-builder-rest.php` exposes
just those specific meta keys (and a canvas-link helper) to REST **behind an
`edit_post`/`manage_options` capability check** — so only authenticated editors can
use them. Copy it to `wp-content/mu-plugins/` (create the folder if needed); mu-plugins
auto-activate. It does **not** modify Divi.

Confirm it's active: `node scripts/wp.js <site> check-plugin` → `pluginActive:true`.

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

## Extending it to new modules

The reliable loop (no guessing):

1. Build the module once in the Divi Visual Builder on a scratch page.
2. `node scripts/wp.js <site> dump-blocks <pageId>` — prints every block's exact
   attribute JSON.
3. Add a small builder in `divi.js` from what you see.

Divi has 70+ modules; this covers the common ones. PRs adding more (via the loop
above) are very welcome.

## Security

- Never commit `~/.web-creds.txt` or any Application Password.
- The mu-plugin only exposes existing Divi meta keys, gated by WordPress
  capabilities — review it before installing.
- Prefer **draft-first**; treat publish / live-page edits / homepage changes as
  visible actions.

## License

[MIT](LICENSE).

## Credits

Reverse-engineered and built against real Divi 5 sites. "Divi" is a trademark of
Elegant Themes; "FilterGrid" of Divi Plugins — this project is independent of both.
