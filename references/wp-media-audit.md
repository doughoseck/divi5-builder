# Media and large-file audit (any WordPress site)

What in `uploads/` is not used, what is big, and what else is taking space. Read-only. Does not need Divi: it works
on Divi 4, other builders and plain WordPress, and it is most useful on old sites.

First run: a 5-year-old site converted from Divi 4. Uploads 2.78 GB in 36,207 files, 1,864 library items. Found:
480 library items nothing refers to (585 MB), 641 referred to only by old revisions and old page copies (461 MB), and
4,190 files (851 MB) in four year-folders that had no library entry at all (left over from the site before a rebuild).

## The pieces

| File | What it is |
|---|---|
| `assets/wp-media-audit.php` | mu-plugin, read-only REST routes, administrators only. The USER uploads it to `wp-content/mu-plugins/` and deletes it afterwards. Stores nothing. Returns file names, sizes, IDs and the place of a reference, never content. PHP 7.0+. |
| `scripts/media-audit.js` | `scan`, `crawl`, `report`. No command in it changes the site. |
| `scripts/media-audit-test.php` | the reference finder, no WordPress needed |
| `scripts/media-audit-test.js` | the classification and the report files, on a made-up site |

```bash
node scripts/media-audit.js scan   <site> --dir <work-folder>     # a few minutes
node scripts/media-audit.js crawl  <site> --dir <work-folder>     # loads every public page
node scripts/media-audit.js report --dir <work-folder> [--ignore-tables a,b]
```

Output in the work folder: `REPORT.md`, `review-unused.csv`, `review-background.csv`, `attachments.csv` (every item,
with where it was found), `orphan-files.csv`, `folders.csv`, `big-files.csv`, `broken-references.csv`.

## Two findings, kept apart

1. **Library items (attachments) nothing refers to.** WordPress knows these; deleting one in the Media Library removes
   its files and thumbnails.
2. **Files on disk that belong to no library item (orphans).** WordPress does not know these: FTP uploads, a previous
   site's folders, plugin caches and exports. They are reported by folder and one by one.

Also: extra copies of library images that the metadata no longer lists (old thumbnail sizes, `.webp` copies), the
biggest files, locally hosted video and audio, library items whose file is missing, references to files that do not
exist, and the size of `plugins/`, `themes/`, the rest of `wp-content` and the site root (old backups sit there).

## Status of a library item

| Status | Meaning | On the review list |
|---|---|---|
| USED | a live post, page, option, widget, menu, custom field, theme file or public page refers to it | no |
| MAYBE | only a bare number equal to its ID was found (`id="12"`, `"id":12`, an unknown meta key). Kept as used. | no |
| BACKGROUND | only revisions, trashed posts, an old builder copy of a page, meta of deleted posts, or a list/log table | `review-background.csv` |
| UNUSED | nothing | `review-unused.csv` |

The rule throughout: **when in doubt, used.** A wrong "unused" deletes somebody's image; a wrong "used" costs disk space.

## Where it looks, and how a reference is recognised

Every table with the site's prefix, every text column. Attachment rows and their own meta are skipped (every image
"refers" to itself there). Transients are skipped. The active theme and its parent are read for paths.

- **Paths.** Anything after the uploads folder's URL path (`wp-content/uploads/…`), whatever the host, with JSON
  escaping (`\/`, `\"`, `-`), `&quot;` and URL-encoding undone first. A size (`-300x200`), `-scaled`, an edit
  (`-e1612345678901`) or a `.webp` copy counts for its image, also when the metadata does not list that size.
- **IDs, strong.** `wp-image-12`, `wp-att-12`, `attachment_12`; `[gallery ids=…]`; an attribute, JSON key or
  serialized key whose NAME says media (`image_id`, `gallery_ids`, `backgroundImageId`, `custom_logo`, `_thumbnail_id`);
  a meta or option value that is only numbers under such a key; Divi 5's nested form
  `"galleryIds":{"innerContent":{"desktop":{"value":"1,2"}}}`.
- **IDs, weak.** A bare `id="12"` / `"id":12`, or a numbers-only meta value under a key that says nothing.
- **Not an ID.** Keys about size and layout (`image_width`, `gallery_columns`, `thumbnail_size_w`), and known
  non-media keys (`_edit_last`, `_menu_item_*`, `page_on_front`).
- **Old-style `[gallery]` with no ids** shows every image uploaded to that post: those all count.

## The cross-check is not optional

`crawl` loads every published page, post and custom post type and notes each upload file in the HTML and its
stylesheets. `report` then says how many items the public pages use that the database scan alone did not find.
That number must be 0, or understood.

On the first run it was 157: a Divi 5 gallery stores its IDs nested three levels down and the scan did not read that
form. No image was at risk (the crawl marks them USED), but it showed a kind of reference the scan did not know, and
the plugin was extended. Expect this on every new builder or gallery plugin: **run the crawl, read that line.**

Further checks that were run and are worth repeating on a new kind of site:
- Known cases: the logo, the site icon, a featured image and a gallery must come out USED.
- An independent instrument: search a database dump as plain text for the file names of the 50 biggest UNUSED items.
  Each should appear only in its own rows (one in `posts`, two or three in `postmeta`, plus optimiser lists). Short
  names collide with ordinary words and e-mail addresses ("nelson."): look at the text around a surprise.
- `controls.js`-style breakdown: which source alone keeps items USED. A source that keeps many items alone is either
  a real use or a table that should not count.

## What had to be learnt

| What happened | Rule now |
|---|---|
| Two visitor-statistics tables (2.3 million and 277,000 rows) took 30 minutes and cannot hold an image | tables outside WordPress core with over 200,000 rows are skipped and named; `--all-tables` scans them |
| Divi 5 keeps the Divi 4 shortcodes of every converted page in the meta `_et_pb_divi_4_content`: 136 images were "used" only there | builder backup meta (`_et_pb_divi_4_content`, `_et_pb_old_content`, …) is an old version, like a revision |
| An import plugin's image log and export settings counted as uses | `pmxi_*` / `pmxe_*` joined the list-and-log tables (image optimisers, security scanners, SEO indexes, schedulers, logs) |
| A retired plugin's own table (a grid plugin, deactivated) kept 160 images "used" | the tool cannot know a plugin is retired: the report names every table that ALONE keeps items used, and `report --ignore-tables <table>` stops counting it |
| Option values of plugins hold links to the VENDOR's uploads folder (`vendor.com/wp-content/uploads/…banner.png`) | the host is ignored on purpose (links to an old domain must count), so these show up as broken references; harmless |
| The plugin returns at most 3 places per reference | "only in an old copy" is decided only when every place is known; otherwise it stays USED |
| A scan that dies half-way loses everything | results are saved after each table; `scan --resume` continues |

## Reading the result with the owner

- BACKGROUND is mostly "only old revisions". It becomes plainly unused once revisions are removed in a database
  cleanup, so do the two jobs together and decide the order with the owner.
- Whole year-folders of orphans mean an earlier site's files were copied along. Check a few by hand, then treat the
  folder as one decision.
- Recent uploads on the unused list may be work in progress: ask.
- Drafts count as live (a draft is somebody's work). Trash does not.
- Not looked at: plugin code, anything that builds a file name in code, files outside WordPress, other sites of a
  multisite (multisite is untested).

## The database: measure, then the owner cleans

`node scripts/media-audit.js db <site> --dir D` (plugin 1.1.0+) writes `DB-REPORT.md`: every table with rows and
size, posts by type and status, revisions (and which posts have the most), the heaviest meta keys and options,
transients, spam, orphaned meta. Read-only: counts and sizes, never content.

What to look for on an old site, biggest first:
- **Counter and log tables of plugins** (form views, popup statistics, preloader impressions): often most of the
  database, and never content. Tables of plugins that are no longer installed stay behind: deleting a plugin removes
  its files, rarely its tables.
- **Revisions.** Unlimited by default; a page edited for years has hundreds.
- **Posts of post types whose plugin is gone**, trash, cached embeds, spam, transients.
- **Form submissions** are data the site owner may have to keep: ask, export, then decide.

You do not run the clean-up. Write ONE SQL file for the owner: a full export first, then one numbered section per
decision, each with what it removes and the row count to expect, the irreversible-but-doubtful ones commented out
(old content of retired plugins, form submissions), `OPTIMIZE TABLE` last. They run it section by section in
phpMyAdmin with the DATABASE selected (from the server level it fails with "No database selected"). Then run `db`
again, `media-quarantine.js verify`, and the media `scan`, `crawl`, `report`: with the revisions gone, the
BACKGROUND items become UNUSED. First run: 467 MB in 68 tables became 88 MB in 41, of which 57 MB were form
submissions kept on purpose.

## Removing: quarantine first, delete later

Nothing in the skill deletes. `scripts/media-quarantine.js` MOVES files out of `uploads/` into
`wp-content/media-audit-quarantine/<batch>/` (closed to the web, with a manifest) and can move them back. It needs a
second file the user uploads, `assets/wp-media-quarantine.php`: kept apart so the audit plugin stays read-only. It has
no delete and does not touch the database (tests: `php scripts/media-quarantine-test.php`).

```bash
node scripts/media-quarantine.js verify  <site> --dir D                # 1. BEFORE: what is already missing (baseline)
node scripts/media-quarantine.js plan    --dir D --batch old-folders --orphan-folders 2017,2018,2019
node scripts/media-quarantine.js plan    --dir D --batch unused --status UNUSED [--csv reviewed-list.csv]
node scripts/media-quarantine.js move    <site> --dir D --batch unused             # dry run; add --write
node scripts/media-quarantine.js verify  <site> --dir D                # 2. AFTER: newly missing files, and on which page
node scripts/media-quarantine.js restore <site> --dir D --batch unused --needed --write   # what verify found
node scripts/media-quarantine.js restore <site> --dir D --batch unused --ids 123,456 --write
node scripts/media-quarantine.js restore <site> --dir D --batch unused --all --write
node scripts/media-quarantine.js status  <site>
```

- **One batch per decision** (the old folders; the unused library items; later the BACKGROUND ones), so each can be
  restored or deleted on its own.
- `plan` never takes a USED or MAYBE item. It holds back a file that an item which stays also owns, a copy that could
  belong to an item which stays, and an orphan something still refers to. Read the "held back" lines.
- `--csv`: the owner deletes the rows they want to KEEP from `review-unused.csv`; what is left moves.
- `verify` loads every public page and asks the server for every upload file on it. The run before the move is the
  baseline (old sites already have missing files); afterwards only NEW misses matter. It cannot see what a visitor
  never gets: the admin, e-mails, files loaded by script, PDFs linked from other sites. So after a clean `verify` the
  owner still uses the site for some days and looks at the server's 404 log.
- A quarantined library item keeps its database row and shows as a broken thumbnail in the Media Library. That is
  expected. Once the owner is sure, they delete those items in the Media Library (their files are already gone) and
  delete the quarantine folder on the server. **Both permanent steps are the owner's, not yours.**
- After the clean-up: run `scan`, `crawl`, `report` again, and have the user delete both plugin files.

## Found on later runs (2026-10-01)

- **A file name can contain two dots** (`Open-Day..jpg`). The first version dropped every path containing
  `..` as a climb out of the folder, so the database scan missed a used image; the crawl caught it (cross-check 1).
  Only a `..` FOLDER is rejected now (plugin 1.1.1).
- **Replacing a block of image modules leaves the old images "BACKGROUND", not "UNUSED":** Divi's stored Divi 4 copy
  of the page (`_et_pb_divi_4_content`) still names them. Plan that batch with `--status BACKGROUND --csv <filtered list>`.
- **After an image optimiser's bulk run, measure delivery, not the plugin's word:** request every page image with
  `Accept: image/webp` and count the answers. A first run converted 333 of 1,720 because the optimiser skipped images
  it had handled before; "force re-optimise" brought it to 1,530 and page image weight from 235 MB to 137 MB.
- **A missing social image tag is not always the audit's doing.** Compare all posts before blaming a restore: on one
  site 2 of 107 posts printed no `og:image`, one of them untouched for a year.
- `wp.js update-post <id> --featured <media id>` sets a featured image (0 removes it).
