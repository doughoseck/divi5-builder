#!/usr/bin/env node
/*
 * media-quarantine.js — move unused files out of uploads into a holding folder, check the site, put back what is
 * needed. REVERSIBLE: nothing is deleted, the database is not touched. Works from a media-audit.js scan.
 *
 *   node media-quarantine.js plan    --dir D --batch B [--status UNUSED[,BACKGROUND]] [--csv list.csv]
 *                                    [--orphan-folders 2017,2018] [--include-referenced] [--ignore-tables a,b]
 *   node media-quarantine.js move    <site> --dir D --batch B [--write]
 *   node media-quarantine.js verify  <site> --dir D            # loads every public page and asks for every upload file on it
 *   node media-quarantine.js restore <site> --dir D --batch B (--all | --needed | --ids 1,2 | --files-from f.txt) [--write]
 *   node media-quarantine.js status  <site>
 *
 * Needs assets/wp-media-quarantine.php in wp-content/mu-plugins/ (the user uploads it) and a finished
 * `media-audit.js scan` + `crawl` in D. Files go to wp-content/media-audit-quarantine/<batch>/ with a manifest.
 *
 * The order that keeps it safe:
 *   1. verify            BEFORE moving: what is already missing (the baseline)
 *   2. plan              what would move, and what is held back and why. Read it.
 *   3. move              dry run; then --write
 *   4. verify            anything newly missing is listed, with the page that wants it -> restore --needed --write
 *   5. the owner uses the site for some days (pages, admin, e-mails, the server's 404 log)
 *   6. the owner deletes the quarantine folder (the permanent step), or restore --all --write
 *
 * plan: library items are chosen by status (from the scan) and, with --csv, by the id column of a reviewed list
 * (delete the rows you want to keep). All files of a chosen item move: its sizes, its original, and extra copies
 * on disk. --orphan-folders picks files with no library item under those folders of uploads.
 * Held back, always: a file that an item which is NOT chosen also owns or could own, and an orphan file that
 * something still refers to (unless --include-referenced).
 * A quarantined library item keeps its row: it shows as a broken image in the Media Library until restored. Removing
 * the rows of items that stay gone is a later step (delete them in the Media Library; their files are already gone).
 */
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const { analyse, crawlSite } = require(process.env.MEDIA_AUDIT_FILE || path.join(__dirname, 'media-audit.js'));
const WP = path.join(__dirname, 'wp.js');
const mb = b => (b / 1048576).toFixed(b >= 10485760 ? 0 : 1);

// Pure: which files move. R = analyse(raw). sel = { status: [...], ids: [..] | null, orphanFolders: [...], includeReferenced }
function buildPlan(R, sel) {
  const allowed = new Set(sel.status || []); const ids = sel.ids ? new Set(sel.ids) : null;
  const chosen = new Set(); const files = new Map(); const held = [];
  const byId = new Map(R.attachments.map(a => [a.id, a]));
  for (const a of R.attachments) {
    if (ids ? !ids.has(a.id) : !allowed.has(a.status)) continue;
    if (!allowed.has(a.status)) { held.push([`library item ${a.id} (${a.file})`, `its status is ${a.status}, not ${[...allowed].join(' or ')}`]); continue; }
    chosen.add(a.id);
  }
  if (ids) for (const id of ids) if (!byId.has(id)) held.push([`library item ${id}`, 'not in the scan']);
  const others = list => list.filter(id => !chosen.has(id));
  for (const id of chosen) {
    const a = byId.get(id);
    for (const f of a.files || []) {
      if (!R.disk.has(f) || files.has(f)) continue;
      const o = others(R.owners.get(f.toLowerCase()) || []);
      if (o.length) { held.push([f, `also a file of library item ${o.join(', ')}, which stays`]); continue; }
      files.set(f, { bytes: R.disk.get(f).bytes, why: 'item ' + id });
    }
  }
  for (const [rel, d] of R.disk) {
    if (d.owner !== null || d.strayOf === null) continue;
    const c = d.strayAll || [d.strayOf];
    if (!c.some(id => chosen.has(id))) continue;
    const o = others(c);
    if (o.length) { held.push([rel, `could also be a copy of library item ${o.join(', ')}, which stays`]); continue; }
    files.set(rel, { bytes: d.bytes, why: 'extra copy of item ' + c[0] });
  }
  const folders = (sel.orphanFolders || []).map(f => f.replace(/^\/+|\/+$/g, '')).filter(Boolean);
  for (const o of R.orphans) {
    const f = folders.find(x => o.rel.startsWith(x + '/')); if (!f) continue;
    if (o.refs.length && !sel.includeReferenced) { held.push([o.rel, 'still referred to in ' + o.refs[0].where]); continue; }
    files.set(o.rel, { bytes: o.bytes, why: 'orphan in ' + f });
  }
  const list = [...files].map(([rel, v]) => [rel, v.bytes, v.why]).sort((a, b) => (a[0] < b[0] ? -1 : 1));
  return { items: chosen.size, files: list, bytes: list.reduce((s, f) => s + f[1], 0), heldBack: held };
}

function main() {
  const [cmd, ...rest] = process.argv.slice(2);
  const flag = (n, d) => { const i = rest.indexOf('--' + n); return i >= 0 ? (rest[i + 1] && !rest[i + 1].startsWith('--') ? rest[i + 1] : true) : d; };
  const site = rest[0] && !rest[0].startsWith('--') ? rest[0] : null;
  const dir = path.resolve(String(flag('dir', 'media-audit'))); const rawDir = path.join(dir, 'raw'); const qDir = path.join(dir, 'quarantine');
  const batch = flag('batch', null); const WRITE = flag('write', false) === true;
  const load = name => { try { return JSON.parse(fs.readFileSync(path.join(rawDir, name + '.json'), 'utf8')); } catch (e) { return null; } };
  const list = v => String(v === true || !v ? '' : v).split(',').map(s => s.trim()).filter(Boolean);
  const wp = args => { try { return JSON.parse(execFileSync('node', [WP, site, ...args], { encoding: 'utf8', maxBuffer: 256e6, stdio: ['ignore', 'pipe', 'pipe'] })); } catch (e) { const msg = String(e.stderr || e.message).replace(/\s+/g, ' ').slice(0, 300); console.error(/HTTP 404/.test(msg) ? 'The site has no wp-media-quarantine plugin. Upload assets/wp-media-quarantine.php to wp-content/mu-plugins/.' : 'ERROR: ' + msg); process.exit(1); } };
  const needBatch = () => { if (!batch || batch === true || !/^[a-z0-9][a-z0-9-]{0,39}$/.test(batch)) { console.error('--batch <name>: 1 to 40 of a-z, 0-9 and -'); process.exit(2); } };
  const planFile = () => path.join(qDir, batch + '.plan.json');
  const readPlan = () => { try { return JSON.parse(fs.readFileSync(planFile(), 'utf8')); } catch (e) { console.error('no plan for batch ' + batch + ': run plan first'); process.exit(1); } };
  const post = (act, body) => { fs.mkdirSync(qDir, { recursive: true }); const f = path.join(qDir, '.request.json'); fs.writeFileSync(f, JSON.stringify(body)); const r = wp(['quarantine', act, '--data-file', f]); fs.unlinkSync(f); return r; };
  const chunks = (a, n) => { const out = []; for (let i = 0; i < a.length; i += n) out.push(a.slice(i, i + n)); return out; };
  const reasons = skipped => { const c = {}; skipped.forEach(s => { c[s[1]] = (c[s[1]] || 0) + 1; }); return Object.entries(c).map(([k, n]) => `${n} ${k}`).join('; '); };

  if (cmd === 'plan') {
    needBatch();
    const raw = { info: load('info'), attachments: load('attachments'), files: load('files'), refs: load('refs'), theme: load('theme'), crawl: load('crawl'), meta: load('meta') };
    if (!raw.info || !raw.attachments || !raw.files || !raw.refs) { console.error('no complete scan in ' + rawDir); process.exit(1); }
    if (!raw.crawl) { console.error('no crawl in ' + rawDir + ': run media-audit.js crawl first. The crawl is what keeps a file a public page loads off the list.'); process.exit(1); }
    const R = analyse(raw, { ignoreTables: list(flag('ignore-tables', '')) });
    const csvFile = flag('csv', null); let ids = null;
    if (csvFile && csvFile !== true) ids = fs.readFileSync(csvFile, 'utf8').split(/\r?\n/).slice(1).map(l => Number((l.match(/^[A-Z]+,(\d+),/) || [])[1])).filter(Boolean);
    const orphanFolders = list(flag('orphan-folders', ''));
    const status = flag('status', null) ? list(flag('status', '')).map(s => s.toUpperCase()) : (ids ? ['UNUSED'] : (orphanFolders.length ? [] : ['UNUSED']));
    if (status.some(s => !['UNUSED', 'BACKGROUND'].includes(s))) { console.error('--status may be UNUSED and/or BACKGROUND; items that are USED or MAYBE never move'); process.exit(2); }
    const P = buildPlan(R, { status, ids, orphanFolders, includeReferenced: flag('include-referenced', false) === true });
    fs.mkdirSync(qDir, { recursive: true });
    fs.writeFileSync(planFile(), JSON.stringify({ batch, created: new Date().toISOString(), scanFinished: (raw.meta || {}).finished, selection: { status, csv: csvFile || null, orphanFolders }, ...P }));
    const by = {}; P.files.forEach(f => { const k = f[2].startsWith('orphan') ? f[2] : f[2].replace(/ \d+$/, '').replace(/^extra copy of item$/, 'extra copies of library items'); by[k] = by[k] || { n: 0, b: 0 }; by[k].n++; by[k].b += f[1]; });
    console.log(`plan ${batch}: ${P.files.length} files, ${mb(P.bytes)} MB` + (P.items ? ` (${P.items} library items)` : ''));
    Object.entries(by).forEach(([k, v]) => console.log(`   ${k === 'item' ? 'files of library items' : k}: ${v.n} files, ${mb(v.b)} MB`));
    console.log(`held back: ${P.heldBack.length}` + (P.heldBack.length ? '\n   ' + P.heldBack.slice(0, 12).map(h => h[0] + '  <- ' + h[1]).join('\n   ') + (P.heldBack.length > 12 ? `\n   ... all ${P.heldBack.length} in the plan file` : '') : ''));
    const age = raw.meta && raw.meta.finished ? Math.round((Date.now() - Date.parse(raw.meta.finished)) / 3600000) : null;
    if (age !== null && age >= 24) console.log(`NOTE: the scan is ${age} hours old. Content may have changed: run scan and crawl again first.`);
    console.log('written: ' + planFile());
    return;
  }

  if (cmd === 'move') {
    needBatch(); if (!site) { console.error('usage: media-quarantine.js move <site> --dir D --batch B [--write]'); process.exit(2); }
    const P = readPlan(); let moved = 0, bytes = 0; const skipped = [];
    for (const c of chunks(P.files.map(f => f[0]), 500)) { const r = post('move', { batch, files: c, dry_run: !WRITE }); moved += r.moved.length; bytes += r.bytes; skipped.push(...r.skipped); process.stderr.write(`  ${moved} of ${P.files.length}\n`); }
    if (WRITE) fs.writeFileSync(path.join(qDir, batch + '.moved.json'), JSON.stringify({ when: new Date().toISOString(), moved, bytes, skipped }));
    console.log(`${WRITE ? 'MOVED' : 'DRY RUN: would move'} ${moved} of ${P.files.length} files, ${mb(bytes)} MB` + (skipped.length ? ` | skipped ${skipped.length}: ${reasons(skipped)}` : ''));
    if (!WRITE) console.log('nothing was changed. Add --write to move.'); else console.log('next: media-quarantine.js verify <site> --dir <dir>');
    return;
  }

  if (cmd === 'restore') {
    needBatch(); if (!site) { console.error('usage: media-quarantine.js restore <site> --dir D --batch B (--all | --needed | --ids 1,2 | --files-from f) [--write]'); process.exit(2); }
    let restored = 0, bytes = 0; const skipped = [];
    if (flag('all', false) === true) {
      if (!WRITE) { const s = wp(['rest-get', 'wp-media-quarantine/v1/batches']).batches.find(b => b.batch === batch); console.log(s ? `DRY RUN: would restore ${s.inQuarantine} files, ${mb(s.bytes)} MB. Add --write.` : 'no such batch on the site'); return; }
      for (;;) { const r = post('restore', { batch, all: true }); restored += r.restored.length; bytes += r.bytes; skipped.push(...r.skipped); if (!r.restored.length || !r.more) break; }
    } else {
      let files = [];
      if (flag('needed', false) === true) { try { files = fs.readFileSync(path.join(qDir, 'restore-needed.txt'), 'utf8').split(/\r?\n/).filter(Boolean); } catch (e) { console.error('no restore-needed.txt: run verify first'); process.exit(1); } }
      else if (flag('files-from', null)) files = fs.readFileSync(String(flag('files-from', '')), 'utf8').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      else if (flag('ids', null)) { const want = new Set(list(flag('ids', '')).map(x => `item ${x}`)); files = readPlan().files.filter(f => want.has(f[2]) || want.has(f[2].replace('extra copy of ', ''))).map(f => f[0]); }
      else { console.error('say what to restore: --all, --needed, --ids 1,2 or --files-from f.txt'); process.exit(2); }
      if (!files.length) { console.log('nothing to restore'); return; }
      for (const c of chunks(files, 500)) { const r = post('restore', { batch, files: c, dry_run: !WRITE }); restored += r.restored.length; bytes += r.bytes; skipped.push(...r.skipped); }
    }
    console.log(`${WRITE ? 'RESTORED' : 'DRY RUN: would restore'} ${restored} files, ${mb(bytes)} MB` + (skipped.length ? ` | skipped ${skipped.length}: ${reasons(skipped)}` : ''));
    return;
  }

  if (cmd === 'status') {
    if (!site) { console.error('usage: media-quarantine.js status <site>'); process.exit(2); }
    const r = wp(['rest-get', 'wp-media-quarantine/v1/batches']);
    console.log(`plugin ${r.version} | folder ${r.folder}` + (r.batches.length ? '' : ' | nothing in quarantine'));
    r.batches.forEach(b => console.log(`   ${b.batch}: ${b.inQuarantine} files in quarantine (${mb(b.bytes)} MB), ${b.restored} restored, created ${String(b.created).slice(0, 10)}`));
    return;
  }

  if (cmd === 'verify') {
    if (!site) { console.error('usage: media-quarantine.js verify <site> --dir D'); process.exit(2); }
    const info = load('info'); if (!info) { console.error('no scan in ' + rawDir); process.exit(1); }
    const get = p => JSON.parse(execFileSync('node', [WP, site, 'rest-get', p], { encoding: 'utf8', maxBuffer: 256e6, stdio: ['ignore', 'pipe', 'pipe'] }));
    const say = s => process.stderr.write(s + '\n');
    (async () => {
      const c = await crawlSite(get, info, Number(flag('max-pages', 1500)), say);
      const home = info.home.replace(/\/$/, ''); const tokens = Object.keys(c.tokens).filter(t => /\.[a-z0-9]{2,5}$/i.test(t));
      const missing = []; let i = 0;
      const work = async () => { while (i < tokens.length) { const t = tokens[i++]; const url = `${home}/${info.uploadsBase}/${t.split('/').map(encodeURIComponent).join('/')}`;
        try { let r = await fetch(url, { method: 'HEAD', headers: { 'User-Agent': 'Mozilla/5.0 (media-audit)' } }); if (r.status === 405) r = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 (media-audit)' } }); if (r.status === 404 || r.status === 410) missing.push(t); }
        catch (e) { missing.push(t); } } };
      await Promise.all([work(), work(), work(), work(), work(), work()]);
      fs.mkdirSync(qDir, { recursive: true });
      const runs = fs.readdirSync(qDir).filter(f => /^verify-.*\.json$/.test(f)).sort(); const prev = runs.length ? JSON.parse(fs.readFileSync(path.join(qDir, runs[runs.length - 1]), 'utf8')) : null;
      const inQ = new Map(); for (const f of fs.readdirSync(qDir).filter(x => /\.plan\.json$/.test(x))) { const p = JSON.parse(fs.readFileSync(path.join(qDir, f), 'utf8')); p.files.forEach(x => inQ.set(x[0], p.batch)); }
      const fresh = prev ? missing.filter(t => !prev.missing.includes(t)) : [];
      const needed = missing.filter(t => inQ.has(t));
      fs.writeFileSync(path.join(qDir, 'verify-' + new Date().toISOString().replace(/[:.]/g, '-') + '.json'), JSON.stringify({ pages: c.pages, failedPages: c.failed, checked: tokens.length, missing }));
      fs.writeFileSync(path.join(qDir, 'restore-needed.txt'), needed.join('\n') + (needed.length ? '\n' : ''));
      console.log(`${c.pages} pages loaded` + (c.failed.length ? ` (${c.failed.length} FAILED: ${c.failed.slice(0, 3).join(' | ')})` : '') + `, ${tokens.length} upload files asked for, ${missing.length} missing`);
      console.log(prev ? `compared with the run before: ${fresh.length} newly missing` + (fresh.length ? '\n   ' + fresh.slice(0, 20).map(t => t + '  on ' + c.tokens[t][0]).join('\n   ') : '') : 'first run: this is the baseline (files that were already missing before anything moved)');
      console.log(needed.length ? `${needed.length} of the missing files are in quarantine -> media-quarantine.js restore <site> --dir <dir> --batch ${[...new Set(needed.map(t => inQ.get(t)))].join('|')} --needed --write` : 'none of the missing files is in quarantine');
      process.exit(needed.length ? 1 : 0);
    })();
    return;
  }

  console.log(fs.readFileSync(__filename, 'utf8').split('*/')[0].replace(/^#!.*\n\/\*\n?/, '').replace(/^ \* ?/gm, ''));
  process.exit(cmd ? 2 : 0);
}

module.exports = { buildPlan };
if (require.main === module) main();
