# Moving a rebuilt dev site over the live site (go-live)

Written from the first Divi 4 -> 5 port that went live (2026-10-13). The route that keeps a revert path: move the
old live files into an `old-site/` folder, fresh WordPress install in the root with a NEW database, migrate dev into
it with a migration plugin (BlogVault, All-in-One WP Migration, WP Migrate), which does the serialised-safe URL
replace. Revert = move the old files back and point wp-config at the old database. Done = delete the folder and
the old database.

## Before the move (dev)

1. **Content delta.** What did the owner's client change on live since the dev copy was taken?
   `node assets/content-delta.js <live> <dev> <since-ISO> posts pages media <cpt...>` (public REST, no login) lists
   new and edited items and whether dev has them by slug. Carry them to dev by hand: fetch the live post's
   rendered content and featured picture over public REST, upload the picture, create the post with the same slug
   and date (plain hyphens for the client's dashes). Not visible over public REST: menus, widgets, settings, form
   entries, drafts. Re-run right before the move.
2. **Baseline check:** `node assets/site-check.js <dev> --pages pages.json` all clear, with `pages.json` listing
   every important path, per-page expectations (grid card counts, links, a form) and the rules (old URLs 404/301,
   popup-only post types 301, the popup request 200, the 404 template, the sitemap, site search).
3. **Dev-only leftovers:** draft test pages, unused layouts and media, debug variants of snippets, test flags
   (a "sold out" left on for a screenshot), Theme Builder templates deleted in the UI but still in the database
   (Divi trashes them 7 days later).
4. Full backups of both sites.

## Keep from the old live root before moving it

`wp-config.php` (plugin constants such as `FORMINATOR_ENCRYPTION_KEY`, salts, memory limits, any define),
`.htaccess` (keep www/https/HSTS/security rules; drop anything that broke dev, e.g. a hotlink block covering css and
html), `robots.txt`, verification files (`google*.html`, `ads.txt`, `.well-known/`), anything custom in the root.
If `old-site/` stays inside the web root, deny web access to it (`Require all denied`): old uploads stay reachable
and a renamed config copy is downloadable. Mind disk space (old uploads can be gigabytes).

## Right after the move (needs wp-config / host access)

- wp-config: the plugin constants from the old config. **Forminator** encrypts integration and payment keys with
  `FORMINATOR_ENCRYPTION_KEY` when defined, otherwise with the WordPress salts: a site with the constant shows no
  notice, a copy without it shows "Secure Your Keys" and cannot decrypt the stored keys; "Add Key" generates a NEW
  value, which is wrong when the stored keys were encrypted with another. Copy the old line, same value. Memory:
  `WP_MEMORY_LIMIT` / `WP_MAX_MEMORY_LIMIT` 512M (REST builder saves stay at the 40 MB default otherwise).
- Settings > Reading: "Discourage search engines" OFF (dev has it ON; `site-check.js` reports the noindex meta).
- Settings > Permalinks: save once (rewrite rules for the custom post types).
- Page cache plugin: re-save its settings (it writes `.htaccess` rules), clear. Divi static CSS clear. CDN purge.
- Licences and connections bound to the domain: theme updates, third-party Divi plugins, ACF Pro, SEO plugin,
  analytics (reconnect the property), image optimiser key.
- Forms: re-connect integrations (Mailchimp etc.) and send one test from each form.
- Redirects owed: pages removed during the port that are indexed (e.g. a scrapped page) -> 301.
- A new application password for the live domain and a creds section for it; decide whether the REST mu-plugin
  stays on live.

## Verify the same day

`node assets/site-check.js <live> --pages pages.json --old-host <dev host>`, then by hand: sticky header, menu
popup, search, every popup type (video, grid item), both forms (inbox + integration), phone width, speed (3 loads,
use the 3rd) against dev, Search Console (sitemap, redirect of removed pages). Keep dev as staging only with
"discourage search engines" ON.

## What the first real go-live day taught (2026-10-19)

**Migration tools and a Cloudflare-fronted destination.** A push migration (Migrate Guru / BlogVault) failed twice:
the destination sat behind Cloudflare, whose bot rules challenge the migration service's requests, and the first
attempt left a half-copied site (the 404 page rendered with the new theme while the homepage, login and xmlrpc
answered 500: the signature of a transfer that died mid-way). All-in-One WP Migration's browser upload of a 2 GB
archive died too. What worked: Duplicator Lite, package built WITHOUT `wp-content/uploads`, installer run in the
owner's own browser (a browser passes Cloudflare), overwrite install into the fresh database, "chunking mode" for the
database so no request outlives Cloudflare's 100-second limit, then the uploads folder by FTP. Expect in the free
installer: the "Partial Backup Check" warning (the excluded uploads), no charset selector (its "Mode: Custom" is the
SQL mode; `utf8mb3` falling back to the server's `utf8` is harmless, `latin1` would not be), and a rewritten
`.htaccess` and `wp-config.php` afterwards. All-in-One 7.112 (free): the import cap is `wp_max_upload_size()`, no
constant any more; a `upload_size_limit` filter returning 4 GB lifts it (the upload is chunked), and "Restore" on
its Backups page needs the paid extension. If Cloudflare can be paused for the window, the push tools work.

**Which folder serves the site.** Fetch files that exist only in one candidate folder and compare sizes with the
file manager listing; a Softaculous or WP Toolkit install record is not evidence (one pointed at a WordPress 4.7
copy in a sub-folder, on the live database). A stale copy inside the web root still EXECUTES for its own path, with
the live database: deny it with `Require all denied` in its own `.htaccess` (the Apache 2.2 `Deny from all` is
ignored by 2.4), or move it out. A WordPress 404 for `/<folder>/` means the folder is not under the web root. Before
moving "all the old files", read cPanel > Domains: other domains' document roots can live inside `public_html`, and
a `test.php` of 19 bytes answering 96 KB is a public `phpinfo()`.

**Keep from the old root:** wp-config (one site had NO Forminator encryption key, so its integration keys depended on
the old salts and had to be re-entered on the new site: "Add Key" first, then connect), `.htaccess` (it held no
https/www rules at all: WordPress's canonical redirect does both from the site address, so there was nothing to
carry; what it held was a hotlink block to drop and cPanel's PHP blocks), `php.ini` AND `.user.ini` (the second one
raised memory and execution time; both left the root with the move and had to be copied back), the search-engine
verification file. `robots.txt` is virtual in WordPress: nothing to copy.

**After the move:** the "discourage search engines" tick travels from staging and the cached homepage keeps the
noindex meta until the page cache is cleared (check a fresh AND a cached copy). EWWW's `.htaccess` WebP rewrite does
not survive the new `.htaccess`, and Cloudflare caches images without `Vary: Accept`, so switch EWWW to "Picture
WebP" and test by counting `<picture>` + `<source type="image/webp">` in the page, not by the Accept header.
Changes to a canvas's Custom CSS over REST do not show until Divi's static CSS is cleared (`site-updates.js clear`).
A tracking comparison (`assets/tracking-compare.js`) is worth running before and after; the official Meta plugin 5.x
starts the pixel through `FacebookSignal.initPixel("id")`, not `fbq('init')`, which fooled the first comparison.

**Expectations drift.** The owner and the client kept editing staging in the last week (packages set to draft, a
section hidden again, a page rebuilt with another module): the baseline check failed on three pages for reasons that
were deliberate. Re-read the failures before "fixing" anything, and keep the expectations file current.

**Phone sweep after go-live** (`assets/phone-headings-scan.js` per page at 375px): the two real faults were a tile
title preset with a 60px size and no phone size (fixed on the preset: 46px tablet, 34px phone) and Divi's full-width
header text block at 80% width on phones, which made long page titles overflow (one site-CSS rule). Long sentences
wrapping are not faults. Heading levels used for styling (a privacy page with 18 h1s) are a content fix: a regex over
the stored block JSON (`\u003ch1` -> `\u003ch2`, open and close counts must match).
