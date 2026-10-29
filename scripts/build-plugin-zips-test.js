// build-plugin-zips-test.js: the one zip, what is in it, and that it rebuilds byte for byte.
//   node scripts/build-plugin-zips-test.js
// Set BUILD_ZIPS_SCRIPT to test another copy of build-plugin-zips.js (used for mutation testing).
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const { execFileSync } = require('child_process');
const ROOT = path.join(__dirname, '..');
const SCRIPT = process.env.BUILD_ZIPS_SCRIPT || path.join(__dirname, 'build-plugin-zips.js');
let n = 0, failed = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail !== undefined ? '\n          ' + JSON.stringify(detail) : '')); if (!ok) failed++; };

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-zip-'));
const build = sub => {
  const out = path.join(tmp, sub);
  execFileSync(process.execPath, [SCRIPT, '--out', out], { stdio: 'pipe', env: { ...process.env, D5B_REPO_ROOT: ROOT } });
  return out;
};
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
  check('exactly two release files: divi5-builder.zip and divi5-builder-loader.php', JSON.stringify(fs.readdirSync(a).sort()) === JSON.stringify(['divi5-builder-loader.php', 'divi5-builder.zip']), fs.readdirSync(a));
  const za = fs.readFileSync(path.join(a, 'divi5-builder.zip')), zb = fs.readFileSync(path.join(b, 'divi5-builder.zip'));
  check('two builds are byte-identical', za.equals(zb));
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
  const abil = ['audit', 'categories', 'read-content', 'read-divi', 'read-history', 'read-site', 'read-structure', 'register', 'scrub', 'secrets'];
  check('holds every file of inc/abilities/ (2.1.0)', abil.every(f => names.includes('divi5-builder/inc/abilities/' + f + '.php')), names.filter(x => x.includes('/abilities/')));
  check('the zip header says version 2.1.0, as the constant and the readme do', /Version: 2\.1\.0\b/.test(main0(files)) && /D5B_VERSION', '2\.1\.0'/.test(main0(files)) && /Stable tag: 2\.1\.0\b/.test(files.find(e => e.name === 'divi5-builder/readme.txt').data.toString()));
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
  for (const d of ['a', 'b']) { const dir = path.join(tmp, d); if (fs.existsSync(dir)) { for (const f of fs.readdirSync(dir)) fs.unlinkSync(path.join(dir, f)); fs.rmdirSync(dir); } }
  fs.rmdirSync(tmp);
}
console.log(failed ? `\n${failed} of ${n} FAILED` : `\nall ${n} passed`);
process.exit(failed ? 1 : 0);
