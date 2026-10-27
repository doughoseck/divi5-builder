#!/usr/bin/env node
/*
 * blocks-test.js: block-level edits keep the rest of the page byte for byte (issue #15). No site needed.
 *   node scripts/blocks-test.js        BLOCKS_JS=<path> tests another copy
 * The last part runs the documented flow (get-page --out, blocks.js, update-page --expect-hash) against the fake
 * WordPress in stale-guard-test.js.
 */
const fs = require('fs'); const os = require('os'); const path = require('path'); const crypto = require('crypto');
const { spawnSync } = require('child_process');
const BJS = path.resolve(__dirname, process.env.BLOCKS_JS || 'blocks.js');
const B = require(BJS);
const md5 = (s) => crypto.createHash('md5').update(s, 'utf8').digest('hex');
let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail !== undefined ? '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 500) : '')); if (!ok) failed++; };
const refuses = (fn, re) => { try { fn(); return false; } catch (e) { return re.test(e.message) ? true : e.message; } };

// A page with what real pages have: the placeholder wrapper, a labelled row, a CSS ID, attributes holding "}", an
// escaped "-->" and a quote, an ordinary HTML comment, a nested row, an empty column, a fullwidth section, line breaks
// and none.
const lbl = (s) => `"meta":{"adminLabel":{"desktop":{"value":"${s}"}}}`;
const PAGE = [
  '<!-- wp:divi/placeholder -->',
  '<!-- wp:divi/section {"module":{"advanced":{"htmlAttributes":{"desktop":{"value":{"id":"intro"}}}}},"builderVersion":"5.15.1"} -->',
  `<!-- wp:divi/row {"module":{${lbl('Featured Work')}}} -->`,
  '<!-- wp:divi/column -->',
  '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003e{a} } \\u0022q\\u0022 \\u002d\\u002d\\u003e\\u003c/p\\u003e"}}}} /-->',
  '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}}} /-->',
  '<!-- /wp:divi/column -->',
  '<!-- wp:divi/column -->',
  '<!-- an ordinary comment -->',
  '<!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x.png"}}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row -->',
  '<!-- /wp:divi/column -->',
  '<!-- /wp:divi/row -->',
  '<!-- /wp:divi/section -->',
  `<!-- wp:divi/section {"module":{${lbl('Hero')}}} -->`,
  '<!-- wp:divi/fullwidth-header {"title":{"innerContent":{"desktop":{"value":"Hi"}}}} /-->',
  '<!-- /wp:divi/section -->',
  `<!-- wp:divi/section {"module":{${lbl('Empty')}}} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->`,
  '<!-- /wp:divi/placeholder -->',
].join('\n');
const MOD = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"new"}}}} /-->';

console.log('--- parsing and addresses');
const { root, errors } = B.parse(PAGE);
check('the page parses with no errors', !errors.length, errors);
const all = B.walk(root);
const at = (a) => all.find((b) => b.addr === a);
check('the placeholder is not counted: section 1 is the #intro section', at('1') && at('1').name === 'divi/section' && /intro/.test(PAGE.slice(at('1').start, at('1').openEnd)));
check('1.1.1.2 is the button, 1.1.2.1 the nested row, 1.1.2.1.1.1 the image inside it',
  at('1.1.1.2').name === 'divi/button' && at('1.1.2.1').name === 'divi/row' && at('1.1.2.1.1.1').name === 'divi/image');
check('an attribute holding "}", an escaped "-->" and a quote does not end the block early', at('1.1.1.1').name === 'divi/text' && at('1.1.1.2') !== undefined && !!at('1.1.1.1').attrs);
check('an ordinary HTML comment is not a block', !all.some((b) => /comment/.test(b.name)));
check('label:Featured Work finds the row, #intro the section', B.resolve(root, 'label:Featured Work').addr === '1.1' && B.resolve(root, '#intro').addr === '1');
check('an address with nothing there is refused, naming tree', refuses(() => B.resolve(root, '9'), /nothing at "9".*tree/) === true);
const dup = PAGE.replace(lbl('Hero'), lbl('Featured Work'));
check('a label used twice is refused, listing both addresses', refuses(() => B.resolve(B.parse(dup).root, 'label:Featured Work'), /matches 2 blocks.*1\.1, 2/) === true);
check('a broken page is reported: a block never closed', B.parse(PAGE.replace('<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->', '<!-- /wp:divi/section -->')).errors.length > 0);

console.log('--- every edit leaves the rest byte for byte');
const ops = [
  ['replace', '1.1.1.2', MOD], ['remove', '1.1.1.2'], ['before', '1.1.1.1', MOD], ['after', '1.1.1.2', MOD],
  ['append', '1.1.1', MOD], ['append', '3.1.1', MOD], ['replace', 'label:Hero', PAGE.split('\n')[16]], ['remove', '#intro'],
  ['after', '1.1.2.1.1.1', MOD], ['append', '2', MOD],
];
for (const [op, a, w] of ops) {
  let r; try { r = B.edit(PAGE, op, { at: a, withText: w }); } catch (e) { check(`${op} ${a}`, false, e.message); continue; }
  const t = B.resolve(root, a);
  // the range it reports must lie on the target block (a line break either side at most), and the text outside it
  // must be the original: checked here against the page, not taken on trust
  const [s, e] = r.summary.range, ins = r.summary.insertedChars;
  const inside = s >= t.start && e <= t.end + 1 && s <= e;
  const outside = r.out.slice(0, s) === PAGE.slice(0, s) && r.out.slice(s + ins) === PAGE.slice(e) && r.out.length === PAGE.length - (e - s) + ins;
  check(`${op} ${a}: only the ${t.name.replace('divi/', '')} at ${t.addr} changed, and the result parses`, inside && outside && !B.parse(r.out).errors.length, { s, e, start: t.start, end: t.end, outside });
}
let r = B.edit(PAGE, 'replace', { at: '1.1.1.2', withText: MOD });
check('replace: the page = before + the new module + after', r.out === PAGE.slice(0, at('1.1.1.2').start) + MOD + PAGE.slice(at('1.1.1.2').end));
r = B.edit(PAGE, 'remove', { at: '1.1.1.2' });
check('remove takes the block and its own line break, nothing else', r.out === PAGE.slice(0, at('1.1.1.2').start) + PAGE.slice(at('1.1.1.2').end + 1));
r = B.edit(PAGE, 'append', { at: '3.1.1', withText: MOD });
check('append into an EMPTY column puts the module inside it', B.walk(B.parse(r.out).root).some((b) => b.addr === '3.1.1.1' && b.name === 'divi/text'));
r = B.edit(PAGE, 'before', { at: '1.1.1.1', withText: MOD });
check('before: the new module is 1.1.1.1 and the old one moved to 1.1.1.2', (() => { const w = B.walk(B.parse(r.out).root); const g = (a) => w.find((b) => b.addr === a); return r.out.slice(g('1.1.1.1').start, g('1.1.1.1').end) === MOD && g('1.1.1.2').name === 'divi/text' && g('1.1.1.3').name === 'divi/button'; })());
const r2 = B.edit(PAGE, 'after', { at: '1.1.1.1', withText: MOD });
check('after: the new module is 1.1.1.2, between the text and the button', (() => { const w = B.walk(B.parse(r2.out).root); const g = (a) => w.find((b) => b.addr === a); return r2.out.slice(g('1.1.1.2').start, g('1.1.1.2').end) === MOD && r2.out.slice(g('1.1.1.1').start, g('1.1.1.1').end) === PAGE.slice(at('1.1.1.1').start, at('1.1.1.1').end) && g('1.1.1.3').name === 'divi/button'; })());
check('the summary names the target and both hashes', r.summary.target.address === '1.1.1.1' && r.summary.inputHash === md5(PAGE) && r.summary.outputHash === md5(r.out));

console.log('--- what goes in must fit');
check('a module in place of a section is refused', refuses(() => B.edit(PAGE, 'replace', { at: '1', withText: MOD }), /module.*cannot go in place of a section/) === true);
check('a section after a module is refused', refuses(() => B.edit(PAGE, 'after', { at: '1.1.1.1', withText: PAGE.split('\n')[13] + '\n<!-- /wp:divi/section -->' }), /section.*cannot go after a module/) === true);
check('a module appended into a row is refused (a row takes columns)', refuses(() => B.edit(PAGE, 'append', { at: '1.1', withText: MOD }), /cannot go inside a row.*columns/) === true);
check('a module appended into a fullwidth section is allowed (it holds modules)', !!B.edit(PAGE, 'append', { at: '2', withText: MOD }).out);
check('a nested row can sit where a module does', !!B.edit(PAGE, 'after', { at: '1.1.1.2', withText: PAGE.split('\n')[9] }).out);
check('appending into a self-closing module is refused', refuses(() => B.edit(PAGE, 'append', { at: '1.1.1.2', withText: MOD }), /no inside/) === true);
check('a fragment that does not parse is refused', refuses(() => B.edit(PAGE, 'replace', { at: '1.1.1.2', withText: '<!-- wp:divi/row -->' }), /--with file does not parse/) === true);
check('a page that does not parse is not edited', refuses(() => B.edit(PAGE + '<!-- wp:divi/row -->', 'remove', { at: '1' }), /page does not parse/) === true);
check('a fragment with no Divi blocks is refused', refuses(() => B.edit(PAGE, 'replace', { at: '1.1.1.2', withText: '<p>hi</p>' }), /no Divi blocks/) === true);
r = B.edit(PAGE, 'replace', { at: '1.1.1.2', withText: '<!-- wp:divi/placeholder -->\n' + MOD + '\n<!-- /wp:divi/placeholder -->' });
check('a compiled page is unwrapped: its placeholder is dropped', r.out === PAGE.slice(0, at('1.1.1.2').start) + MOD + PAGE.slice(at('1.1.1.2').end));
r = B.edit(PAGE, 'replace', { at: '1.1.1.2', withText: PAGE, from: '1.1.2.1.1.1' });
check('--from picks one block out of a bigger file', r.out.includes(PAGE.split('\n')[9].match(/<!-- wp:divi\/image[^>]*\/-->/)[0]) && B.walk(B.parse(r.out).root).find((b) => b.addr === '1.1.1.2').name === 'divi/image');

// the divi.js compiler's own output, where it exists
const DIVI = path.join(__dirname, 'divi.js'), SPEC = path.join(__dirname, '..', 'examples', 'spec.json');
if (fs.existsSync(SPEC)) {
  const d = require(DIVI); const errs = []; const o = process.stderr.write.bind(process.stderr); process.stderr.write = (s) => { errs.push(s); return true; };
  let compiled; try { compiled = d.compile(JSON.parse(fs.readFileSync(SPEC, 'utf8'))); } finally { process.stderr.write = o; }
  r = B.edit(PAGE, 'after', { at: '1', withText: compiled });
  const secs = B.walk(B.parse(compiled).root).filter((b) => !b.addr.includes('.')).length;
  check(`a whole compiled page goes in as its ${secs} sections, after section 1`, B.walk(B.parse(r.out).root).filter((b) => !b.addr.includes('.')).length === 3 + secs);
} else console.log('  SKIP  examples/spec.json (not in this copy)');

console.log('--- the CLI');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-blocks-'));
const put = (name, text) => { const f = path.join(tmp, name); fs.writeFileSync(f, text); return f; };
const cli = (...a) => { const x = spawnSync(process.execPath, [BJS, ...a], { encoding: 'utf8' }); let json = null; try { json = JSON.parse(x.stdout); } catch (e) {} return { code: x.status, out: x.stdout, err: x.stderr, json }; };
const pf = put('page.html', PAGE), mf = put('mod.html', MOD);
let c = cli('tree', pf);
check('tree prints addresses, labels and CSS IDs', c.code === 0 && /^1\s+section\s+#intro/m.test(c.out) && /^1\.1\s+ row\s+«Featured Work»/m.test(c.out) && /^1\.1\.2\.1\.1\.1\s/m.test(c.out), c.out);
c = cli('replace', pf, '--at', '1.1.1.2', '--with', mf, '--out', path.join(tmp, 'o.html'));
check('replace writes --out, prints a summary, leaves the input alone', c.code === 0 && c.json && c.json.ok && fs.readFileSync(path.join(tmp, 'o.html'), 'utf8') === B.edit(PAGE, 'replace', { at: '1.1.1.2', withText: MOD }).out && fs.readFileSync(pf, 'utf8') === PAGE, c.err || c.out);
c = cli('remove', pf, '--at', '1', '--out', pf);
check('--out may not be the input file', c.code === 1 && /different file/.test(c.err) && fs.readFileSync(pf, 'utf8') === PAGE);
c = cli('replace', pf, '--at', '1', '--with', mf, '--out', path.join(tmp, 'bad.html'));
check('a refusal exits 1 and writes nothing', c.code === 1 && /^REFUSED: /.test(c.err) && !fs.existsSync(path.join(tmp, 'bad.html')), c.err);
c = cli('get', pf, '--at', 'label:Hero');
check('get prints the block as stored', c.code === 0 && c.out === PAGE.split('\n').slice(13, 16).join('\n') + '\n', c.out);
check('check reports a page that does not parse', cli('check', put('broken.html', PAGE + '<!-- wp:divi/row -->')).code === 1 && cli('check', pf).code === 0);

console.log('--- the whole flow, through the stale guard (fake WordPress)');
const SG = path.join(__dirname, 'stale-guard-test.js'), WPJS = path.join(__dirname, 'wp.js');
if (fs.existsSync(SG) && fs.existsSync(WPJS)) {
  const STATE = put('state.json', JSON.stringify({ pages: { 10: { id: 10, title: 'Home', status: 'publish', content: PAGE } }, posts: {}, et_pb_canvas: {} }));
  const CREDS = put('creds.ini', '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');
  const wp = (...a) => { const x = spawnSync(process.execPath, ['-r', SG, WPJS, 'fake', ...a], { encoding: 'utf8', env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, WP_BACKUP_DIR: path.join(tmp, 'bk') }) }); let json = null; try { json = JSON.parse(x.stdout); } catch (e) {} return { code: x.status, json, err: x.stderr }; };
  const got = path.join(tmp, 'got.html'), next = path.join(tmp, 'next.html');
  const g = wp('get-page', '10', '--out', got);
  cli('replace', got, '--at', 'label:Hero', '--with', mf, '--out', path.join(tmp, 'x.html'));   // a module in a section's place: refused
  c = cli('append', got, '--at', 'label:Hero', '--with', mf, '--out', next);
  const u = wp('update-page', '10', '--content-file', next, '--expect-hash', g.json.hash);
  const stored = JSON.parse(fs.readFileSync(STATE, 'utf8')).pages[10].content;
  check('get-page --out, blocks.js append, update-page --expect-hash: stored = the page with one module added', g.code === 0 && c.code === 0 && u.code === 0 && stored === B.edit(PAGE, 'append', { at: 'label:Hero', withText: MOD }).out, [g.err, c.err, u.err]);
  // someone saves in the Visual Builder between the read and the write: the guard still stops it
  const s2 = JSON.parse(fs.readFileSync(STATE, 'utf8')); s2.pages[10].content = stored + '\n'; fs.writeFileSync(STATE, JSON.stringify(s2));
  const u2 = wp('update-page', '10', '--content-file', next, '--expect-hash', u.json.hash);
  check('a save in between is still caught: exit 3, nothing written', u2.code === 3 && JSON.parse(fs.readFileSync(STATE, 'utf8')).pages[10].content === stored + '\n', u2.err);
} else console.log('  SKIP  the fake WordPress (stale-guard-test.js or wp.js not in this copy)');

console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
