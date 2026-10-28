#!/usr/bin/env node
/**
 * vb-resave-check-test.js: the Visual Builder re-save comparison (issue #21). No site needed.
 *
 *   node scripts/vb-resave-check-test.js
 *
 * The fixtures in scripts/fixtures/vb-resave-5.15.1/ are a real pair: a page the compiler built from this repo's own
 * examples/spec.json + assets/roundtrip-spec.json (before.html, written by the REST API, as the compiler of that day
 * emitted it, including the empty "module" group of issue #21), and the same page after the maintainer opened it in
 * the Visual Builder on Divi 5.15.1 / WordPress 7.1.3 and clicked Save with no changes (after.html). The two
 * *-rendered.html files are what the server rendered for each.
 *
 * What it proves:
 *   - the real pair: exit 0 (content, tree and render unchanged) and the three formatting-only changes are counted;
 *   - a changed attribute value, a removed block, a changed rendered word, changed text outside the block comments:
 *     exit 1, and the output names the path / block / word;
 *   - a pure whitespace change in the rendered file: exit 0;
 *   - usage and unreadable input: exit 2;
 *   - snapshot (against the fake WordPress of stale-guard-test.js) saves the files, the hash, and only ever GETs;
 *   - MUTATION: five broken copies of the tool (each ignoring one thing) are run against the same cases and each
 *     must be caught by at least one case, so a compare that stops looking at attribute values fails this test.
 */
const fs = require('fs'), os = require('os'), path = require('path'), crypto = require('crypto');
const { spawnSync } = require('child_process');
const B = require('./blocks.js');
const FIX = path.join(__dirname, 'fixtures', 'vb-resave-5.15.1');
const TOOL = path.join(__dirname, 'vb-resave-check.js');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-vbresave-'));
let failed = 0, n = 0;
const check = (name, ok, detail) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || !detail ? '' : '\n          ' + String(detail).slice(0, 400))); if (!ok) failed++; };
const rd = (f) => fs.readFileSync(path.join(FIX, f), 'utf8');

// A working directory holding before/after (+ rendered), with edits applied to the "after" side.
function setup(mut = {}) {
  const dir = path.join(tmp, 'case' + (++n)); fs.mkdirSync(dir);
  const files = { 'before.html': rd('before.html'), 'after.html': rd('after.html'), 'before-rendered.html': rd('before-rendered.html'), 'after-rendered.html': rd('after-rendered.html') };
  for (const [f, fn] of Object.entries(mut)) files[f] = fn(files[f]);
  for (const [f, text] of Object.entries(files)) if (text != null) fs.writeFileSync(path.join(dir, f), text);
  return dir;
}
const run = (tool, dir, extra = []) => { const r = spawnSync(process.execPath, [tool, 'compare', '--dir', dir, ...extra], { encoding: 'utf8' }); return { code: r.status, out: (r.stdout || '') + (r.stderr || ''), stdout: r.stdout || '' }; };
const replaceOnce = (text, from, to) => { if (!text.includes(from)) throw new Error('fixture no longer contains ' + JSON.stringify(from)); return text.replace(from, to); };

// ---- the cases: [name, mutation of the fixture, expected exit, regex the output must match]
const CASES = [
  ['the real pair (Divi 5.15.1 Visual Builder re-save with no changes): exit 0', {}, 0, /RESULT: content, tree and render unchanged/],
  ['a changed attribute value: exit 1, names the block and the path', { 'after.html': (t) => replaceOnce(t, '"top":"90px"', '"top":"91px"') }, 1, /CHANGED divi\/section module\.decoration\.spacing\.desktop\.value\.padding\.top "90px" -> "91px"/],
  ['a changed text value: exit 1, names the path', { 'after.html': (t) => replaceOnce(t, 'WELCOME', 'WELCOMED') }, 1, /CHANGED divi\/text content\.innerContent\.desktop\.value/],
  ['an attribute added: exit 1', { 'after.html': (t) => replaceOnce(t, '"builderVersion":"5.15.1"} -->', '"builderVersion":"5.15.1","extra":"x"} -->') }, 1, /ADDED divi\/\S+ extra = "x"/],
  ['an attribute removed: exit 1', { 'after.html': (t) => replaceOnce(t, '"phone":{"value":{"flexWrap":"wrap"}}', '"x":1') }, 1, /REMOVED divi\/row module\.decoration\.layout\.phone\.value\.flexWrap/],
  ['a removed block: exit 1, the tree names it', { 'after.html': (t) => B.edit(t, 'remove', { at: '4.1.2.2' }).out }, 1, /4\.1\.2\.2 divi\/code: only in before/],
  ['a changed rendered word: exit 1', { 'after-rendered.html': (t) => replaceOnce(t, 'WELCOME', 'WELCOMED') }, 1, /rendered HTML identical[^\n]*: NO/],
  ['changed text outside the block comments: exit 1', { 'after.html': (t) => t.replace(/<!-- \/wp:divi\/placeholder -->$/, '<p>stray</p><!-- /wp:divi/placeholder -->') }, 1, /text outside the block comments identical: NO/],
  ['a pure whitespace change in the rendered HTML (between tags, before closing tags): exit 0', { 'after-rendered.html': (t) => t.replace(/<\/p>/g, '\n\n  </p>').replace(/<\/div>/g, '\n</div>').replace(/></g, '>\n  <') }, 0, /rendered HTML identical[^\n]*: yes/],
];

console.log('compare, against the real fixtures');
const results = CASES.map(([name, mut, code, re]) => { const dir = setup(mut); return { name, dir, code, re, got: run(TOOL, dir) }; });
for (const c of results) check(c.name, c.got.code === c.code && c.re.test(c.got.out), `exit ${c.got.code} (want ${c.code}); ${c.got.out.split('\n').filter((l) => /NO|CHANGED|ADDED|REMOVED|only in/.test(l)).slice(0, 4).join(' | ')}`);

// the real pair: formatting-only counts, in the output and in --json
const real = results[0];
const j = JSON.parse(run(TOOL, real.dir, ['--json']).stdout);
check('the real pair: 35 blocks each side, same tree, no data change, same render', j.blocks.before === 35 && j.blocks.after === 35 && j.treeIdentical && !j.data.length && j.renderedIdentical && j.outsideIdentical, JSON.stringify(j.blocks));
check('formatting only: key order changed in some blocks (builderVersion moved last)', j.formatting.keyOrderBlocks > 0 && Object.keys(j.formatting.lastKeyMoves).some((k) => /-> builderVersion/.test(k)), JSON.stringify(j.formatting));
check('formatting only: open/close pairs saved as self-closing', j.formatting.toSelfClosing > 0 && j.formatting.toOpenClose === 0, JSON.stringify(j.formatting));
check('formatting only: whitespace between block comments changed', j.formatting.whitespaceBetweenBlocks.changed && j.formatting.whitespaceBetweenBlocks.before.chars !== j.formatting.whitespaceBetweenBlocks.after.chars, JSON.stringify(j.formatting.whitespaceBetweenBlocks));
check('the stored hash differs after a no-change save, and the report says why (STALE)', j.hash.before !== j.hash.after && /changed \(.*\); expected after a builder save, so wp\.js will report STALE/.test(real.got.out));
const same = run(TOOL, setup({ 'after.html': () => rd('before.html'), 'after-rendered.html': () => rd('before-rendered.html') }));
check('the same page twice: exit 0, and the report notes the text is byte-identical', same.code === 0 && /byte-identical: was the page saved at all/.test(same.out), same.out.slice(-300));

// ---- usage and unreadable input: exit 2
const bad = [
  ['no command', [], null], ['compare without --dir', ['compare'], null],
  ['a missing file', ['compare', '--dir', setup({ 'after.html': () => null })], null],
  ['an after.html that does not parse', ['compare', '--dir', setup({ 'after.html': (t) => t.replace('<!-- /wp:divi/placeholder -->', '') })], null],
  ['snapshot without --as', ['snapshot', 'fake', '10', '--dir', path.join(tmp, 'x')], null],
  ['snapshot with a page id that is not a number', ['snapshot', 'fake', 'abc', '--dir', path.join(tmp, 'x'), '--as', 'before'], null],
];
for (const [name, args] of bad) { const r = spawnSync(process.execPath, [TOOL, ...args], { encoding: 'utf8' }); check('exit 2 on ' + name, r.status === 2, `exit ${r.status}: ${(r.stderr || '').slice(0, 120)}`); }

// ---- snapshot: against the fake WordPress of stale-guard-test.js (this repo's wp.js, no network)
console.log('snapshot, against the fake WordPress');
{
  const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini');
  fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');
  fs.writeFileSync(STATE, JSON.stringify({ recordMethods: true, pages: { 10: { id: 10, title: 'Draft', status: 'draft', content: rd('before.html') }, 11: { id: 11, title: 'Live', status: 'publish', content: rd('after.html') } } }));
  const env = Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: ((process.env.NODE_OPTIONS || '') + ' --require ' + path.join(__dirname, 'stale-guard-test.js').replace(/\\/g, '/')).trim() });
  const dir = path.join(tmp, 'snap');
  const snap = (id, as) => spawnSync(process.execPath, [TOOL, 'snapshot', 'fake', String(id), '--dir', dir, '--as', as], { encoding: 'utf8', env });
  const r1 = snap(10, 'before');
  check('snapshot --as before: exit 0, saves the stored content byte for byte', r1.status === 0 && fs.existsSync(path.join(dir, 'before.html')) && fs.readFileSync(path.join(dir, 'before.html'), 'utf8') === rd('before.html'), (r1.stdout || '') + (r1.stderr || ''));
  check('snapshot saves the rendered HTML and the hash', fs.existsSync(path.join(dir, 'before-rendered.html')) && JSON.parse(fs.readFileSync(path.join(dir, 'before.meta.json'), 'utf8')).hash === crypto.createHash('md5').update(rd('before.html'), 'utf8').digest('hex'));
  const r2 = snap(10, 'after');
  check('snapshot --as after works the same, and compare then runs on the pair (identical text: exit 0)', r2.status === 0 && run(TOOL, dir).code === 0);
  const r3 = snap(11, 'after');
  check('snapshot of a page that is not a draft still reads, and warns', r3.status === 0 && /not a draft/.test(r3.stdout), r3.stdout);
  const reqs = JSON.parse(fs.readFileSync(STATE, 'utf8')).requests || [];
  check('snapshot only ever GETs (' + reqs.length + ' requests)', reqs.length >= 6 && reqs.every((q) => q.startsWith('GET ')), reqs.join(', '));
  const r4 = spawnSync(process.execPath, [TOOL, 'snapshot', 'fake', '999', '--dir', path.join(tmp, 'snap2'), '--as', 'before'], { encoding: 'utf8', env });
  check('snapshot of a page that does not exist: exit 2', r4.status === 2, r4.stderr);
}

// ---- mutation: break the tool five ways; every broken copy must be caught by at least one case above
console.log('mutation: a broken copy of the tool must fail the cases');
const src = fs.readFileSync(TOOL, 'utf8');
const MUTANTS = [
  ['ignores CHANGED attribute values', "if (fa[k] === fz[k]) continue;", "if (k in fa && k in fz) continue;"],
  ['ignores ADDED and REMOVED attributes', "const kind = !(k in fa) ? 'ADDED' : !(k in fz) ? 'REMOVED' : 'CHANGED';", "if (!(k in fa) || !(k in fz)) continue; const kind = 'CHANGED';"],
  ['ignores the block tree', "res.treeIdentical = !res.treeDiff.length;", "res.treeIdentical = true;"],
  ['ignores the rendered HTML', "res.renderedIdentical = na === nz;", "res.renderedIdentical = true;"],
  ['ignores text outside the block comments', "res.outsideIdentical = oa === oz;", "res.outsideIdentical = true;"],
];
MUTANTS.forEach(([name, from, to], i) => {
  if (!src.includes(from)) { check('mutant "' + name + '" can be built', false, 'the tool no longer contains: ' + from); return; }
  const file = path.join(__dirname, `vb-resave-check.mutant${i}.tmp.js`);
  fs.writeFileSync(file, src.replace(from, to));
  try {
    const caught = results.filter((c) => { const g = run(file, c.dir); return !(g.code === c.code && c.re.test(g.out)); }).map((c) => c.name.split(':')[0]);
    check(`mutant "${name}" is caught (${caught.length} case(s) fail)`, caught.length > 0, 'every case still passed with the broken tool');
  } finally { fs.unlinkSync(file); }
});

try { fs.rmSync(tmp, { recursive: true, force: true }); } catch (e) {}
console.log(failed ? `\n${failed} FAILED` : '\nall passed');
process.exit(failed ? 1 : 0);
