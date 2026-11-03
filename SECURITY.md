# Security policy

This project ships a WordPress plugin that runs on live WordPress sites, and Node
scripts that hold WordPress Application Passwords. Security reports are very welcome.

## Reporting a vulnerability

**Please report privately - do not open a public issue.**

Use GitHub's private reporting: **Security tab -> "Report a vulnerability"**
(https://github.com/doughoseck/divi5-builder/security/advisories/new).

Include what you can: the file and route or command, the WordPress role needed,
steps to reproduce, and the impact. You'll get an acknowledgement within a few days.
Fixes are released as a new plugin version, the advisory is published once a fix is
out, and reporters are credited unless they prefer not to be.

## Supported versions

Only the latest version on `main` is supported. Always run the newest `divi5-builder`
plugin (the version is in its header and in `GET /wp-json/divi5-builder/v1/version`).

| Plugin | Latest | Notes |
|---|---|---|
| `divi5-builder` (`divi5-builder.zip`) | 2.4.0 | One plugin: page building (always on), plus Media audit, Media clean-up and Updates, each a switch in Settings > Divi 5 Builder, **off by default**. Only an administrator in wp-admin can flip a switch. 2.4.0 adds maintenance abilities (plugins, themes, WordPress and translation updates, users, a test email), with a kept copy of every plugin or theme it updates, rolls back or deletes, and risky switches off by default (see "Maintenance" below). 2.3.0 added abilities that manage the site (settings, homepage, theme settings, Custom CSS, widgets, caches, one scheduled task, templates and global styles), with risky switches off by default (see "Site management" below). 2.2.0 added abilities that change content, each category behind its switch, with a 30-day change history (see "Writes" below). 2.1.0 added read-only AI abilities through the WordPress Abilities API, behind a master switch that is **off by default** (see "AI abilities" below). 2.0.1 checks nested design variables on the server (a missing, archived, other-type, self or cyclic reference is refused) and refuses to archive a variable that another active variable uses. |
| `divi5-builder-rest.php` | 1.8.5 | Replaced by `divi5-builder` 2.0.0. Security fixes only until 2027-01-31. In `assets/legacy/`. |
| `wp-media-audit.php` | 1.1.2 | Replaced. Security fixes only until 2027-01-31. In `assets/legacy/`. |
| `wp-media-quarantine.php` | 1.0.1 | Replaced. Security fixes only until 2027-01-31. In `assets/legacy/`. |
| `wp-site-updates.php` | 1.0.2 | Replaced. Security fixes only until 2027-01-31. In `assets/legacy/`. |

### Moving from the four old plugins

Install `divi5-builder.zip` (Plugins > Add New > Upload Plugin) and activate it. It deactivates
old normal copies of the four plugins by itself. Old must-use files cannot be deactivated
from code: the plugin stands aside for that part and shows a notice naming the file to
delete. Delete what the notice lists. A red warning shows when an old updates or clean-up
copy is still live while its switch is off. After 2027-01-31 the old files get no more fixes.
While `divi5-builder` is active, activating one of the four old plugins over the REST API
(an Application Password or a script) is refused with a 403. An administrator can still
activate one by hand in wp-admin; the red warning then shows. The refusal covers only the
eight file names the old plugins were released under (`<slug>.php` and `<slug>/<slug>.php`
for each of the four). An old copy installed under another folder or file name is not
recognised and can still be activated over REST; the red warning then shows once it is
serving. Delete old copies rather than leave them installed.
On a multisite network, the Network Admin > Settings > Divi 5 Builder page (where the
switches are) also lists every subsite (up to 500) where an old copy was serving a part at
its last request, in red when that part's switch is off.
Deleting the plugin (Plugins > Delete) removes its stored options, the switches included,
so a reinstall starts with every switch off.

### What the switches protect against

The switches guard against mistakes and scripts: an AI agent, a script or an Application
Password cannot turn a feature on. They do not protect against a stolen administrator
login or administrator Application Password. That credential can install and activate any
plugin, and so run any code on the site. On sites that never install or update plugins
remotely, add `define( 'DISALLOW_FILE_MODS', true );` to `wp-config.php`, and only hand
out administrator Application Passwords when a job needs one.

### AI abilities (2.1.0)

From 2.1.0 the plugin can offer abilities to AI tools through the WordPress Abilities API
(WordPress 6.9 or newer; on older versions nothing is registered). This is a new surface, so:

- **Off by default.** While the master switch is off, no ability and no category is
  registered. Only an administrator in wp-admin can turn it on (the same Settings page and
  nonce as the other switches). There is no ability and no REST route that changes a switch.
  Each category has its own switch. Risky actions (writes) arrive in later releases, each
  with a switch of its own.
- **Read only in 2.1.0.** Nothing an ability does can change the site. Each call is checked
  against the signed-in user's own capabilities, per object (a Contributor cannot read
  another user's draft through an ability). A caller who is not logged in is refused before
  anything runs (over REST WordPress answers 401 or 403). On a multisite network the site health abilities (debug log, scheduled
  tasks) need the network capability, as plugins, themes and core do.
- **Audit log.** Every call is written to a table before it runs and updated afterwards,
  with the user, the ability, the arguments with secrets removed, the outcome and the time.
  Rows are kept for 30 days. Deleting the plugin drops the table. Refusals of callers who are
  not logged in are logged at most once per 10 minutes for the whole site (whatever the
  address, so rotating addresses cannot flood the table either); the row keeps the address of
  the first caller. A call that WordPress itself rejects before the plugin's code runs leaves no row:
  core checks the input against the ability's schema first and answers HTTP 400 for a missing
  required field, an unknown field (where the schema allows none) or a value of the wrong type, so
  those refusals are not in the audit log (the web server's access log has the request). Free-text
  arguments are stored with secrets masked (key=value pairs with a secret name, and the certain
  value shapes) before they are cut to 1024 characters.
- **Secrets are filtered, best effort.** Password hashes, activation keys, Application
  Passwords, session tokens and site settings outside a short list never leave the site.
  Theme mods, widget options and Divi design data are returned with secrets filtered: values
  are hidden by the name they are stored under, by the name beside them and by a few
  certain shapes (private keys, payment keys, Stripe webhook secrets, Google API keys, GitHub
  and GitLab tokens, AWS access key ids, Slack tokens, JSON Web Tokens, bearer tokens). The
  vendor-prefixed keys are masked even when they run straight on from other text
  (`tokensk_live_...`, `x=ghp_...`).

**What the secret filter cannot catch.** Names are a heuristic, built to hide when in doubt,
so it also hides some harmless fields. It does not catch: a key written with look-alike
letters from another alphabet; a value whose name is only a number pointing into a separate
list; a name that sits two or more levels away from its value, or is spaced ("SMTP
password"), or belongs to a pair our own code builds; a secret written in plain prose with
no word such as password, token or key before it and no known shape; serialized strings,
which are not unpacked. Post content is checked for value shapes only, so that it stays
editable. Do not rely on the filter alone: leave the master switch off on a site where you
do not trust the account that will call the abilities, and use the lowest role that works.

### Writes (2.2.0)

From 2.2.0 an ability can change the site. They are behind the same master switch (off by default) and the same category switches as the reads, and each one is checked against the signed-in user's own capabilities, per object. Callers who are not logged in are refused before anything runs.

- **The one rule: everything can be undone.** Content goes to the trash, never straight to deletion, and there is no empty-trash ability. A permanent delete exists only for things WordPress cannot trash (a term, a menu, a custom field, a media file) and always takes two steps: a preview that changes nothing and returns a confirmation code, then the delete with that code. A code works once, only for the same user, the same ability and the same object, and expires after 10 minutes.
- **The change history keeps the full "before".** Every change writes a row to a table kept for 30 days, holding a full copy of the object as it was, so a person can put it back from WordPress's own revisions or from the copy. A value stored under a secret-named key (a password, a token, an API key) is not kept in the copy, so a write that would overwrite or delete such a value is refused instead (the history could not put it back). The copy is therefore never a place secrets pile up. The copies are masked again when shown. The size cap is per field: the content is always kept whole (up to 8 MB on its own; an item whose content is larger is not changed by an ability), and any other field that does not fit (a very large value another plugin keeps) is replaced by a marker that says how big it was, while the rest of the copy stays complete. Text that is not valid UTF-8 is stored with a replacement character and the copy says so. A permanent delete writes its row first: if the row cannot be written, or would not be an exact and complete copy (a field over the cap, text that is not valid UTF-8), nothing is deleted and the delete is left to wp-admin. For any other change, if the row cannot be written (the table is there but the insert failed), the change stands, the answer carries a warning that it cannot be undone from the history, the audit log marks the call `ok_no_history` and the settings page warns for 24 hours. A missing history table refuses every write before it runs (`history_unavailable`), after one attempt to create the table again. An item whose content is over 8 MB is refused with `too_large_to_record` (HTTP 413).
- **A permanently deleted media file is kept for 30 days.** Before WordPress deletes a media item, the file and every size of it are moved into `wp-content/uploads/d5b-history/<history row id>-<32 random hex characters>/` (an `.htaccess` with `Require all denied` / `Deny from all` and an empty `index.html` are written into `d5b-history` once): the file is kept for 30 days in wp-content/uploads/d5b-history and can be put back by hand. If a move fails, what was moved is put back and nothing is deleted. The folder is denied on Apache and its name cannot be guessed on any server (nginx ignores `.htaccess`), and the history row records the full name. The original upload was public at its own URL anyway. The daily prune removes the folder with its history row (and any folder whose row is gone); deleting the plugin removes `d5b-history` on every site.
- **The update to 2.2.0 asks again.** A site that had the AI abilities switched on with 2.1.x (read-only) gets the master switch turned off by the update and a notice on the settings page until it is dismissed: switching it back on is consent to abilities that change content.
- **`expect_hash` on every content update.** The update names the hash of the content it was based on; if the content changed since, nothing is written (409). The hash comes from the matching get.
- **A masked read is never written back.** If the read that produced the content had secrets hidden (`masked: true`), an update built on it is refused with `masked_read`, so a hidden value is never overwritten by the placeholder.
- **Media upload.** Only http and https addresses, with the address resolved once and pinned for the request, so a name cannot be pointed at a private address after the check. If the pin cannot be set (a proxy, or a transport that is not curl) the upload is refused. Loopback, private, link-local and reserved ranges are refused, redirects are checked the same way, only ports 80 and 443 are used, SVG is refused, and size and type are capped. The real file type is read from the bytes, never from what the server says.
- **Divi content needs `unfiltered_html`.** Content that contains Divi blocks is only written for a user who may post unfiltered HTML.
- **Custom field names are plain ASCII.** A write accepts only names of letters, digits, `_ - . :`. MySQL's usual collations treat `pässword` as `password` and a full-width `＿` as `_`, so a look-alike name would reach the real protected or secret field past the name checks.
- **Strictest switch wins.** An input that writes into another category (custom fields, the featured image, Divi's builder flag, terms) is refused when that category is off, and a menu reorder may not move an item that links to content whose category is off. A change that would publish (a scheduled post given a past date, or edited after its time) needs the right to publish even when no status was given; a scheduled post with a past date is refused.

**What this does not cover.** The change history is a safety net for mistakes, not a backup: it is kept 30 days, per site, and a site where the table cannot be created refuses the calls. The address pinning narrows DNS tricks but a resolver that answers differently within one request is not something the plugin can see. Two writes in the same second can share a design-system backup time, so a restore by time may refuse. The secret filter has the limits listed under "What the secret filter cannot catch": it is a heuristic, so a secret it does not recognise can be stored in a copy. Custom field names a request gives are plain ASCII, but names already stored with non-ASCII letters (made by another plugin or by hand) can still fold together with an ASCII name under MySQL's collations. A history row near 16 MB can fail on MariaDB's default `max_allowed_packet`: a delete is then refused, any other change stands with the `ok_no_history` warning. As before, leave the master switch off on a site where you do not trust the account that calls the abilities, and tick only the categories you need.

### Site management (2.3.0)

From 2.3.0 an ability can also change the site's settings and appearance. Everything above still holds (master switch, category switch, per-user capability, the change history with the full "before", `expect_hash`, the masked-read rule).

- **Risky switches.** Each of the categories Settings, Appearance, Site health and Templates has a second switch, "Also allow risky actions", **off by default**. A risky ability is registered only when the master, the category and the risky switch are all on, and the run wrapper and the permission callback check all three again. What each allows, in the words of the settings page: *Settings* - "Can change the homepage, permalinks, time zone, site language and whether search engines index the site. A wrong value is easy to put back from the change history, but visitors see it in the meantime." *Appearance* - "Can change Custom CSS and theme settings. A wrong CSS rule can hide the whole page until it is put back." *Site health and cron* - "Can run a scheduled task now. Tasks belong to plugins and may do real work (send emails, process orders, run migrations)." *Templates and global styles* - "Changes the theme's templates and global styles on block themes. A broken template can blank a page until it is put back." Widgets and Cache have no risky actions.
- **The update asks again.** Moving from schema version 2 to 3 (2.2.x to 2.3.0) with the master switch on turns it off once (network-wide on a multisite network) and shows a notice: switching it back on is consent to abilities that change settings, theme settings, widgets and caches. Every risky switch starts off.
- **A stale settings page cannot undo a switch change.** The settings form carries a revision of the abilities switches as the page showed them (`d5b_switch_rev`). If they changed since the page was loaded (another administrator, or the update's consent step above), the save is refused whole with "The switches changed since this page was loaded (by another admin or an update). Reload and try again." and nothing changes, part switches included.
- **Capabilities, as core asks for them.** Settings, the homepage, cache-flush and `appearance-get` (which shows the Custom CSS and the theme settings): `manage_options`. Theme settings (`appearance-theme-mod-set`, `-delete`): `edit_theme_options`, as the Customizer. Custom CSS (`appearance-custom-css-set`): `edit_theme_options` **and** `edit_css`, as the Customizer (an editor has `edit_css` through `unfiltered_html` but not `edit_theme_options`, so an editor cannot replace it). Templates and global styles, reading and writing: `edit_theme_options`, as core's templates and global styles endpoints; `templates-update` also needs `unfiltered_html`. Widgets: `edit_theme_options`. Site health and cron: `manage_options`, and `manage_network` on a network. A stale Custom CSS answer carries the current hash only for a caller who may read the stylesheet (`manage_options`).
- **A settings allow-list, never an open option writer.** `settings-set` accepts `blogname`, `blogdescription`, `date_format`, `time_format`, `start_of_week`, `posts_per_page` (1 to 500, or -1 for all) and `default_comment_status`; the risky keys are `timezone_string`, `gmt_offset`, `WPLANG` (an installed language), `permalink_structure` (the rewrite rules are flushed) and `blog_public`. Anything else is refused `invalid_input`. `siteurl`, `home` and `admin_email` are never writable (they can lock everyone out or take over the account); `users_can_register` and `default_role` are not on the list either. Values go through core's `sanitize_option`. One call with a risky key while the switch is off is refused whole and writes nothing. `settings-set-homepage` needs published pages that differ, and the Pages category on as well.
- **Custom CSS and theme settings.** The Custom CSS is stored as CSS (never HTML), refused over 256 KB, with `</style` or `[redacted]`, and written only with the hash of the stylesheet it was based on (the stored stylesheet, unfiltered); the whole previous stylesheet is in the history. Anything else, `@import` and `expression()` included, is stored as given, as core stores it. Theme settings with a secret-looking name, or whose current value holds one, cannot be set or deleted; `nav_menu_locations`, `custom_css_post_id` and `sidebars_widgets` are refused. A theme setting's history row names the setting and the theme it belongs to.
- **Global styles keep what a read could not show.** `global-styles-get` returns the site owner's layer with values under secret-named keys left out, and says `masked: true` when it left any out. `global-styles-set` refuses (`not_allowed`) to write over a stored layer that holds such a value, and refuses a new layer holding one, so nothing hidden is lost and the history never keeps one. It merges as core does: `styles` or `settings` given replaces that part, a part left out keeps its stored value, `null` removes it. Markup is refused in every `css` value (per-block CSS too), as core refuses it in the top-level one. The layer is sanitised as WordPress does for users without `unfiltered_html` (only for them, as core: an administrator's layer is stored as given); a write that would lose something that way (unsafe values, custom CSS, settings other than presets and layout sizes, in the part sent or in a stored part kept) is refused with the paths listed, never dropped silently.
- **Widgets.** Instances pass through the widget's own sanitiser, and without `unfiltered_html` markup core would strip is refused or reported. The history keeps the whole widget layout and the widget's settings; a widget whose settings hold a secret-named key cannot be changed or removed. Widget ids are matched exactly (`text-02` is refused, it is not `text-2`), and a remove takes the widget out of every sidebar that lists it.
- **`cache-flush` works without the Updates switch.** It calls the Updates part's `wpsu_clear_caches()`, shared code that is loaded whatever that switch says; the switch only gates that part's own REST routes. It needs `manage_options` (and `manage_network` on a network, where a flush reaches every site).
- **The cron run runs only what is scheduled.** `cron-run` identifies one scheduled event exactly (hook, timestamp, the schedule and the `args_key` where several share a time). A hook that is not scheduled is `not_found`; this plugin's own `d5b_` tasks and core's lifecycle hooks (`init`, `wp_loaded`, `shutdown` and the like) are refused `not_allowed`, so it can never be used to fire an arbitrary action. It follows `wp-cron.php`: take core's `doing_cron` lock, read fresh past this request's caches as `_get_cron_lock()` reads it (refused `unavailable` while WP-Cron holds a fresh one, and again when the lock read back right after taking it is not ours), look the event up again under the lock in the cron option itself (a direct read, not this request's copy), book the next run of a recurring event and take this one off the schedule **first**, then run the hook with output buffered (counted, never sent), then release the lock only if it is still ours (read fresh again). The history row is written before anything runs; if it cannot be, nothing runs. PHP cannot stop a hook once started, so the time is measured and `over_budget` is true past 20 seconds, on a failed run too. A task that runs `cron-run` itself keeps the outer run's record: a fatal error is recorded on every run still in progress. **Side effect of the live check:** the live script's cron step runs `wp_privacy_delete_old_export_files` on the site it checks, which deletes expired personal-data export files (benign) and moves that event's next run.
- **Every ability file needs its own function names.** The plugin loads each abilities file behind a guard on one of its function names; two files that declare the same function name would let the second be skipped silently while the first's version runs. A check in scripts/build-plugin-zips-test.js fails when a function name is declared in two abilities files.
- **Known limits.** `DOING_CRON` is not defined during a run (core defines it only in wp-cron.php); the run sets `wp_doing_cron` instead, so a task that tests the constant behaves as it does on a normal request. `global-styles-set` replaces a part (`styles` or `settings`) whole when it is given, as core does: there is no deeper merge inside a part, so read `global-styles-get` first and send the whole part. A site whose global styles hold a secret-named key (some design-token plugins use names such as `tokens`) cannot change them by ability; use the Site Editor. A theme mod set to `false` is a stored value, not the same as deleting it; use `appearance-theme-mod-delete` to go back to the theme default. Divi's own Theme Options (the `et_divi` option) are not writable by an ability.

### Maintenance (2.4.0)

From 2.4.0 an ability can install, update, roll back and delete plugins and themes, update WordPress and its translations, manage users and send a test email. Everything above still holds (master switch, category switch, per-user capability, the change history, confirmation codes for deletes, the secret filter on every result). These abilities can take a site down or hand out access, so most of them need the risky switch of their category: "Also allow risky actions" under Users, Plugins, Themes and WordPress core, **off by default**. The only writes without one are `users-password-reset`, `email-send-test`, `translations-update` and `updates-check`.

**Capabilities, as core asks for them.** On a multisite network, install, update, rollback and delete (plugins, themes, core) need a network administrator, as core's capabilities are network-wide there; activate, deactivate and switch theme work per site.

| Ability | Capability | Risky switch |
|---|---|---|
| `plugins-status`, `updates-check` | `update_plugins` | no |
| `plugins-install` | `install_plugins` | Plugins |
| `plugins-activate`, `plugins-deactivate` | `activate_plugins` (`network: true` needs a network administrator) | Plugins |
| `plugins-update`, `plugins-rollback` | `update_plugins` (a rollback of a deleted plugin is an install: `install_plugins` too) | Plugins |
| `plugins-delete-preview`, `plugins-delete` | `delete_plugins` | Plugins |
| `themes-status` | `update_themes` | no |
| `themes-install` | `install_themes` | Themes |
| `themes-switch` | `switch_themes` | Themes |
| `themes-update`, `themes-rollback` | `update_themes` (a rollback of a deleted theme: `install_themes` too) | Themes |
| `themes-delete-preview`, `themes-delete` | `delete_themes` | Themes |
| `core-status`, `translations-update` | `update_core` | no |
| `core-update` | `update_core` | WordPress core |
| `users-create` | `create_users` (a role other than the site's default role: `promote_users` too; administrator, or any role holding `promote_users`, `edit_users`, `manage_options` or `delete_users`: `promote_users` always, even as the default role) | Users |
| `users-update` | `edit_users` (a role change: `promote_users` and a role from `get_editable_roles()`) | Users |
| `users-password-reset` | `edit_users` | no |
| `users-meta-set`, `users-meta-delete` | `edit_users` (never the fields of another administrator or user manager) | Users |
| `users-delete-preview`, `users-delete` | `delete_users` | Users |
| `email-send-test` | `manage_options` (category Email, new in 2.4.0, off by default) | no (none exists) |

- **The kept-copy store.** Before a plugin or theme update, rollback or delete, its folder (or a single-file plugin) is copied to `wp-content/uploads/d5b-history/<history row id>-<32 random hex characters>/<kind>/<slug>/`, the same store and the same protection as the media files of 2.2.0 (an `.htaccess` that denies everything, an empty `index.html`, a name that cannot be guessed). A copy over **512 MB** is refused (`copy_too_big`), and so is a copy that would take the whole store over **2 GB** (`store_full`, with the store size and the oldest row's date); the plugin never deletes a copy early to make room. A failed copy refuses the change (`copy_failed`). Only `without_copy: true` lifts these three, and the answer and the row then say the change cannot be put back by the plugin. The copy's path is kept in the history row only: results and the history reads show `{ bytes, files }`, and `kept_copies` in `plugins-status` / `themes-status` lists the item, version, history row and date, never a server path. The daily prune removes a row's folder with its row after 30 days, recursively, only inside `d5b-history/<row id>-<hex>/`, never following a symbolic link. A rollback swaps the files in a staging folder and switches them in with WordPress's maintenance mode on for the moment of two renames; the files it replaces are kept in turn, so a rollback is undoable too. Not copied: WordPress itself (there is no rollback for a core update; the result says "none, restore a backup") and translation files.
- **The kept copy must be the copy that was kept (fix wave).** When a copy is made, the sorted list of its files with the sha1 of each (`path\0sha1` lines) is fingerprinted into the history row (`kept.manifest_sha1`; the list itself is kept beside the copy, as `manifest.json`, only to say what changed). A rollback re-computes the list and refuses a copy that no longer matches (`copy_tampered`, HTTP 409, with how many files changed, were added and were removed): anything that can write in the uploads folder (another plugin, a plugin's own uninstall routine run by `plugins-delete`, a person) could otherwise turn a file planted in the store into code in `wp-content/plugins` or `wp-content/themes` on the next rollback. The row is what is trusted: a list edited beside the copy changes nothing. A delete therefore says the files can be put back from the kept copy *if it is unchanged*.
- **One update or rollback of an item at a time (fix wave).** An update and a rollback (and the automatic restore of `rollback_on_failure`) hold a lock per plugin or theme folder (`d5b_maint_lock_<kind>_<md5 of the folder>`, a transient read fresh past the request's caches as WP-Cron reads its own lock, 600 seconds (the update engine's own time limit), released when the call ends; on a multisite network a site transient, one for the whole network, because the plugins and themes folders are shared). A second update or rollback of the same item meanwhile, an AI client retrying a call that timed out for instance, answers `busy` (HTTP 409) and touches nothing. The lock is written and then read back, which is not one atomic step: it narrows the window in which two requests could both take it to a few milliseconds, as WP-Cron's own lock does, rather than closing it. The rollback row is written before the two renames of the swap and names both folders (`staging`, `aside`, names in the plugins or themes folder only), so a request that dies between them leaves a row that says where the files are. A swap that fails with the live files stranded in that folder keeps its row, completed as failed with the folder's name, and a rollback whose replaced files could not be moved into the store keeps naming the folder that holds their only copy. The same kind of lock (`d5b_users_admin_lock`, 30 seconds) is held by every role change and user delete, (per site: each site counts its own administrators), so a demote and a delete running at the same time do not both pass the last-administrator count, short of the same few-millisecond window.
- **The `linked` refusal.** A plugin or theme folder that is a symbolic link or a junction, or holds one, is never updated, rolled back or deleted (`linked`, HTTP 409), and `without_copy` does not lift it: WordPress would follow the link when it removes or replaces the files and change what it points to.
- **The self rule.** This plugin's own file, and the files of the four plugins it replaces, are refused for activate, deactivate, update, rollback, delete and the delete preview (`self`), so an AI tool can never switch off or replace the plugin that records and limits what it does. The real path of the named plugin's folder is compared too, so a link (or junction) to this plugin's folder under another name is `self` as well.
- **Install source.** Plugins and themes are installed by wordpress.org slug only. The download link must be the one `plugins_api` / `themes_api` returned for that slug and must be on `https://downloads.wordpress.org/`; anything else (a filter's link included) is refused (`bad_source`) before anything is downloaded. What was installed must then be that slug's own folder: a wordpress.org link to another plugin or theme (another plugin's filter can give one) is removed again and refused (`bad_source`). No zip upload, no URL install.
- **Checked changes.** Activate, deactivate, update, rollback, switch theme and core update fetch the home page and the recently changed pages (at most 7, through the site's own loopback) before and after, and compare status, fatal error text, a cut-off page and size. An activation or theme switch that makes them worse is reverted at once (`reverted: true`). A page the check ran out of time for (60 seconds a round) is listed in `pages.skipped` and named in `warnings`; it was not compared, and `rollback_on_failure` says it could not judge it. An uncatchable PHP fatal while a plugin loads ends the request with no answer; the Plugins screen avoids that with a redirect sandbox an ability cannot use. Every one of these results carries `human_check`, a fixed request to look at the site in a browser and on a phone (layout breaks, missing images, console errors, broken forms, raw shortcodes), because the page check cannot see any of these.
- **Plugin delete runs the uninstall routine.** As on the Plugins screen, `plugins-delete` runs the plugin's uninstall routine, so its tables and settings go for good; only the files are kept. The preview says whether the plugin has one, and `keep_data: true` (bound into the confirmation code) removes the files without it, which is fully undoable. The delete removes the plugin's whole folder: other plugin files in it are listed in the preview (`siblings`), and an active one refuses the delete (`active`).
- **Users: no passwords, ever.** No input or output carries a password, key or reset link. A new user gets WordPress's own "set your password" email; a password reset is WordPress's own link to the user's own address. The caller cannot change their own role or email, delete themselves or send themselves a reset through the abilities (`own_account`). The last administrator, counted fresh at run time, can be neither demoted nor deleted (`last_admin`). The email of another administrator or user manager (a super admin, or anyone with `promote_users`, `edit_users`, `manage_options` or `delete_users`) is never changed (`other_admin_email`): an email change followed by a reset link would be an account takeover. A deleted user's content is reassigned to an existing user, never deleted; comments keep their author name, as WordPress does. User meta writes refuse roles and capabilities (`capabilities`, `user_level`, `*_capabilities`, `*_user_level`), `session_tokens`, `use_ssl`, `dismissed_wp_pointers`, protected and secret-looking names, and every field of another administrator or user manager (the same protected users as for the email: `not_allowed`), because a two-factor plugin's switch or recovery address kept in an ordinary field would otherwise be one call away from their account. Creating a user with administrator, or with any role that holds `promote_users`, `edit_users`, `manage_options` or `delete_users`, needs `promote_users` even when that role is the site's default role. On a network, once `wpmu_create_user` has made the network user, `users-create` always answers with that user and its history row, plus `warnings` when adding it to this site or setting the names failed, so a client never retries and leaves more users behind.
- **The identity-change marker and the 24-hour reset block.** An email or role change through the abilities first writes a marker on that user (the protected meta `_d5b_identity_changed_at`, which no meta ability can touch); without the marker, no change. For 24 hours after it, `users-password-reset` refuses that user (`recently_changed`): changing an address and then sending a reset link to it is the two-step takeover the block is there for. The marker is read fresh from the user, not from the history, so flooding the history cannot hide it; a marker that cannot be read refuses the reset. Resets are also limited to one per user per 10 minutes and 20 per site per hour (`too_soon`).
- **The recipient rule.** `email-send-test` sends only to `me` (the caller's own address), `admin` (the site's admin email) or `user:<id>` (an existing user of this site); any address given as text is refused (`invalid_input`). Subject and body are fixed, plus an optional plain-text note of at most 500 characters. At most 5 tries per hour per site (a soft limit: two requests in the same instant can both pass it, as with every rate limit of the plugin). The subject is `Test email from <site name>` with the name decoded as WordPress decodes it for its own mail and any line break or NUL removed, so a site name can never add a header line whatever mailer replaces `wp_mail`. A recipient without a valid address is `no_email` (409). The history row records `me`, `admin` or the user id, never the address, and a mailer error is passed on without the recipient's address in it.
- **The update asks again.** Moving from schema version 3 to 4 (2.3.x to 2.4.0) with the master switch on turns it off once (once per network on a multisite network, once per site otherwise: the step records `d5b_abilities_consent_240`, so two first requests cannot both run it and undo an admin who switched it on again in between) and shows a notice: switching it back on is consent to abilities that manage users, plugins, themes, WordPress updates and email, and whose normal writes send a test email, send password reset links, update translations and refresh the update list. Every risky switch and the Email category start off.
- **Deleting the plugin removes the whole store (fix wave).** `uninstall.php` (Plugins > Delete) removes `wp-content/uploads/d5b-history` on every site WHOLE: the deleted media files and every kept plugin and theme copy, at any depth. A symbolic link or junction inside is unlinked, never followed, so nothing outside the store is touched. The store's `.htaccess` (the deny rule) goes only after everything else is gone, and the folder last: when a file cannot be removed, the deny rule stays in front of it. No old plugin or theme PHP is left in the uploads folder without it.
- **Known limits.** The kept copies are a 30-day undo, not a backup: a core update cannot be undone by the plugin, a plugin's uninstall routine cannot be undone, and translation files are not put back. A plugin whose fatal error kills the request leaves the site as WordPress leaves it after a failed activation: read `plugins-status` and the history row, and check the site. The page check reads only the pages it fetches, through the site's own loopback; when the loopback fails before a change the result says `unavailable` and the human check is the whole check.

## Hardening advice for users

- Use the **lowest role that does the job**: page building needs an **Editor**
  Application Password; only design-system writes, options, Theme Builder, Custom CSS
  and the site-maintenance tools need an Administrator.
- Leave the Media audit, Media clean-up and Updates switches off, and turn one on only for the job.
- Keep `~/.web-creds.txt` readable by your user only (`chmod 600` on macOS/Linux;
  on Windows keep it in your user profile). Never commit it.
- Revoke an Application Password (Users -> Profile) as soon as you stop using it.

## Past advisories

- **GHSA-6qm2-h8qg-57m7 - media plugins (quarantine 1.0.1, audit 1.1.2)** - the quarantine
  folder had a guessable name protected only by an Apache `.htaccess`, so on nginx the
  moved files could be downloadable; dot files such as `uploads/.htaccess` could be moved;
  `restore` did not check real paths (a planted link or Windows junction could pull files
  in or push them out of uploads); on multisite a subsite admin could use both plugins.
  All fixed, covered by `scripts/media-quarantine-test.php` and `scripts/media-audit-test.php`.
  These plugins are meant to be removed after use; if you kept one, **update it**, and if an
  old `wp-content/media-audit-quarantine/` folder remains, restore or delete it.

- **1.8.3** - `GET /postinfo?scan=` let any user who could edit one post (Contributor
  and up) read `wp_options` values; `POST /link-canvas` did not check the target page;
  `/postinfo` returned protected post meta; `/custom-css` did not require `edit_css`.
  All fixed in 1.8.3, covered by `scripts/permissions-test.php`. **Update to 1.8.3.**
