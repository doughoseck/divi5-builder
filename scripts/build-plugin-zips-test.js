// build-plugin-zips-test.js: the one zip, what is in it, that it rebuilds byte for byte, and the update.json it writes.
//   node scripts/build-plugin-zips-test.js
// Set BUILD_ZIPS_SCRIPT to test another copy of build-plugin-zips.js (used for mutation testing).
// The builds run against a copy of plugin/ and LICENSE in a temp folder (D5B_REPO_ROOT), so the test never
// rewrites the repository's own plugin/update.json; it compares the copy's rebuilt json with the committed one.
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');
const { execFileSync, spawnSync } = require('child_process');
const ROOT = path.join(__dirname, '..');
const SCRIPT = process.env.BUILD_ZIPS_SCRIPT || path.join(__dirname, 'build-plugin-zips.js');
let n = 0, failed = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail !== undefined ? '\n          ' + JSON.stringify(detail) : '')); if (!ok) failed++; };

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-zip-'));
const repoCopy = (sub, withJson = true) => { // plugin/ and LICENSE, as checked out
  const r = path.join(tmp, sub);
  fs.cpSync(path.join(ROOT, 'plugin'), path.join(r, 'plugin'), { recursive: true });
  if (fs.existsSync(path.join(ROOT, 'LICENSE'))) fs.copyFileSync(path.join(ROOT, 'LICENSE'), path.join(r, 'LICENSE'));
  if (!withJson && fs.existsSync(path.join(r, 'plugin', 'update.json'))) fs.unlinkSync(path.join(r, 'plugin', 'update.json'));
  return r;
};
const run = (root, args) => spawnSync(process.execPath, [SCRIPT, ...args], { encoding: 'utf8', env: { ...process.env, D5B_REPO_ROOT: root } });
const REPO = repoCopy('repo');
const build = (sub, extra = [], root = REPO) => {
  const out = path.join(tmp, sub);
  execFileSync(process.execPath, [SCRIPT, '--out', out, ...extra], { stdio: 'pipe', env: { ...process.env, D5B_REPO_ROOT: root } });
  return out;
};
const TAG_FORM = /^v\d{4}\.\d{2}\.\d{2}(\.\d+)?$/;
const today = () => new Date().toISOString().slice(0, 10);
function entries(buf) { // read the central directory: name, method, data
  const eocd = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  const count = buf.readUInt16LE(eocd + 10); let p = buf.readUInt32LE(eocd + 16); const out = [];
  for (let i = 0; i < count; i++) {
    const method = buf.readUInt16LE(p + 10), csize = buf.readUInt32LE(p + 20), nlen = buf.readUInt16LE(p + 28), xlen = buf.readUInt16LE(p + 30), clen = buf.readUInt16LE(p + 32), off = buf.readUInt32LE(p + 42);
    const name = buf.toString('utf8', p + 46, p + 46 + nlen);
    const lnlen = buf.readUInt16LE(off + 26), lxlen = buf.readUInt16LE(off + 28), start = off + 30 + lnlen + lxlen;
    const raw = buf.subarray(start, start + csize);
    out.push({ name, data: method === 8 ? zlib.inflateRawSync(raw) : raw, time: buf.readUInt16LE(p + 12), date: buf.readUInt16LE(p + 14) });
    p += 46 + nlen + xlen + clen;
  }
  return out;
}
const main0 = files => files.find(e => e.name === 'divi5-builder/divi5-builder.php').data.toString();
function walk(dir, rel) { let o = []; for (const e of fs.readdirSync(dir, { withFileTypes: true })) { const r = rel ? rel + '/' + e.name : e.name; o = e.isDirectory() ? o.concat(walk(path.join(dir, e.name), r)) : o.concat([r]); } return o; }

try {
  const a = build('a'), b = build('b');
  check('the build writes the two release files (divi5-builder.zip, divi5-builder-loader.php) and update.json', JSON.stringify(fs.readdirSync(a).sort()) === JSON.stringify(['divi5-builder-loader.php', 'divi5-builder.zip', 'update.json']), fs.readdirSync(a));
  const za = fs.readFileSync(path.join(a, 'divi5-builder.zip')), zb = fs.readFileSync(path.join(b, 'divi5-builder.zip'));
  check('two builds are byte-identical', za.equals(zb));

  // --- update.json (2.5.0: the GitHub updater reads it from main) ---
  const jpath = d => path.join(d, 'update.json');
  const ja = fs.existsSync(jpath(a)) ? fs.readFileSync(jpath(a)) : Buffer.alloc(0), jb = fs.existsSync(jpath(b)) ? fs.readFileSync(jpath(b)) : Buffer.alloc(1);
  check('update.json: two builds in a row give byte-identical json', ja.equals(jb));
  const repoJsonFile = path.join(REPO, 'plugin', 'update.json');
  check('update.json: the json in --out equals plugin/update.json written by the same build', fs.existsSync(repoJsonFile) && ja.equals(fs.readFileSync(repoJsonFile)));
  const committed = path.join(ROOT, 'plugin', 'update.json');
  check('update.json: the committed plugin/update.json is what a build of this commit writes (rebuild before committing)', fs.existsSync(committed) && ja.toString('utf8') === fs.readFileSync(committed, 'utf8').replace(/\r\n/g, '\n'));
  let J = {}; try { J = JSON.parse(ja.toString('utf8')); } catch (e) { /* checks below fail */ }
  const KEYS = ['slug', 'version', 'tag', 'download_url', 'sha256', 'requires', 'requires_php', 'tested', 'last_updated', 'changelog_url'];
  check('update.json: exactly the ten keys, in order, every value a string', JSON.stringify(Object.keys(J)) === JSON.stringify(KEYS) && KEYS.every(k => typeof J[k] === 'string'), J);
  check('update.json: 2-space indent, LF endings, one trailing newline', ja.length > 0 && ja.toString('utf8') === JSON.stringify(J, null, 2) + '\n' && !ja.includes('\r'));
  check('update.json: slug is divi5-builder', J.slug === 'divi5-builder', J.slug);
  const mainSrc = fs.readFileSync(path.join(ROOT, 'plugin', 'divi5-builder', 'divi5-builder.php'), 'utf8');
  const readmeSrc = fs.readFileSync(path.join(ROOT, 'plugin', 'divi5-builder', 'readme.txt'), 'utf8');
  const hdr = k => (mainSrc.match(new RegExp('^\\s*\\*\\s*' + k + ':\\s*(\\S+)', 'm')) || [])[1];
  check('update.json: version equals the zip header, D5B_VERSION and the readme Stable tag', !!J.version && J.version === hdr('Version') && mainSrc.includes("define( 'D5B_VERSION', '" + J.version + "' )") && (readmeSrc.match(/^Stable tag:\s*(\S+)/m) || [])[1] === J.version, J.version);
  check('update.json: sha256 is the sha256 of the zip bytes', J.sha256 === crypto.createHash('sha256').update(za).digest('hex'), J.sha256);
  check('update.json: tag has the form vYYYY.MM.DD[.N]', TAG_FORM.test(J.tag || ''), J.tag);
  check('update.json: download_url is the release asset of that tag', J.download_url === 'https://github.com/doughoseck/divi5-builder/releases/download/' + J.tag + '/divi5-builder.zip', J.download_url);
  check('update.json: changelog_url is the release page of that tag', J.changelog_url === 'https://github.com/doughoseck/divi5-builder/releases/tag/' + J.tag, J.changelog_url);
  check('update.json: requires / requires_php equal the header Requires at least / Requires PHP', !!J.requires && J.requires === hdr('Requires at least') && J.requires_php === hdr('Requires PHP'), [J.requires, J.requires_php]);
  check('update.json: tested is the readme Tested up to, else 7.1', J.tested === ((readmeSrc.match(/^Tested up to:\s*(\S+)/m) || [])[1] || '7.1'), J.tested);
  check('update.json: last_updated is a YYYY-MM-DD date', /^\d{4}-\d{2}-\d{2}$/.test(J.last_updated || ''), J.last_updated);
  const zipNames = entries(za).map(e => e.name);
  check('update.json is NOT inside the zip', zipNames.length > 0 && !zipNames.some(x => /update\.json$/.test(x)), zipNames.filter(x => /json/.test(x)));

  // Without --tag the committed tag is kept, and so is last_updated while version and tag are unchanged.
  const R2 = repoCopy('repo2'); const j2 = path.join(R2, 'plugin', 'update.json');
  if (fs.existsSync(j2)) { const o = JSON.parse(fs.readFileSync(j2, 'utf8')); o.last_updated = '2000-01-01'; fs.writeFileSync(j2, JSON.stringify(o, null, 2) + '\n'); }
  const c1 = build('c1', [], R2); const J1 = fs.existsSync(jpath(c1)) ? JSON.parse(fs.readFileSync(jpath(c1), 'utf8')) : {};
  check('update.json: a rebuild with the same version and tag keeps last_updated (byte-identical rule)', J1.last_updated === '2000-01-01' && J1.tag === J.tag, J1);
  const c2 = build('c2', ['--tag', J.tag || 'v2026.11.05'], R2); const J2 = fs.existsSync(jpath(c2)) ? JSON.parse(fs.readFileSync(jpath(c2), 'utf8')) : {};
  check('update.json: --tag equal to the committed tag also keeps last_updated', J2.last_updated === '2000-01-01', J2);
  const c3 = build('c3', ['--tag', 'v2099.01.02.3'], R2); const J3 = fs.existsSync(jpath(c3)) ? JSON.parse(fs.readFileSync(jpath(c3), 'utf8')) : {};
  check('update.json: a new --tag sets tag, both URLs and last_updated = today (UTC)', J3.tag === 'v2099.01.02.3' && J3.download_url === 'https://github.com/doughoseck/divi5-builder/releases/download/v2099.01.02.3/divi5-builder.zip' && J3.changelog_url === 'https://github.com/doughoseck/divi5-builder/releases/tag/v2099.01.02.3' && J3.last_updated === today() && fs.readFileSync(j2, 'utf8') === fs.readFileSync(jpath(c3), 'utf8'), J3);
  const c4 = build('c4', [], R2); const J4 = fs.existsSync(jpath(c4)) ? JSON.parse(fs.readFileSync(jpath(c4), 'utf8')) : {};
  check('update.json: the next build without --tag reuses the tag now in plugin/update.json', J4.tag === 'v2099.01.02.3', J4.tag);
  if (fs.existsSync(j2)) { const o = JSON.parse(fs.readFileSync(j2, 'utf8')); o.version = '0.0.1'; o.last_updated = '2000-01-01'; fs.writeFileSync(j2, JSON.stringify(o, null, 2) + '\n'); }
  const c5 = build('c5', [], R2); const J5 = fs.existsSync(jpath(c5)) ? JSON.parse(fs.readFileSync(jpath(c5), 'utf8')) : {};
  check('update.json: a version change sets version from the header and last_updated = today', J5.version === J.version && J5.last_updated === today(), J5);

  // STOPs: a bad --tag, or no --tag and no committed json, writes nothing and exits 1.
  for (const bad of ['v2026.11.5', '2026.11.05', 'v2026.11.05.', 'v2026.11.05x', 'v2026-11-05', 'v2026.11.05.1.2', '']) {
    const R = repoCopy('bad' + n); const jf = path.join(R, 'plugin', 'update.json'); const before = fs.existsSync(jf) ? fs.readFileSync(jf) : null;
    const o = path.join(tmp, 'badout' + n); const r = run(R, ['--out', o, '--tag', bad]);
    check('a bad --tag ' + JSON.stringify(bad) + ' STOPs (exit 1) and writes nothing', r.status === 1 && /STOP/.test(r.stderr) && !fs.existsSync(o) && before !== null && fs.readFileSync(jf).equals(before), { status: r.status, stderr: r.stderr, out: fs.existsSync(o) });
  }
  { const R = repoCopy('bad-end'); const o = path.join(tmp, 'badout-end'); const r = run(R, ['--out', o, '--tag']);
    check('--tag with no value STOPs (exit 1) and writes nothing', r.status === 1 && /STOP/.test(r.stderr) && !fs.existsSync(o), { status: r.status, stderr: r.stderr }); }
  { const R = repoCopy('nojson', false); const o = path.join(tmp, 'nojson-out'); const r = run(R, ['--out', o]);
    check('no --tag and no plugin/update.json STOPs (exit 1) with a message naming --tag, and writes nothing', r.status === 1 && /STOP/.test(r.stderr) && /--tag/.test(r.stderr) && !fs.existsSync(o) && !fs.existsSync(path.join(R, 'plugin', 'update.json')), { status: r.status, stderr: r.stderr }); }
  const lpath = d => path.join(d, 'divi5-builder-loader.php');
  const la = fs.existsSync(lpath(a)) ? fs.readFileSync(lpath(a)) : Buffer.alloc(0), lb = fs.existsSync(lpath(b)) ? fs.readFileSync(lpath(b)) : Buffer.alloc(1);
  const lsrc = fs.readFileSync(path.join(ROOT, 'plugin', 'mu-loader', 'divi5-builder-loader.php'), 'utf8').replace(/\r\n/g, '\n');
  check('the must-use loader is the source file, LF endings, the same in both builds', la.equals(lb) && la.toString('utf8') === lsrc && !la.includes('\r') && /Plugin Name: Divi 5 Builder \(must-use loader\)/.test(lsrc));

  const es = entries(za); const files = es.filter(e => !e.name.endsWith('/')); const names = files.map(e => e.name);
  const src = walk(path.join(ROOT, 'plugin', 'divi5-builder'), '').map(f => 'divi5-builder/' + f);
  if (fs.existsSync(path.join(ROOT, 'LICENSE'))) src.push('divi5-builder/LICENSE');
  check('the zip holds exactly the plugin folder plus LICENSE, under divi5-builder/', JSON.stringify([...names].sort()) === JSON.stringify([...src].sort()), { names, src });
  for (const want of ['divi5-builder/divi5-builder.php', 'divi5-builder/uninstall.php', 'divi5-builder/readme.txt', 'divi5-builder/LICENSE']) check('holds ' + want, names.includes(want));
  check('holds inc/ and parts/ files', names.filter(x => x.startsWith('divi5-builder/inc/')).length >= 5 && names.filter(x => x.startsWith('divi5-builder/parts/')).length >= 4);
  const abil = ['audit', 'categories', 'confirm', 'history', 'read-content', 'read-divi', 'read-history', 'read-site', 'read-structure', 'register', 'scrub', 'secrets', 'write-comments', 'write-appearance', 'write-common', 'write-divi', 'write-media', 'write-menus', 'write-posts', 'write-settings', 'write-terms', 'write-widgets', 'write-site', 'write-templates', 'maint-common', 'write-plugins', 'write-themes', 'write-core', 'write-users', 'write-email'];
  check('holds every file of inc/abilities/ (2.4.0: 30 files, the 2.4.0 six included) and no other', abil.length === 30 && JSON.stringify(names.filter(x => x.startsWith('divi5-builder/inc/abilities/')).sort()) === JSON.stringify(abil.map(f => 'divi5-builder/inc/abilities/' + f + '.php').sort()), names.filter(x => x.includes('/abilities/')));
  // The loader guards each abilities file with function_exists() on one of its own function names: a name declared in two files would make the second file's guard skip it silently.
  const declared = {}, twice = [];
  for (const e of files.filter(x => /^divi5-builder\/inc\/abilities\/[^/]+\.php$/.test(x.name))) {
    const code = e.data.toString().replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    for (const m of code.matchAll(/^\s*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/gm)) { const k = m[1].toLowerCase(); if (declared[k] && declared[k] !== e.name) twice.push(k); declared[k] = e.name; }
  }
  check('no function name is declared in two abilities files (the loader guard trap)', Object.keys(declared).length > 400 && twice.length === 0, twice);
  check('holds inc/blocks.php and inc/blocks-text-fields.php (2.2.0)', ['blocks', 'blocks-text-fields'].every(f => names.includes('divi5-builder/inc/' + f + '.php')), names.filter(x => x.startsWith('divi5-builder/inc/')));
  check('the zip header says version 2.4.3, as the constant and the readme do', /Version: 2\.4\.3\b/.test(main0(files)) && /D5B_VERSION', '2\.4\.3'/.test(main0(files)) && /Stable tag: 2\.4\.3\b/.test(files.find(e => e.name === 'divi5-builder/readme.txt').data.toString()));
  check('every name sits under divi5-builder/', names.every(x => x.startsWith('divi5-builder/')), names);
  check('no old plugin, no assets/, no must-use loader in the zip', !names.some(x => /legacy|assets|mu-loader|divi5-builder-rest|wp-media|wp-site-updates|divi5-builder-loader/.test(x)), names);
  const body = names.filter(x => x !== 'divi5-builder/LICENSE');
  check('names are sorted, LICENSE last', JSON.stringify(body) === JSON.stringify([...body].sort()) && names[names.length - 1] === 'divi5-builder/LICENSE', names);
  check('every entry has the fixed 1980-01-01 timestamp', es.every(e => e.time === 0 && e.date === ((0 << 9) | (1 << 5) | 1)), es.map(e => [e.name, e.time, e.date]));
  const main = files.find(e => e.name === 'divi5-builder/divi5-builder.php');
  check('the main file in the zip is the loader, with LF endings', main && /Plugin Name: Divi 5 Builder/.test(main.data.toString()) && !main.data.includes('\r'));
  const same = files.filter(e => e.name !== 'divi5-builder/LICENSE').every(e => fs.existsSync(path.join(ROOT, 'plugin', e.name)) && e.data.toString('utf8') === fs.readFileSync(path.join(ROOT, 'plugin', e.name), 'utf8').replace(/\r\n/g, '\n'));
  check('each file in the zip equals its source (LF-normalised)', same);
} finally {
  fs.rmSync(tmp, { recursive: true, force: true });
}
console.log(failed ? `\n${failed} of ${n} FAILED` : `\nall ${n} passed`);
process.exit(failed ? 1 : 0);
