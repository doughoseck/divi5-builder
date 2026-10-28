# Site search on a Divi 5 site (built and verified 2026-10-01, Divi 5.13.1)

What it takes to give a Divi 5 site a search that works: where the field goes, what the results page is, and the three
places where WordPress or Divi do something you would not expect.

## First: check what a search returns today

Fetch `/?s=<a word you know>` and `/?s=<nonsense>` as a visitor and count the `<article>` elements and their
`type-…` classes. On one converted site BOTH returned the latest 12 news posts: the "Blog" Theme Builder template was
also assigned to Search Results, and its grid was a third-party grid module with its own query, which ignores the
search term. A search that "works" but lists the same posts for every word is this.

## The parts

| Part | Native? | How |
|---|---|---|
| Search field | yes | `divi/search`. Placeholder: `searchPlaceholder.innerContent`. Field and button styles: `field.decoration.*`, `button.decoration.*`. Background/padding set on `module.decoration` did NOT reach the front end in one test; the module's Custom CSS did. |
| Limit to pages + posts | yes | The module's form sends `et_pb_searchform_submit`, `et_pb_include_pages=yes`, `et_pb_include_posts=yes` (both "exclude" switches off). Divi's `et_pb_custom_search` (`includes/builder/functions.php`) then sets `post_type` to page + post on `pre_get_posts`. No code needed. |
| Results page | yes | A Theme Builder template assigned to Search Results, with a body layout holding a `divi/blog` module with `post.advanced.useCurrentLoop = on` ("posts for the current page"). It builds `new WP_Query( main query vars + its own paging )`, so it shows what was searched for, with pagination. |
| Title "Results for …" | yes | Dynamic content `post_title` in a heading: on a search page Divi returns `Results for "<query>"`. |
| "Nothing found" | yes | The Blog module prints its own "No Results Found" block. |
| Pages before posts | no | WordPress orders by match quality, then newest first; a page called "Tickets" lands behind every newer post with "tickets" in its title. A `posts_orderby` filter that prepends `({$wpdb->posts}.post_type = 'page') DESC` on front-end searches fixes it (about 8 lines). |
| A page whose content is a loop of other posts (FAQs) | no | See below. |

The tool cannot CREATE a Theme Builder template (only edit layouts with `tb-set`). The user adds the template
(assigned to Search Results, custom body, global header and footer) and unticks Search Results on any template that
had it; then `tb-set <body id>` fills the body.

Blog module elements worth knowing (from its `module.json`): card background is `masonry.decoration.background`
(not `post`), card border `post.decoration.border`, excerpt text `content.decoration.bodyFont.body.font`, title
`title.decoration.font.font`, grid columns `blogGrid.decoration.layout.<breakpoint>.value.gridColumnCount`, plain list
vs grid `fullwidth.advanced.enable` (`off` = grid). In a JS object literal do not write the `post` key twice (settings
and border): the second silently replaces the first and `useCurrentLoop` is gone.

## Three traps

1. **Divi's search form rewrites EVERY search query on the results page.** `et_pb_custom_search` runs on
   `pre_get_posts` for any query with `is_search`, whenever the form's parameters are in the URL. A helper query such
   as `get_posts( [ 'post_type' => 'faq', 's' => $term ] )` inside a snippet is therefore turned into a page + post
   search and finds nothing (`suppress_filters` does not stop an action). Look other post types up with direct SQL
   (`$wpdb->prepare`, `LIKE` per word), not with a WordPress search.
2. **A hand-typed `/?s=word` (no form parameters) searches every public post type**, including ones that only exist
   to feed a loop (FAQ entries with their own `/faq/…` URLs). Switch on "exclude from search" where the type is
   registered.
3. **A page that shows other posts through a loop has almost no text of its own**, so it never matches. See next.

## "The answer is in a FAQ": make the FAQs page the first result

Pattern (two small snippets, both in the site's code-snippet plugin):

- **PHP, `the_posts` filter** on front-end searches, first results page only: look for a published FAQ entry, in the
  categories the FAQs page actually shows, whose title or content contains every word (direct SQL, see trap 1). If
  there is one, remove the FAQs page from the results if present and put it first. Remember the term in a global; a
  `page_link` filter adds `?q=<term>` to that page's link, only `in_the_loop()` so menu and footer links stay plain.
  Count only the categories on the page: a FAQ shown nowhere must not promise a result.
- **JS on the FAQs page: filter as you type.** All questions are already in the HTML (inside the hidden category
  sections). The field is a `divi/search` with a CSS class; the script prevents the submit, and on input hides the
  category tiles, shows each section that has a match, hides non-matching accordion items
  (`.et_pb_accordion_item[data-loop-item]`; the hidden dummy item has no `data-loop-item`), and prints a count. It
  reads `?q=` on load. Divi shows/hides those sections with an inline `display … !important`, so the script saves the
  section's `style` attribute before changing it and restores it when the field is emptied; a never-shown section
  still carries `et_animated` (opacity 0), so a visible-by-filter section also needs `opacity:1; animation:none`.

Debugging a PHP snippet you cannot run yourself: add a temporary trace (push strings to a global, print them as an
HTML comment on `wp_footer` only when a `?debug` parameter is present), read it with a fetch, then hand over a clean
version. Do this BEFORE writing a second fix on a guess: on this build the first "fix" answered a diagnosis that was
never proven, and the real cause of "no effect" was that an old version of the snippet was still installed.

## Check scripts worth keeping per site

Fetch as a visitor and parse: the `type-…` class of each result card in order (pages then posts), the first card's
link, page 2, a nonsense word, and a word that only lives in the looped posts.
