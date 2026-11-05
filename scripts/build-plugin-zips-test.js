// build-plugin-zips-test.js: the one zip, what is in it, that it rebuilds byte for byte, and the update.json it writes.
//   node scripts/build-plugin-zips-test.js
// Set BUILD_ZIPS_SCRIPT to test another copy of build-plugin-zips.js (used for mutation testing).
// The builds run against a copy of plugin/ and LICENSE in a temp folder (D5B_REPO_ROOT), so the test never
// rewrites the repository's own plugin/update.json; it holds the committed json to the release rule (the same version
// as the header: the sha256 of a build of this commit; another version: older than the header). The git cases run
// git init / add / commit in a temp copy; the other copies have no .git and use the walk build.
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
const REPO_JSON_BEFORE = fs.existsSync(path.join(REPO, 'plugin', 'update.json')) ? fs.readFileSync(path.join(REPO, 'plugin', 'update.json')) : Buffer.alloc(0);
// The Version: header of a copy's main file, read as the build reads it.
const hdrOf = root => (fs.readFileSync(path.join(root, 'plugin', 'divi5-builder', 'divi5-builder.php'), 'utf8').slice(0, 8192).match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1];
// PHP's version_compare for the dotted numeric versions the plugin uses (1.2.10 > 1.2.9; 1.2 < 1.2.0): -1, 0 or 1.
function versionCompare(a, b) {
  const pa = String(a).split('.'), pb = String(b).split('.');
  if (![...pa, ...pb].every(x => /^\d+$/.test(x))) return NaN; // not a plain version: never "newer"
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    if (i >= pa.length) return -1;
    if (i >= pb.length) return 1;
    const x = Number(pa[i]), y = Number(pb[i]);
    if (x !== y) return x < y ? -1 : 1;
  }
  return 0;
}
const build =(sub, extra = [], root = REPO) => {
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
  // 2.5.0 final review: only a release build (--tag) writes the repository's plugin/update.json, so main's sha256 never
  // drifts away from the released asset when someone builds a zip to look at it.
  check('update.json: a build without --tag leaves plugin/update.json byte-identical (it writes the json into --out only)', fs.existsSync(repoJsonFile) && fs.readFileSync(repoJsonFile).equals(REPO_JSON_BEFORE), { before: REPO_JSON_BEFORE.toString(), after: fs.existsSync(repoJsonFile) ? fs.readFileSync(repoJsonFile, 'utf8') : null });
  { const R = repoCopy('tagged'); const o = build('tagged-out', ['--tag', 'v2099.03.04'], R); const rj = path.join(R, 'plugin', 'update.json');
    check('update.json: a --tag build writes the same json to --out and to plugin/update.json', fs.existsSync(rj) && fs.existsSync(jpath(o)) && fs.readFileSync(jpath(o)).equals(fs.readFileSync(rj)) && /"tag": "v2099\.03\.04"/.test(fs.readFileSync(rj, 'utf8')), fs.existsSync(rj) ? fs.readFileSync(rj, 'utf8') : null); }
  // The release rule, applied to the committed json: the same version as the header means the sha256 of a build of this
  // commit (a plugin change without a version bump fails here, on purpose); another version must be OLDER than the header
  // (the bump is made, the release build not yet run), and then the sha256 is not compared.
  const committed = path.join(ROOT, 'plugin', 'update.json');
  const ruleOn = (jsonText, headerVersion, builtSha) => {
    let c; try { c = JSON.parse(jsonText); } catch (e) { return 'the committed json is not JSON'; }
    if (!c || typeof c.version !== 'string') return 'the committed json has no version';
    if (c.version === headerVersion) return c.sha256 === builtSha ? '' : 'same version ' + c.version + ' as the header but sha256 ' + c.sha256 + ' is not that of a build of this commit (' + builtSha + '): bump the version and build with --tag';
    return versionCompare(headerVersion, c.version) > 0 ? '' : 'the header version ' + headerVersion + ' is not newer than the committed json\'s ' + c.version;
  };
  const shaOf = d => crypto.createHash('sha256').update(fs.readFileSync(path.join(d, 'divi5-builder.zip'))).digest('hex');
  { const why = fs.existsSync(committed) ? ruleOn(fs.readFileSync(committed, 'utf8'), hdrOf(ROOT), crypto.createHash('sha256').update(za).digest('hex')) : 'no plugin/update.json';
    check('update.json: the committed plugin/update.json follows the release rule (same version: the sha256 of a build of this commit; else the header is newer)', why === '', why); }
  { // the same version, a plugin file changed without a bump: the rule fails
    const R = repoCopy('rule-same'); build('rule-same-tag', ['--tag', 'v2099.05.06'], R);
    const jt = fs.readFileSync(path.join(R, 'plugin', 'update.json'), 'utf8');
    check('release rule: a copy just built with --tag passes (same version, same sha256)', ruleOn(jt, hdrOf(R), shaOf(build('rule-same-1', [], R))) === '');
    fs.appendFileSync(path.join(R, 'plugin', 'divi5-builder', 'readme.txt'), '\nchanged without a version bump\n');
    const why = ruleOn(jt, hdrOf(R), shaOf(build('rule-same-2', [], R)));
    check('release rule: a plugin change without a version bump fails (same version, another sha256)', /same version/.test(why), why); }
  { // a newer header (the bump is made): the sha256 is not compared
    const R = repoCopy('rule-newer'); const jt = fs.readFileSync(path.join(R, 'plugin', 'update.json'), 'utf8'); const cv = JSON.parse(jt).version;
    const bumped = cv.split('.').map((x, i, a) => i === a.length - 1 ? String(Number(x) + 1) : x).join('.');
    const mf = path.join(R, 'plugin', 'divi5-builder', 'divi5-builder.php'); fs.writeFileSync(mf, fs.readFileSync(mf, 'utf8').replace(/^(\s*\*\s*Version:\s*)\S+/m, '$1' + bumped));
    const why = ruleOn(jt, hdrOf(R), shaOf(build('rule-newer-out', [], R)));
    check('release rule: a header newer than the committed json passes, the sha256 not compared (' + cv + ' -> ' + bumped + ')', hdrOf(R) === bumped && why === '', why);
    check('release rule: version_compare semantics, 2.5.10 is newer than 2.5.9 (not string order)', ruleOn(JSON.stringify({ version: '2.5.9', sha256: 'x' }), '2.5.10', 'y') === '' && ruleOn(JSON.stringify({ version: '2.5.10', sha256: 'x' }), '2.5.9', 'y') !== ''); }
  { // an older header than the committed json: fails
    const R = repoCopy('rule-older'); const jt = fs.readFileSync(path.join(R, 'plugin', 'update.json'), 'utf8');
    const mf = path.join(R, 'plugin', 'divi5-builder', 'divi5-builder.php'); fs.writeFileSync(mf, fs.readFileSync(mf, 'utf8').replace(/^(\s*\*\s*Version:\s*)\S+/m, '$10.0.1'));
    const why = ruleOn(jt, hdrOf(R), shaOf(build('rule-older-out', [], R)));
    check('release rule: a header OLDER than the committed json fails', /not newer/.test(why), why); }
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
  const t3a = today(); const c3 = build('c3', ['--tag', 'v2099.01.02.3'], R2); const t3b = today(); const J3 = fs.existsSync(jpath(c3)) ? JSON.parse(fs.readFileSync(jpath(c3), 'utf8')) : {};
  check('update.json: a new --tag sets tag, both URLs and last_updated = today (UTC)', J3.tag === 'v2099.01.02.3' && J3.download_url === 'https://github.com/doughoseck/divi5-builder/releases/download/v2099.01.02.3/divi5-builder.zip' && J3.changelog_url === 'https://github.com/doughoseck/divi5-builder/releases/tag/v2099.01.02.3' && [t3a, t3b].includes(J3.last_updated) && fs.readFileSync(j2, 'utf8') === fs.readFileSync(jpath(c3), 'utf8'), J3);
  const c4 = build('c4', [], R2); const J4 = fs.existsSync(jpath(c4)) ? JSON.parse(fs.readFileSync(jpath(c4), 'utf8')) : {};
  check('update.json: the next build without --tag reuses the tag now in plugin/update.json', J4.tag === 'v2099.01.02.3', J4.tag);
  if (fs.existsSync(j2)) { const o = JSON.parse(fs.readFileSync(j2, 'utf8')); o.version = '0.0.1'; o.last_updated = '2000-01-01'; fs.writeFileSync(j2, JSON.stringify(o, null, 2) + '\n'); }
  const t5a = today(); const c5 = build('c5', [], R2); const t5b = today(); const J5 = fs.existsSync(jpath(c5)) ? JSON.parse(fs.readFileSync(jpath(c5), 'utf8')) : {};
  check('update.json: a version change sets version from the header and last_updated = today', J5.version === J.version && [t5a, t5b].includes(J5.last_updated), J5);

  // tested comes from the readme's Tested up to when there is one (the 7.1 fallback is checked above).
  { const R = repoCopy('tested'); const rf = path.join(R, 'plugin', 'divi5-builder', 'readme.txt');
    fs.writeFileSync(rf, fs.readFileSync(rf, 'utf8').replace(/^(Requires PHP:[^\n]*\n)/m, '$1Tested up to: 6.9\n'));
    const o = build('tested-out', [], R); const JT = fs.existsSync(jpath(o)) ? JSON.parse(fs.readFileSync(jpath(o), 'utf8')) : {};
    check('update.json: a readme with Tested up to: 6.9 gives tested 6.9', /^Tested up to: 6\.9\r?$/m.test(fs.readFileSync(rf, 'utf8')) && JT.tested === '6.9', JT.tested); }

  // A broken committed json or a missing Requires header STOPs before anything is written.
  const stopCase = (label, edit, args = [], says = /STOP/) => {
    const R = repoCopy('stop-' + n); edit(R); const jf = path.join(R, 'plugin', 'update.json'); const before = fs.existsSync(jf) ? fs.readFileSync(jf) : null;
    const o = path.join(tmp, 'stopout-' + n); const r = run(R, ['--out', o, ...args]);
    check(label + ' STOPs (exit 1), plugin/update.json unchanged, no out folder', r.status === 1 && /STOP/.test(r.stderr) && says.test(r.stderr) && !fs.existsSync(o) && before !== null && fs.readFileSync(jf).equals(before), { status: r.status, stderr: r.stderr, out: fs.existsSync(o) });
  };
  const mainOf = R => path.join(R, 'plugin', 'divi5-builder', 'divi5-builder.php');
  const dropLine = key => R => { const s = fs.readFileSync(mainOf(R), 'utf8'); const t = s.replace(new RegExp('^[^\\n]*' + key + ':[^\\n]*\\n', 'm'), ''); if (t === s) throw new Error('no ' + key + ' line to remove'); fs.writeFileSync(mainOf(R), t); };
  stopCase('a committed plugin/update.json that is not JSON ("{")', R => fs.writeFileSync(path.join(R, 'plugin', 'update.json'), '{'), [], /not valid JSON/);
  stopCase('a committed plugin/update.json that is not JSON ("{"), even with a good --tag,', R => fs.writeFileSync(path.join(R, 'plugin', 'update.json'), '{'), ['--tag', 'v2026.11.05'], /not valid JSON/);
  stopCase('a committed plugin/update.json with a tag of the wrong form ({"tag":"bad"}) and no --tag', R => fs.writeFileSync(path.join(R, 'plugin', 'update.json'), '{"tag":"bad"}'));
  stopCase('a main file without its Requires at least: line', dropLine('Requires at least'));
  stopCase('a main file without its Requires PHP: line', dropLine('Requires PHP'));

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
  check('the zip header says version 2.5.0, as the constant and the readme do', /Version: 2\.5\.0\b/.test(main0(files)) && /D5B_VERSION', '2\.5\.0'/.test(main0(files)) && /Stable tag: 2\.5\.0\b/.test(files.find(e => e.name === 'divi5-builder/readme.txt').data.toString()));
  check('every name sits under divi5-builder/', names.every(x => x.startsWith('divi5-builder/')), names);
  check('no old plugin, no assets/, no must-use loader in the zip', !names.some(x => /legacy|assets|mu-loader|divi5-builder-rest|wp-media|wp-site-updates|divi5-builder-loader/.test(x)), names);
  const body = names.filter(x => x !== 'divi5-builder/LICENSE');
  check('names are sorted, LICENSE last', JSON.stringify(body) === JSON.stringify([...body].sort()) && names[names.length - 1] === 'divi5-builder/LICENSE', names);
  check('every entry has the fixed 1980-01-01 timestamp', es.every(e => e.time === 0 && e.date === ((0 << 9) | (1 << 5) | 1)), es.map(e => [e.name, e.time, e.date]));
  const main = files.find(e => e.name === 'divi5-builder/divi5-builder.php');
  check('the main file in the zip is the loader, with LF endings', main && /Plugin Name: Divi 5 Builder/.test(main.data.toString()) && !main.data.includes('\r'));
  const same = files.filter(e => e.name !== 'divi5-builder/LICENSE').every(e => fs.existsSync(path.join(ROOT, 'plugin', e.name)) && e.data.toString('utf8') === fs.readFileSync(path.join(ROOT, 'plugin', e.name), 'utf8').replace(/\r\n/g, '\n'));
  check('each file in the zip equals its source (LF-normalised)', same);
  const lic = files.find(e => e.name === 'divi5-builder/LICENSE');
  check('LICENSE in the zip has LF endings (whatever the checkout has)', !!lic && !lic.data.includes('\r') && lic.data.toString('utf8') === fs.readFileSync(path.join(ROOT, 'LICENSE'), 'utf8').replace(/\r\n/g, '\n'));

  // --- line endings by content (2.5.0 final review): every file without a NUL byte is text and gets LF, whatever its name ---
  { const toLF = b => Buffer.from(b.toString('latin1').replace(/\r\n/g, '\n'), 'latin1'), toCRLF = b => Buffer.from(toLF(b).toString('latin1').replace(/\n/g, '\r\n'), 'latin1');
    const BIN = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x0d, 0x0a, 0xff]); // a PNG-like head: CRLF and NUL bytes
    const L = repoCopy('eol-lf'), C = repoCopy('eol-crlf');
    for (const [R, conv] of [[L, toLF], [C, toCRLF]]) {
      fs.writeFileSync(path.join(R, 'LICENSE'), conv(fs.readFileSync(path.join(ROOT, 'LICENSE'))));
      fs.writeFileSync(path.join(R, 'plugin', 'divi5-builder', '.htaccess'), conv(Buffer.from('# no extension, not in any list\nDeny from all\n')));
      fs.writeFileSync(path.join(R, 'plugin', 'divi5-builder', 'notes'), conv(Buffer.from('caf\xe9 in latin-1\nline two\n', 'latin1')));
      fs.writeFileSync(path.join(R, 'plugin', 'divi5-builder', 'pixel.png'), BIN);
      for (const rel of walk(path.join(R, 'plugin', 'divi5-builder'), '')) { const f = path.join(R, 'plugin', 'divi5-builder', rel); if (!fs.readFileSync(f).includes(0)) fs.writeFileSync(f, conv(fs.readFileSync(f))); }
    }
    check('premise: the CRLF copy holds CRLF in LICENSE and .htaccess, the LF copy none', fs.readFileSync(path.join(C, 'LICENSE')).includes('\r\n') && fs.readFileSync(path.join(C, 'plugin', 'divi5-builder', '.htaccess')).includes('\r\n') && !fs.readFileSync(path.join(L, 'LICENSE')).includes('\r'));
    const zl = fs.readFileSync(path.join(build('eol-lf-out', [], L), 'divi5-builder.zip')), zc = fs.readFileSync(path.join(build('eol-crlf-out', [], C), 'divi5-builder.zip'));
    check('a copy with LICENSE, .htaccess and every text file in CRLF builds the same zip bytes as the LF copy', zl.equals(zc));
    const ec = entries(zc); const got = n => (ec.find(e => e.name === 'divi5-builder/' + n) || {}).data;
    check('a file holding a NUL byte is binary: its CR LF bytes are kept as they are', !!got('pixel.png') && got('pixel.png').equals(BIN), got('pixel.png'));
    check('a text file that is not UTF-8 (latin-1) keeps its bytes apart from the line ends', !!got('notes') && got('notes').equals(Buffer.from('caf\xe9 in latin-1\nline two\n', 'latin1')), got('notes')); }

  // --- only what git tracks (2.5.0 final review): a checkout with .git zips git ls-files, and STOPs on anything else ---
  { const G = repoCopy('git'); const git = (...a) => execFileSync('git', a, { cwd: G, stdio: 'pipe' });
    git('init', '-q'); git('config', 'core.autocrlf', 'false'); git('config', 'user.name', 'test'); git('config', 'user.email', 'test@example.invalid'); git('config', 'commit.gpgsign', 'false');
    git('add', '-A'); git('commit', '-q', '-m', 'copy');
    const zg = fs.readFileSync(path.join(build('git-out', [], G), 'divi5-builder.zip'));
    check('a git checkout with a clean, tracked tree builds the same bytes as the walk build', zg.equals(za));
    const gitStop = (label, edit, says) => {
      edit(); const jf = path.join(G, 'plugin', 'update.json'); const before = fs.readFileSync(jf);
      const o = path.join(tmp, 'gitstop-' + n); const r = run(G, ['--out', o, '--tag', 'v2099.07.08']);
      check(label + ': STOP (exit 1) naming it, nothing written (no out folder, plugin/update.json unchanged)', r.status === 1 && /STOP/.test(r.stderr) && says.test(r.stderr) && !fs.existsSync(o) && fs.readFileSync(jf).equals(before), { status: r.status, stderr: r.stderr, out: fs.existsSync(o) });
    };
    const thumbs = path.join(G, 'plugin', 'divi5-builder', 'Thumbs.db');
    gitStop('an untracked plugin/divi5-builder/Thumbs.db', () => fs.writeFileSync(thumbs, 'x'), /plugin\/divi5-builder\/Thumbs\.db/);
    fs.unlinkSync(thumbs);
    const deep = path.join(G, 'plugin', 'divi5-builder', 'inc', 'new', 'stray.php');
    gitStop('an untracked file in an untracked folder', () => { fs.mkdirSync(path.dirname(deep), { recursive: true }); fs.writeFileSync(deep, '<?php'); }, /plugin\/divi5-builder\/inc\/new\/stray\.php/);
    fs.rmSync(path.dirname(deep), { recursive: true, force: true });
    gitStop('an ignored file (.gitignore *.log, debug.log)', () => { fs.writeFileSync(path.join(G, '.gitignore'), '*.log\n'); git('add', '.gitignore'); git('commit', '-q', '-m', 'ignore'); fs.writeFileSync(path.join(G, 'plugin', 'divi5-builder', 'debug.log'), 'x'); }, /plugin\/divi5-builder\/debug\.log/);
    fs.unlinkSync(path.join(G, 'plugin', 'divi5-builder', 'debug.log'));
    const rf = path.join(G, 'plugin', 'divi5-builder', 'readme.txt'); const rbefore = fs.readFileSync(rf);
    gitStop('a tracked file changed and not committed', () => fs.appendFileSync(rf, '\nx\n'), /plugin\/divi5-builder\/readme\.txt/);
    fs.writeFileSync(rf, rbefore);
    const r = run(G, ['--out', path.join(tmp, 'git-clean-again')]);
    check('premise: the git copy is clean again and builds', r.status === 0, r.stderr);
    const NG = repoCopy('notgit'); fs.mkdirSync(path.join(NG, '.git'));
    const no = path.join(tmp, 'notgit-out'); const rn = run(NG, ['--out', no]); const zf = path.join(no, 'divi5-builder.zip');
    check('a .git folder that is not a repository (git exits 128): the walk build, same bytes', rn.status === 0 && fs.existsSync(zf) && fs.readFileSync(zf).equals(za), { status: rn.status, stderr: rn.stderr }); }
} finally {
  fs.rmSync(tmp, { recursive: true, force: true });
}
console.log(failed ? `\n${failed} of ${n} FAILED` : `\nall ${n} passed`);
process.exit(failed ? 1 : 0);
