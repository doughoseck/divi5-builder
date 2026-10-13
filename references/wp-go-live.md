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
