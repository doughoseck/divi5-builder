#!/usr/bin/env node
/**
 * Round-trip test: does the site store EXACTLY what the compiler emits?
 *
 *   node scripts/roundtrip-test.js <site> <draft_page_id> [spec.json]
 *
 * Compiles the spec (default assets/roundtrip-spec.json, which is full of quotes, backslashes, dashes, angle
 * brackets, ampersands, apostrophes and non-ASCII), writes it to the given page, reads the raw content back and
 * compares byte for byte. The Theme Builder write guard (tb-set) rejects any content the save alters, so this is
 * the test that the compiler and the guard agree. Run it after touching attrJSON()/compile(), and once on any
 * site running a Divi version this has not been run against.
 *
 * <draft_page_id> MUST be a throwaway draft: its content is overwritten. Exits non-zero on any difference.
 */
const fs = require('fs'), os = require('os'), path = require('path');
const { execFileSync } = require('child_process');
const [site, id, specArg] = process.argv.slice(2);
if (!site || !/^\d+$/.test(id || '')) { console.error('usage: node roundtrip-test.js <site> <draft_page_id> [spec.json]'); process.exit(2); }
const here = __dirname, spec = specArg || path.join(here, '..', 'assets', 'roundtrip-spec.json');
const run = (script, args) => execFileSync(process.execPath, [path.join(here, script), ...args], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });

const status = JSON.parse(run('wp.js', [site, 'get-page', id])).status;
if (status !== 'draft') { console.error(`STOP: page ${id} is "${status}", not a draft. This test overwrites content.`); process.exit(2); }

const tmp = path.join(os.tmpdir(), `d5b-roundtrip-${process.pid}.html`);
run('divi.js', ['compile', spec, '--out', tmp]);
const sent = fs.readFileSync(tmp, 'utf8');
let failed = 0;
const check = (name, ok, detail) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || !detail ? '' : '\n        ' + detail)); if (!ok) failed++; };

check('compiled output closes the page wrapper', /<!-- \/wp:divi\/placeholder -->$/.test(sent));
check('compiled output has no raw \\" or \\\\ inside block comments', !/\\"|\\\\/.test(sent));
const blocks = [...sent.matchAll(/<!-- wp:\S+ (\{.*?\}) \/?-->/gs)].map(m => m[1]);
check(`found the block JSON to inspect (${blocks.length} blocks)`, blocks.length >= 5);
check('compiled output has no raw -- inside block JSON', !blocks.some(j => j.includes('--')));
run('wp.js', [site, 'update-page', id, '--content-file', tmp]);
const stored = run('wp.js', [site, 'get-page', id, '--raw']).replace(/\r?\n$/, '');
fs.unlinkSync(tmp);
let at = 0; while (at < sent.length && sent[at] === stored[at]) at++;
check(`stored content is byte-identical to what was sent (${sent.length} chars)`, sent === stored,
  `first difference at ${at}: sent "${sent.slice(at, at + 40)}" / stored "${stored.slice(at, at + 40)}" (stored ${stored.length} chars)`);

console.log(failed ? `\n${failed} check(s) FAILED` : '\nall checks passed');
process.exit(failed ? 1 : 0);
