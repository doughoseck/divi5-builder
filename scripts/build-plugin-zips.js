#!/usr/bin/env node
/**
 * build-plugin-zips.js: package each plugin in assets/ as a normal WordPress plugin zip, so it can
 * be installed from the admin (Plugins > Add New > Upload Plugin) instead of by FTP to mu-plugins.
 *
 *   node scripts/build-plugin-zips.js [--out dist]
 *
 * Each zip holds <slug>/<slug>.php plus LICENSE, which is the layout WordPress expects. Node built-ins
 * only (zlib + a small zip writer below), so it runs anywhere the rest of the skill runs.
 * Prints each zip with the plugin version read from its header.
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = path.join(__dirname, '..');
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

// --- build ---------------------------------------------------------------------------------------
fs.mkdirSync(OUT, { recursive: true });
const licenseFile = path.join(ROOT, 'LICENSE');
const license = fs.existsSync(licenseFile) ? fs.readFileSync(licenseFile) : null;
if (!license) console.error('note: no LICENSE next to assets/, the zips will hold the plugin file only');
const plugins = fs.readdirSync(path.join(ROOT, 'assets')).filter(f => f.endsWith('.php')).sort();
let built = 0;
for (const file of plugins) {
  const src = fs.readFileSync(path.join(ROOT, 'assets', file));
  const head = src.toString('utf8', 0, 8192);
  if (!/Plugin Name:/.test(head)) continue; // not a plugin
  const version = (head.match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1];
  if (!version) { console.error(`STOP: ${file} has no Version: header`); process.exit(1); }
  const slug = file.replace(/\.php$/, '');
  // Normalise line endings to LF so the zip is the same whatever the checkout's core.autocrlf.
  const php = Buffer.from(src.toString('utf8').replace(/\r\n/g, '\n'), 'utf8');
  const out = path.join(OUT, `${slug}.zip`);
  const files = [{ name: `${slug}/${slug}.php`, data: php }];
  if (license) files.push({ name: `${slug}/LICENSE`, data: license });
  fs.writeFileSync(out, zip(files));
  console.log(`${path.relative(ROOT, out).replace(/\\/g, '/')}  ${slug} ${version}  (${fs.statSync(out).size} bytes)`);
  built++;
}
if (!built) { console.error('no plugins found in assets/'); process.exit(1); }
