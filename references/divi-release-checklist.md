# Checklist for a new Divi release

Divi ships often. Each release can change what the builder writes or how the site stores it, so a version
that was not checked is a version that is not known to work. Run this once on a **staging or test site** per new
Divi release (a minor, `5.16`, always; a patch only if its changelog touches the builder), then add a row to the
"Verified on" table in the [README](../README.md#verified-on). Everything writes only to **throwaway drafts**.

1. **Rebuild the catalogue** from the new theme folder, and see which modules or options changed:
   `node scripts/catalog.js build <path to the Divi theme folder>` (then `catalog.js list` and compare the module
   count with the table).
2. **Compile and lint the example**, with the new version as `builderVersion` (`wp.js <site> builder-version`):
   `node scripts/divi.js compile examples/spec.json --out out.html --strict` must succeed, emit no inline CSS
   (`grep -c 'style=' out.html` is 0), and the lint after it must say "no errors".
3. **Round trip** on a throwaway draft: `node scripts/roundtrip-test.js <site> <draft_id>`. It writes the compiler's
   output and fails unless the site stores it byte for byte. A failure here means the compiler and the Theme Builder
   write guard have drifted from what the new Divi stores.
4. **Visual Builder re-save**: does opening a page in the builder and saving it, with no changes, change it?
   Needs a person at the builder, once:
   1. Build a draft from the example plus the round-trip spec in one page:
      ```bash
      node scripts/make-resave-spec.js 5.16.0 --out resave-spec.json     # the new Divi version
      node scripts/divi.js compile resave-spec.json --out resave.html
      node scripts/wp.js <site> create-page --title "VB re-save test" --content-file resave.html --status draft
      ```
   2. `node scripts/vb-resave-check.js snapshot <site> <draft_id> --dir vb --as before`
   3. The person opens the draft in the Visual Builder and clicks **Save** without touching anything.
   4. `node scripts/vb-resave-check.js snapshot <site> <draft_id> --dir vb --as after`
   5. `node scripts/vb-resave-check.js compare --dir vb`. Exit 0 = the content, the block tree and the rendered
      HTML are unchanged; the formatting-only section lists what the builder reformatted (key order, self-closing
      blocks, whitespace between blocks). Exit 1 = the builder changed something that matters: read the listed
      paths, and do not publish the row until it is understood.
   A changed content hash after this save is **expected** (the builder reformats the markup), and `wp.js` then
   reports STALE until the page is read again. That is the stale guard working, not a failure.
5. **Design-system writes**, if the plugin's design-system routes are used on that Divi:
   `node scripts/ds-live-test.js <site> all` (staging, while nobody is editing).
6. **Offline suites**: everything under "Tests" in the README still passes.
7. **Delete the throwaway drafts** (`wp.js <site> delete-page <id> --force`) and **add a row** to the README table:
   Divi version, date, and what was checked (say what was NOT, for example "no VB re-save").

To keep the evidence, the `before`/`after` files of step 4 are what `scripts/fixtures/vb-resave-5.15.1/` holds for
Divi 5.15.1; a new release whose re-save differs from that deserves a fixture of its own.
