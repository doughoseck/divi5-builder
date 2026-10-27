# One plugin with switches (issue #37): design

Date: 2026-10-27. Status: draft for the maintainer's approval.

## Intent

Today the skill ships four WordPress plugins:

| Plugin | What it does | Today's install advice |
|---|---|---|
| `divi5-builder-rest.php` 1.8.5 | page building over REST: Divi mode, popups, Theme Builder, Custom CSS, design system | stays installed, usually as a must-use plugin |
| `wp-media-audit.php` 1.1.2 | read-only audit of media, files on disk, database references | install for the job, remove after |
| `wp-media-quarantine.php` 1.0.1 | moves upload files to a quarantine folder and back | install for the job, remove after |
| `wp-site-updates.php` 1.0.2 | lists what is out of date and runs one update per request | install for the job, remove after |

The maintainer wants **one plugin** that is installed, activated, updated and removed like any other. It should not be necessary to work out which of four files a job needs.

Two of its jobs can do real damage, and **the switches exist to prevent those mistakes**:

- **Media clean-up:** files moved, or later deleted, when they should not be. A moved file disappears from every page that uses it.
- **Updates:** plugins, themes or WordPress updated without proper checks before and after. An update can break a site.

## Goals

1. One plugin, slug `divi5-builder`, one zip, one version, one entry in the Plugins screen.
2. A settings page with one switch per risky feature. **Both risky switches are off by default.** That is the safeguard; the maintainer judged it enough.
3. Next to each switch, a short plain-language note on the risk, so people switch it on knowingly. Wording is in "Settings page" below.
4. It installs cleanly on **any** WordPress site.
   - On a site without Divi 5 the page-building part sits idle and nothing errors.
   - Everything else (media audit, clean-up, updates, and WordPress's own REST routes such as Gravity Forms) works as normal.
   - All of the maintainer's sites are moving to Divi 5; Divi 4 and non-Divi sites must still not break.
5. It **takes over automatically** from the four old plugins and shows a notice telling the admin to delete them (details in "Taking over from the old plugins").
6. The Node scripts keep working unchanged against the same REST routes. When a feature is switched off, they print "switch X on in Settings > Divi 5 Builder" instead of "install plugin Y".

## Non-goals

- No forced safety steps. The plugin does **not** refuse to run updates unless page checks run with them; the maintainer chose "off by default" as the safeguard. The scripts keep their own before/after checks as today.
- No auto-off timer. The issue proposed one; it was dropped, because off-by-default is enough.
- No new features in the four parts; this is a merge, not a rewrite. Behaviour, capability checks and route shapes stay as they are.
- No Abilities API (#32), and no split of the Node scripts (#19).

## Anti-goals (a failure even if it "works")

- **A switch that says off while the feature is live.** For example, an old `wp-site-updates.php` still active while the new Updates switch shows off. If that can happen, the notice must say so in red (see below).
- **A switch that a script, an Application Password or an AI agent can turn on.** Switching on must take a person in wp-admin.
- **A switched-off feature that is merely refused.** It must not exist: no routes registered at all, the same standard `permissions-test.php` holds today.
- **A fatal error on any site**: no Divi, Divi 4, multisite, the old plugins still present, or loaded twice (the `double-load-test.php` standard).

## The parts

| Part | Switch | Default | Routes (namespaces unchanged) |
|---|---|---|---|
| Page building (today's `divi5-builder-rest.php`) | none, always on | on | `divi5-builder/v1/*`, plus the REST meta registration |
| Media audit (read-only) | **Media audit** | off | `wp-media-audit/v1/*` |
| Media clean-up (moves files) | **Media clean-up** | off | `wp-media-quarantine/v1/*` |
| Updates | **Updates** | off | `wp-site-updates/v1/*` |

**Why media audit has a switch, though it is read-only:** it lists every file on disk and where each is referenced. That is information about the site, and today it is "remove after the audit". Off by default keeps it that way. Its risk note is milder (below).

**On a site without Divi 5:** page building registers its routes as today. Routes that need Divi answer with a clear error, as they do now when Divi is missing. Builder's job: confirm every Divi call is guarded (the current file already checks `class_exists` and `function_exists` in places); nothing may fatal when Divi 4 or no Divi is active.

## Settings page

- **Location:** Settings > Divi 5 Builder. On multisite, the switches for media clean-up and media audit are in Network Admin > Settings, matching their current `manage_network_options` rule. Updates follows its current rule (`update_plugins`, network-level on multisite).
- **Who can flip a switch:** a person in wp-admin with `manage_options` on a single site; on multisite, `manage_network_options` for every switch.
  - The form is a normal admin form with a nonce. The maintainer confirmed this is enough: no password re-entry or typed confirmation. An agent driving the maintainer's own logged-in browser acts on the maintainer's say-so.
  - **No REST route, no option API exposure, no WP-CLI command in this plugin flips a switch.** An option that core REST could write through `/wp/v2/settings` must not be registered with `show_in_rest`.
- **What the page shows for each switch:**
  - on/off
  - the risk note
  - when it was last switched on and off, and by whom (user login)
  - for page building: the installed version and whether Divi 5 was detected

**Risk notes** (the maintainer may reword; keep them short and plain):

- **Media audit (read-only):** "Lists every uploaded file, its size, and where the site uses it. It changes nothing, but it shows a lot about your site to anyone with an administrator login. Switch it off when the audit is done."
- **Media clean-up:** "Moves uploaded files out of your uploads folder into a quarantine folder. Anything moved disappears from the pages that use it until it is moved back. Take a backup first, and check the site after every move."
- **Updates:** "Updates plugins, themes and WordPress itself. An update can break a site. Take a backup first, and check your key pages after every update."

**On multisite**, the notes say the risk is network-wide:

- **Updates:** add "On a multisite network an update applies to every site in it at once."
- **Media clean-up:** add "On a network, check the sites that share these files."

**Multisite rules, confirmed with the maintainer** (a production multisite network of the maintainer's is the first real case):

- page building is per site
- the risky switches are network-level and network-admin-only
- network activation and per-site activation both work
- the takeover follows the activation scope

**Storage:**
- one option, not autoloaded, holding per switch `{ on, changed_at, changed_by }`
- on multisite, a site option for the network-level switches

## Taking over from the old plugins

The old files define `D5B_REST_VERSION`, `WPMA_VERSION`, `WPMQ_VERSION` and `WPSU_VERSION`. The combined plugin uses **new** constants of its own, so it can tell an old file is loaded.

1. **Old plugins installed as normal plugins** (Plugins screen; a single file or `<slug>/<slug>.php` from the zips): **on activation, the combined plugin deactivates them** with `deactivate_plugins()`. It does not delete anything.
   - The notice lists them as "replaced, you can delete them".
   - On multisite, network-active old plugins are deactivated network-wide only when the combined plugin is network-activated.
2. **Old plugins installed as must-use files** (`wp-content/mu-plugins/`): WordPress loads these first, and nothing can switch them off; only deleting the file stops them.
   - For each such file, **the combined plugin stands aside for that part**: it does not register that part's routes, so nothing is registered twice.
   - The notice says: "The old file `wp-content/mu-plugins/<file>` is still in charge of <part>. Delete it to finish the move."
3. **Red warning:** while an old *media clean-up* or *updates* plugin is loaded, in either form, the notice says in red that this feature is live **even though its switch here says off**. That is the anti-goal above, made visible.
4. **Where the notice shows:**
   - an admin notice for users who can manage plugins, until the old files are gone
   - the same lines on the settings page
5. **When the combined plugin is a must-use file** (my call; must-use files never fire an activation hook): it runs the same deactivation once, on the first wp-admin load by a user who can activate plugins, and records that it did.
6. **An old plugin reactivated later** (my call): the combined plugin does not fight a person who does it in wp-admin. It stands aside for that part, as for a must-use file, and the red warning returns. Activation of an old plugin over the REST API (`/wp/v2/plugins`, an Application Password or a script) is refused with a 403 while the combined plugin is loaded (adversarial ruling, 2026-10-27).
7. **Detection** (my call, amended by the adversarial ruling of 2026-10-27): standing aside is decided per part at `rest_api_init`, at a late priority. The part stands aside only if its probe route is already registered, which is the fact that the old copy is really serving it. The old constant alone does not stand it aside.
   - This catches an old copy pasted as a Code Snippets or WPCode snippet, which skips the constant line. The notice then says "an older copy (perhaps a code snippet) is already providing <part>".
   - Every stand-aside is recorded per site (`d5b_legacy_seen`), and the red warning follows that record. A constant defined while the part was not stood aside gives a plain line: "An old copy of <part> is installed but inactive. Delete it to finish the move."
   - Load order matters: the old media audit and media clean-up files guard their routes with `function_exists`, so loaded after the combined plugin they define their constant but register nothing. That is why the constant alone cannot decide.
8. **Combined plugin installed twice** (a must-use copy and a normal copy): it must survive being loaded twice, the `double-load-test.php` standard. The first copy wins, and the second does nothing.

## Must-use install

It is still supported, as the issue asked: sites where the plugin must not be switchable off can drop the single file into `mu-plugins`. The switches still work there, and the risky ones are still off by default.

Builder's call: keep the plugin a **single PHP file** as today, if that stays readable (about 2,500 lines today). Otherwise use a folder plus a small loader, and document both install routes.

## Scripts

- A new route `GET divi5-builder/v1/status` (capability `edit_posts`, read-only) returns the plugin version, whether Divi 5 is active, each switch's state, and any old plugin still loaded. It never changes anything.
- `wp.js`, `media-audit.js`, `media-quarantine.js` and `site-updates.js`: when a route answers 404 (`rest_no_route`), they ask `status`.
  - If the combined plugin is there and the switch is off, they print "Switch <name> on in Settings > Divi 5 Builder (an administrator must do it in wp-admin)".
  - Otherwise they print the current "install the plugin" advice.
- **No script ever tries to switch anything on.**

## Versions and release

- The combined plugin starts at **2.0.0**. The `divi5-builder/v1/version` route keeps answering, with the new version.
- The release ships `divi5-builder.zip`.
- **Updates are manual** (the maintainer's choice): upload the new zip over the old one. There is no update checker and no "update available" notice.
- **My call:** the four old zips are no longer attached to new releases. Their last versions stay downloadable from earlier releases. SECURITY.md lists them as replaced, with security fixes only until 2027-01-31.
- SECURITY.md gets a migration note: install the new plugin, then delete the files the notice lists.
- **The old four files** (my call): they move to `assets/legacy/` and stay until 2027-01-31, for security fixes only, then are removed.
  - `build-plugin-zips.js` stops packaging them.
  - `double-load-test.php` keeps checking them.
  - The docs describe only the new plugin, with one line pointing to `legacy/` for old sites.
- **The scripts' fallback advice** (when the plugin is missing altogether) names `divi5-builder.zip` from the latest release, not the old files.
- **`Update URI` header** (my call): set to the GitHub repo, so a wordpress.org plugin that happens to use the slug `divi5-builder` is never offered as an "update".

## Tests (each must fail on the current code or a mutant before it counts)

- **Switch off means no routes.** For each switch, with it off, `rest_get_server()->get_routes()` has none of that part's routes; with it on, it has all of them. This extends `permissions-test.php`.
- **No REST path flips a switch:**
  - the settings option is not in `/wp/v2/settings`
  - no registered route writes it
  - a POST to every route of the plugin, as an administrator over REST, leaves the option unchanged
- **Takeover:**
  - an old normal plugin active, then activation: it is deactivated, and the notice lists it
  - an old must-use file present: that part's routes are registered once, by the old file, and the notice names the file
  - an old updates or clean-up plugin loaded: the red warning shows
- **Any site:**
  - loads with no Divi, with Divi 4 stubs, and on multisite stubs, without errors
  - loaded twice, nothing fatal
- **Existing suites** pass against the combined file, adjusted only where a route now depends on its switch: `permissions-test.php`, `media-quarantine-test.php`, `media-audit-test.php`, `site-updates-test.php`, `double-load-test.php`, `ds-write-test.php`, `tree-guard-test.php`.
- **Where the tests run** (my call): the offline suites use WordPress stand-ins, as today. The stand-ins share the builder's assumptions, so the two security tests also run **live** through the scripts on the dev site:
  - list `/wp-json/` and check which namespaces exist with each switch on and off
  - read `/wp/v2/settings` and check the option is absent
  - **No live POSTs at all** (corrected while planning): an empty POST to `divi5-builder/v1/custom-css` could clear a site's CSS, and `wp-site-updates/v1/update` or `wp-media-quarantine/v1/move` could run a real update or move real files. "No route writes the switch option" is proved offline only, where every option write is recorded.
- **Live, on the maintainer's own dev site.** The maintainer uploads the must-use files; an agent cannot.
  - **First real multisite target:** a production network of the maintainer's that still runs an old must-use copy, which is exactly the "stand aside + notice" case.
  - fresh install
  - install over each old plugin, both normal and must-use
  - each switch on and off, checked with the scripts

## Left to the builder

- File layout: one file or a folder plus a loader (see "Must-use install").
- Option names, and the notice's exact HTML.
- How the settings form is built (Settings API or a plain form), as long as it is admin-only and nonce-checked.
- The order of work, provided the two security tests ("switch off means no routes", "no REST path flips a switch") come first.
