# WordPress abilities in divi5-builder (issue #32): design

Date: 2026-10-28. Status: draft for the maintainer's approval (builder check done, the maintainer's answers included).

## Intent

The maintainer tried Easy MCP AI (2.3.0), a third-party plugin that gives an AI client about 220 tools over a WordPress site. They want **the same core WordPress capabilities in our own plugin**, `divi5-builder`, so they never need another plugin. Our plugin already knows Divi 5 deeply; this adds everything else in WordPress.

The abilities are registered through **WordPress's own Abilities API** (core since WordPress 6.9), so:
- **Locally**, Claude Code calls them through WordPress's REST API with the existing Application Password. The route is `POST /wp-json/wp-abilities/v1/abilities/<name>/run`. That is one guarded route, and it adds things raw REST cannot do today, such as cron and the error log.
- **Later**, phone access (#49) exposes the same abilities to the Claude app, with the same protections. The transport and sign-in are not part of this design.
- Any other adapter (Easy MCP AI, the WordPress MCP Adapter) sees them as abilities with no extra code.

## What the Abilities API gives us (verified on a WordPress 7.1.3 site, 2026-10-28)

- **Registration:** `wp_register_ability( name, args )` and `wp_register_ability_category( slug, args )`. Abilities exist only when those functions exist; never fatal on older WordPress.
- **The ability record:** `name`, `label`, `description`, `category`, `input_schema`, `output_schema` (JSON Schema) and `meta`:
  - `meta.annotations`: `{ readonly, destructive, idempotent }`
  - `meta.show_in_rest`
- **REST routes:**
  - `GET /wp-abilities/v1/abilities` (`?category=`)
  - `GET /wp-abilities/v1/abilities/<name>`
  - `GET|POST|… /wp-abilities/v1/abilities/<name>/run`
  - `GET /wp-abilities/v1/categories`
- **Core already ships** `core/get-site-info`, `core/get-user-info` and `core/get-environment-info`. We do not duplicate them.

## Goals

1. Parity with the **core WordPress** part of Easy MCP AI's tool list, minus the server tools (see Non-goals), plus the Divi abilities this plugin already has as REST routes. The categories are listed in "Categories" below.
2. **A master switch and a switch per category** in Settings > Divi 5 Builder, in the same style and with the same rules as the 2.0.0 switches:
   - only an administrator in wp-admin can flip them (a network admin, network-level, on multisite);
   - no REST route, script or ability can flip them;
   - **a switched-off category registers no abilities at all**.
3. **Risky actions** inside a category sit behind a separate per-category switch, **off by default**, each with a plain risk note. The set of risky actions follows Easy MCP AI's "disabled by default" list, minus the server tools (see "Risky actions").
4. **Permanent deletes only after the user confirms** (the maintainer's updated rule, 2026-10-28; see "Deleting"). For things WordPress can put in the trash, delete means trash.
5. **Audit log:** every ability call is recorded (when, user, ability, arguments with secrets redacted, result or error, duration, client IP), kept **30 days**.
6. **Change history:** every write records a before and after snapshot of what it changed (linked to the WordPress revision where one exists), kept **30 days**. A permanent delete keeps a full copy where the item can be rebuilt. Both are viewable in wp-admin and readable through read-only abilities.
7. Delivered in **four releases**, in the order agreed with the maintainer (see "Releases").

## Non-goals

- **Phone access, MCP transport, OAuth.** That is #49, blocked until the host lets Anthropic's servers reach the sites.
- **Server tools: WP-CLI, database search-replace, reading server files. Not offered at all.** The maintainer, 2026-10-28: "if you want to do these, login to your hosting account and go for it... not in our plugin, too risky for us".
- **Replacing the existing tools.** `wp.js`, the page compiler, `blocks.js`, the site-care scripts and the plugin's existing `divi5-builder/v1` REST routes stay as they are, outside these switches. They are the local tools, used with an administrator's Application Password, and keep their own protections (stale hash, local backups, the 2.0.0 switches). (My call, from the builder check.)
- External data sources (Analytics, Search Console, SEO services) and third-party plugin integrations (WooCommerce, ACF, forms). Only core WordPress plus Divi.
- Duplicating WordPress core's own abilities.

## Anti-goals (a failure even if it "works")

- A switched-off category, a risky action whose switch is off, or any ability while the master switch is off, that can still be reached **as an ability** through any route (core REST, an MCP adapter, `wp_get_ability()` in PHP).
- A switch that anything other than an administrator in wp-admin can flip.
- An ability write that leaves no trace in the change history, or an ability call missing from the audit log.
- **A permanent delete without the user's confirmation** (see "Deleting"). Media without trash support (WordPress's default `MEDIA_TRASH` is off) counts as a permanent delete.
- A content write that overwrites a newer save. The stale check applies, as in `wp.js --expect-hash`; see "Guardrails".
- A fatal error on any site: WordPress without the Abilities API, no Divi, multisite, the plugin loaded twice.

## Categories

All are named `divi5-builder/<category>-<action>`, for example `divi5-builder/posts-list` and `divi5-builder/pages-update`, so they never clash with other plugins.

| Category | Read | Normal writes | Risky actions (separate switch, off) | Release |
|---|---|---|---|---|
| Posts | list, get, search, get revisions | create, update, trash, restore from trash | none | 1 read, 2 write |
| Pages | list, get | create, update, trash, restore | none ("set homepage" is a Settings risky action) | 1, 2 |
| Media | list, get, sizes | upload (from URL or base64), update alt/title/caption, trash (where supported) | permanent delete (with confirmation) | 1, 2 |
| Taxonomy (categories, tags, custom taxonomies) | list, get | create, update, assign | delete term (with confirmation) | 1, 2 |
| Comments | list, get | approve, unapprove, spam, reply, trash | none | 1, 2 |
| Custom fields (post meta) | get | set; delete a key (with confirmation) | protected (`_`-prefixed) keys | 1, 2 |
| Custom post types | list types, list and get items | create, update, trash | none | 1, 2 |
| Blocks (reusable/synced patterns) | list, get | create, update, trash | none | 1, 2 |
| Menus | list menus, items, locations | add, update, reorder, remove items, assign location | delete a whole menu (with confirmation) | 1, 2 |
| Revisions | list, get, compare | restore a revision | none | 1, 2 |
| Search | site-wide content search | none | none | 1 |
| Settings | read core settings (general, reading, writing, discussion, permalinks) | none | update settings, set homepage | 1, 3 |
| Templates and global styles (block-theme parts) | list, get | none | update template, update global styles | 1, 3 |
| Appearance | active theme, theme mods, Custom CSS | none | update or delete theme mods, update Custom CSS | 1, 3 |
| Widgets and sidebars | list, get | add, update, move, remove | none | 1, 3 |
| Cache | status | flush known caches (reuse the site-care cache clearing) | none | 1, 3 |
| Site health, cron, error log | health status, cron events, recent error log lines | none | run a cron event now | 1, 3 |
| Users | list, get (no passwords or secrets ever) | none | create, update users, update user meta; delete a user (with confirmation, content reassigned) | 1, 4 |
| Plugins | list, status | none | activate, deactivate, install, update; delete (with confirmation) | 1, 4 |
| Themes | list, status | none | switch, install, update; delete (with confirmation) | 1, 4 |
| Core | version, update available | none | update WordPress core | 1, 4 |
| Email | none | none | send an email | 4 |
| History | audit log, change history, diff | none | none | 1 (log), 2 (history) |
| Divi (existing routes as abilities) | design system, Theme Builder list/get, canvases, builder status | set builder mode, link canvas, design-system writes, Theme Builder write (hash-checked), section-level page edit | Custom CSS (governed by the Appearance risky switch) | 1 read, 2 write |

Notes:
- **Overlaps: the strictest switch wins** (my call, from the builder check).
  - Custom CSS writes are risky everywhere, including through the Divi category, and governed by the Appearance risky switch.
  - "Set homepage" changes `show_on_front` / `page_on_front`, so it is a Settings risky action.
  - The generic custom-post-type abilities refuse these internal types, which have their own guarded abilities: `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`, `custom_css`, `et_template`, `et_header_layout`, `et_body_layout`, `et_footer_layout`, `et_pb_layout`, `et_theme_builder` and `et_pb_canvas`.
- "Normal writes" are on by default with their category once the master switch is on, matching Easy MCP AI, which enables them. "Risky actions" are off by default.
- The 2.0.0 switches (Media audit, Media clean-up, Updates) stay. The new plugin, theme and core **update** abilities must reuse the Updates part's code, not duplicate it. The builder decides whether the Updates switch and the new risky switches for plugins, themes and core are merged or linked, and states it in the plan.

## Risky actions

These follow Easy MCP AI's disabled-by-default list (screenshot, 2026-10-28), minus the server tools, which are not offered:
- create, update or delete users, and update user meta
- update site settings (including the homepage), global styles, a template, theme mods or Custom CSS
- run a cron event
- activate, deactivate, install, update or delete plugins and themes, and switch theme
- update core
- send email
- permanent deletes of things WordPress cannot trash (see "Deleting")
- generic REST delete: not offered at all; deletes are per type

Each risky switch shows a short plain note of what can go wrong. For example:
- **Plugins:** "Installing or removing plugins can take the site down. Take a backup first."
- **Users:** "Changing or deleting users can lock people out. Deleted users' content is reassigned, never deleted."

## Deleting

The maintainer's rule, 2026-10-28, updated: **a permanent delete only after the user confirms it.** (It replaces "never permanently delete" for this plugin; the maintainer often tests plugins and deletes them afterwards.)

- **Things WordPress can trash** (posts, pages, custom post type items, blocks, comments, and media when `MEDIA_TRASH` is on): "delete" means trash, in one step. Restore-from-trash abilities are offered. There is no "empty trash" ability.
- **Things WordPress cannot trash** (terms, whole menus, meta keys, users, plugins, themes, and media when `MEDIA_TRASH` is off) need **two steps**:
  1. A **preview** call returns exactly what would be deleted: name, type, and what depends on it (posts in a term, a user's content to be reassigned, whether a plugin is active). It also returns a one-time confirmation code, valid for 10 minutes, bound to that user, that item and its current state.
  2. Only a second call carrying that code deletes. The ability's description tells the AI to show the preview to the user and wait for their explicit OK before the second call.
- **Refusals:** a code that is stale (the item changed), used, expired, or for another item or user is refused.
- **Change history:** a full copy of anything that can be rebuilt (term with its assignments, menu with its items, meta value, user record without secrets). For plugins and themes it keeps the slug and version, which are enough to reinstall.
- **Users:** a user's content is always reassigned (to a user the preview names), never deleted with them.
- Note for the maintainer: Claude itself still follows its own rule of never performing a permanent delete. With these abilities, Claude prepares the preview and the user gives the confirmation.

## Switches: how they behave

- **One option holds them all,** extending the 2.0.0 store, not autoloaded, a site option on multisite. It holds:
  - `abilities_master` `{ on, changed_at, changed_by }`
  - per category `{ on, risky_on, changed_at, changed_by }`
- **Same rules as 2.0.0:**
  - wp-admin form only, nonce-checked;
  - `manage_options` (or `manage_network_options` on multisite);
  - never `show_in_rest`;
  - the static test scan extended to the new code.
- **Registration gate:**
  - with the master switch off, no `divi5-builder/*` abilities are registered at all;
  - a category whose switch is off registers none;
  - a risky action whose switch is off is not registered.
  - The 2.0.0 test "switch off means no routes" extends to "switch off means no abilities".
- **Defaults (the maintainer agreed, 2026-10-28):**
  - The master switch **starts off on every site**, on a fresh install and on an upgrade from 2.0.x. No site exposes any ability until an administrator switches it on.
  - When it is switched on, the read and normal-write categories are already ticked and every risky switch is off.
  - No migration of existing settings is needed: a missing value reads as "off" for the master and every risky switch.

## Guardrails inside every ability

On a phone the skill's rules are not loaded, so every rule lives in the ability.

1. **Permissions:** the same capability WordPress requires for that action in wp-admin, checked per object (`edit_post` on that post, not just `edit_posts`).
2. **No stale overwrite:** an update to content (post or page content, Theme Builder layouts, canvases) requires `expect_hash`, the MD5 of `content.raw` the client read, the same definition as `wp.js`. A get returns that hash. On a mismatch the ability returns `stale` with when it was last saved, and writes nothing. The description tells the AI to stop and ask the user, as SKILL.md does.
3. **Deleting:** trash where possible, otherwise preview and confirmation (see "Deleting").
4. **Divi pages:** a full content replace of a page built with Divi is allowed only with `expect_hash`. The description says to prefer the section-level edit ability (the `blocks.js` logic on the server, release 2).
5. **Descriptions an AI can follow:** every ability's `description` and schema field descriptions say what it does, what it will refuse, and what to do next. `meta.annotations` is set truthfully (`readonly`, `destructive`, `idempotent`).
6. **Secrets never leave the site:** no password hashes, application passwords, API keys, or secret options in any output, including `options` reads (an allow-list of readable settings) and user records.

### What the secret filter cannot catch

The filter (`inc/abilities/secrets.php`, `scrub.php`, the masker in `audit.php`) hides values by the name they are stored under, by the name beside them, by key=value text and by a few certain shapes (private keys, Stripe keys, JSON Web Tokens, password hashes, bearer tokens). Names are a heuristic, so it is built to hide when in doubt. Free text goes through one of two profiles:

- **FULL** (the audit log's exception text, debug log lines, raw third-party data, a WP_Error's messages and data): everything the masker knows, including "password hunter2" and "key: value".
- **STRICT** (every other string an ability returns: titles, snippets, descriptions, comments, names): only key=value where the key is a secret word as a whole token or known compound, `Authorization:` / `Proxy-Authorization:` headers, Bearer and Basic credentials, URL passwords, `define('SECRET', …)`, `-u user:pass`, and the value shapes. "Password: choose a strong one", "Sea salt crackers" and "Cookie: chocolate chip" stay as written.
- Post content (posts, pages, custom items, blocks, revisions, templates, global styles, Custom CSS) gets the value shapes only.

A value counts as a NAME beside another value (raw data only) by whole tokens and known compounds, never by a word inside a word, and a value with a hyphen is a slug (`session-times`), unless it is exactly a known compound (`api-key`). These are the filter's known limits, accepted rather than chased:

- **Look-alike letters.** A key written with letters from another alphabet (`pаssword` with a Cyrillic а) or full-width letters is not recognised.
- **Names held only by index.** A value whose name is given only as a number pointing into a separate list (`{ "name": 0, "value": "…", "names": ["password"] }`) is not linked to that name.
- **Names beside values only in raw data.** The "name beside it" rule runs only inside raw third-party data (post meta, theme mods, widget settings, option values, a WP_Error's data; each ability declares these paths). There, the name is looked for in the same small array (8 entries or fewer) and one level down, and must be key-like (no spaces). A name two or more levels below or away from its value (`{ "name": [["password"]], "value": "…" }`), a spaced name ("SMTP password"), or a name/value pair inside a row our own handlers build is not linked. In our own rows only key removal and the free-text masker apply.
- **Secrets in prose with no key word.** A secret written into text with no word such as password, token or key before it, and no known shape ("my login is hunter2-x"), passes. In the strings our own handlers build (STRICT) the forms "password hunter2" and "password: hunter2" pass too; only key=value and the forms listed above are masked there. Serialized strings are not unpacked. Post content (posts, pages, custom items, blocks, revisions, templates, global styles, Custom CSS) gets only the shape check, so that it stays editable; results say `"masked": true` whenever anything was hidden.
- **Harmless fields get hidden.** Keys that contain a secret word are hidden too: `random_seed`, `post_password_required`, `session_count`, `token_count` (cookie, session and pin count only as the last word or before value, id, key, token, data, secret, hash or code, so `cookie_notice_text` and `pin_icon_color` stay), and, inside raw third-party data, every other text in a small array where one key-like value names a secret (`{ "name": "smtp_password", "host": "…" }` in post meta hides the host). Only a short exact list of known-harmless keys (compass, author, layout_key and a few more) is exempt. Text over 256 KB is cut and marked `…[truncated]`.

## Audit log and change history

- **Storage:** custom tables created on activation and dropped by `uninstall.php`, so the options table stays small. The builder may choose another store if it argues for it in the plan. Multisite: per-site tables.
- **Audit log row:** time (UTC), user ID, ability name, arguments (secrets and large payloads redacted or truncated), result status or error code, duration, client IP.
- **Change history row:** time, user, ability, object type and ID, and before and after (the changed fields only, capped in size, except the full copies kept for permanent deletes). It also records the WordPress revision ID where one was made.
- **Retention:** 30 days, pruned daily by WP-Cron. No setting for this in release 1 or 2.
- **wp-admin:** two read-only list screens under Settings > Divi 5 Builder, filterable by date, user and ability. History rows link to WordPress's own revision compare screen where a revision exists.
- **Abilities:** `history-audit-list`, `history-changes-list` and `history-change-get` (read-only).

## Releases (the order the maintainer agreed)

| Release | Contents |
|---|---|
| **2.1.0** | Master switch (off), read-only abilities for every category, the switches screen, and the audit log |
| **2.2.0** | Content writes (posts, pages, media, taxonomy, comments, custom fields, custom post types, menus, revisions restore) **plus blocks**, Divi write abilities including the section-level edit, the delete preview and confirmation, and the change history |
| **2.3.0** | Site management: settings, templates and global styles, appearance and Custom CSS, widgets, cache, site health, cron and error log |
| **2.4.0** | Risky switches: users, plugins and themes (reusing the Updates part), core updates, email |

Each release gets the same treatment as 2.0.0 and 2.0.1:
- tests first;
- mutation checks on every guard;
- an adversarial pass where writes are involved;
- a live run on the maintainer's dev site before release.

## Tests

- **Offline (WordPress stand-ins, extending `plugin-test-stubs.php`):**
  - master off means no abilities at all
  - for every category: switch off means no abilities registered; switch on means exactly that category's abilities; risky off means risky abilities absent
  - the strictest switch wins on overlaps (Custom CSS via Divi, set homepage)
  - custom post type abilities refuse the internal types
  - the permission callback for each ability against Contributor, Editor and Administrator
  - `expect_hash` refusal
  - trash where possible
  - delete without a code refused; a stale, used, expired or wrong-item code refused; the deleted item rebuildable from history where promised
  - no secret in any output (scan the outputs)
  - audit row for every call, including refused ones
  - change history row for every write
  - 30-day pruning
- **Static scan:** nothing outside the switch file writes the switch option.
- **Live (a script like `ds-live-test.js`) on the dev site:**
  - read every category
  - create and trash a test post and page
  - create a test term, delete it through preview and confirmation (the maintainer gives the confirmation)
  - check the audit and history rows
  - leave the site as it was (test items trashed, and the maintainer empties the trash)

## Left to the builder

- Exact ability names and schemas within the naming pattern above.
- How abilities are grouped in code: one file per category is suggested.
- Table schemas, indexes and the pruning schedule.
- The confirmation code format and storage, within the rules in "Deleting".
- The wp-admin screen layout, which should match the 2.0.0 settings page.
- Whether the Updates switch merges into the new risky switches for plugins, themes and core (state the choice in the plan).
