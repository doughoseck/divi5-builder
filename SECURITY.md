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
| `divi5-builder` (`divi5-builder.zip`) | 2.0.0 | One plugin: page building (always on), plus Media audit, Media clean-up and Updates, each a switch in Settings > Divi 5 Builder, **off by default**. Only an administrator in wp-admin can flip a switch. |
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
