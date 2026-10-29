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
| `divi5-builder` (`divi5-builder.zip`) | 2.1.0 | One plugin: page building (always on), plus Media audit, Media clean-up and Updates, each a switch in Settings > Divi 5 Builder, **off by default**. Only an administrator in wp-admin can flip a switch. 2.1.0 adds read-only AI abilities through the WordPress Abilities API, behind a master switch that is **off by default** (see "AI abilities" below). 2.0.1 checks nested design variables on the server (a missing, archived, other-type, self or cyclic reference is refused) and refuses to archive a variable that another active variable uses. |
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
  the first caller. A call that WordPress itself rejects before the plugin's code runs (input that
  does not match the ability's schema, answered with HTTP 400 by core) leaves no row.
- **Secrets are filtered, best effort.** Password hashes, activation keys, Application
  Passwords, session tokens and site settings outside a short list never leave the site.
  Theme mods, widget options and Divi design data are returned with secrets filtered: values
  are hidden by the name they are stored under, by the name beside them and by a few
  certain shapes (private keys, payment keys, JSON Web Tokens, bearer tokens).

**What the secret filter cannot catch.** Names are a heuristic, built to hide when in doubt,
so it also hides some harmless fields. It does not catch: a key written with look-alike
letters from another alphabet; a value whose name is only a number pointing into a separate
list; a name that sits two or more levels away from its value, or is spaced ("SMTP
password"), or belongs to a pair our own code builds; a secret written in plain prose with
no word such as password, token or key before it and no known shape; serialized strings,
which are not unpacked. Post content is checked for value shapes only, so that it stays
editable. Do not rely on the filter alone: leave the master switch off on a site where you
do not trust the account that will call the abilities, and use the lowest role that works.

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
