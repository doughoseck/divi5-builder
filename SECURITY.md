# Security policy

This project ships PHP must-use plugins that run on live WordPress sites, and Node
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

Only the latest version on `main` is supported. Always run the newest
`assets/divi5-builder-rest.php` (the version is in its header and in
`GET /wp-json/divi5-builder/v1/version`).

| Plugin | Latest | Notes |
|---|---|---|
| `divi5-builder-rest.php` | 1.8.3 | Page building bridge. Meant to stay installed. |
| `wp-media-audit.php` | 1.1.2 | Read-only. Install for the audit, **remove after use**. |
| `wp-media-quarantine.php` | 1.0.1 | Moves files. Install for the clean-up, **remove after use**. |
| `wp-site-updates.php` | 1.0.1 | Runs updates. Install when needed, remove after use. |

## Hardening advice for users

- Use the **lowest role that does the job**: page building needs an **Editor**
  Application Password; only design-system writes, options, Theme Builder, Custom CSS
  and the site-maintenance tools need an Administrator.
- Only install the plugins you use, and remove the maintenance plugins when done.
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
