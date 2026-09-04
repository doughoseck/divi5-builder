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
- Install the mu-plugin (`assets/divi5-builder-rest.php`) on the test site.

## Testing

Before opening a PR:

1. **Compile the example** (must succeed, and emit zero inline CSS):
   ```bash
   node scripts/divi.js compile examples/spec.json --out /tmp/out.html
   grep -c 'style=' /tmp/out.html      # expect 0
   ```
2. **Live round-trip** for anything new — create a draft, flip Divi mode, verify it
   renders as native modules, then delete:
   ```bash
   node scripts/wp.js <site> create-page --title "PR test" --content-file /tmp/out.html --status draft
   node scripts/wp.js <site> set-builder <id>
   node scripts/wp.js <site> rendered <id>     # real et_pb_* output, no leaked <!-- wp:divi comments
   node scripts/wp.js <site> delete-page <id> --force
   ```

## Pull requests

- Keep PRs focused (one module or fix). Update `references/` for any new keys.
- Note the Divi version you verified against (it moves fast).
- **Never commit** credentials, `snapshots/`, private page content, real site URLs,
  or client data. `.gitignore` blocks the obvious cases — double-check your diff.
- Match the existing code style (plain Node, small functions, terse comments).

## Security

Report anything sensitive privately rather than in a public issue. This tool holds
site credentials at runtime — treat the creds file and any dumped page content as
secret and keep them out of git.
