# Contributing to divi5-builder

Thanks for helping extend this! The most useful contributions are **new module
support** and **fixes for Divi 5 serialization changes**. This guide covers how to
add them reliably.

## Ground rules (non-negotiable)

These keep generated pages editable and re-themeable in the Divi Visual Builder —
please don't send PRs that break them:

1. **No inline CSS.** Never emit `style="..."` in module content. Style through
   module settings (the `decoration.*` objects the compiler builds). The one
   exception is a deliberate `code` module. Run the check in "Testing" below.
2. **Global variables/presets over literals.** Colours reference `gcid-*` names,
   fonts reference global family names. Only set a typography prop when it must
   deviate from the module's preset (setting a value pins it and breaks global
   inheritance).
3. **Native modules first.** Reach for `code` only when there's no native
   equivalent. Fractional flex for content rows, CSS Grid for card/looped grids.

Full rationale is in `SKILL.md` ("Styling rules — NON-NEGOTIABLE").

## The reverse-engineering loop (how to add a module)

Never guess a module's attribute keys — read them from a real one:

1. In the Divi Visual Builder, add the module to a scratch page and configure the
   fields you want to support. Save.
2. Dump its exact serialization:
   ```bash
   node scripts/wp.js <site> dump-blocks <pageId>          # full attrs
   node scripts/wp.js <site> dump-blocks <pageId> --tree   # structure only
   ```
3. Add a small builder function in `scripts/divi.js` following the existing ones
   (map a compact spec → the block's `content`/`decoration`/`advanced` keys), wire
   it into the `buildModule` switch, and document the keys in
   `references/divi5-modules-verified.md`.
4. Prefer a thin passthrough for rarely-used fields (see how `filtergrid` and
   `exwo_options`-style arrays are passed verbatim) so contributors don't have to
   model every option.

Mark anything you couldn't verify against a live block as `[verify-live]` in the
reference docs — honesty about confidence matters here.

## Dev setup

- **Node.js 18+** (built-ins only — no `npm install`).
- A **Divi 5** test site + a WordPress **Application Password** (admin user).
- Credentials: copy `.web-creds.example.txt` to `~/.web-creds.txt` (or set
  `WEB_CREDS_PATH`). **Never commit it.**
- Install the plugin (`divi5-builder.zip`, built with `node scripts/build-plugin-zips.js`) on the test site.

## Testing

Before opening a PR:

1. **Compile the example** (must succeed, and emit zero inline CSS):
   ```bash
   node scripts/divi.js compile examples/spec.json --out /tmp/out.html
   grep -c 'style=' /tmp/out.html      # expect 0
   ```
2. **Offline suites** (no site needed), all must pass:
   ```bash
   node scripts/compile-test.js && node scripts/preset-spec-test.js && node scripts/catalog-test.js
   node scripts/variable-refs-test.js   # Divi 5.18 variables in values, byte for byte vs a builder-saved page; the math-operator warning
   node scripts/stale-guard-test.js   # page/post/canvas writes refuse when the content changed since the read
   node scripts/spec-check-test.js    # unknown spec keys are reported; every documented example checks clean
   node scripts/blocks-test.js        # block-level edits leave the rest of the page byte for byte
   node scripts/vb-resave-check-test.js   # the Visual Builder re-save comparison, on a real Divi 5.15.1 before/after pair
   node scripts/site-care/media-audit-test.js && node scripts/site-care/site-updates-test.js
   node scripts/switch-advice-test.js    # what the scripts say when a switch is off
   node scripts/plugin-live-check-test.js    # the read-only live check (plugin-live-check.js <site>): GET only, exit 0/1/2
   node scripts/abilities-live-check-test.js   # the read-only abilities check (abilities-live-check.js <site>): GET only, exit 0/1/2
   node scripts/build-plugin-zips-test.js
   php scripts/permissions-test.php && php scripts/double-load-test.php && php scripts/media-quarantine-test.php && php scripts/media-audit-test.php
   php scripts/site-updates-test.php
   node scripts/site-care/hook-profiler.js gen <empty-dir> && php scripts/site-care/hook-profiler-test.php <empty-dir>   # generate the profiler, then check it
   php scripts/plugin-switches-test.php && php scripts/plugin-takeover-test.php && php scripts/plugin-settings-test.php && php scripts/plugin-anysite-test.php
   php scripts/plugin-uninstall-test.php   # deleting the plugin removes its options (every site of a network too)
   php scripts/plugin-abilities-switches-test.php && php scripts/plugin-abilities-gate-test.php && php scripts/plugin-abilities-audit-test.php && php scripts/plugin-abilities-content-test.php && php scripts/plugin-abilities-site-test.php && php scripts/plugin-abilities-divi-test.php && php scripts/plugin-abilities-history-test.php && php scripts/plugin-abilities-confirm-test.php && php scripts/plugin-abilities-blocks-test.php && php scripts/plugin-abilities-write-posts-test.php && php scripts/plugin-abilities-write-comments-test.php && php scripts/plugin-abilities-write-media-test.php && php scripts/plugin-abilities-write-terms-test.php && php scripts/plugin-abilities-write-menus-test.php && php scripts/plugin-abilities-write-divi-test.php   # the AI abilities: reads, then the change history, confirmation codes, block edits and every write
   ```
   Two more need arguments (a WordPress `wp-includes` folder, a Divi 5 theme folder) and are optional:
   `php scripts/tree-guard-test.php <wp-includes> <sent.html> <stored.html>` and `php scripts/ds-write-test.php "<Divi theme folder>"`.
   A change to the design-system writes runs `ds-write-test.php` against BOTH a Divi 5.18 and a 5.15 copy (nested variables
   need 5.18 for Divi's own reference finder; 5.15 proves the fallback).
   Anything that changes a write path in `wp.js` needs a case in `stale-guard-test.js`, which runs `wp.js`
   against a fake WordPress (state in a JSON file) so a "save in the builder" can be simulated.
3. **Live round-trip** for anything new — create a draft, flip Divi mode, verify it
   renders as native modules, then delete:
   ```bash
   node scripts/wp.js <site> create-page --title "PR test" --content-file /tmp/out.html --status draft
   node scripts/wp.js <site> set-builder <id>
   node scripts/wp.js <site> rendered <id>     # real et_pb_* output, no leaked <!-- wp:divi comments
   node scripts/wp.js <site> delete-page <id> --force
   ```
4. **New Divi release?** Run the [per-release checklist](references/divi-release-checklist.md) (catalogue, round
   trip, Visual Builder re-save with `scripts/vb-resave-check.js`) and add a row to the README's "Verified on" table.

## Pull requests

- Keep PRs focused (one module or fix). Update `references/` for any new keys.
- Note the Divi version you verified against (it moves fast).
- **Never commit** credentials, `snapshots/`, private page content, real site URLs,
  or client data. `.gitignore` blocks the obvious cases — double-check your diff.
- Match the existing code style (plain Node, small functions, terse comments).

## Adding a route to the plugin

Plugin code lives in `plugin/divi5-builder/`. Put a new REST route in the matching file in
`plugin/divi5-builder/parts/` (`page-building.php`, `media-audit.php`, `media-clean-up.php` or
`updates.php`). A route in one of the three switch parts must not exist while its switch is off;
`scripts/plugin-switches-test.php` covers that.

## Security

Report vulnerabilities privately, never in a public issue: see [SECURITY.md](SECURITY.md)
(GitHub Security tab -> "Report a vulnerability"). Any change to a PHP plugin's routes
must keep `php scripts/permissions-test.php` passing, and new routes need cases in it.

This tool holds
site credentials at runtime — treat the creds file and any dumped page content as
secret and keep them out of git.
