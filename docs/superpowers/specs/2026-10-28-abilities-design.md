# WordPress abilities in divi5-builder (issue #32): design

Date: 2026-10-28. Status: draft for the maintainer's approval.

## Intent

The maintainer tried Easy MCP AI (2.3.0), a third-party plugin that gives an AI client about 220 tools over a WordPress site. They want **the same core WordPress capabilities in our own plugin**, `divi5-builder`, so they never need another plugin. Our plugin already knows Divi 5 deeply; this adds everything else in WordPress.

The abilities are registered through **WordPress's own Abilities API** (core since WordPress 6.9), so:
- **Locally**, Claude Code calls them through WordPress's REST API with the existing Application Password. The route is `POST /wp-json/wp-abilities/v1/abilities/<name>/run`. That is one guarded route, and it adds things raw REST cannot do today, such as cron, the error log and search-replace.
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

1. Parity with the **core WordPress** part of Easy MCP AI's tool list, plus the Divi abilities this plugin already has as REST routes. The categories are listed in "Categories" below.
2. **A switch per category** in Settings > Divi 5 Builder, in the same style and with the same rules as the 2.0.0 switches:
   - only an administrator in wp-admin can flip them (a network admin, network-level, on multisite);
   - no REST route, script or ability can flip them;
   - **a switched-off category registers no abilities at all**.
3. **Risky actions** inside a category sit behind a separate per-category switch, **off by default**, each with a plain risk note. The set of risky actions follows Easy MCP AI's "disabled by default" list (see "Risky actions").
4. **Dangerous server tools** (WP-CLI, database search-replace, reading server files) are **offered**, in their own category, off by default, with a stronger warning. This is the maintainer's choice: "offer them, switched off by default and with inline warning text about their use".
5. **Audit log:** every ability call is recorded (when, user, ability, arguments with secrets redacted, result or error, duration, client IP), kept **30 days**.
6. **Change history:** every write records a before and after snapshot of what it changed (linked to the WordPress revision where one exists), kept **30 days**. Both are viewable in wp-admin and readable through read-only abilities.
7. Delivered in **five releases**, in the order agreed with the maintainer (see "Releases").

## Non-goals

- **Phone access, MCP transport, OAuth.** That is #49, blocked until the host lets Anthropic's servers reach the sites.
- **Replacing the existing tools.** `wp.js`, the page compiler, `blocks.js` and the site-care scripts stay as they are. Scripts may move onto abilities later where that is safer, as separate work.
- External data sources (Analytics, Search Console, SEO services) and third-party plugin integrations (WooCommerce, ACF, forms). Only core WordPress plus Divi.
- Duplicating WordPress core's own abilities.

## Anti-goals (a failure even if it "works")

- A switched-off category, or a risky action whose switch is off, that can still be reached by any route.
- A switch that anything other than an administrator in wp-admin can flip.
- An AI write that leaves no trace in the change history, or a call missing from the audit log.
- **A permanent delete.** Deletes go to the trash; there is never an "empty trash" or "force delete" ability. Media without trash support (WordPress's default `MEDIA_TRASH` is off) is refused rather than deleted permanently.
- A content write that overwrites a newer save. The stale check applies, as in `wp.js --expect-hash`; see "Guardrails".
- A fatal error on any site: WordPress without the Abilities API, no Divi, multisite, the plugin loaded twice.

## Categories

All are named `divi5-builder/<category>-<action>`, for example `divi5-builder/posts-list` and `divi5-builder/pages-update`, so they never clash with other plugins.

| Category | Read | Normal writes | Risky actions (separate switch, off) | Release |
|---|---|---|---|---|
| Posts | list, get, search, get revisions | create, update, trash, restore from trash | none | 1 read, 2 write |
| Pages | list, get | create, update, trash, restore, set homepage | none | 1, 2 |
| Media | list, get, sizes | upload (from URL or base64), update alt/title/caption | delete (trash only where supported) | 1, 2 |
| Taxonomy (categories, tags, custom taxonomies) | list, get | create, update, assign | delete term | 1, 2 |
| Comments | list, get | approve, unapprove, spam, reply, trash | none | 1, 2 |
| Custom fields (post meta) | get | set, delete a key | protected (`_`-prefixed) keys | 1, 2 |
| Custom post types | list types, list and get items | create, update, trash | none | 1, 2 |
| Blocks (reusable/synced patterns) | list, get | create, update, trash | none | 1, 2 |
| Menus | list menus, items, locations | add, update, reorder, remove items, assign location | delete a whole menu | 1, 2 |
| Revisions | list, get, compare | restore a revision | none | 1, 2 |
| Search | site-wide content search | none | none | 1 |
| Settings | read core settings (general, reading, writing, discussion, permalinks) | none | update settings | 1, 3 |
| Templates and global styles (block-theme parts) | list, get | none | update template, update global styles | 1, 3 |
| Appearance | active theme, theme mods, Custom CSS | none | update or delete theme mods, update Custom CSS | 1, 3 |
| Widgets and sidebars | list, get | add, update, move, remove | none | 1, 3 |
| Cache | status | flush known caches (reuse the site-care cache clearing) | none | 1, 3 |
| Site health, cron, error log | health status, cron events, recent error log lines | none | run a cron event now | 1, 3 |
| Users | list, get (no passwords or secrets ever) | none | create, update, delete users, update user meta | 1, 4 |
| Plugins | list, status | none | activate, deactivate, install, update, delete | 1, 4 |
| Themes | list, status | none | switch, install, update, delete | 1, 4 |
| Core | version, update available | none | update WordPress core | 1, 4 |
| Email | none | none | send an email | 4 |
| Server tools (dangerous) | none | none | WP-CLI command, database search-replace, read a server file | 5 |
| History | audit log, change history, diff | none | none | 1 (log), 2 (history) |
| Divi (existing routes as abilities) | design system, Theme Builder list/get, canvases, builder status | set builder mode, link canvas, design-system writes, Theme Builder write (hash-checked), Custom CSS | none beyond today's rules | 1 read, 2 write |

Notes:
- "Normal writes" are on by default with their category, matching Easy MCP AI, which enables them. "Risky actions" are off by default.
- The 2.0.0 switches (Media audit, Media clean-up, Updates) stay. The new plugin, theme and core **update** abilities must reuse the Updates part's code, not duplicate it. The builder decides whether the Updates switch and the new risky switches for plugins, themes and core are merged or linked, and states it in the plan.

## Risky actions

These follow Easy MCP AI's disabled-by-default list (screenshot, 2026-10-28):
- create, update or delete users, and update user meta
- update site settings, global styles, a template, theme mods or Custom CSS
- run a cron event
- activate, deactivate, install, update, delete or uninstall plugins and themes, and switch theme
- update core
- generic REST delete (not offered at all here; deletes are per type and to the trash)
- send email
- WP-CLI, privileged file read, privileged search-replace (in the Server tools category)

Each risky switch shows a short plain note of what can go wrong. For example:
- **Plugins:** "Installing or removing plugins can take the site down. Take a backup first."
- **Server tools:** "These run commands directly on the server and can change or expose anything on the site. Switch them on only for a specific job, and off straight after."

## Switches: how they behave

- **One option holds them all,** extending the 2.0.0 store, not autoloaded, a site option on multisite. It holds per category `{ on, risky_on, changed_at, changed_by }`.
- **Same rules as 2.0.0:**
  - wp-admin form only, nonce-checked;
  - `manage_options` (or `manage_network_options` on multisite);
  - never `show_in_rest`;
  - the static test scan extended to the new code.
- **Registration gate:** a category whose switch is off registers no abilities, and a risky action whose switch is off is not registered either. The 2.0.0 test "switch off means no routes" extends to "switch off means no abilities".
- **Defaults:**
  - read and normal-write categories are on;
  - risky switches are off;
  - Server tools is off, with no "normal" part.
  - The maintainer should be able to switch everything off in one click ("AI abilities: off"), as a master switch above the categories, also off-safe.

## Guardrails inside every ability

On a phone the skill's rules are not loaded, so every rule lives in the ability.

1. **Permissions:** the same capability WordPress requires for that action in wp-admin, checked per object (`edit_post` on that post, not just `edit_posts`).
2. **No stale overwrite:** an update to content (post or page content, Theme Builder layouts, canvases) requires `expect_hash`, the MD5 of the content the client read. A get returns that hash. On a mismatch the ability returns `stale` with when it was last saved, and writes nothing. The description tells the AI to stop and ask the user, as SKILL.md does.
3. **Trash, never delete:** see Anti-goals.
4. **Divi pages:** a full content replace of a page built with Divi is allowed only with `expect_hash`. The description says to prefer section-level edits. A section-level edit ability (the `blocks.js` logic on the server) is in release 2.
5. **Descriptions an AI can follow:** every ability's `description` and schema field descriptions say what it does, what it will refuse, and what to do next. `meta.annotations` is set truthfully (`readonly`, `destructive`, `idempotent`).
6. **Secrets never leave the site:** no password hashes, application passwords, API keys, or secret options in any output, including `options` reads (an allow-list of readable settings) and user records.

## Audit log and change history

- **Storage:** custom tables created on activation and dropped by `uninstall.php`, so the options table stays small. The builder may choose another store if it argues for it in the plan.
- **Audit log row:** time (UTC), user ID, ability name, arguments (secrets and large payloads redacted or truncated), result status or error code, duration, client IP.
- **Change history row:** time, user, ability, object type and ID, before and after (the changed fields only, capped in size), and the WordPress revision ID where one was made.
- **Retention:** 30 days, pruned daily by WP-Cron. No setting for this in release 1 or 2.
- **wp-admin:** two read-only list screens under Settings > Divi 5 Builder, filterable by date, user and ability. History rows link to WordPress's own revision compare screen where a revision exists.
- **Abilities:** `history-audit-list`, `history-changes-list` and `history-change-get` (read-only).

## Releases (the order the maintainer agreed)

| Release | Contents |
|---|---|
| **2.1.0** | Read-only abilities for every category, the switches screen with a master switch, and the audit log |
| **2.2.0** | Content writes (posts, pages, media, taxonomy, comments, custom fields, custom post types, menus, revisions restore) **plus blocks**, Divi write abilities including a section-level edit, and the change history |
| **2.3.0** | Site management: settings, templates and global styles, appearance and Custom CSS, widgets, cache, site health, cron and error log |
| **2.4.0** | Risky switches: users, plugins and themes (reusing the Updates part), core updates, email |
| **2.5.0** | Server tools: WP-CLI, search-replace, file read, with the strongest warnings |

Each release gets the same treatment as 2.0.0 and 2.0.1:
- tests first;
- mutation checks on every guard;
- an adversarial pass where writes are involved;
- a live run on the maintainer's dev site before release.

## Tests

- **Offline (WordPress stand-ins, extending `plugin-test-stubs.php`):**
  - for every category: switch off means no abilities registered; switch on means exactly that category's abilities; risky off means risky abilities absent
  - the permission callback for each ability against Contributor, Editor and Administrator
  - `expect_hash` refusal
  - trash, not delete
  - no secret in any output (scan the outputs)
  - audit row written for every call, including refused ones
  - change history row for every write
  - 30-day pruning
- **Static scan:** nothing outside the switch file writes the switch option.
- **Live (a script like `ds-live-test.js`) on the dev site:**
  - read every category
  - create and trash a test post and page
  - check the audit and history rows
  - leave the site as it was (test items trashed, and the maintainer empties the trash)

## Left to the builder

- Exact ability names and schemas within the naming pattern above.
- How abilities are grouped in code: one file per category is suggested.
- Table schemas, indexes and the pruning schedule.
- The wp-admin screen layout, which should match the 2.0.0 settings page.
- Whether the Updates switch merges into the new risky switches for plugins, themes and core (state the choice in the plan).
