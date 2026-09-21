#!/usr/bin/env node
/**
 * Test of `catalog.js lint`: planted mistakes must each be caught, and the clean original must pass.
 *
 *   node scripts/catalog-test.js            (needs a built catalogue: catalog.js build <Divi folder>)
 *
 * The clean sample is compiled from assets/roundtrip-spec.json plus an accordion, so it also proves the compiler's own
 * output passes the lint. Exits non-zero on any failure.
 */
const fs = require('fs'), os = require('os'), path = require('path'); const { execFileSync } = require('child_process');
const here = __dirname; const tmp = (n) => path.join(os.tmpdir(), `d5b-cat-${process.pid}-${n}`);
const spec = { builderVersion: '5.13', sections: [{ rows: [{ layout: '1', columns: [{ modules: [
  { type: 'heading', text: 'Catalogue lint test', level: 'h2' },
  { type: 'button', text: 'Go', url: 'https://example.com' },
  { type: 'accordion', items: [{ title: 'One', html: '<p>First</p>' }, { title: 'Two', html: '<p>Second</p>' }] } ] }] }] }] };
fs.writeFileSync(tmp('spec.json'), JSON.stringify(spec)); execFileSync(process.execPath, [path.join(here, 'divi.js'), 'compile', tmp('spec.json'), '--out', tmp('clean.html')]);
const clean = fs.readFileSync(tmp('clean.html'), 'utf8');
const lint = (content) => { const f = tmp('case.html'); fs.writeFileSync(f, content); try { return { code: 0, out: execFileSync(process.execPath, [path.join(here, 'catalog.js'), 'lint', f], { encoding: 'utf8' }) }; } catch (e) { return { code: e.status, out: (e.stdout || '') + (e.stderr || '') }; } };
const swap = (from, to) => { if (!clean.includes(from)) { console.log(`STOP: mutation anchor not found: ${from}`); process.exit(2); } return clean.replace(from, to); };
let failed = 0; const check = (name, ok, detail) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || !detail ? '' : '\n        ' + detail.trim().split('\n').slice(-4).join('\n        '))); if (!ok) failed++; };

const c = lint(clean); check('compiler output (heading, button, accordion) lints clean', c.code === 0 && /no errors/.test(c.out), c.out);
const must = (name, content, expect) => { const r = lint(content); check(name, r.code === 1 && expect.test(r.out), r.out); };
console.log('--- each of these is a mistake and must be reported ---');
must('a module name that does not exist', swap('wp:divi/button ', 'wp:divi/buton '), /divi\/buton: no such module/);
must('an element the module does not have', swap('"button":{', '"buttonn":{'), /unknown element "buttonn"/);
must('a child module outside its parent', clean.replace(/<!-- wp:divi\/accordion \{[^>]*?-->/, '').replace('<!-- /wp:divi/accordion -->', ''), /accordion-item must sit inside/);
must('a foreign module inside an accordion', swap('<!-- /wp:divi/accordion -->', '<!-- wp:divi/divider {"builderVersion":"5.13"} /--><!-- /wp:divi/accordion -->'), /divider sits inside divi\/accordion/);
const btn = clean.match(/<!-- wp:divi\/button (\{.*?\}) \/?-->/); if (!btn) { console.log('STOP: no button block in the sample'); process.exit(2); }
const withAlign = (v) => { const a = JSON.parse(btn[1]); a.module = a.module || {}; a.module.advanced = Object.assign({}, a.module.advanced, { alignment: { desktop: { value: v } } }); return clean.replace(btn[1], JSON.stringify(a)); };
const okAlign = lint(withAlign('center')); check('control: a VALID option value passes', okAlign.code === 0, okAlign.out);
must('a value that is not one of the field\'s options', withAlign('middle'), /"middle" is not an option\. Allowed: left \| center \| right/);
must('attributes that are not valid JSON', swap('"builderVersion":"5.13"} -->\n<!-- wp:divi/row', '"builderVersion":"5.13" -->\n<!-- wp:divi/row'), /not valid JSON|never closed|does not match/);
must('a block that is never closed', swap('<!-- /wp:divi/row -->', ''), /never closed|does not match/);
for (const n of ['spec.json', 'clean.html', 'case.html']) try { fs.unlinkSync(tmp(n)); } catch (e) {}
console.log(failed ? `\n${failed} check(s) FAILED` : '\nall checks passed'); process.exit(failed ? 1 : 0);
