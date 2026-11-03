# Updating plugins, themes and WordPress (any WordPress site)

`scripts/site-care/site-updates.js` + the **Updates** part of the `divi5-builder` plugin (`plugin/divi5-builder/parts/`). The plugin runs WordPress's own updater (the code behind the
Updates screen), one update per request; the client decides the order, checks pages, and stops when something breaks.

## Set-up, once per site

The user installs `divi5-builder.zip` (Plugins > Add New > Upload Plugin) and turns on the **Updates** switch in
Settings > Divi 5 Builder (Network Admin > Settings on a multisite). The switch is off by default and only an
administrator in wp-admin can flip it; while it is off the update routes do not exist, and the script says "Switch
Updates on in Settings > Divi 5 Builder". Old sites can still run the single-file plugin from `assets/legacy/`
(security fixes only until 2027-01-31). The application password must belong to a
user who may update: an administrator, on a multisite a network administrator.

## The commands

```bash
node scripts/site-care/site-updates.js status <site>                 # what is out of date (asks the update servers first)
node scripts/site-care/site-updates.js update <site> --all           # the plan only: nothing changes without --write
node scripts/site-care/site-updates.js update <site> --all --write   # careful: one at a time, pages checked after each
node scripts/site-care/site-updates.js update <site> --all --fast --write    # small brochure site: all in one go
node scripts/site-care/site-updates.js update <site> --plugin akismet/akismet.php --write
node scripts/site-care/site-updates.js clear  <site>                 # Divi's generated CSS + the page cache
```

`<what>` is `--all`, `--plugins`, `--themes`, `--plugin a/a.php[,b/b.php]`, `--theme folder`, `--core`, `--translations`.
`--skip a/a.php,Divi,core` leaves items out. `--check /,/tickets/,/contact/` names the pages to watch (default: the
home page, the five most recently changed pages, the newest post).

## Which mode

| Site | Mode | What happens |
|---|---|---|
| Matters (shop, many plugins, a client who notices) | `--careful` (default) | One update, then every watched page is loaded again and compared with before; the run STOPS at the first failed update or the first page that got worse. What was not attempted is listed. |
| Small brochure site | `--fast` | Every update in a row, pages compared once at the end. A failed update does not stop the others. |

Always read the plan (the run without `--write`) first and show it to the user. Updating is a change to a live site:
get the user's go for the run, and for a site that matters agree the watched pages.

## The rules built in

- **After every update the site clears Divi's generated CSS and its page cache** (WP Fastest Cache, WP Rocket, W3TC,
  WP Super Cache, LiteSpeed, object cache: whichever exists). On Divi 5, stale static CSS after a plugin, theme or
  WordPress update is the usual reason a site "looks broken" afterwards. It is cleared even when the update failed.
- **WordPress itself**: a maintenance release of the site's own branch (6.8.1 -> 6.8.3) is taken by default. A new
  release (6.8 -> 6.9) only with `--major`; otherwise it is listed as left out. The client names the exact version; the
  site refuses a version that is not on offer, so a release that appeared in between is never installed blind.
- **Plugins and themes** are taken whatever the size of the step. A first-number change shows as `MAJOR` in the plan.
- **Blocked items are never tried**, and are listed with the reason: "no download offered" (a premium plugin or theme
  whose licence is not active on that site, or a vendor that is unreachable), "needs PHP x", "needs WordPress x".
- **An update counts as done only when the installed version read back from disk is the new one.** An updater that
  reports success but leaves the old version is a failure.
- An active plugin stays active. While an active plugin or theme is replaced, visitors see WordPress's maintenance
  page for a few seconds.
- A page that was already broken before the run is reported once, up front, and not blamed on the updates.
- A site with `DISALLOW_FILE_MODS` refuses everything, and says so.

## What a page check is, and is not

It loads each watched page as a visitor (with a cache-buster) and compares with before: the status code, WordPress's
"critical error" page, a printed PHP fatal or parse error, the database error page, a stuck maintenance page, a page
cut off before `</html>`, a page that lost more than half its content. It does NOT see a layout that shifted, a form
that stopped sending, or a problem only logged-in users or the checkout meet. Ask for the human check below every
time, and say plainly what was and was not checked.

## The human check

In the maintainer's words: there is no substitute for the human check, and it only takes a few seconds. After every
run, ask the user to look at the site in a browser AND on a phone for layout breaks, missing images, errors in the
browser console, forms that no longer work and raw shortcodes showing as text. The page check compares status, error
text and size only, so it cannot see any of these: never report the site as fine, say what was checked and pass the
request on.

## No rollback (the script); the old version kept (the abilities)

The script and the REST route keep nothing: a bad update made that way is undone by restoring a backup or re-uploading
the old plugin. Before a careful run on a site that matters, ask whether a current backup exists. Each run writes a log
to `./site-updates/<site>/run-<time>.json`: every update with its from and to version, and every page check.

The abilities (next section) do keep the old version: before a plugin or theme is updated, rolled back or deleted, its
folder is copied to the change history store for 30 days, and `plugins-rollback` / `themes-rollback` put it back, premium
plugins included. WordPress itself is the exception: no copy, a core update is undone only by a backup.

## From a phone or an AI client: the abilities (plugin 2.4.0, WordPress 6.9+)

The same loop is offered as abilities, for a phone or any AI client that speaks the WordPress Abilities API: see what is
out of date, keep a copy, update, check, and roll back when something breaks. The user turns on the AI abilities master
switch, the Plugins / Themes / Core categories and, for anything that changes a plugin, theme or WordPress, "Also allow
risky actions" under each (Settings > Divi 5 Builder). The Updates switch is not needed.

1. `plugins-status` (plugins and themes with the version on offer and why one is blocked, plus `kept_copies`),
   `themes-status`, `core-status`. They read the stored list; `updates-check` asks wordpress.org again (once per 5 minutes).
   Show the user what is out of date and get their go, as with the script.
   Premium plugins with their own updater (Divi Plugins' FilterGrid, 2026-11-03) only add their offer to the update list
   while someone is inside wp-admin, so neither `updates-check` nor the status reads see them from outside. Before an
   abilities update on a site with such plugins, ask the user to open Plugins in wp-admin once, then read the status again;
   the update then runs through `plugins-update` with its kept copy like any other.
   A big theme (Divi, 135 MB kept copy, 2026-11-03) can take longer than the host keeps a connection open: the client
   sees `ECONNRESET` or a timeout while PHP carries on and finishes. That is not a failure. Read the newest
   `history-changes-list` row: `after.running: true` means it is still going, a `version` and `kept` block means it
   finished; only a row that stays `running` after ten minutes needs a look at the files. Never retry blind: the item
   lock answers `busy` while the first run is alive, and a second download is wasted work.
2. `plugins-update` / `themes-update` with the plugin file or theme folder: a copy is kept first (refused with
   `copy_too_big`, `store_full` or `copy_failed` when it cannot be, and `linked` for a folder that is a link), then the
   update, with the pages checked before and after. `problems` lists a page that got worse; by default the new version
   stays so it can be fixed (`rollback_on_failure: true` puts the copy back at once). `core-update` needs the exact
   version on offer and keeps no copy. `translations-update` keeps none either (nothing to break).
3. `plugins-rollback` / `themes-rollback` with the `history_id` of the update (in the result, and in `kept_copies`)
   puts the old version back; the files it replaces are kept in turn.
4. Pass the result's `human_check` on to the user (see "The human check" above), every time.

Also there: `plugins-install` / `themes-install` (wordpress.org slugs only, never activated or switched to),
`plugins-activate` / `plugins-deactivate` and `themes-switch` (checked; an activation or switch that makes the pages worse
is reverted at once), and deletes with a preview and a confirmation code (`plugins-delete` runs the plugin's uninstall
routine, so its data goes for good; `keep_data: true` removes the files only). Every change is a row in the change
history.

**Which to use.** The abilities on a site with WordPress 6.9 or newer and plugin 2.4.0, from a phone or an AI client, or
whenever a rollback should be one call away. The REST route and this script stay for sites below WordPress 6.9 (no
Abilities API there) and for the PC runs above (`--careful` / `--fast` over many items, the run log). Retiring the
route is decided once the maintainer's sites are all on 6.9.

To try the abilities on a dev site: `node scripts/abilities-live-maint.js <site> plugin|theme-switch|update|user|email|history|all`
(only the `hello-dolly` plugin and a `d5b-live-user` user are ever deleted; deletes and the theme switch print a plan and
exit 3 until `--confirm go`; `update --item <plugin file>` updates and rolls back, or skips when nothing is waiting).
Before the `plugin` step, deactivate WordPress's own `hello.php` if it is active: it declares the same function as the
wordpress.org `hello-dolly`, so activating `hello-dolly` next to it is a PHP fatal (the abilities answer `activation_failed`,
or the request ends with no answer). A failed update prints the history row that keeps the copy of the version before
(`plugins-rollback` with that `history_id` puts it back); after a failed step the HUMAN CHECK line names what the run changed.

## Not covered

mu-plugins and drop-ins (WordPress does not update those), installing or deleting plugins by the script (the abilities
do it), switching auto-updates on or off, updates that need FTP credentials (reported as the updater's error).

## Tests

`php scripts/site-updates-test.php` (the plugin against a fake WordPress: who may, what it refuses, dry run, each
kind of update, the lying updater, cache clearing) and `node scripts/site-care/site-updates-test.js` (the plan and the page
comparison). Both were mutation-tested when written.
