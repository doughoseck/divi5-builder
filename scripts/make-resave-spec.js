#!/usr/bin/env node
/**
 * make-resave-spec.js: the page spec for the Visual Builder re-save check (references/divi-release-checklist.md).
 *
 *   node scripts/make-resave-spec.js [builderVersion] [--out file]     (default 5.15.1; no --out = print it)
 *
 * Joins the sections of examples/spec.json and assets/roundtrip-spec.json into one spec, with the given builderVersion.
 */
const fs = require('fs'), path = require('path');
const args = process.argv.slice(2);
const oi = args.indexOf('--out');
const out = oi >= 0 ? args[oi + 1] : null;
if (oi >= 0 && !out) { console.error('--out needs a file'); process.exit(2); }
const version = args.filter((a, i) => a !== '--out' && i !== oi + 1)[0] || '5.15.1';
if (!/^\d+\.\d+(\.\d+)?$/.test(version)) { console.error('usage: node make-resave-spec.js [builderVersion, e.g. 5.16.0] [--out file]'); process.exit(2); }
const load = (f) => JSON.parse(fs.readFileSync(path.join(__dirname, '..', f), 'utf8'));
const a = load('examples/spec.json'), b = load('assets/roundtrip-spec.json');
const spec = Object.assign({}, a, { builderVersion: version, sections: [...(a.sections || []), ...(b.sections || [])] });
const text = JSON.stringify(spec, null, 1) + '\n';
if (out) { fs.writeFileSync(out, text); console.log(`wrote ${out}: ${spec.sections.length} sections, builderVersion ${version}`); } else process.stdout.write(text);
