#!/usr/bin/env node
/**
 * vb-resave-check.js: does opening a page in the Visual Builder and saving it change it? (issue #21)
 *
 *   node vb-resave-check.js snapshot <site> <page_id> --dir D --as before|after
 *   node vb-resave-check.js compare --dir D [--json]
 *
 * The procedure (run it once per new Divi release, see references/divi-release-checklist.md):
 *   1. Build a THROWAWAY DRAFT through the REST API (divi.js compile, wp.js create-page --status draft).
 *   2. node vb-resave-check.js snapshot <site> <id> --dir out --as before
 *   3. The person opens the draft in the Visual Builder and clicks Save WITHOUT changing anything.
 *   4. node vb-resave-check.js snapshot <site> <id> --dir out --as after
 *   5. node vb-resave-check.js compare --dir out
 *
 * snapshot is read-only (wp.js get-page and wp.js rendered; credentials never pass through this script). It writes
 *   D/<as>.html            the stored content, byte for byte
 *   D/<as>-rendered.html   the server-rendered HTML
 *   D/<as>.meta.json       page id, content hash, when
 *
 * compare reports, in this order:
 *   - the block tree: every address and block name
 *   - every attribute path, compared as DATA (ADDED, REMOVED, CHANGED, with values)
 *   - the text outside the block comments
 *   - the rendered HTML, after whitespace between tags, before closing tags and runs of spaces are normalised
 *     (so a space between two inline tags is not told apart from none: both sides are normalised alike)
 *   then, separately, "formatting only": key order, open/close pairs saved as self-closing blocks, whitespace
 *   between block comments. These change the stored text and so the content hash, but nothing the page shows.
 *
 * Exit 0: content, tree and render unchanged (formatting-only changes allowed, and listed).
 * Exit 1: the data, the tree, the text outside the comments or the render changed.
 * Exit 2: usage, or a file could not be read or parsed.
 *
 * A changed hash after a no-change save is EXPECTED: the stale guard (wp.js --expect-hash) then reports STALE, which
 * is correct, because the stored text did change. Re-read the page before the next write.
 */
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const { execFileSync } = require('child_process');
const B = require('./blocks.js');
const md5 = (s) => crypto.createHash('md5').update(String(s), 'utf8').digest('hex');
const die = (m, code = 2) => { process.stderr.write(m + '\n'); process.exit(code); };

// ---- arguments ---------------------------------------------------------------------------------------------------
const argv = process.argv.slice(2);
const flags = {}, pos = [];
for (let i = 0; i < argv.length; i++) {
  if (argv[i] === '--json') flags.json = true;
  else if (argv[i] === '--dir' || argv[i] === '--as') flags[argv[i].slice(2)] = argv[++i];
  else pos.push(argv[i]);
}
const USAGE = 'usage:\n  node vb-resave-check.js snapshot <site> <page_id> --dir D --as before|after\n  node vb-resave-check.js compare --dir D [--json]';

// ---- snapshot ----------------------------------------------------------------------------------------------------
function snapshot() {
  const [, site, id] = pos;
  if (!site || !/^\d+$/.test(id || '') || !flags.dir || !/^(before|after)$/.test(flags.as || '')) die(USAGE);
  fs.mkdirSync(flags.dir, { recursive: true });
  const wp = (args) => execFileSync(process.execPath, [path.join(__dirname, 'wp.js'), site, ...args], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  const file = path.join(flags.dir, flags.as + '.html');
  let info;
  try {
    info = JSON.parse(wp(['get-page', id, '--out', file]));
    fs.writeFileSync(path.join(flags.dir, flags.as + '-rendered.html'), wp(['rendered', id]), 'utf8');
  } catch (e) { die('snapshot failed: ' + String(e.stderr || e.message).trim().split('\n').slice(-3).join(' | ')); }
  const stored = fs.readFileSync(file, 'utf8');
  const meta = { pageId: Number(id), as: flags.as, hash: md5(stored), contentLength: stored.length, status: info.status, at: new Date().toISOString() };
  fs.writeFileSync(path.join(flags.dir, flags.as + '.meta.json'), JSON.stringify(meta, null, 2) + '\n');
  console.log(`saved ${flags.as}: ${stored.length} chars, hash ${meta.hash}, page status "${info.status}" -> ${flags.dir}`);
  if (info.status !== 'draft') console.log('note: this page is not a draft. Snapshots only read, but a Visual Builder test belongs on a throwaway draft.');
}

// ---- compare -----------------------------------------------------------------------------------------------------
const flat = (o, p = '', out = {}) => {
  if (Array.isArray(o)) { if (!o.length) out[p] = '[]'; o.forEach((v, i) => flat(v, `${p}[${i}]`, out)); }
  else if (o && typeof o === 'object') { const k = Object.keys(o); if (!k.length) out[p] = '{}'; for (const x of k) flat(o[x], p ? p + '.' + x : x, out); }
  else out[p] = JSON.stringify(o);
  return out;
};
const clip = (s, n = 110) => (String(s).length > n ? String(s).slice(0, n) + '...' : String(s));
const normRendered = (s) => s.replace(/\r\n?/g, '\n').replace(/\s+/g, ' ').replace(/>\s+</g, '><').replace(/\s+(<\/)/g, '$1').replace(/>\s+/g, '>').trim();

// Comment tokens of a parsed page (every block, Divi or not), in order: the gaps between them are the text between blocks.
function tokens(root) {
  const t = [];
  const go = (b) => { for (const c of b.children) { t.push([c.start, c.openEnd]); go(c); if (!c.selfClosing) t.push([c.innerEnd, c.end]); } };
  go(root);
  return t.sort((a, b) => a[0] - b[0]);
}
function gaps(src, root) {
  const t = tokens(root), out = []; let at = 0;
  for (const [s, e] of t) { out.push(src.slice(at, s)); at = e; }
  out.push(src.slice(at));
  return out;
}
// Key order of every object in an attribute tree: how many objects list the same keys in a different order.
function keyOrder(a, z, acc, top) {
  if (a && z && typeof a === 'object' && typeof z === 'object' && !Array.isArray(a) && !Array.isArray(z)) {
    const ka = Object.keys(a), kz = Object.keys(z);
    const common = ka.filter((k) => k in z);
    if (common.join('\u0000') !== kz.filter((k) => k in a).join('\u0000')) {
      if (top) { acc.top++; const key = `${ka[ka.length - 1]} -> ${kz[kz.length - 1]}`; acc.last[key] = (acc.last[key] || 0) + 1; } else acc.nested++;
    }
    for (const k of common) keyOrder(a[k], z[k], acc, false);
  } else if (Array.isArray(a) && Array.isArray(z)) a.forEach((v, i) => i < z.length && keyOrder(v, z[i], acc, false));
}

function load(dir, as) {
  const read = (f) => { try { return fs.readFileSync(path.join(dir, f), 'utf8'); } catch (e) { die(`cannot read ${path.join(dir, f)}: run "snapshot ... --as ${as}" first`); } };
  const src = read(as + '.html'), rendered = read(as + '-rendered.html');
  const p = B.parse(src);
  if (p.errors.length) die(`${as}.html does not parse:\n  ` + p.errors.slice(0, 5).join('\n  '));
  return { src, rendered, root: p.root, list: B.walk(p.root) };
}

function compare() {
  if (!flags.dir) die(USAGE);
  const A = load(flags.dir, 'before'), Z = load(flags.dir, 'after');
  const res = { blocks: { before: A.list.length, after: Z.list.length }, hash: { before: md5(A.src), after: md5(Z.src) } };

  // 1. tree
  const ta = new Map(A.list.map((b) => [b.addr, b.name])), tz = new Map(Z.list.map((b) => [b.addr, b.name]));
  res.treeDiff = [];
  for (const [ad, n] of ta) { if (!tz.has(ad)) res.treeDiff.push(`${ad} ${n}: only in before`); else if (tz.get(ad) !== n) res.treeDiff.push(`${ad}: ${n} -> ${tz.get(ad)}`); }
  for (const [ad, n] of tz) if (!ta.has(ad)) res.treeDiff.push(`${ad} ${n}: only in after`);
  res.treeIdentical = !res.treeDiff.length;

  // 2. attributes, as data, by address
  const kinds = {};
  const za = new Map(Z.list.map((b) => [b.addr, b]));
  for (const a of A.list) {
    const z = za.get(a.addr); if (!z || z.name !== a.name) continue;
    const fa = flat(a.attrs || {}), fz = flat(z.attrs || {});
    for (const k of new Set([...Object.keys(fa), ...Object.keys(fz)])) {
      if (fa[k] === fz[k]) continue;
      const kind = !(k in fa) ? 'ADDED' : !(k in fz) ? 'REMOVED' : 'CHANGED';
      const p = k.replace(/\[\d+\]/g, '[]');
      const val = kind === 'CHANGED' ? `${clip(fa[k])} -> ${clip(fz[k])}` : kind === 'ADDED' ? `= ${clip(fz[k])}` : `(was ${clip(fa[k])})`;
      const key = `${kind} ${a.name} ${p} ${val}`;
      (kinds[key] = kinds[key] || { kind, block: a.name, path: p, value: val, count: 0, at: [] }).count++;
      if (kinds[key].at.length < 3) kinds[key].at.push(a.addr);
    }
  }
  res.data = Object.values(kinds).sort((x, y) => (x.kind + x.block + x.path).localeCompare(y.kind + y.block + y.path));

  // 3. text outside the block comments (core blocks keep their content there)
  const outside = (src, root) => gaps(src, root).join('').replace(/\s+/g, ' ').trim();
  const oa = outside(A.src, A.root), oz = outside(Z.src, Z.root);
  res.outsideIdentical = oa === oz;
  if (!res.outsideIdentical) res.outsideDiff = { before: clip(oa, 200), after: clip(oz, 200) };

  // 4. rendered
  const na = normRendered(A.rendered), nz = normRendered(Z.rendered);
  res.renderedIdentical = na === nz;
  if (!res.renderedIdentical) {
    let i = 0; while (i < na.length && na[i] === nz[i]) i++;
    let j = 0; while (j < na.length - i && j < nz.length - i && na[na.length - 1 - j] === nz[nz.length - 1 - j]) j++;
    res.renderedDiff = { before: na.slice(Math.max(0, i - 50), na.length - j + 20), after: nz.slice(Math.max(0, i - 50), nz.length - j + 20) };
  }

  // formatting only
  const ord = { top: 0, nested: 0, last: {} };
  let toSelf = 0, toPair = 0;
  for (const a of A.list) {
    const z = za.get(a.addr); if (!z || z.name !== a.name) continue;
    keyOrder(a.attrs || {}, z.attrs || {}, ord, true);
    if (!a.selfClosing && z.selfClosing) toSelf++; else if (a.selfClosing && !z.selfClosing) toPair++;
  }
  const ws = (src, root) => {
    const g = gaps(src, root).filter((x) => x.trim() === ''), shapes = {};
    for (const x of g) { const k = x.length ? JSON.stringify(x) : '(none)'; shapes[k] = (shapes[k] || 0) + 1; }
    return { gaps: g.filter((x) => x.length).length, chars: g.join('').length, lineBreaks: (g.join('').match(/\n/g) || []).length, shapes };
  };
  const wa = ws(A.src, A.root), wz = ws(Z.src, Z.root);
  res.formatting = { keyOrderBlocks: ord.top, keyOrderNested: ord.nested, lastKeyMoves: ord.last, toSelfClosing: toSelf, toOpenClose: toPair,
    whitespaceBetweenBlocks: { before: wa, after: wz, changed: wa.chars !== wz.chars || wa.gaps !== wz.gaps } };

  res.unchanged = res.treeIdentical && !res.data.length && res.outsideIdentical && res.renderedIdentical;
  if (flags.json) { console.log(JSON.stringify(res, null, 1)); process.exit(res.unchanged ? 0 : 1); }

  const L = [];
  L.push(`blocks: before ${res.blocks.before}, after ${res.blocks.after}`);
  L.push(`block tree identical: ${res.treeIdentical ? 'yes' : 'NO'}`);
  for (const d of res.treeDiff.slice(0, 20)) L.push('  ' + d);
  if (res.treeDiff.length > 20) L.push(`  ... and ${res.treeDiff.length - 20} more`);
  L.push(`attributes identical as data: ${res.data.length ? 'NO' : 'yes'}`);
  for (const d of res.data.slice(0, 60)) L.push(`${String(d.count).padStart(4)}x ${d.kind} ${d.block} ${d.path} ${d.value}   [at ${d.at.join(', ')}${d.count > d.at.length ? ', ...' : ''}]`);
  if (res.data.length > 60) L.push(`  ... and ${res.data.length - 60} more kinds`);
  L.push(`text outside the block comments identical: ${res.outsideIdentical ? 'yes' : 'NO'}`);
  if (!res.outsideIdentical) L.push(`  before: ${JSON.stringify(res.outsideDiff.before)}`, `  after:  ${JSON.stringify(res.outsideDiff.after)}`);
  L.push(`rendered HTML identical (whitespace between tags ignored): ${res.renderedIdentical ? 'yes' : 'NO'}`);
  if (!res.renderedIdentical) L.push(`  before: ${JSON.stringify(res.renderedDiff.before)}`, `  after:  ${JSON.stringify(res.renderedDiff.after)}`);
  const f = res.formatting, w = f.whitespaceBetweenBlocks;
  L.push('', 'formatting only (changes the stored text and its hash, not what the page holds or shows):');
  L.push(`  key order: ${f.keyOrderBlocks} block(s) list their attributes in another order` + (f.keyOrderNested ? `, ${f.keyOrderNested} nested object(s) too` : '') +
    (Object.keys(f.lastKeyMoves).length ? ` (last key before -> after: ${Object.entries(f.lastKeyMoves).map(([k, c]) => `${k} x${c}`).join(', ')})` : ''));
  L.push(`  open/close pair saved as self-closing: ${f.toSelfClosing}` + (f.toOpenClose ? `; self-closing saved as a pair: ${f.toOpenClose}` : ''));
  const shape = (s) => Object.entries(s).map(([k, c]) => `${c}x ${k}`).join(', ');
  L.push(`  whitespace between block comments${w.changed ? '' : ' (same)'}: ${w.before.chars} chars in ${w.before.gaps} gap(s) before, ${w.after.chars} in ${w.after.gaps} after`,
    `    before: ${shape(w.before.shapes)}`, `    after:  ${shape(w.after.shapes)}`);
  L.push(`stored content hash: ${res.hash.before === res.hash.after ? 'same (the text is byte-identical: was the page saved at all?)' : `changed (${res.hash.before} -> ${res.hash.after}); expected after a builder save, so wp.js will report STALE until the page is read again`}`);
  L.push('', res.unchanged ? 'RESULT: content, tree and render unchanged.' : 'RESULT: the re-save CHANGED content (see the NO lines above).');
  console.log(L.join('\n'));
  process.exit(res.unchanged ? 0 : 1);
}

if (pos[0] === 'snapshot') snapshot();
else if (pos[0] === 'compare') compare();
else die(USAGE);
