# Updating plugins, themes and WordPress (any WordPress site)

`scripts/site-updates.js` + `assets/wp-site-updates.php`. The plugin runs WordPress's own updater (the code behind the
Updates screen), one update per request; the client decides the order, checks pages, and stops when something breaks.

## Set-up, once per site

The user uploads `assets/wp-site-updates.php` to `wp-content/mu-plugins/`. The application password must belong to a
user who may update: an administrator, on a multisite a network administrator.

## The commands

```bash
node scripts/site-updates.js status <site>                 # what is out of date (asks the update servers first)
node scripts/site-updates.js update <site> --all           # the plan only: nothing changes without --write
node scripts/site-updates.js update <site> --all --write   # careful: one at a time, pages checked after each
node scripts/site-updates.js update <site> --all --fast --write    # small brochure site: all in one go
node scripts/site-updates.js update <site> --plugin akismet/akismet.php --write
node scripts/site-updates.js clear  <site>                 # Divi's generated CSS + the page cache
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
that stopped sending, or a problem only logged-in users or the checkout meet. On a site that matters, look at the
site afterwards (or run the project's own snapshot/visual check) and say plainly what was and was not checked.

## No rollback

Nothing keeps the old version. A bad update is undone by restoring a backup or re-uploading the old plugin. Before a
careful run on a site that matters, ask whether a current backup exists. Each run writes a log to
`./site-updates/<site>/run-<time>.json`: every update with its from and to version, and every page check.

## Not covered

mu-plugins and drop-ins (WordPress does not update those), installing or deleting plugins, switching auto-updates
on or off, updates that need FTP credentials (reported as the updater's error).

## Tests

`php scripts/site-updates-test.php` (the plugin against a fake WordPress: who may, what it refuses, dry run, each
kind of update, the lying updater, cache clearing) and `node scripts/site-updates-test.js` (the plan and the page
comparison). Both were mutation-tested when written.
