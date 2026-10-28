# Site care: media audit and clean-up, updates, go-live, performance

Tools for looking after a WordPress site, not for building pages. They work on **any** WordPress site, Divi or not.
Read this page before using any of them. Page building lives in `SKILL.md`.

## The tools, one line each

| Tool | What it is for | Needs the switch (Settings > Divi 5 Builder) | Playbook |
|---|---|---|---|
| `scripts/site-care/media-audit.js` | Which media is unused, which files are orphans, what takes the disk space. Read-only. | **Media audit** | `wp-media-audit.md` |
| `scripts/site-care/media-quarantine.js` | Moves unused files to a holding folder, checks the site, puts back what is needed. Never deletes. | **Media clean-up** | `wp-media-audit.md` |
| `scripts/site-care/site-updates.js` | Updates plugins, themes and WordPress, with page checks before and after. | **Updates** | `wp-site-updates.md` |
| `scripts/site-care/hook-profiler.js` | Names the slow callback when a page is slow. Read-only, temporary, key-gated. | none (a temporary file the user uploads) | below |
| `assets/site-care/content-delta.js`, `site-check.js`, `tracking-compare.js` | Go-live: what live gained, page sweep, tracking tags. | none (public pages only) | `wp-go-live.md` |

The old paths `scripts/media-audit.js`, `scripts/media-quarantine.js`, `scripts/site-updates.js` and
`scripts/hook-profiler.js` still work: they hand over to the files above.

The three switches are **off by default** and only an administrator can flip them. The USER installs `divi5-builder.zip`
from the GitHub release (Plugins > Add New > Upload Plugin) and turns the switch on. A script never flips one: if the
switch is off it prints the sentence that tells the user what to do. The user turns the switch off afterwards.

## Playbooks

- `wp-media-audit.md`: the media and large-file audit, the quarantine, a graphics size guide for a client's designer, and
  form entries as database weight (with `assets/image-slots-measure.js`).
- `wp-site-updates.md`: updating plugins, themes and WordPress.
- `wp-go-live.md`: dev over live, with `assets/site-care/content-delta.js` (what live gained since the copy) and
  `assets/site-care/site-check.js` (page sweep + rules, before and after), the old-site folder route, wp-config
  constants such as Forminator's encryption key. Its last section is what a real go-live day taught (migration tools
  behind Cloudflare, which folder serves the site, what to keep from the old root, expectations drift). Also
  `assets/site-care/tracking-compare.js <url-a> <url-b>` and `assets/phone-headings-scan.js` (headings that break on a phone).
- `wp-site-search.md`: site search (search field, a results template that really shows the results, pages before posts,
  a FAQs page that comes first when a FAQ answers the search, filter-as-you-type). Start by fetching `/?s=<word>` and
  `/?s=<nonsense>`: if both list the same posts, the results template ignores the search.
- `wp-page-cache-notes.md`: page cache, what helped and what did not, and why writes need a cache clear afterwards.

## Media and large-file audit

"The site is too big", "clean up the media library", "what can go before we move it": use this. It does not need
Divi 5 (or Divi at all), only an administrator's application password and the `divi5-builder` plugin with its
**Media audit** switch on. The audit stores nothing.

```bash
node scripts/site-care/media-audit.js scan   <site> --dir <work-folder>   # library items, files on disk, where each is referred to
node scripts/site-care/media-audit.js crawl  <site> --dir <work-folder>   # every public page: the cross-check
node scripts/site-care/media-audit.js report --dir <work-folder>          # REPORT.md + CSV lists, biggest first
```

- Two findings, kept apart: library items nothing refers to, and files on disk with no library item. Plus the
  biggest files, local video and audio, broken references, and the size of plugins, themes and the site root.
- Every library item is USED, MAYBE (kept as used), BACKGROUND (only revisions, trash, old builder copies) or
  UNUSED. When in doubt, used.
- **Always run the crawl and read the cross-check line in the report.** It counts items the public pages use that
  the database scan missed; it must be 0 or understood. It is how a new kind of reference gets noticed.
- Tables of plugins the site no longer uses still count as uses until you say so: `report --ignore-tables <table>`.
  The report names every table that alone keeps items used.
- **Nothing here deletes, and neither do you.** To remove, quarantine: `scripts/site-care/media-quarantine.js` (needs the
  **Media clean-up** switch, also off by default) moves the files to a holding folder outside `uploads/`, `verify`
  checks every public page for newly missing files, `restore` puts back what is needed. Order: `verify` (baseline),
  `plan`, `move` (dry run, then `--write`), `verify`, the user lives with it for some days, then the USER deletes the
  quarantine folder and the library rows. One batch per decision.
- **MAYBE never moves by default.** On a shop, image IDs collide with ordinary numbers (customer ids on orders, quote
  numbers, prices), and the scan keeps only 3 places per reference. To move MAYBE items the owner chose, check EVERY
  hit on the full database (a local copy of a dump: exact values and `id="N"` patterns per table and key), write the
  ids that only sit in fields that cannot hold an image to a file, then `plan --csv <reviewed list> --maybe-cleared
  <file>`: an item moves only when it is on both. First used on a WooCommerce site 2026-10-14: 59 of 59 cleared.
- **Crawls come from the user's own IP** (scripts run on their PC): two full crawls in a row got the site owner
  locked out by Wordfence's rate limiting (and the "unlock" e-mail cannot arrive on a dev site that blocks mail).
  Ask for the IP to be allowlisted in Wordfence BEFORE crawling a site with Wordfence; without it product pages also
  answer 503 and the crawl silently covers fewer pages.
- Tests: `php scripts/media-audit-test.php`, `node scripts/site-care/media-audit-test.js`, `php scripts/media-quarantine-test.php`.

## Updating plugins, themes and WordPress

`scripts/site-care/site-updates.js` with the **Updates** switch of the `divi5-builder` plugin.

```bash
node scripts/site-care/site-updates.js status <site>                       # what is out of date
node scripts/site-care/site-updates.js update <site> --all                 # the plan; nothing changes without --write
node scripts/site-care/site-updates.js update <site> --all --write         # careful: one at a time, pages checked after each, stops at the first problem
node scripts/site-care/site-updates.js update <site> --all --fast --write  # small brochure site: all in one go, pages checked at the end
```

- Show the user the plan first and get a go for the run: it changes a live site and there is NO rollback (a bad
  update is undone from a backup). Careful mode for sites that matter, fast for small brochure sites.
- After every update the site clears Divi's generated CSS and the page cache by itself: on Divi 5 stale CSS after any
  plugin, theme or WordPress update is the classic "the site looks broken". `site-updates.js clear <site>` does only that.
- A new WordPress release (6.8 -> 6.9) needs `--major`; maintenance releases are taken. Premium items without an
  active licence are listed as blocked, never tried.
- The page check sees errors and broken pages, not shifted layouts: say what was and was not checked.

## Speed: the hook profiler

When a Divi/WordPress page is slow and you do not know why, do not theorise, measure:

```bash
node scripts/site-care/hook-profiler.js gen <dir>            # writes d5b-profiler.php + a random key
php  scripts/site-care/hook-profiler-test.php <dir>          # 9 local checks, no WordPress needed
# the USER uploads d5b-profiler.php to wp-content/mu-plugins/ (you cannot), then:
node scripts/site-care/hook-profiler.js run <page-url> --key-file <dir>/d5b-profiler.key
```

It is read-only, does nothing without the key, and reports the slowest hook callbacks, who is attached to Divi's per-block
parser hooks, raw CPU benchmarks, and WordPress core's parser versus the site's `parse_blocks()` on the page's real content.
Have the user DELETE the file afterwards. Query Monitor first is a good split: if its "Database Queries" total is small and
"HTTP API Calls" is none, the time is PHP, and this profiler is the next step. The interactions measurements it explains are in
`../divi5-interactions-canvases.md`.

## Longer descriptions

The project README used to carry these four sections. They say what each tool does and why it exists.

### Media and large-file audit: any WordPress site

Old sites carry years of uploads nobody uses. This part does not need Divi at all: it works on any WordPress site
with an administrator's application password.

```bash
node scripts/site-care/media-audit.js scan   <site> --dir audit     # needs the Media audit switch on (read-only)
node scripts/site-care/media-audit.js crawl  <site> --dir audit     # loads every public page: the cross-check
node scripts/site-care/media-audit.js report --dir audit            # REPORT.md + CSV lists, biggest first
node scripts/site-care/media-audit.js db     <site> --dir audit     # what takes the space in the database (read-only)
```

- **Two findings, kept apart:** media library items nothing refers to, and files on disk that belong to no library
  item. Plus the biggest files, local video and audio, broken references, and the size of plugins, themes and the
  site root.
- **Every library item gets a status:** USED, MAYBE (only a bare number matched; kept as used), BACKGROUND (only
  revisions, trash or an old builder copy refer to it) or UNUSED. When in doubt, used.
- **It finds references where they hide:** escaped JSON, serialized PHP, shortcode attributes, block attributes,
  custom fields, options, theme files, every plugin table. A size, a `-scaled` copy or a `.webp` copy counts for
  its image.
- **The crawl is the cross-check.** A file a public page loads can never be on the unused list, and the report says
  how many such files the database scan alone would have missed. On the first real run that number exposed a form
  of reference the scan did not know (Divi 5's nested gallery IDs), which is now covered.

**Nothing deletes.** To remove files, quarantine them:

```bash
node scripts/site-care/media-quarantine.js verify  <site> --dir audit                 # baseline: what is already missing
node scripts/site-care/media-quarantine.js plan    --dir audit --batch unused --status UNUSED
node scripts/site-care/media-quarantine.js move    <site> --dir audit --batch unused  # dry run; add --write
node scripts/site-care/media-quarantine.js verify  <site> --dir audit                 # newly missing files, and on which page
node scripts/site-care/media-quarantine.js restore <site> --dir audit --batch unused --needed --write
```

With the **Media clean-up** switch on, the plugin moves the named files to `wp-content/media-audit-quarantine-<random>/<batch>/` (an unguessable name, closed to
the web, with a manifest) and can move them back. It has no delete and does not touch the database. The permanent
step, deleting the quarantine folder, is the site owner's. First real run: a 2.78 GB uploads folder went to 0.8 GB
with no file missing on any public page. Playbook: `references/site-care/wp-media-audit.md`.

### Updating plugins, themes and WordPress: any WordPress site

`scripts/site-care/site-updates.js` with the **Updates** switch of the plugin (off by default; an administrator turns it on in Settings > Divi 5 Builder). It runs
WordPress's own updater, the code behind the Updates screen, one update per request.

```bash
node scripts/site-care/site-updates.js status <site>                       # what is out of date (asks the update servers first)
node scripts/site-care/site-updates.js update <site> --all                 # the plan only; nothing changes without --write
node scripts/site-care/site-updates.js update <site> --all --write         # careful: one at a time, pages checked after each
node scripts/site-care/site-updates.js update <site> --all --fast --write  # small brochure site: all in one go
node scripts/site-care/site-updates.js clear  <site>                       # Divi's generated CSS and the page cache
```

- **Careful mode** (the default) loads a set of pages before the run and again after every update, and stops at the
  first update that fails or the first page that got worse (an error page, a printed PHP error, a page cut off or one
  that lost more than half its content). **Fast mode** runs everything and compares once at the end.
- After every update the site clears Divi's generated CSS and the page cache by itself. On Divi 5, stale static CSS
  after a plugin, theme or WordPress update is the usual reason a site looks broken afterwards.
- A new WordPress release (6.8 to 6.9) needs `--major`; maintenance releases are taken. Premium items with no
  download on offer (no active licence) are listed as blocked and never tried.
- An update counts as done only when the installed version, read back from disk, is the new one.
- There is no rollback: a bad update is undone from a backup. The page check sees errors and broken pages, not a
  layout that shifted. Playbook: `references/site-care/wp-site-updates.md`.

Status when published: plugin updates, the plan, the forced fresh check and the cache clearing have run on a real
site. Theme updates, WordPress core updates, translations, fast mode and multisite are covered by the tests against a
fake WordPress only; try them first on a site where a failure does not matter.

### Going live: moving the rebuilt site over the old one

Two scripts and a checklist for the day a rebuilt staging site replaces the live one (`references/site-care/wp-go-live.md`):

```bash
node assets/site-care/content-delta.js https://live.example.com https://staging.example.com 2026-09-18T12:31:00 posts pages media
node assets/site-care/site-check.js https://staging.example.com --pages pages.json               # baseline before the move
node assets/site-care/site-check.js https://live.example.com --pages pages.json --old-host staging.example.com   # after it
```

`content-delta.js` lists what was created or edited on the live site since the staging copy was taken, and whether
staging has it (public REST, no login). `site-check.js` fetches every listed page and reports missing pages, leftover
staging-host references, leaked block markup, a `noindex` left on, failed per-page expectations (a grid's card
count, a form, a link) and redirect rules (removed pages, popup-only post types). The reference covers the rest: what
to keep from the old root, the wp-config constants a copy loses (such as a form plugin's encryption key), the
settings to flip, and the same-day checks.

Two small WPCode snippets that came out of the same build: `assets/wpcode-no-single-page.php` (a post type that only
lives in a grid popup: direct visits redirect, the popup request passes with `noindex`) and
`assets/wpcode-open-revealed-section.js` (a `#hash` link that opens a section hidden behind a click Interaction).

Two more checks from a real go-live day: `node assets/site-care/tracking-compare.js <live> <staging>` (GA4, GTM and Meta pixel ids
on both homepages, including the official Meta plugin's own loader) and `assets/phone-headings-scan.js`, a console snippet
that lists only the headings that break at phone width. The reference's last section is the day itself: migration
tools behind Cloudflare, proving which folder serves a site, what to keep from the old root, expectations drift.

### When a Divi page is slow: measure, don't guess

`scripts/site-care/hook-profiler.js` generates a small, temporary, **key-gated, read-only** mu-plugin that times every callback on the
hooks a front-end request goes through and prints the slowest as one HTML comment. It also compares WordPress core's block
parser with the parser the site actually runs, on the page's real content, and runs raw CPU benchmarks so you can tell a slow
server from slow code.

```bash
node scripts/site-care/hook-profiler.js gen ./prof           # d5b-profiler.php + a random key (both git-ignored)
php  scripts/site-care/hook-profiler-test.php ./prof         # 9 local checks, no WordPress needed
# upload d5b-profiler.php to wp-content/mu-plugins/, then:
node scripts/site-care/hook-profiler.js run https://example.com/some-page/ --key-file ./prof/d5b-profiler.key
# delete the file from the site when you are done
```

Why it exists: on one converted Divi 4 site, any Divi **interaction** took pages from ~5 s to 14–17 s. Three plausible theories
(a slow query, run-time content migrations, OPcache) were each wrong; the profiler found it in a few runs — a per-block cost in
the theme's own front-end block parser, multiplied by the extra passes interactions trigger. Another site showed no such cost,
so the advice in `references/divi5-interactions-canvases.md` is simply: time a page before and after your first interaction,
and there is a native-section + tiny-script fallback if it jumps.
