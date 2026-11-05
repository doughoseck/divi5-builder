#!/usr/bin/env node
/**
 * build-plugin-zips.js: package the divi5-builder plugin (plugin/divi5-builder/) as a normal WordPress
 * plugin zip, so it can be installed from the admin (Plugins > Add New > Upload Plugin).
 *
 *   node scripts/build-plugin-zips.js [--out dist] [--tag vYYYY.MM.DD[.N]]
 *
 * The zip holds every file of plugin/divi5-builder/ under divi5-builder/ (so divi5-builder/divi5-builder.php,
 * inc/, parts/, readme.txt) plus LICENSE: the layout WordPress expects. In a git checkout "every file" is what git
 * tracks (git ls-files), and an untracked, ignored or uncommitted file under plugin/divi5-builder (or LICENSE) STOPs
 * the build; outside one (git exits 128) every file on disk. Reproducible: sorted names, fixed timestamps, LF line
 * endings in every file without a NUL byte. Node built-ins and git only (zlib + a small zip writer below).
 * The single-file plugins in assets/legacy/ are no longer packaged.
 * Next to the zip it writes divi5-builder-loader.php (from plugin/mu-loader/, LF endings): the second release
 * file, for a must-use install.
 * Then update.json (slug, version, tag, download_url, sha256, requires, requires_php, tested, last_updated,
 * changelog_url), written next to the zip; a release build (--tag) also writes it into plugin/update.json, the copy
 * the GitHub updater reads from main. Without --tag the repository's copy is never touched (the json in --out keeps
 * the tag already in plugin/update.json, for inspection); with no tag at all, or a tag of the wrong form, the build
 * STOPs (exit 1) before writing anything.
 * Prints the zip with the plugin version read from its header, then the loader, then the json.
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

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
const header = key => (mainHead.match(new RegExp(`^\\s*\\*\\s*${key}:\\s*(\\S+)`, 'm')) || [])[1];
const version = header('Version');
if (!version) { console.error(`STOP: ${SLUG}.php has no Version: header`); process.exit(1); }
const requires = header('Requires at least'), requiresPhp = header('Requires PHP');
if (!requires || !requiresPhp) { console.error(`STOP: ${SLUG}.php needs both a Requires at least: and a Requires PHP: header (update.json carries them)`); process.exit(1); }
const readmeFile = path.join(PLUGIN_DIR, 'readme.txt');
const tested = (fs.existsSync(readmeFile) && (fs.readFileSync(readmeFile, 'utf8').match(/^Tested up to:\s*(\S+)/m) || [])[1]) || '7.1';

// --- the release tag, for update.json ------------------------------------------------------------
// --tag vYYYY.MM.DD[.N] names the release; without it the tag already in plugin/update.json goes into --out's json.
// Everything is checked here, before anything is written: a STOP leaves no zip and no json behind.
const TAG_FORM = /^v\d{4}\.\d{2}\.\d{2}(\.\d+)?$/;
const JSON_FILE = path.join(ROOT, 'plugin', 'update.json');
let prev = null;
if (fs.existsSync(JSON_FILE)) {
  try { prev = JSON.parse(fs.readFileSync(JSON_FILE, 'utf8')); } catch (e) { console.error(`STOP: plugin/update.json is not valid JSON (${e.message}); fix or delete it and build with --tag`); process.exit(1); }
}
const tagArg = process.argv.indexOf('--tag');
let tag;
if (tagArg > -1) {
  tag = process.argv[tagArg + 1];
  if (typeof tag !== 'string' || !TAG_FORM.test(tag)) { console.error(`STOP: --tag must look like v2026.11.05 or v2026.11.05.2 (got ${JSON.stringify(tag === undefined ? null : tag)}); nothing written`); process.exit(1); }
} else {
  tag = prev && prev.tag;
  if (!tag) { console.error('STOP: no --tag given and no tag in plugin/update.json; build with --tag vYYYY.MM.DD (the release you are about to create); nothing written'); process.exit(1); }
  if (typeof tag !== 'string' || !TAG_FORM.test(tag)) { console.error(`STOP: the tag in plugin/update.json (${JSON.stringify(tag)}) is not of the form v2026.11.05; build with --tag; nothing written`); process.exit(1); }
}

function walk(dir, rel) { // every file under dir, as forward-slash paths relative to the plugin folder
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const r = rel ? `${rel}/${e.name}` : e.name;
    if (e.isDirectory()) out = out.concat(walk(path.join(dir, e.name), r));
    else if (e.isFile()) out.push(r);
  }
  return out;
}
// --- which files: what git tracks, when ROOT is a git checkout ------------------------------------
// A release zip must hold the commit and nothing else: in a checkout the plugin files are git ls-files, and anything
// untracked, ignored or changed under plugin/divi5-builder (or LICENSE) STOPs the build before anything is written.
// Not a git repository (git exits 128 saying "not a git repository", as in the test's temp copies): every file found on
// disk (walk). Any other git failure (a corrupt index, "dubious ownership", git missing) STOPs with git's own message.
const PLUGIN_REL = `plugin/${SLUG}`;
function gitOut(args) {
  try { return execFileSync('git', args, { cwd: ROOT, stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 }); } catch (e) {
    if (e.status === 128 && /not a git repository/i.test(String(e.stderr || ''))) return null;
    console.error(`STOP: git ${args.join(' ')} failed (${e.code === 'ENOENT' ? 'git is not on PATH' : String(e.stderr || e.message).trim()}); nothing written`); process.exit(1);
  }
}
let names = null, licenseTracked = true;
if (fs.existsSync(path.join(ROOT, '.git'))) {
  const status = gitOut(['status', '--porcelain', '--ignored', '--untracked-files=all', '--', PLUGIN_REL, 'LICENSE']);
  if (status !== null) {
    const dirty = status.toString('utf8').split('\n').filter(l => l.trim() !== '');
    if (dirty.length) { console.error(`STOP: the release zip holds only what git tracks, and these are untracked, ignored or not committed:\n${dirty.map(l => '  ' + l).join('\n')}\ncommit or remove them (nothing written)`); process.exit(1); }
    // assume-unchanged (a lower-case tag) or skip-worktree ('S') hides an edit from git status: such a file is refused too.
    const flagged = gitOut(['ls-files', '-v', '-z', '--', PLUGIN_REL, 'LICENSE']);
    const hidden = flagged === null ? [] : flagged.toString('utf8').split('\0').filter(Boolean).filter(l => /^[a-z]/.test(l) || l[0] === 'S');
    if (hidden.length) { console.error(`STOP: these tracked files are marked assume-unchanged or skip-worktree, so git status cannot show an edit in them:\n${hidden.map(l => '  ' + l).join('\n')}\nclear the flag (git update-index --no-assume-unchanged / --no-skip-worktree) and commit (nothing written)`); process.exit(1); }
    const listed = gitOut(['ls-files', '-z', '--', PLUGIN_REL, 'LICENSE']);
    if (listed !== null) {
      const all = listed.toString('utf8').split('\0').filter(Boolean);
      names = all.filter(f => f.startsWith(PLUGIN_REL + '/')).map(f => f.slice(PLUGIN_REL.length + 1)).sort();
      licenseTracked = all.includes('LICENSE');
    }
  }
}
if (names === null) names = walk(PLUGIN_DIR, '').sort();
// Line endings by content: a file with no NUL byte is text and gets LF (bytes, not a re-encoding: a latin-1 file keeps its
// bytes), whatever its name (LICENSE, .htaccess); a file holding a NUL is binary and stays as it is. So the zip is the same
// whatever the checkout's core.autocrlf.
const lf = data => (data.includes(0) ? data : Buffer.from(data.toString('latin1').replace(/\r\n/g, '\n'), 'latin1'));
const files = names.map(rel => ({ name: `${SLUG}/${rel}`, data: lf(fs.readFileSync(path.join(PLUGIN_DIR, rel))) }));
if (license && licenseTracked) files.push({ name: `${SLUG}/LICENSE`, data: lf(license) });
// The second release file: the must-use loader, published next to the zip so a must-use install needs no repository.
// Copied as it is (LF line endings), so it is the same byte for byte on every build.
const LOADER = path.join(ROOT, 'plugin', 'mu-loader', `${SLUG}-loader.php`);
if (!fs.existsSync(LOADER)) { console.error(`STOP: ${path.relative(ROOT, LOADER)} not found`); process.exit(1); }

const zipBytes = zip(files);
// update.json: what the GitHub updater (2.5.0+) reads from main. Written from the zip just built, next to it
// and, on a --tag build, into plugin/update.json (committed with the version bump). NOT inside the zip: it describes the zip.
// last_updated changes only when version or tag change, so a rebuild from the same commit is byte-identical.
const keepDate = prev && prev.version === version && prev.tag === tag && typeof prev.last_updated === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(prev.last_updated);
const manifest = {
  slug: SLUG,
  version,
  tag,
  download_url: `https://github.com/doughoseck/divi5-builder/releases/download/${tag}/${SLUG}.zip`,
  sha256: crypto.createHash('sha256').update(zipBytes).digest('hex'),
  requires,
  requires_php: requiresPhp,
  tested,
  last_updated: keepDate ? prev.last_updated : new Date().toISOString().slice(0, 10),
  changelog_url: `https://github.com/doughoseck/divi5-builder/releases/tag/${tag}`,
};
const manifestBytes = Buffer.from(JSON.stringify(manifest, null, 2) + '\n', 'utf8');

fs.mkdirSync(OUT, { recursive: true });
const out = path.join(OUT, `${SLUG}.zip`);
fs.writeFileSync(out, zipBytes);
console.log(`${path.relative(ROOT, out).replace(/\\/g, '/')}  ${SLUG} ${version}  (${fs.statSync(out).size} bytes, ${files.length} files)`);

const loaderOut = path.join(OUT, `${SLUG}-loader.php`);
fs.writeFileSync(loaderOut, Buffer.from(fs.readFileSync(LOADER, 'utf8').replace(/\r\n/g, '\n'), 'utf8'));
console.log(`${path.relative(ROOT, loaderOut).replace(/\\/g, '/')}  the must-use loader  (${fs.statSync(loaderOut).size} bytes)`);

const jsonOut = path.join(OUT, 'update.json');
fs.writeFileSync(jsonOut, manifestBytes);
// Only a release build (--tag) writes the repository's copy: every site reads it from main, so its sha256 must stay the
// released asset's until the next release, however many zips are built in between to look at.
if (tagArg > -1) fs.writeFileSync(JSON_FILE, manifestBytes);
console.log(`${path.relative(ROOT, jsonOut).replace(/\\/g, '/')}${tagArg > -1 ? ' and plugin/update.json' : ' (plugin/update.json untouched: only a --tag build writes it)'}  ${version} ${tag}  sha256 ${manifest.sha256}${keepDate ? '' : '  (last_updated ' + manifest.last_updated + ')'}`);
