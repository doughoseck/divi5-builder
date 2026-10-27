#!/usr/bin/env node
/**
 * build-plugin-zips.js: package the divi5-builder plugin (plugin/divi5-builder/) as a normal WordPress
 * plugin zip, so it can be installed from the admin (Plugins > Add New > Upload Plugin).
 *
 *   node scripts/build-plugin-zips.js [--out dist]
 *
 * The zip holds every file of plugin/divi5-builder/ under divi5-builder/ (so divi5-builder/divi5-builder.php,
 * inc/, parts/, readme.txt) plus LICENSE: the layout WordPress expects. Reproducible: sorted names, fixed
 * timestamps, LF line endings. Node built-ins only (zlib + a small zip writer below).
 * The single-file plugins in assets/legacy/ are no longer packaged.
 * Next to the zip it writes divi5-builder-loader.php (from plugin/mu-loader/, LF endings): the second release
 * file, for a must-use install.
 * Prints the zip with the plugin version read from its header, then the loader.
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = process.env.D5B_REPO_ROOT || path.join(__dirname, '..'); // the override is for the mutation test
const outArg = process.argv.indexOf('--out');
const OUT = path.resolve(outArg > -1 ? process.argv[outArg + 1] : path.join(ROOT, 'dist'));

// --- minimal zip writer (deflate, no encryption, no zip64: plugin files are small) ------------
const CRC = (() => { const t = new Int32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c; } return t; })();
const crc32 = buf => { let c = -1; for (const b of buf) c = CRC[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ -1) >>> 0; };
function dosTime(d) {
  return { time: (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1), date: ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate() };
}
// Reproducible: every entry gets the same fixed time (1980-01-01 00:00, the zip epoch), so building
// the same source twice gives byte-identical zips, and anyone can rebuild and compare with a release.
const FIXED_TIME = new Date(1980, 0, 1, 0, 0, 0);
function zip(entries) { // entries: [{ name, data: Buffer }]; folders are implied by the names
  const locals = []; const centrals = []; let offset = 0; const { time, date } = dosTime(FIXED_TIME);
  const all = [];
  // WordPress and most unzip tools are happy without explicit folder entries, but add them anyway.
  const dirs = new Set(); for (const e of entries) { const parts = e.name.split('/'); for (let i = 1; i < parts.length; i++) dirs.add(parts.slice(0, i).join('/') + '/'); }
  for (const d of [...dirs].sort()) all.push({ name: d, data: Buffer.alloc(0), dir: true });
  all.push(...entries);
  for (const e of all) {
    const name = Buffer.from(e.name, 'utf8');
    const raw = e.data; const comp = e.dir ? raw : zlib.deflateRawSync(raw, { level: 9 });
    const method = e.dir ? 0 : 8; const crc = crc32(raw);
    const lh = Buffer.alloc(30);
    lh.writeUInt32LE(0x04034b50, 0); lh.writeUInt16LE(20, 4); lh.writeUInt16LE(0x0800, 6); lh.writeUInt16LE(method, 8);
    lh.writeUInt16LE(time, 10); lh.writeUInt16LE(date, 12); lh.writeUInt32LE(crc, 14); lh.writeUInt32LE(comp.length, 18);
    lh.writeUInt32LE(raw.length, 22); lh.writeUInt16LE(name.length, 26); lh.writeUInt16LE(0, 28);
    locals.push(lh, name, comp);
    const ch = Buffer.alloc(46);
    ch.writeUInt32LE(0x02014b50, 0); ch.writeUInt16LE(0x031e, 4); ch.writeUInt16LE(20, 6); ch.writeUInt16LE(0x0800, 8); ch.writeUInt16LE(method, 10);
    ch.writeUInt16LE(time, 12); ch.writeUInt16LE(date, 14); ch.writeUInt32LE(crc, 16); ch.writeUInt32LE(comp.length, 20); ch.writeUInt32LE(raw.length, 24);
    ch.writeUInt16LE(name.length, 28); ch.writeUInt16LE(0, 30); ch.writeUInt16LE(0, 32); ch.writeUInt16LE(0, 34); ch.writeUInt16LE(0, 36);
    ch.writeUInt32LE(e.dir ? (0o40755 << 16) | 0x10 : (0o100644 << 16) >>> 0, 38); ch.writeUInt32LE(offset, 42);
    centrals.push(ch, name);
    offset += lh.length + name.length + comp.length;
  }
  const cd = Buffer.concat(centrals);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(0, 4); end.writeUInt16LE(0, 6); end.writeUInt16LE(all.length, 8); end.writeUInt16LE(all.length, 10);
  end.writeUInt32LE(cd.length, 12); end.writeUInt32LE(offset, 16); end.writeUInt16LE(0, 20);
  return Buffer.concat([...locals, cd, end]);
}

// --- license -------------------------------------------------------------------------------------
const licenseFile = path.join(ROOT, 'LICENSE');
const license = fs.existsSync(licenseFile) ? fs.readFileSync(licenseFile) : null;
if (!license) console.error('note: no LICENSE in the repository root, the zip will not hold one');

// --- build ---------------------------------------------------------------------------------------
// One zip, divi5-builder.zip, from plugin/divi5-builder/ (every file, sorted, under divi5-builder/) plus LICENSE.
// The old single-file plugins in assets/legacy/ are no longer packaged.
const SLUG = 'divi5-builder';
const PLUGIN_DIR = path.join(ROOT, 'plugin', SLUG);
const MAIN = path.join(PLUGIN_DIR, `${SLUG}.php`);
if (!fs.existsSync(MAIN)) { console.error(`STOP: ${path.relative(ROOT, MAIN)} not found`); process.exit(1); }
const mainHead = fs.readFileSync(MAIN, 'utf8').slice(0, 8192);
if (!/Plugin Name:/.test(mainHead)) { console.error(`STOP: ${SLUG}.php has no Plugin Name: header`); process.exit(1); }
const version = (mainHead.match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1];
if (!version) { console.error(`STOP: ${SLUG}.php has no Version: header`); process.exit(1); }

function walk(dir, rel) { // every file under dir, as forward-slash paths relative to the plugin folder
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const r = rel ? `${rel}/${e.name}` : e.name;
    if (e.isDirectory()) out = out.concat(walk(path.join(dir, e.name), r));
    else if (e.isFile()) out.push(r);
  }
  return out;
}
const TEXT = /\.(php|txt|md|json|js|css|html)$/i;
const names = walk(PLUGIN_DIR, '').sort();
const files = names.map(rel => {
  let data = fs.readFileSync(path.join(PLUGIN_DIR, rel));
  // Normalise line endings to LF in text files so the zip is the same whatever the checkout's core.autocrlf.
  if (TEXT.test(rel)) data = Buffer.from(data.toString('utf8').replace(/\r\n/g, '\n'), 'utf8');
  return { name: `${SLUG}/${rel}`, data };
});
if (license) files.push({ name: `${SLUG}/LICENSE`, data: license });
fs.mkdirSync(OUT, { recursive: true });
const out = path.join(OUT, `${SLUG}.zip`);
fs.writeFileSync(out, zip(files));
console.log(`${path.relative(ROOT, out).replace(/\\/g, '/')}  ${SLUG} ${version}  (${fs.statSync(out).size} bytes, ${files.length} files)`);

// The second release file: the must-use loader, published next to the zip so a must-use install needs no repository.
// Copied as it is (LF line endings), so it is the same byte for byte on every build.
const LOADER = path.join(ROOT, 'plugin', 'mu-loader', `${SLUG}-loader.php`);
if (!fs.existsSync(LOADER)) { console.error(`STOP: ${path.relative(ROOT, LOADER)} not found`); process.exit(1); }
const loaderOut = path.join(OUT, `${SLUG}-loader.php`);
fs.writeFileSync(loaderOut, Buffer.from(fs.readFileSync(LOADER, 'utf8').replace(/\r\n/g, '\n'), 'utf8'));
console.log(`${path.relative(ROOT, loaderOut).replace(/\\/g, '/')}  the must-use loader  (${fs.statSync(loaderOut).size} bytes)`);
