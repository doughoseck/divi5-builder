#!/usr/bin/env node
/*
 * media-audit.js — what in the uploads folder is not used, and what is big?  READ-ONLY. Works on any WordPress site.
 *
 *   node media-audit.js scan   <site> [--dir D] [--resume] [--all-tables]
 *                                                            # pulls the facts from the site into D/raw (needs the plugin)
 *                                                            # --resume: keep the tables a stopped scan finished
 *                                                            # --all-tables: also scan log-sized tables (over 200,000 rows)
 *   node media-audit.js crawl  <site> [--dir D] [--max-pages N]   # loads the public pages, notes every upload file they use
 *   node media-audit.js report [--dir D] [--ignore-tables a,b]
 *                                                            # offline: classifies, writes D/REPORT.md and the CSV lists
 *                                                            # --ignore-tables: tables of plugins that are no longer used;
 *                                                            # a mention there stops counting as a use
 *
 * Needs assets/wp-media-audit.php in wp-content/mu-plugins/ of the site (the user uploads it; it is read-only and
 * stores nothing) and an administrator's application password in the creds file. <site> is the creds section.
 * D defaults to ./media-audit. Nothing on the site is changed by any command here: there is no delete in this file.
 *
 * Two separate findings:
 *   1. media library items (attachments) that nothing refers to
 *   2. files on disk that belong to no media library item (orphans), by folder
 * plus: the biggest files, locally hosted video and audio, references to files that do not exist, library items
 * whose file is missing, and the size of everything else in wp-content.
 *
 * Every library item gets one status:
 *   USED        a live post, page, option, widget, menu, custom field, theme file or public page refers to it
 *   MAYBE       only a weak match: a bare number somewhere that equals its ID. Treated as used.
 *   BACKGROUND  only old revisions, trashed posts, leftover meta or an index table refer to it. A person decides.
 *   UNUSED      nothing refers to it
 * When in doubt an item counts as used: a wrong "unused" deletes somebody's image, a wrong "used" costs disk space.
 * The crawl is the cross-check: a file a public page loads must never be on the unused list. Where the database
 * scan alone would have missed it, the report says so. See references/wp-media-audit.md.
 */
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const WP = path.join(__dirname, 'wp.js');
const NS = 'wp-media-audit/v1';
const MIN_PLUGIN = '1.0.2';

// Tables that list every file or log every visit: a mention there is not a use. Not scanned.
const SKIP_TABLES = /(ewwwio_|^pmxi_|^pmxe_|wfknownfilelist|wffilemods|wfissues|wfhits|wflogins|wfcrawlers|smush|yoast_indexable|yoast_seo_links|redirection_(logs|404)|actionscheduler_|wsal_|statistics_|frmt_form_views|litespeed_|wpr_|rank_math_analytics|rank_math_404|icl_translation_status|imagify_|shortpixel_|nf3_|_log$|_logs$)/i;
// Post meta in which a page builder keeps an OLD copy of the page: a mention there is an old version, not a use.
// _et_pb_divi_4_content is what Divi 5 keeps of a page's Divi 4 shortcodes after converting it.
const BACKUP_META = /^(_et_pb_old_content|_et_pb_divi_4_content|_et_pb_ab_.*|_elementor_data_backup.*|_oembed_.*|_wp_old_.*)$/;
const MAX_ROWS = 200000;
const CORE_TABLES = ['posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'links', 'users'];

/* ---------------------------------------------------------------- pure helpers (tested by media-audit-test.js) */

// Same rules as wpma_norm_text() and wpma_paths() in the plugin, for the HTML and CSS the crawl reads.
function normText(t) {
  t = String(t).replace(/\\+\//g, '/').replace(/\\+"/g, '"').replace(/\\+u00([0-7][0-9a-fA-F])/g, (m, h) => String.fromCharCode(parseInt(h, 16)));
  t = t.replace(/&quot;|&#0?34;|&#x22;/g, '"').replace(/&#47;|&#x2[Ff];/g, '/');
  if (/%2F/i.test(t)) { try { t += '\n' + decodeURIComponent(t.replace(/%(?![0-9a-fA-F]{2})/g, '%25')); } catch (e) { /* not valid encoding: keep as is */ } }
  return t;
}
function pathsIn(text, base) {
  const out = new Set(); if (!base) return [];
  const re = new RegExp(base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/([^\\s"\'<>()\\\\?#,;&\\[\\]{}|`^*]+)', 'gi');
  for (const m of normText(text).matchAll(re)) {
    let p = m[1].replace(/[.:!/]+$/, '');
    if (p.includes('%')) { try { p = decodeURIComponent(p); } catch (e) { /* keep */ } }
    if (p && p.length <= 400 && !/(^|\/)\.\.(\/|$)/.test(p)) out.add(p);   // a ".." folder, not two dots in a file name
  }
  return [...out];
}

// "2020/05/photo-300x200.jpg.webp" -> "2020/05/photo": the name all copies of one upload share.
function stemOf(rel) {
  const i = rel.lastIndexOf('/'); const dir = i >= 0 ? rel.slice(0, i + 1) : ''; let name = i >= 0 ? rel.slice(i + 1) : rel;
  name = name.replace(/\.(webp|avif)$/i, m => (/\.[a-z0-9]{2,5}\.(webp|avif)$/i.test(name) ? '' : m));   // photo.jpg.webp -> photo.jpg
  name = name.replace(/\.[a-z0-9]{1,5}$/i, '');
  let prev; do { prev = name; name = name.replace(/(-\d+x\d+|-scaled|-rotated|-e\d{10,})$/i, ''); } while (name !== prev && name);
  return (dir + (name || prev)).toLowerCase();
}
const extOf = rel => { const m = rel.replace(/\.(webp|avif)$/i, s => (/\.[a-z0-9]{2,5}\.(webp|avif)$/i.test(rel) ? '' : s)).match(/\.([a-z0-9]{1,5})$/i); return m ? m[1].toLowerCase() : ''; };
const RANK = { USED: 3, MAYBE: 2, BACKGROUND: 1, UNUSED: 0 };

/*
 * analyse(raw) -> everything the report needs. raw = { info, attachments, files, refs, theme, crawl }.
 *   files: [[rel, bytes, mtime]]   refs: { table: [[kind, token, strength, class, count, [where]]] }
 */
function analyse(raw, opts = {}) {
  const info = raw.info || {}; const prefix = info.prefix || ''; const ignore = new Set(opts.ignoreTables || []);
  const atts = new Map(); for (const a of raw.attachments || []) atts.set(a.id, { ...a, ev: [], bytes: 0, onDisk: 0, stray: [], strayBytes: 0 });
  const disk = new Map();
  for (const [rel, bytes, mtime] of raw.files || []) disk.set(rel, { bytes, mtime, owner: null, strayOf: null, refs: [] });
  // exact names only: on a Linux server K.JPG and k.jpg are two files
  const onDisk = rel => (disk.has(rel) ? rel : undefined);

  // who owns which file
  const owners = new Map(); const stems = new Map();
  for (const a of atts.values()) {
    for (const f of a.files || []) { const k = f.toLowerCase(); if (!owners.has(k)) owners.set(k, []); if (!owners.get(k).includes(a.id)) owners.get(k).push(a.id); }
    if (a.file) { const s = stemOf(a.file); if (!stems.has(s)) stems.set(s, []); stems.get(s).push(a.id); }
  }
  const byStem = rel => { const c = stems.get(stemOf(rel)); if (!c) return []; if (c.length === 1) return c; const e = extOf(rel); const same = c.filter(id => extOf(atts.get(id).file) === e); return same.length ? same : c; };
  for (const a of atts.values()) {
    const seen = new Set();
    for (const f of a.files || []) { const rel = onDisk(f); if (!rel || seen.has(rel)) continue; seen.add(rel); const d = disk.get(rel); a.bytes += d.bytes; a.onDisk++; if (d.owner === null) d.owner = a.id; }
    a.missing = !a.file || !onDisk(a.file);
  }
  for (const [rel, d] of disk) {
    if (d.owner !== null) continue; const c = byStem(rel); if (!c.length) continue;
    d.strayOf = c[0]; d.strayAll = c; const a = atts.get(c[0]); a.stray.push(rel); a.strayBytes += d.bytes;
  }

  // index-like tables: a table outside WordPress core that mentions a large share of the library is a list, not a use
  const indexLike = []; const tableStats = [];
  const resolve = token => { const o = owners.get(token.toLowerCase()); if (o) return o; return byStem(token); };
  for (const [table, hits] of Object.entries(raw.refs || {})) {
    const short = table.startsWith(prefix) ? table.slice(prefix.length) : table; const set = new Set();
    for (const [kind, token, strength] of hits) { if (kind === 'p') resolve(String(token)).forEach(id => set.add(id)); else if (kind === 'i' && strength === 's' && atts.has(Number(token))) set.add(Number(token)); }
    // an index: on the list of known list-and-log tables, named by the user (a retired plugin's table), or found by share
    const isIndex = !CORE_TABLES.includes(short) && (SKIP_TABLES.test(short) || ignore.has(table) || ignore.has(short) || (set.size >= 50 && set.size >= 0.4 * atts.size));
    if (isIndex) indexLike.push(table);
    tableStats.push({ table, hits: hits.length, attachments: set.size, indexLike: isIndex });
  }

  // evidence
  const broken = new Map(); const children = new Map();
  for (const a of atts.values()) if (a.parent) { if (!children.has(a.parent)) children.set(a.parent, []); children.get(a.parent).push(a.id); }
  const addPath = (token, cls, where, source) => {
    const ids = resolve(token);
    if (ids.length) { ids.forEach(id => atts.get(id).ev.push({ how: 'path', strength: 's', cls, where, source, token })); return; }
    const rel = onDisk(token);
    if (rel) { disk.get(rel).refs.push({ cls, where, source }); return; }
    if (/\.[a-z0-9]{2,5}$/i.test(token)) { if (!broken.has(token)) broken.set(token, []); if (broken.get(token).length < 3) broken.get(token).push(where); }
  };
  const feed = (hits, source, forceCls) => {
    for (const [kind, token, strength, cls0, n, wheres] of hits) {
      const where = (wheres || [])[0] || source;
      // an old copy only if EVERY place of this hit is one: the plugin lists at most 3 places, so with more we cannot know
      const backup = cls0 === 'live' && (wheres || []).length > 0 && n <= wheres.length && wheres.every(x => { const w = x.split('|'); return w[0] === 'postmeta' && BACKUP_META.test(w[2] || ''); });
      const cls = forceCls || (backup ? 'revision' : cls0);
      if (kind === 'p') addPath(String(token), cls, where, source);
      else if (kind === 'i') { const a = atts.get(Number(token)); if (a) a.ev.push({ how: 'id', strength, cls, where, source }); }
      else if (kind === 'g') (children.get(Number(token)) || []).forEach(id => atts.get(id).ev.push({ how: 'gallery of its parent post', strength: 's', cls, where, source }));
    }
  };
  for (const [table, hits] of Object.entries(raw.refs || {})) feed(hits, table, indexLike.includes(table) ? 'index' : null);
  if (raw.theme) feed(raw.theme.hits || [], 'theme files');
  for (const [token, urls] of Object.entries((raw.crawl || {}).tokens || {})) addPath(token, 'live', urls[0], 'crawl');

  // status, with and without the crawl
  const statusOf = ev => {
    if (ev.some(e => e.cls === 'live' && e.strength === 's')) return 'USED';
    if (ev.some(e => e.cls === 'live')) return 'MAYBE';
    return ev.length ? 'BACKGROUND' : 'UNUSED';
  };
  const crawlOnly = [];
  for (const a of atts.values()) {
    a.dbStatus = statusOf(a.ev.filter(e => e.source !== 'crawl'));
    a.status = statusOf(a.ev);
    if (a.status === 'USED' && a.dbStatus !== 'USED') crawlOnly.push(a.id);
  }
  // two library items that own the same file: deleting the unused one would delete the used one's file
  for (const ids of owners.values()) {
    if (ids.length < 2) continue;
    const best = ids.map(id => atts.get(id)).sort((x, y) => RANK[y.status] - RANK[x.status])[0];
    for (const id of ids) { const a = atts.get(id); if (a.id !== best.id && RANK[a.status] < RANK[best.status]) { a.sharedWith = best.id; a.status = best.status; } }
  }

  const orphans = []; const folders = new Map(); let uploadsBytes = 0;
  for (const [rel, d] of disk) {
    uploadsBytes += d.bytes;
    const seg = rel.split('/'); const top = seg.length > 1 ? seg[0] : '(files in the uploads folder itself)';
    const dated = /^\d{4}$/.test(seg[0]) && seg.length > 1;
    const key = dated ? seg[0] : (seg.length > 2 ? seg[0] + '/' + seg[1] : top);
    if (!folders.has(key)) folders.set(key, { folder: key, files: 0, bytes: 0, owned: 0, ownedBytes: 0, stray: 0, strayBytes: 0, orphan: 0, orphanBytes: 0, orphanRef: 0 });
    const f = folders.get(key); f.files++; f.bytes += d.bytes;
    if (d.owner !== null) { f.owned++; f.ownedBytes += d.bytes; } else if (d.strayOf !== null) { f.stray++; f.strayBytes += d.bytes; }
    else { f.orphan++; f.orphanBytes += d.bytes; if (d.refs.some(r => r.cls === 'live')) f.orphanRef++; orphans.push({ rel, bytes: d.bytes, mtime: d.mtime, dated, referenced: d.refs.some(r => r.cls === 'live'), refs: d.refs }); }
  }
  orphans.sort((a, b) => b.bytes - a.bytes);
  const list = [...atts.values()];
  const sum = st => list.filter(a => a.status === st).reduce((o, a) => ({ n: o.n + 1, bytes: o.bytes + a.bytes + a.strayBytes }), { n: 0, bytes: 0 });
  return {
    info, attachments: list, disk, orphans, folders: [...folders.values()].sort((a, b) => b.bytes - a.bytes), broken: [...broken].map(([token, where]) => ({ token, where })),
    indexLike, tableStats, crawlOnly, uploadsBytes, uploadsFiles: disk.size,
    totals: { USED: sum('USED'), MAYBE: sum('MAYBE'), BACKGROUND: sum('BACKGROUND'), UNUSED: sum('UNUSED') },
    owners, ghosts: list.filter(a => a.missing), shared: list.filter(a => a.sharedWith),
  };
}

/* ---------------------------------------------------------------- report */

const mb = b => (b / 1048576).toFixed(b >= 10485760 ? 0 : 1);
const csv = rows => rows.map(r => r.map(v => { v = v === null || v === undefined ? '' : String(v); return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(',')).join('\r\n') + '\r\n';
const day = t => (t ? new Date(t * 1000).toISOString().slice(0, 10) : '');
const evText = (a, n = 3) => { const seen = new Set(); const out = []; for (const e of a.ev) { const s = `${e.how}${e.strength === 'w' ? ' (weak)' : ''} in ${e.where}${e.cls !== 'live' ? ' [' + e.cls + ']' : ''}`; if (!seen.has(s)) { seen.add(s); out.push(s); } if (out.length >= n) break; } return out.join('; '); };
const VIDEO_AUDIO = /\.(mp4|m4v|mov|webm|avi|wmv|mkv|ogv|mp3|wav|m4a|ogg|flac|aac)$/i;

function writeReport(dir, raw, R) {
  const home = String(R.info.home || '').replace(/\/$/, ''); const base = R.info.uploadsBase || 'wp-content/uploads';
  const order = { UNUSED: 0, BACKGROUND: 1, MAYBE: 2, USED: 3 };
  const rows = R.attachments.slice().sort((a, b) => order[a.status] - order[b.status] || (b.bytes + b.strayBytes) - (a.bytes + a.strayBytes));
  const head = ['status', 'id', 'MB', 'bytes (all its files)', 'files on disk', 'extra copies on disk (bytes)', 'type', 'uploaded', 'title', 'file', 'attached to post', 'found in', 'note', 'edit link', 'file link'];
  const line = a => [a.status, a.id, mb(a.bytes + a.strayBytes), a.bytes, a.onDisk, a.strayBytes, a.mime, String(a.date || '').slice(0, 10), a.title, a.file, a.parent || '', evText(a),
    [a.missing ? 'FILE MISSING on disk' : '', a.sharedWith ? 'shares a file with item ' + a.sharedWith : '', R.crawlOnly.includes(a.id) ? 'seen on a public page; the database scan alone missed it' : ''].filter(Boolean).join('; '),
    `${home}/wp-admin/post.php?post=${a.id}&action=edit`, a.file ? `${home}/${base}/${a.file}` : ''];
  fs.writeFileSync(path.join(dir, 'attachments.csv'), csv([head, ...rows.map(line)]));
  fs.writeFileSync(path.join(dir, 'review-unused.csv'), csv([head, ...rows.filter(a => a.status === 'UNUSED').map(line)]));
  fs.writeFileSync(path.join(dir, 'review-background.csv'), csv([head, ...rows.filter(a => a.status === 'BACKGROUND').map(line)]));
  fs.writeFileSync(path.join(dir, 'orphan-files.csv'), csv([['MB', 'bytes', 'file', 'modified', 'referenced', 'found in'], ...R.orphans.map(o => [mb(o.bytes), o.bytes, o.rel, day(o.mtime), o.referenced ? 'yes' : (o.refs.length ? 'old versions only' : 'no'), o.refs.slice(0, 2).map(r => r.where).join('; ')])]));
  fs.writeFileSync(path.join(dir, 'folders.csv'), csv([['folder in uploads', 'MB', 'files', 'library files', 'library MB', 'extra copies', 'extra copies MB', 'orphan files', 'orphan MB', 'orphans that are referenced'], ...R.folders.map(f => [f.folder, mb(f.bytes), f.files, f.owned, mb(f.ownedBytes), f.stray, mb(f.strayBytes), f.orphan, mb(f.orphanBytes), f.orphanRef])]));
  const byId = new Map(R.attachments.map(a => [a.id, a]));
  const fileStatus = (rel, d) => (d.owner !== null ? byId.get(d.owner).status + ' (library item ' + d.owner + ')' : d.strayOf !== null ? 'extra copy of library item ' + d.strayOf : (d.refs.some(r => r.cls === 'live') ? 'orphan, referenced' : 'orphan, not referenced'));
  const big = [...R.disk].sort((a, b) => b[1].bytes - a[1].bytes).slice(0, 200);
  fs.writeFileSync(path.join(dir, 'big-files.csv'), csv([['MB', 'file in uploads', 'modified', 'status'], ...big.map(([rel, d]) => [mb(d.bytes), rel, day(d.mtime), fileStatus(rel, d)])]));
  fs.writeFileSync(path.join(dir, 'broken-references.csv'), csv([['file that does not exist', 'referred to in'], ...R.broken.map(b => [b.token, b.where.join('; ')])]));

  const T = R.totals; const L = [];
  const orphanTotal = R.orphans.reduce((s, o) => s + o.bytes, 0); const orphanFree = R.orphans.filter(o => !o.refs.length).reduce((s, o) => s + o.bytes, 0);
  const strayTotal = R.attachments.reduce((s, a) => s + a.strayBytes, 0);
  L.push(`# Media audit: ${home}`, '', `Scanned ${String((raw.meta || {}).finished || '').slice(0, 16).replace('T', ' ')} UTC. Read-only: nothing on the site was changed.`, '');
  L.push('## In short', '', `- Uploads folder: **${mb(R.uploadsBytes)} MB** in ${R.uploadsFiles} files. Media library: ${R.attachments.length} items.`);
  L.push(`- **${T.UNUSED.n} library items are not used anywhere: ${mb(T.UNUSED.bytes)} MB.** List: \`review-unused.csv\`, biggest first.`);
  L.push(`- ${T.BACKGROUND.n} more (${mb(T.BACKGROUND.bytes)} MB) are only referred to by old revisions, trashed posts or leftovers: \`review-background.csv\`.`);
  L.push(`- ${R.orphans.length} files on disk (${mb(orphanTotal)} MB) belong to no library item; ${mb(orphanFree)} MB of that is not referred to at all. Lists: \`folders.csv\`, \`orphan-files.csv\`.`);
  if (strayTotal) L.push(`- ${mb(strayTotal)} MB are extra copies of library images that WordPress no longer knows about (old thumbnail sizes, .webp copies).`);
  L.push('', '## Library items by status', '', '| Status | Items | MB | Meaning |', '|---|---:|---:|---|');
  L.push(`| USED | ${T.USED.n} | ${mb(T.USED.bytes)} | something live refers to it |`, `| MAYBE | ${T.MAYBE.n} | ${mb(T.MAYBE.bytes)} | only a bare number equal to its ID was found; kept as used |`);
  L.push(`| BACKGROUND | ${T.BACKGROUND.n} | ${mb(T.BACKGROUND.bytes)} | only revisions, trash, leftover meta or an index table |`, `| UNUSED | ${T.UNUSED.n} | ${mb(T.UNUSED.bytes)} | nothing refers to it |`);
  // why BACKGROUND, and which single plugin table keeps items USED
  const why = e => (e.cls === 'index' ? 'the table ' + e.source : e.cls === 'trash' ? 'trashed posts' : e.cls === 'orphan' ? 'meta of deleted posts' : /postmeta$/.test(e.source) ? 'an old builder copy of a page' : 'old revisions');
  const bg = {}; for (const a of R.attachments.filter(x => x.status === 'BACKGROUND')) { const k = [...new Set(a.ev.map(why))].sort().join(' + '); bg[k] = bg[k] || { n: 0, b: 0 }; bg[k].n++; bg[k].b += a.bytes + a.strayBytes; }
  if (T.BACKGROUND.n) { L.push('', 'BACKGROUND items are referred to only by:', ''); Object.entries(bg).sort((a, b) => b[1].b - a[1].b).forEach(([k, v]) => L.push(`- ${k}: ${v.n} items, ${mb(v.b)} MB`)); }
  const sole = {}; for (const a of R.attachments.filter(x => x.status === 'USED')) { const s = [...new Set(a.ev.filter(e => e.cls === 'live' && e.strength === 's').map(e => e.source))]; if (s.length === 1 && s[0] !== 'crawl' && s[0] !== 'theme files' && !CORE_TABLES.includes(s[0].slice((R.info.prefix || '').length))) { sole[s[0]] = sole[s[0]] || { n: 0, b: 0 }; sole[s[0]].n++; sole[s[0]].b += a.bytes + a.strayBytes; } }
  R.soleTables = sole;
  const types = {}; for (const a of R.attachments.filter(x => x.status === 'UNUSED')) { const t = (a.mime || 'unknown').split('/')[0]; types[t] = types[t] || { n: 0, b: 0 }; types[t].n++; types[t].b += a.bytes + a.strayBytes; }
  if (T.UNUSED.n) L.push('', 'Unused by type: ' + Object.entries(types).sort((a, b) => b[1].b - a[1].b).map(([t, v]) => `${t} ${v.n} (${mb(v.b)} MB)`).join(', ') + '.');
  L.push('', '### The 25 biggest unused items', '', '| MB | ID | File | Uploaded |', '|---:|---:|---|---|');
  rows.filter(a => a.status === 'UNUSED').slice(0, 25).forEach(a => L.push(`| ${mb(a.bytes + a.strayBytes)} | ${a.id} | ${a.file} | ${String(a.date || '').slice(0, 10)} |`));
  L.push('', '## Folders in uploads', '', '| Folder | MB | Files | Library MB | Orphan files | Orphan MB |', '|---|---:|---:|---:|---:|---:|');
  R.folders.slice(0, 40).forEach(f => L.push(`| ${f.folder} | ${mb(f.bytes)} | ${f.files} | ${mb(f.ownedBytes + f.strayBytes)} | ${f.orphan} | ${mb(f.orphanBytes)} |`));
  const va = [...R.disk].filter(([rel]) => VIDEO_AUDIO.test(rel)).sort((a, b) => b[1].bytes - a[1].bytes);
  L.push('', '## Video and audio hosted on the site', '', va.length ? `${va.length} files, ${mb(va.reduce((s, x) => s + x[1].bytes, 0))} MB. Candidates for YouTube, Vimeo or a podcast host.` : 'None.', '');
  if (va.length) { L.push('| MB | File | Status |', '|---:|---|---|'); va.slice(0, 40).forEach(([rel, d]) => L.push(`| ${mb(d.bytes)} | ${rel} | ${fileStatus(rel, d)} |`)); }
  const du = raw.du || {};
  if (du.content) { L.push('', '## Everything else in wp-content', '', '| Folder or file | MB | Files |', '|---|---:|---:|'); du.content.slice().sort((a, b) => (b.bytes || 0) - (a.bytes || 0)).forEach(c => L.push(`| ${c.path}${c.dir ? '/' : ''} | ${c.skipped ? 'see above' : mb(c.bytes || 0)} | ${c.dir ? (c.files || '') : ''} |`)); }
  for (const sub of ['plugins', 'themes']) if (du[sub] && du[sub].length) { L.push('', `### wp-content/${sub}`, '', '| Folder or file | MB | Files |', '|---|---:|---:|'); du[sub].slice().sort((a, b) => (b.bytes || 0) - (a.bytes || 0)).slice(0, 60).forEach(c => L.push(`| ${c.path}${c.dir ? '/' : ''} | ${mb(c.bytes || 0)} | ${c.dir ? (c.files || '') : ''} |`)); }
  if (du.abspath) { const big2 = du.abspath.filter(c => (c.bytes || 0) >= 1048576 || (c.dir && !c.skipped)); if (big2.length) { L.push('', '## In the site root (outside wp-content)', '', 'Folders that are not part of WordPress, and files over 1 MB. Old backups and zips often sit here.', '', '| Folder or file | MB |', '|---|---:|'); big2.sort((a, b) => (b.bytes || 0) - (a.bytes || 0)).forEach(c => L.push(`| ${c.path}${c.dir ? '/' : ''} | ${mb(c.bytes || 0)} |`)); } }
  if (du.big && du.big.length) { L.push('', '### Files over 5 MB outside uploads', '', '| MB | File | Modified |', '|---:|---|---|'); du.big.slice().sort((a, b) => b[1] - a[1]).slice(0, 40).forEach(b => L.push(`| ${mb(b[1])} | ${b[0]} | ${day(b[2])} |`)); }
  L.push('', '## Other findings', '', `- ${R.ghosts.length} library items whose file is missing on disk (a row with nothing behind it).`, `- ${R.broken.length} references to upload files that do not exist: \`broken-references.csv\`.`, `- ${R.shared.length} library items share a file with another item and were given that item's status.`);
  L.push('', '## How far to trust this', '');
  const crawl = raw.crawl;
  if (crawl) { L.push(`- Cross-check: ${crawl.pages} public pages were loaded and every upload file in their HTML and CSS noted (${Object.keys(crawl.tokens || {}).length} files).`); L.push(R.crawlOnly.length ? `- **${R.crawlOnly.length} library items are used on public pages but the database scan alone did not find them.** They are counted as USED; the note column in \`attachments.csv\` marks them. Each one is a kind of reference the scan does not know yet, so other unused items of the same kind deserve a second look.` : '- Every file the public pages load was also found by the database scan. No disagreement.'); }
  else L.push('- NOT cross-checked: no crawl was run. Run `media-audit.js crawl <site>` and `report` again before trusting the unused list.');
  const m = raw.meta || {};
  L.push(`- Tables scanned: ${Object.keys(raw.refs || {}).length}. Not scanned (lists and logs, a mention there is not a use): ${(m.skippedTables || []).join(', ') || 'none'}.`);
  if (R.indexLike.length) L.push(`- Scanned but not counted as a use (a list, a log, a table you named, or one that mentions a large share of the library): ${R.indexLike.join(', ')}.`);
  if (Object.keys(R.soleTables).length) L.push(`- USED only because a plugin's own table mentions them: ${Object.entries(R.soleTables).map(([t, v]) => `${t} ${v.n} items (${mb(v.b)} MB)`).join(', ')}. If that plugin is no longer used, run \`report --ignore-tables <table>\`.`);
  L.push('- A link to a file with the same path on ANOTHER website counts too (the host is ignored so that links to an old domain still count). That is why `broken-references.csv` can list plugin vendors\' banners.');
  if ((m.errors || []).length) L.push(`- **Errors during the scan (${m.errors.length}): the lists are incomplete until these are solved.** ${m.errors.slice(0, 5).join(' | ')}`);
  if (raw.theme && raw.theme.complete === false) L.push('- The theme folder was too big to read completely.');
  L.push('- Not looked at: plugin code, files outside WordPress, other sites of a multisite, and anything that builds a file name in code.', '- Nothing here deletes. Review the lists, then decide.', '');
  fs.writeFileSync(path.join(dir, 'REPORT.md'), L.join('\n'));
  return L;
}

/* ---------------------------------------------------------------- talking to the site */

// Loads every published page, post and custom post type (no login) and notes each upload file in the HTML and in
// the site's own stylesheets. get = a function that reads a REST path with the stored credentials.
async function crawlSite(get, info, max, say) {
  const q = o => Object.entries(o).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');
  const home = info.home.replace(/\/$/, ''); const host = new URL(home).host;
  const urls = new Set([home + '/']); const skipTypes = /^(attachment|nav_menu_item|wp_block|wp_template|wp_template_part|wp_navigation|wp_global_styles|wp_font_family|wp_font_face)$/;
  const types = get('wp/v2/types');
  for (const [name, t] of Object.entries(types)) { if (skipTypes.test(name) || !t.rest_base) continue;
    for (let page = 1; page <= 50; page++) { let r; try { r = get(`${t.rest_namespace || 'wp/v2'}/${t.rest_base}?${q({ per_page: 100, page, _fields: 'id,link,status' })}`); } catch (e) { break; }
      if (!Array.isArray(r) || !r.length) break; r.forEach(p => { if (p.link && p.status === 'publish') { try { if (new URL(p.link).host === host) urls.add(p.link); } catch (e) { /* skip */ } } }); if (r.length < 100) break; } }
  const list = [...urls].slice(0, max); say(`crawling ${list.length} public URLs` + (urls.size > max ? ` (of ${urls.size}; raise --max-pages)` : ''));
  const tokens = {}; const cssSeen = new Set(); const failed = [];
  const note = (text, url) => pathsIn(text, info.uploadsBase).forEach(p => { (tokens[p] = tokens[p] || []).length < 2 && tokens[p].push(url); });
  const grab = async url => { const r = await fetch(url, { headers: { 'User-Agent': 'Mozilla/5.0 (media-audit)' }, redirect: 'follow' }); if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); };
  let i = 0; const work = async () => { while (i < list.length) { const url = list[i++];
    try { const html = await grab(url); note(html, url);
      for (const m of html.matchAll(/<link[^>]+href=["']([^"']+\.css[^"']*)["']/gi)) { let css; try { css = new URL(m[1].replace(/&#0?38;|&amp;/g, '&'), url); } catch (e) { continue; } if (css.host !== host || cssSeen.has(css.href)) continue; cssSeen.add(css.href); try { note(await grab(css.href), css.href); } catch (e) { /* a missing stylesheet is not our problem */ } }
    } catch (e) { failed.push(url + ' ' + e.message); } } };
  await Promise.all([work(), work(), work(), work()]);
  return { pages: list.length - failed.length, failed, stylesheets: cssSeen.size, tokens };
}

function main() {
  const [cmd, ...rest] = process.argv.slice(2);
  const flag = (n, d) => { const i = rest.indexOf('--' + n); return i >= 0 ? (rest[i + 1] && !rest[i + 1].startsWith('--') ? rest[i + 1] : true) : d; };
  const site = rest[0] && !rest[0].startsWith('--') ? rest[0] : null;
  const dir = path.resolve(String(flag('dir', 'media-audit'))); const rawDir = path.join(dir, 'raw');
  const save = (name, v) => fs.writeFileSync(path.join(rawDir, name + '.json'), JSON.stringify(v));
  const load = name => { try { return JSON.parse(fs.readFileSync(path.join(rawDir, name + '.json'), 'utf8')); } catch (e) { return null; } };
  const wp = args => execFileSync('node', [WP, site, ...args], { encoding: 'utf8', maxBuffer: 512e6, stdio: ['ignore', 'pipe', 'pipe'] });
  const get = p => { try { return JSON.parse(wp(['rest-get', p])); } catch (e) { const msg = String(e.stderr || e.message).replace(/\s+/g, ' ').slice(0, 300); const err = new Error(msg); err.http = (msg.match(/HTTP (\d+)/) || [])[1]; throw err; } };
  const q = o => Object.entries(o).filter(([, v]) => v !== '' && v !== undefined && v !== null).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');
  const say = s => process.stderr.write(s + '\n');

  if (cmd === 'scan') {
    if (!site) { console.error('usage: media-audit.js scan <site> [--dir D]'); process.exit(2); }
    fs.mkdirSync(rawDir, { recursive: true });
    let info; try { info = get(NS + '/info'); } catch (e) { console.error(e.http === '404' ? 'The site has no wp-media-audit plugin. Upload assets/wp-media-audit.php to wp-content/mu-plugins/ and run this again.' : e.http === '401' || e.http === '403' ? 'The user in the creds file must be an administrator.' : 'ERROR: ' + e.message); process.exit(1); }
    if (info.version < MIN_PLUGIN) { console.error('plugin ' + info.version + ' is older than ' + MIN_PLUGIN + ': upload the current assets/wp-media-audit.php'); process.exit(1); }
    const meta = { site, started: new Date().toISOString(), plugin: info.version, errors: [], skippedTables: [] };
    save('info', info); say(`site ${info.home} | WordPress ${info.wp} | PHP ${info.php} | ${info.attachments} library items | ${info.tables.length} tables`);
    if (info.multisite) say('NOTE: multisite. Only this site of the network is audited; untested.');

    const atts = []; for (let after = 0; ;) { const r = get(`${NS}/attachments?${q({ after, limit: 1000 })}`); atts.push(...r.items); after = r.next; if (r.done) break; }
    save('attachments', atts); say(`library items: ${atts.length}`);

    const files = []; let calls = 0;
    const walk = d => { calls++; const r = get(`${NS}/files?${q({ root: 'uploads', dir: d, deep: 1 })}`); if (!r.truncated) { files.push(...r.files); return; }
      const flat = get(`${NS}/files?${q({ root: 'uploads', dir: d })}`); calls++; files.push(...flat.files); flat.dirs.forEach(walk); };
    try { walk(''); } catch (e) { meta.errors.push('listing uploads: ' + e.message); }
    save('files', files); say(`files in uploads: ${files.length} (${mb(files.reduce((s, f) => s + f[1], 0))} MB, ${calls} requests)`);

    const du = { content: [], abspath: [], big: [] }; const bigSeen = new Set();
    const duDir = (root, d, skip, out, depth) => { let r; try { r = get(`${NS}/du?${q({ root, dir: d, skip })}`); } catch (e) { meta.errors.push(`size of ${root}/${d}: ${e.message}`); return { bytes: 0, files: 0 }; }
      for (const b of r.big) if (!bigSeen.has(root + ':' + b[0])) { bigSeen.add(root + ':' + b[0]); du.big.push([(root === 'abspath' ? '' : 'wp-content/') + b[0], b[1], b[2]]); }
      let bytes = 0, n = 0;
      for (const c of r.children) { const p = d ? d + '/' + c.name : c.name; let cb = c.bytes || 0, cf = c.files || 0;
        if (c.dir && c.complete === false) { const sub = duDir(root, p, '', null, depth + 1); cb = sub.bytes; cf = sub.files; }
        bytes += cb; n += c.dir ? cf : 1; if (out && (depth === 0 || c.dir)) out.push({ path: p, dir: !!c.dir, bytes: cb, files: cf, skipped: !!c.skipped, mtime: c.mtime }); }
      return { bytes, files: n }; };
    const contentName = info.contentDir.replace(/\/$/, '').split('/').pop(); const uploadsInContent = info.uploadsDir.startsWith(info.contentDir) ? info.uploadsDir.slice(info.contentDir.length).replace(/^\//, '').split('/')[0] : '';
    duDir('content', '', uploadsInContent, du.content, 0);
    for (const sub of ['plugins', 'themes']) { const kids = []; duDir('content', sub, '', kids, 0); du[sub] = kids; }
    duDir('abspath', '', ['wp-admin', 'wp-includes', contentName].join(','), du.abspath, 0);
    save('du', du); say(`wp-content measured: ${du.content.length} entries`);

    // --resume keeps the tables a stopped scan already finished; refs.json is saved after every table
    const refs = (flag('resume', false) && load('refs')) || {};
    for (const t of info.tables) {
      if (refs[t.name]) { say(`  ${t.name}: kept from the earlier run`); continue; }
      if (SKIP_TABLES.test(t.name.slice(info.prefix.length))) { meta.skippedTables.push(t.name); continue; }
      // a table outside WordPress core with this many rows is a log or a counter, not content
      if (!CORE_TABLES.includes(t.name.slice(info.prefix.length)) && t.rows > MAX_ROWS && !flag('all-tables', false)) { meta.skippedTables.push(`${t.name} (${t.rows} rows)`); say(`  ${t.name}: skipped, ${t.rows} rows (--all-tables scans it)`); continue; }
      const short = t.name.slice(info.prefix.length); let limit = short === 'posts' ? 300 : short === 'options' ? 500 : 2000; const hits = []; let after = 0, rows = 0, fails = 0;
      for (;;) { let r; try { r = get(`${NS}/refs?${q({ table: t.name, after, limit })}`); } catch (e) { if (limit > 10 && ++fails < 8) { limit = Math.max(10, Math.floor(limit / 2)); continue; } meta.errors.push(`table ${t.name} after ${after}: ${e.message}`); break; }
        hits.push(...r.hits); rows += r.rows; after = r.next; if (r.done) break; }
      refs[t.name] = hits; save('refs', refs); say(`  ${t.name}: ${rows} rows, ${hits.length} references`);
    }
    try { save('theme', get(NS + '/theme-refs')); } catch (e) { meta.errors.push('theme files: ' + e.message); }
    meta.finished = new Date().toISOString(); save('meta', meta);
    say(meta.errors.length ? `DONE WITH ${meta.errors.length} ERRORS:\n  ` + meta.errors.join('\n  ') : 'scan done. Next: crawl, then report.');
    return;
  }

  if (cmd === 'crawl') {
    if (!site) { console.error('usage: media-audit.js crawl <site> [--dir D] [--max-pages N]'); process.exit(2); }
    const info = load('info'); if (!info) { console.error('run scan first'); process.exit(1); }
    crawlSite(get, info, Number(flag('max-pages', 1500)), say).then(c => {
      save('crawl', c);
      say(`crawl done: ${c.pages} pages, ${c.stylesheets} stylesheets, ${Object.keys(c.tokens).length} upload files seen` + (c.failed.length ? `, ${c.failed.length} pages failed` : ''));
    });
    return;
  }

  if (cmd === 'db') {
    // What takes the space in the database (plugin >= 1.1.0). Read-only: counts and sizes, written to D/DB-REPORT.md.
    if (!site) { console.error('usage: media-audit.js db <site> [--dir D]'); process.exit(2); }
    fs.mkdirSync(rawDir, { recursive: true });
    const info = get(NS + '/info'); let db; try { db = get(NS + '/db'); } catch (e) { console.error(e.http === '404' ? 'the plugin on the site is older than 1.1.0: upload the current assets/wp-media-audit.php' : 'ERROR: ' + e.message); process.exit(1); }
    save('db', { info, db });
    const L = []; const tables = info.tables.slice().sort((a, b) => b.bytes - a.bytes); const total = tables.reduce((s, t) => s + t.bytes, 0);
    const short = n => n.slice(info.prefix.length);
    L.push(`# Database: ${info.home}`, '', `Measured ${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC. Read-only. **${mb(total)} MB in ${tables.length} tables.** Revisions setting: ${db.revisionsSetting}.`, '');
    L.push('## Tables', '', '| Table | Rows (estimate) | MB | WordPress core |', '|---|---:|---:|---|');
    tables.filter(t => t.bytes >= 65536 || !CORE_TABLES.includes(short(t.name))).forEach(t => L.push(`| ${t.name} | ${t.rows} | ${mb(t.bytes)} | ${CORE_TABLES.includes(short(t.name)) ? 'yes' : ''} |`));
    L.push('', '## Posts by type and status', '', '| Type | Status | Rows | MB of content |', '|---|---|---:|---:|');
    db.posts.slice(0, 40).forEach(r => L.push(`| ${r.type} | ${r.status} | ${r.n} | ${mb(Number(r.bytes))} |`));
    L.push('', `Revisions: ${db.revisionsOfLive.n} of existing posts (${mb(db.revisionsOfLive.bytes)} MB), ${db.revisionsOrphan.n} of deleted posts (${mb(db.revisionsOrphan.bytes)} MB). Most revisions: ` + db.revisionsTop.slice(0, 8).map(r => `post ${r.id}: ${r.n} (${mb(Number(r.bytes))} MB)`).join(', ') + '.');
    L.push('', '## Post meta', '', `Meta of posts that no longer exist: ${db.postmetaOrphan.n} rows, ${mb(db.postmetaOrphan.bytes)} MB. Meta of revisions: ${db.postmetaOfRevisions.n} rows, ${mb(db.postmetaOfRevisions.bytes)} MB.`, '', '| Meta key | Rows | MB |', '|---|---:|---:|');
    db.postmetaKeys.slice(0, 20).forEach(r => L.push(`| ${r.k} | ${r.n} | ${mb(Number(r.bytes))} |`));
    L.push('', '## Options', '', `Loaded on every page (autoload): ${db.optionsAutoload.n} options, ${mb(db.optionsAutoload.bytes)} MB. Transients (caches): ${db.optionsTransient.n}, ${mb(db.optionsTransient.bytes)} MB.`, '', '| Option | KB | Autoload |', '|---|---:|---|');
    db.optionsTop.slice(0, 20).forEach(r => L.push(`| ${r.k} | ${(Number(r.bytes) / 1024).toFixed(0)} | ${r.autoload} |`));
    L.push('', '## Other', '', `- Comments: ${db.comments.map(r => `${r.n} ${r.status === '1' ? 'approved' : r.status === '0' ? 'pending' : r.status}${r.type && r.type !== 'comment' ? ' (' + r.type + ')' : ''}`).join(', ') || 'none'}.`, `- Orphans: ${db.commentmetaOrphan.n} comment meta, ${db.termRelOrphan.n} term links, ${db.usermetaOrphan.n} user meta.`, `- Active plugins: ${db.activePlugins.map(x => x.split('/')[0]).join(', ')}.`, '');
    fs.writeFileSync(path.join(dir, 'DB-REPORT.md'), L.join('\n'));
    console.log(`database ${mb(total)} MB in ${tables.length} tables | revisions ${db.revisionsOfLive.n + db.revisionsOrphan.n} | written: ${path.join(dir, 'DB-REPORT.md')}`);
    return;
  }

  if (cmd === 'report') {
    const raw = { info: load('info'), attachments: load('attachments'), files: load('files'), refs: load('refs'), theme: load('theme'), crawl: load('crawl'), du: load('du'), meta: load('meta') };
    if (!raw.info || !raw.attachments || !raw.files || !raw.refs) { console.error('no complete scan in ' + rawDir + ': run scan first'); process.exit(1); }
    const ignoreTables = String(flag('ignore-tables', '') === true ? '' : flag('ignore-tables', '')).split(',').map(s => s.trim()).filter(Boolean);
    const R = analyse(raw, { ignoreTables }); writeReport(dir, raw, R);
    const T = R.totals;
    console.log(`uploads ${mb(R.uploadsBytes)} MB in ${R.uploadsFiles} files | library ${R.attachments.length}: USED ${T.USED.n}, MAYBE ${T.MAYBE.n}, BACKGROUND ${T.BACKGROUND.n} (${mb(T.BACKGROUND.bytes)} MB), UNUSED ${T.UNUSED.n} (${mb(T.UNUSED.bytes)} MB) | orphan files ${R.orphans.length} (${mb(R.orphans.reduce((s, o) => s + o.bytes, 0))} MB)`);
    console.log(raw.crawl ? `cross-check: ${R.crawlOnly.length} items used on public pages that the database scan missed` : 'NOT cross-checked: run crawl, then report again');
    console.log('written: ' + path.join(dir, 'REPORT.md'));
    return;
  }

  console.log(fs.readFileSync(__filename, 'utf8').split('*/')[0].replace(/^#!.*\n\/\*\n?/, '').replace(/^ \* ?/gm, ''));
  process.exit(cmd ? 2 : 0);
}

module.exports = { analyse, writeReport, crawlSite, stemOf, extOf, pathsIn, normText, SKIP_TABLES };
if (require.main === module) main();
