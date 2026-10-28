# Page cache on a Divi 5 site: what helped and what did not (measured 2026-10-01)

One converted site, shared hosting, WP Fastest Cache (free). Measured as a visitor, 9 pages, 5 loads each, first two
dropped.

| Setting | Effect |
|---|---|
| Cache System + Gzip + Browser Caching | Time to first byte 1.8-3.5 s -> 32-59 ms on every cached page. Assets get a 120-day lifetime; JS becomes gzipped. Search result pages stay uncached (correct). |
| Minify CSS + Combine CSS | WORSE. Stylesheets per page went UP by four (18 -> 22 on the homepage): the plugin added four files of its own and combined none of the existing ones. Switched off again. |
| Combine JS | Not tried: the free version only handles scripts in the head; Divi's are in the footer. |

Rules that came out of it:

- Before the cache, all of the server time was page generation (download about 20 ms). The page cache IS the gain.
- Measure, then switch ONE thing, then measure again and look at the HTML: count `<link rel=stylesheet>` and
  `<script src>`, and read the cache plugin's timestamp comment at the end of the page. A setting saved in the plugin
  does not show until the cache has been deleted; an unchanged timestamp or byte count means you measured the old copy.
- After the cache is on, a write through the REST tools is not visible until the cache is cleared. A page update may
  clear that page; a header, footer or popup canvas change (`tb-set`, `update-canvas`) does not clear the other pages.
  Tell the user to delete the cache after such a change, and let logged-in users bypass the cache.
- What is left is in the browser. Group the page's scripts and stylesheets by owner (plugin folder in the URL) with
  their sizes: on this site a form plugin loaded about 440 KB on every page for two small footer forms, more than
  anything combining could save.
- Functions to re-test with the cache on: popups (they are moved to `<body>` by script), anything that reads the URL
  (`?q=`), background video, interactions.
