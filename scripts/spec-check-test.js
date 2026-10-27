#!/usr/bin/env node
/*
 * spec-check-test.js: unknown keys in a page spec are reported (issue #17). No site needed.
 *   node scripts/spec-check-test.js        DIVI_JS=<path> tests another copy of the compiler
 * The known keys are derived from divi.js's own source; this test proves both directions:
 *   - no false alarm: every example in divi.js's header, examples/spec.json and the round-trip spec check clean;
 *   - real mistakes are caught, with a "did you mean", on modules, sections, rows, columns, nested rows and popups.
 */
const fs = require('fs'); const path = require('path');
const DIVI = path.join(__dirname, process.env.DIVI_JS || 'divi.js');
const d = require(DIVI);
let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail !== undefined ? '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 600) : '')); if (!ok) failed++; };
const wrap = (m) => ({ sections: [{ rows: [{ columns: [{ modules: [m] }] }] }] });
const has = (list, re) => list.some((w) => re.test(w));

console.log('--- no false alarms');
// Every `{"type":...}` example in the header of divi.js documents a real way to write a module.
const header = fs.readFileSync(DIVI, 'utf8').split('*/')[0];
const examples = [...header.matchAll(/^\s\*\s+(\{"type".*\})(?:\s+\/\/.*)?$/gm)].map((m) => m[1]);
const parsed = []; const bad = [];
for (const e of examples) { if (/\{\.\.\.\}|"\.\.\."/.test(e)) continue; try { parsed.push(JSON.parse(e)); } catch (err) { bad.push(e.slice(0, 80)); } }   // {...} = a placeholder, not JSON
check(`the header's module examples parse as JSON (${parsed.length} of ${examples.length}, placeholders skipped)`, parsed.length >= 10 && !bad.length, bad);
const fromHeader = parsed.flatMap((m) => d.checkSpec(wrap(m)).map((w) => `${m.type}: ${w}`));
check('every header example checks clean (no key a builder does not read)', !fromHeader.length, fromHeader);
for (const f of ['examples/spec.json', 'assets/roundtrip-spec.json']) {
  const fp = path.join(__dirname, '..', f);
  if (!fs.existsSync(fp)) { console.log(`  SKIP  ${f} (not in this copy)`); continue; }
  const w = d.checkSpec(JSON.parse(fs.readFileSync(fp, 'utf8')));
  check(`${f} checks clean`, !w.length, w);
}
const rich = { builderVersion: '5.15.1', sections: [{ cssId: 'a', hideOn: ['phone'], modulePreset: 'x', background: { color: '#fff' }, padding: { top: '1px' }, _comment: 'notes are free', rows: [
  { layout: '2-1', wrap: ['phone'], maxWidth: '900px', columns: [{ padding: '10px', radius: '8px', border: { width: '1px' }, shadow: {}, hover: { borderColor: '#000' }, rowGap: '0', modules: [
    { type: 'row', layout: '1', columns: [{ modules: [{ type: 'button', text: 'b', url: '#', align: 'center', bg: '#000', color: '#fff', weight: '700', radius: '4px', padding: { top: '1px' }, icon: true, newTab: true }] }] },
  ] }, { bgImage: 'https://x/a.png', bgPosition: 'top', modules: [{ type: 'image', src: 'x', alt: 'y', id: 3, frame: { radius: '4px' }, align: 'left', url: 'https://x', newTab: false }] }] },
  { grid: { cols: { desktop: 3 } }, columns: [{ loop: { postTypes: ['post'] }, modules: [{ type: 'group', loop: {}, modules: [{ type: 'text', html: '<p>x</p>' }] }] }] },
] }] };
check('a spec using many real options (nested row, grid, loop, group) checks clean', !d.checkSpec(rich).length, d.checkSpec(rich));

console.log('--- real mistakes are caught');
let w = d.checkSpec(wrap({ type: 'button', text: 'b', colour: '#fff' }));
check('a typo on a module: "colour" on a button -> did you mean "color"', has(w, /unknown key "colour" on the button module .*did you mean "color"/), w);
w = d.checkSpec(wrap({ type: 'text', html: '<p>x</p>', Size: '20px' }));
check('a wrong case: "Size" -> did you mean "size"', has(w, /"Size".*did you mean "size"/), w);
w = d.checkSpec({ sections: [{ paddng: { top: '1px' }, rows: [] }] });
check('on a section: "paddng" -> did you mean "padding"', has(w, /"paddng" on the section .*did you mean "padding"/), w);
w = d.checkSpec({ sections: [{ rows: [{ layout: '1', colums: [] }] }] });
check('on a row: "colums" -> did you mean "columns"', has(w, /"colums" on the row .*did you mean "columns"/), w);
w = d.checkSpec({ sections: [{ rows: [{ columns: [{ bgimage: 'x', modules: [] }] }] }] });
check('on a column: "bgimage" -> did you mean "bgImage"', has(w, /"bgimage" on the column .*did you mean "bgImage"/), w);
w = d.checkSpec(wrap({ type: 'row', layout: '1', columns: [{ modules: [{ type: 'image', src: 'x', alt: 'y', frame: {}, shadow: true }] }] }));
check('inside a nested row: "shadow" on an image (it belongs in frame) is reported', has(w, /"shadow" on the image module at spec\.sections\[0\]\.rows\[0\]\.columns\[0\]\.modules\[0\]\.columns\[0\]\.modules\[0\]/), w);
w = d.checkSpec(wrap({ type: 'group', modules: [{ type: 'text', htm: '<p>x</p>' }] }));
check('inside a group: "htm" on a text module -> did you mean "html"', has(w, /"htm".*did you mean "html"/), w);
w = d.checkSpec({ targetId: 'p', modules: [{ type: 'button', txt: 'x' }] }, 'popup');
check('in a popup canvas spec: "txt" on a button -> did you mean "text"', has(w, /"txt".*did you mean "text"/), w);
w = d.checkSpec(wrap({ type: 'text', html: '<p>x</p>', zzzz: 1 }));
check('a key nothing resembles: reported with no guess', has(w, /"zzzz"/) && !has(w, /zzzz.*did you mean/), w);

console.log('--- compile()');
const errs = []; const orig = process.stderr.write.bind(process.stderr);
process.stderr.write = (s) => { errs.push(String(s)); return true; };
let out; try { out = d.compile(wrap({ type: 'button', text: 'b', colour: '#fff' })); } finally { process.stderr.write = orig; }
check('compile() still compiles, and prints one WARNING per unknown key', /wp:divi\/button/.test(out) && errs.filter((e) => /^WARNING: unknown key "colour"/.test(e)).length === 1, errs);
check('compile.warnings keeps them', Array.isArray(d.compile.warnings) && d.compile.warnings.length === 1);
let threw = false; try { d.compile(wrap({ type: 'button', text: 'b', colour: '#fff' }), { strict: true }); } catch (e) { threw = /spec check failed[\s\S]*colour/.test(e.message); }
check('compile(spec, { strict: true }) refuses it', threw);
errs.length = 0; process.stderr.write = (s) => { errs.push(String(s)); return true; };
try { d.compile(wrap({ type: 'button', text: 'b', colour: '#fff' }), { quiet: true }); } finally { process.stderr.write = orig; }
check('{ quiet: true } prints nothing', !errs.length, errs);

console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
