# Moving a rebuilt dev site over the live site (go-live)

Written from the first Divi 4 -> 5 port that went live (2026-10-13). The route that keeps a revert path: move the
old live files into an `old-site/` folder, fresh WordPress install in the root with a NEW database, migrate dev into
it with a migration plugin (BlogVault, All-in-One WP Migration, WP Migrate), which does the serialised-safe URL
replace. Revert = move the old files back and point wp-config at the old database. Done = delete the folder and
the old database.

## Before the move (dev)

1. **Content delta.** What did the owner's client change on live since the dev copy was taken?
   `node assets/site-care/content-delta.js <live> <dev> <since-ISO> posts pages media <cpt...>` (public REST, no login) lists
   new and edited items and whether dev has them by slug. Carry them to dev by hand: fetch the live post's
   rendered content and featured picture over public REST, upload the picture, create the post with the same slug
   and date (plain hyphens for the client's dashes). Not visible over public REST: menus, widgets, settings, form
   entries, drafts. Re-run right before the move.
2. **Baseline check:** `node assets/site-care/site-check.js <dev> --pages pages.json` all clear, with `pages.json` listing
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

`node assets/site-care/site-check.js <live> --pages pages.json --old-host <dev host>`, then by hand: sticky header, menu
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
A tracking comparison (`assets/site-care/tracking-compare.js`) is worth running before and after; the official Meta plugin 5.x
starts the pixel through `FacebookSignal.initPixel("id")`, not `fbq('init')`, which fooled the first comparison.

**Expectations drift.** The owner and the client kept editing staging in the last week (packages set to draft, a
section hidden again, a page rebuilt with another module): the baseline check failed on three pages for reasons that
were deliberate. Re-read the failures before "fixing" anything, and keep the expectations file current.

**Phone sweep after go-live** (`assets/phone-headings-scan.js` per page at 375px): the two real faults were a tile
title preset with a 60px size and no phone size (fixed on the preset: 46px tablet, 34px phone) and Divi's full-width
header text block at 80% width on phones, which made long page titles overflow (one site-CSS rule). Long sentences
wrapping are not faults. Heading levels used for styling (a privacy page with 18 h1s) are a content fix: a regex over
the stored block JSON (`\u003ch1` -> `\u003ch2`, open and close counts must match).

## Merging live's changes into a rebuilt dev (a WooCommerce shop, 2026-10-13/14)

A shop keeps taking orders while dev is rebuilt: copying live over dev loses the rebuild, copying dev over live loses
the orders. The method that worked, rehearsed on a local MySQL before touching either site:

- **A 3-way merge** of the cut-off database (live as it was when dev was copied), today's live export and today's dev
  export. Only what LIVE changed since the cut-off is written; what dev changed too is reported as a conflict. Main
  rows by primary key (leave an ID gap on posts, users, comments and order items, so live's new IDs are free on dev);
  meta tables have no such gap, so per object + meta_key delete dev's rows and insert live's without the meta id;
  per-order tables (discount records, order stats and lookups) by order id. The output is one re-runnable `delta.sql`
  (gzipped for phpMyAdmin) with every value as a hex literal (`_utf8mb4 X'..'`), so quotes, emoji and accents survive
  any import tool.
- **The cut-off database is inside the Duplicator archive** (a DupArchive `.daf`: `<F>` file headers, `<G>` globs,
  raw-deflated when the stored size is smaller; the dump is `dup-installer/dup_descriptors_*/db_dumps/*-dump.sql.gz`).
  Duplicator's dump has no `SET NAMES`: import it with `--default-character-set=utf8mb4`, or every non-ASCII
  character is garbled.
- **Duplicator can install a copy with `DB_CHARSET` latin1.** The copy's text is then double-encoded ("é" stored as
  "Ã©") and only looks right because the site also reads it as latin1. A row merged in from a utf8mb4 site shows
  broken, and changing wp-config later garbles everything old. Check Site Health > Database on both sites BEFORE
  merging. Repair: per text column, `CONVERT(CAST(CONVERT(c USING latin1) AS BINARY) USING utf8mb4)`, only where the
  value has non-ASCII, converts to latin1 without loss and gives valid UTF-8; then set `DB_CHARSET` utf8mb4. Prove it
  byte for byte against the cut-off. On the next Duplicator install, choose utf8mb4 in the installer's advanced options.
- **Rules found by verifying:** `guid` differs everywhere (the copy rewrote it: leave it out of row comparisons); the
  copy also rewrote the domain in order-attribution meta and `_store_url` (legitimate dev changes, not conflicts); dev
  can process live's pending payment orders in the first hours after the copy (it added notes to real orders: delete
  those); product sales and stock = live's wherever dev differs (dev's only changes there are test orders); a counter
  inside a plugin's serialized settings (an invoice number) is patched into dev's value, not replaced; a payment
  plugin's pending-orders option = live's.
- **Tool traps:** `mysql -B` escapes backslashes (use `--raw` when the output carries a NULL marker); line-based
  parsing breaks on option values with line breaks (compare MD5s or extract in SQL); `utf8mb4_unicode_ci` makes
  `LIKE '%Â%'` match "A" (test bytes with `LOCATE(UNHEX(..), CAST(c AS BINARY))`); MySQL regex has no `\x80`; Windows
  lowercases table names (take the real case from the export for SQL that runs on Linux).
- **Verify 3-way, then mutation-test the verifier.** Expected value per field = live's where live changed it, else
  dev's. Then break the rehearsal copy on purpose (delete an order note, corrupt a billing name, put back a test
  sale): each break must fail exactly one check.

## More checks worth doing on every go-live (2026-10-15/16)

- **Tracking that lives in the old theme disappears.** A gtag and a Facebook pixel typed into the old theme's header
  setting were not on the rebuilt dev. Compare the homepage tags of live and dev before go-live
  (`assets/site-care/tracking-compare.js`). Rebuild them in a GTM container whose tags fire only on the live hostname (Page
  Hostname regex), so the dev copy carries the setup and sends nothing. GTM4WP gives WooCommerce cart, checkout and
  purchase events; a food-ordering plugin's own AJAX add-to-cart does not fire `add_to_cart`.
- **The SEO plugin's sitemap can list client documents.** An invoices/quotes plugin's 500+ documents were readable
  without login and listed in Yoast's sitemap (the pages themselves were noindex, but the sitemap is a public index of
  them). Check `sitemap_index.xml` for any post type holding client data and turn "show in search results" off for it.
- **Duplicator rewrites `.htaccess` on install,** dropping EWWW's WebP rules AND WP Fastest Cache's rules (gzip,
  browser caching). Re-save both plugins' settings after the copy, then check a static file carries
  `Cache-Control`/`Expires`. EWWW's bulk run skips already-optimised images: WebP copies for them need "Force WebP".
  WP Fastest Cache settings that suit WooCommerce with a menu on the home page: clear all on new/updated post, preload,
  a daily timeout rule, logged-in users uncached, Combine CSS/JS off (it breaks Divi).
- **Your own crawl can create page-cache copies** that then hide redirects added later. Clear the cache after adding
  redirects, and test with a throwaway query string first.
- **Test redirects against real traffic:** every page path from a GA4 "Pages and screens" export, checked before and
  after. It found old attachment pages under existing pages and a no-trailing-slash variant that the old literal rules
  missed. Make redirect targets relative so they never hold the dev domain.
- **A checkout with no "Place order" button = the Terms page's HTML.** WooCommerce prints the terms page content,
  hidden, inside the checkout just before the button; an unclosed `<div>` in that content (two, carried over from the
  old site) swallows the button into the hidden box. The Terms page itself looks fine because the browser closes the
  tags at the end of the page. Count opening vs closing divs in the terms text, and place a test order on the copy
  shortly BEFORE go-live (an earlier test order passed before the terms page changed).
- **Custom e-mail templates can live in the old theme,** and an order-printing app may depend on them (a plain-text
  "new order" layout). Search the old site for `woocommerce/emails/` overrides before deleting the old theme, and
  carry them over as a small plugin using the `woocommerce_locate_template` filter, not into the new theme.
- Git Bash turns a bare `/` argument into `C:/Program Files/Git/`: set `MSYS_NO_PATHCONV=1` for scripts that take URL
  paths.

## A builder save that crossed three layouts (live, 2026-10-20)

Symptom: the homepage rendered blank apart from the footer, and every page lost its header. One Visual Builder save had
written the HEADER layout's content into the homepage and a popup CANVAS's content into the header layout, leaving the
canvas itself untouched. Nothing was deleted; the media files were all still there.

How it was found: `rest-get wp/v2/pages/<id>?context=edit` showed the page content had shrunk and began with the
header's top strip; `tb-get <header> --raw` was 2 KB and held the popup's section; `get-canvas <id> --raw` was intact. A
count of block types per post (`section`, `menu`, `fullwidth-header`, `social-media-follow`…) shows at a glance which
post holds which layout.

Recovery:
1. The page came back from the copy kept at the previous REST write (identical to the page's own revision).
2. The header came back from the stranded copy inside the page's revision. WordPress keeps revisions for pages and
   posts, NOT for Theme Builder layouts or canvases, so after a crossed save the lost layout is usually sitting in a
   revision of whichever page received it. Compare it block for block with the last known header readback before
   trusting it (here only the dev-to-live URL change and one rgba casing differed).
3. `tb-set <id> --content-file <stranded copy> --expect-hash <hash of the broken version>`, then clear the page cache.
4. Check the header on several pages, open the menu popup, and check the page that owns the canvas still shows it.

What made it a 20-minute fix instead of a rebuild: every REST write kept a `-before` copy; `tb-get` readbacks were kept
after header edits; `--expect-hash` meant the restore could not land on top of a newer edit.

## WordPress-side notes from a WooCommerce rebuild (2026-10-12)

- **Creating or editing nav menus over core REST (`wp/v2/menus`, `menu-items`) can answer 403 `rest_cannot_create`
  even for an administrator.** The cause was PublishPress Capabilities (its nav menu restrictions); deactivating it on
  the dev site fixed it at once. Ask whether the site needs it before suggesting removal (here it gave staff access to
  an invoices plugin: reactivate it before go-live).
- **The Redirection plugin can hold an old rule for a slug you now want as a real page** (`/about/` to the homepage,
  2,592 hits). List the rules before creating a page whose slug may have been redirected; disable rather than delete.
- **A Divi front page can also be the WooCommerce Shop page** (`woocommerce_shop_page_id`): the Divi page keeps
  rendering (no product archive is swapped in), the body gets `woocommerce-shop`, and the old shop slug 301s home.
- `wp.js rest-post` only writes `wp/v2/...` routes; plugin APIs (Redirection, WooCommerce settings) needed a small
  one-off helper locked to the site.
- **Checking computed styles from a browser tool:** a background browser pane freezes CSS transitions, so colours read
  half-way through a fade. Run `document.getAnimations().forEach(a => a.finish())` first; screenshot timeouts are the
  tell. For real pixels, set the viewport to the screenshot frame size so zoomed crops are 1:1.
