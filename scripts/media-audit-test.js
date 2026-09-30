#!/usr/bin/env node
/*
 * media-audit-test.js: the classification of media-audit.js on a made-up site. No site needed.
 *
 *   node scripts/media-audit-test.js
 *
 * Set MEDIA_AUDIT_FILE to test another copy of media-audit.js (used for mutation testing).
 */
const path = require('path');
const { analyse, stemOf, extOf, pathsIn, SKIP_TABLES } = require(process.env.MEDIA_AUDIT_FILE || path.join(__dirname, 'media-audit.js'));
let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail !== undefined ? '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 400) : '')); if (!ok) failed++; };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

console.log('--- names');
check('a size, a scaled copy, an edit and a .webp copy share one stem', ['2020/05/Photo.jpg', '2020/05/photo-300x200.jpg', '2020/05/photo-scaled.jpg', '2020/05/photo-scaled-768x512.jpg', '2020/05/photo-e1612345678901-150x150.jpg', '2020/05/photo.jpg.webp', '2020/05/photo-300x200.jpg.webp', '2020/05/photo.webp'].every(f => stemOf(f) === '2020/05/photo'));
check('another folder is another stem', stemOf('2020/06/photo.jpg') !== stemOf('2020/05/photo.jpg'));
check('photo-2.jpg is not a copy of photo.jpg', stemOf('photo-2.jpg') === 'photo-2');
check('the type of photo.jpg.webp is jpg, of photo.webp is webp', extOf('photo.jpg.webp') === 'jpg' && extOf('photo.webp') === 'webp' && extOf('a/b.PNG') === 'png');
check('paths in crawled HTML follow the plugin\'s rules', same(pathsIn('<img src="https://x.example/wp-content/uploads/2020/05/a.jpg?v=1"> url(\\/wp-content\\/uploads\\/b%20c.png)', 'wp-content/uploads').sort(), ['2020/05/a.jpg', 'b c.png']));
check('list and log tables are skipped, content tables are not', ['ewwwio_images', 'wfKnownFileList', 'yoast_indexable', 'frmt_form_views', 'actionscheduler_logs', 'redirection_404'].every(t => SKIP_TABLES.test(t)) && ['posts', 'postmeta', 'options', 'revslider_slides', 'frmt_form_entry_meta', 'eg_grids'].every(t => !SKIP_TABLES.test(t)));

console.log('--- a made-up site');
const att = (id, file, files, more) => ({ id, title: 't' + id, mime: /pdf$/.test(file) ? 'application/pdf' : 'image/jpeg', parent: 0, date: '2020-05-01 10:00:00', file, files: files || [file], ...more });
const attachments = [
  att(1, '2020/05/a.jpg', ['2020/05/a.jpg', '2020/05/a-300x200.jpg', '2020/05/a-150x150.jpg']),
  att(2, '2020/05/b-scaled.jpg', ['2020/05/b-scaled.jpg', '2020/05/b.jpg', '2020/05/b-300x200.jpg']),
  att(3, '2020/06/c.png', ['2020/06/c.png', '2020/06/c.png']),   // the same file listed twice
  att(4, '2020/06/d.pdf'),
  att(5, '2020/06/e.jpg', ['2020/06/e.jpg', '2020/06/e-150x150.jpg']),
  att(6, '2020/07/f.jpg', null, { parent: 100 }),
  att(7, '2020/07/g.jpg'),
  att(9, '2020/08/i.jpg'),
  att(10, '2020/08/j.jpg'),
  att(11, '2020/08/j.jpg'),
  att(12, '2020/08/K.JPG'),
  att(13, '2020/09/m.jpg'),
  att(14, '2020/09/m.png'),
  att(15, '2020/09/n.jpg'),
  att(16, '2020/09/o.jpg'),
  att(17, '2020/10/p.jpg'), att(18, '2020/10/q.jpg'), att(19, '2020/10/r.jpg'), att(20, '2020/10/s.jpg'), att(21, '2020/10/t.jpg'),
];
for (let i = 0; i < 60; i++) attachments.push(att(1000 + i, `2021/01/fill${i}.jpg`));
const files = [];
const put = (rel, bytes) => files.push([rel, bytes, 1600000000]);
for (const a of attachments) if (a.id !== 9) for (const f of a.files) if (!files.some(x => x[0] === f)) put(f, 1000);
put('2020/06/e-999x999.jpg', 70); put('2020/06/e.jpg.webp', 30);           // copies WordPress does not know about
put('2019/01/old.zip', 5000000); put('2019/01/ref.pdf', 400); put('et-cache/1/x.css', 10); put('backup.sql', 9000000);
const refs = {
  wp_posts: [
    ['p', '2020/05/a-300x200.jpg', 's', 'live', 2, ['posts|59|page|publish']],
    ['p', '2020/05/b-768x512.jpg', 's', 'live', 1, ['posts|59|page|publish']],   // a size that is not in the metadata
    ['p', '2020/05', 's', 'live', 1, ['posts|59|page|publish']],                  // a folder, not a file
    ['p', '2021/01/gone.jpg', 's', 'live', 1, ['posts|60|page|publish']],
    ['p', '2019/01/ref.pdf', 's', 'live', 1, ['posts|60|page|publish']],
    ['g', 100, 's', 'live', 1, ['posts|100|post|publish']],
    ['p', '2020/08/k.jpg', 's', 'live', 1, ['posts|61|page|publish']],
    ['p', '2020/09/m-300x300.png', 's', 'live', 1, ['posts|61|page|publish']],
    ['p', '2020/09/n.jpg', 's', 'revision', 3, ['posts|900|revision|inherit']],
    ['i', 16, 's', 'revision', 1, ['posts|900|revision|inherit']],
    ['i', 16, 'w', 'live', 1, ['posts|62|page|publish']],
  ],
  wp_postmeta: [
    ['i', 3, 's', 'trash', 1, ['postmeta|70|_thumbnail_id|page|trash']],
    ['i', 4, 'w', 'live', 1, ['postmeta|71|related_item|page|publish']],
    ['i', 10, 's', 'live', 1, ['postmeta|72|_thumbnail_id|page|publish']],
    // the old Divi 4 copy of a converted page
    ['p', '2020/10/p.jpg', 's', 'live', 1, ['postmeta|15|_et_pb_divi_4_content|page|publish']],
    ['p', '2020/10/q.jpg', 's', 'live', 5, ['postmeta|15|_et_pb_divi_4_content|page|publish', 'postmeta|16|_et_pb_divi_4_content|page|publish', 'postmeta|17|_et_pb_old_content|page|publish']],
    ['p', '2020/10/r.jpg', 's', 'live', 2, ['postmeta|15|_et_pb_divi_4_content|page|publish', 'postmeta|20|image_1|artist|publish']],
  ],
  wp_oldplugin: [['p', '2020/10/s.jpg', 's', 'live', 1, ['oldplugin|1|data']]],
  wp_pmxi_images: [['p', '2020/10/t.jpg', 's', 'live', 1, ['pmxi_images|1|image_url']]],
  wp_filelist: attachments.map(a => ['p', a.file, 's', 'live', 1, ['filelist|' + a.id + '|path']]),
};
const raw = { info: { prefix: 'wp_', home: 'https://x.example', uploadsBase: 'wp-content/uploads' }, attachments, files, refs, theme: { hits: [] }, crawl: { pages: 3, tokens: { '2020/07/g.jpg': ['https://x.example/about/'] } } };
const R = analyse(raw);
const A = id => R.attachments.find(a => a.id === id);
const st = id => A(id).status;

check('a table that lists the whole library is an index, not a use; so is a known import log', same(R.indexLike.slice().sort(), ['wp_filelist', 'wp_pmxi_images']), R.indexLike);
check('17: only in the old Divi 4 copy of a page -> BACKGROUND', st(17) === 'BACKGROUND', st(17));
check('18: 5 places of which only 3 are known, all old copies -> cannot know, stays USED', st(18) === 'USED', st(18));
check('19: an old copy and a live custom field -> USED', st(19) === 'USED', st(19));
check('20: only in a plugin\'s own table -> USED', st(20) === 'USED', st(20));
const Ri = analyse(raw, { ignoreTables: ['oldplugin'] });
check('20: ... and BACKGROUND once that table is named as no longer used; nothing else changes', Ri.attachments.find(a => a.id === 20).status === 'BACKGROUND' && Ri.attachments.every(a => a.id === 20 || a.status === st(a.id)) && Ri.indexLike.includes('wp_oldplugin'));
check('21: only in the import plugin\'s image log -> BACKGROUND', st(21) === 'BACKGROUND', st(21));
check('1: a size file in a live page -> USED', st(1) === 'USED', st(1));
check('2: a size that is not in the metadata still counts for its image -> USED', st(2) === 'USED', st(2));
check('3: featured image of a trashed page only -> BACKGROUND', st(3) === 'BACKGROUND', st(3));
check('4: a bare number only -> MAYBE', st(4) === 'MAYBE', st(4));
check('5: only the index table mentions it -> BACKGROUND, not UNUSED', st(5) === 'BACKGROUND', st(5));
check('6: uploaded to a post that has a [gallery] without ids -> USED', st(6) === 'USED', st(6));
check('7: only the crawl saw it -> USED, and reported as missed by the database scan', st(7) === 'USED' && A(7).dbStatus === 'BACKGROUND' && same(R.crawlOnly, [7]), [st(7), A(7).dbStatus, R.crawlOnly]);
check('9: its file is not on disk -> listed as missing', A(9).missing === true && R.ghosts.some(a => a.id === 9) && R.ghosts.length === 1, R.ghosts.map(a => a.id));
check('10 and 11 own the same file: the unused one takes the used one\'s status', st(10) === 'USED' && st(11) === 'USED' && A(11).sharedWith === 10 && !A(10).sharedWith, [st(10), st(11), A(11).sharedWith]);
check('12: K.JPG referred to as k.jpg -> USED', st(12) === 'USED', st(12));
check('13 m.jpg and 14 m.png: an unknown size of the .png counts for the .png only', st(14) === 'USED' && st(13) === 'BACKGROUND', [st(13), st(14)]);
check('15: only a revision refers to it -> BACKGROUND', st(15) === 'BACKGROUND', st(15));
check('16: strong in a revision + weak in a live page -> MAYBE', st(16) === 'MAYBE', st(16));
check('the 60 fillers, only in the index table -> BACKGROUND', attachments.filter(a => a.id >= 1000).every(a => st(a.id) === 'BACKGROUND'));

const R2 = analyse({ ...raw, refs: { wp_posts: refs.wp_posts, wp_postmeta: refs.wp_postmeta }, crawl: null });
const st2 = id => R2.attachments.find(a => a.id === id).status;
check('without the index table and the crawl: 5, 7 and the fillers are UNUSED', st2(5) === 'UNUSED' && st2(7) === 'UNUSED' && st2(1000) === 'UNUSED' && st2(9) === 'UNUSED', [st2(5), st2(7), st2(1000), st2(9)]);
check('... and nothing is reported as missed by the scan', R2.crawlOnly.length === 0);
const R3 = analyse({ ...raw, refs: { wp_posts: refs.wp_filelist }, crawl: null });
check('a WordPress core table is never an index, however much of the library it mentions', R3.indexLike.length === 0 && R3.attachments.find(a => a.id === 1000).status === 'USED', R3.indexLike);
check('3: a file listed twice is counted once', A(3).bytes === 1000 && A(3).onDisk === 1, [A(3).bytes, A(3).onDisk]);
check('totals add up to the library', Object.values(R2.totals).reduce((s, t) => s + t.n, 0) === attachments.length);

console.log('--- sizes and files on disk');
check('5: 2 files of its own (2000 bytes) and 2 extra copies (100 bytes)', A(5).bytes === 2000 && A(5).onDisk === 2 && A(5).strayBytes === 100 && same(A(5).stray.slice().sort(), ['2020/06/e-999x999.jpg', '2020/06/e.jpg.webp']), [A(5).bytes, A(5).strayBytes, A(5).stray]);
check('2: three files, 3000 bytes', A(2).bytes === 3000 && A(2).onDisk === 3, [A(2).bytes, A(2).onDisk]);
check('the UNUSED total counts the extra copies too', R2.totals.UNUSED.bytes === R2.attachments.filter(a => a.status === 'UNUSED').reduce((s, a) => s + a.bytes + a.strayBytes, 0) && R2.totals.UNUSED.bytes > 0);
check('orphans: exactly the 4 files no library item owns, biggest first', same(R.orphans.map(o => o.rel), ['backup.sql', '2019/01/old.zip', '2019/01/ref.pdf', 'et-cache/1/x.css']), R.orphans.map(o => o.rel));
check('an orphan that a live page links to is marked referenced, the others are not', same(R.orphans.map(o => o.referenced), [false, false, true, false]), R.orphans.map(o => o.referenced));
check('a reference to a file that does not exist is reported; a folder is not', same(R.broken.map(b => b.token), ['2021/01/gone.jpg']), R.broken);
const fo = k => R.folders.find(f => f.folder === k);
check('folders: a year is one row, plugin folders go one level deeper, loose files have their own row', fo('2019') && fo('2019').orphan === 2 && fo('2019').orphanRef === 1 && fo('et-cache/1') && fo('(files in the uploads folder itself)').orphanBytes === 9000000, R.folders.map(f => f.folder));
check('every byte on disk is counted once', R.uploadsBytes === files.reduce((s, f) => s + f[1], 0) && R.folders.reduce((s, f) => s + f.bytes, 0) === R.uploadsBytes && R.folders.reduce((s, f) => s + f.ownedBytes + f.strayBytes + f.orphanBytes, 0) === R.uploadsBytes);

console.log('--- the quarantine plan');
const { buildPlan } = require(process.env.MEDIA_QUARANTINE_FILE || path.join(__dirname, 'media-quarantine.js'));
const names = P => P.files.map(f => f[0]);
let P = buildPlan(R2, { status: ['UNUSED'] });
check('UNUSED items: all their files move, extra copies on disk too', ['2020/06/e.jpg', '2020/06/e-150x150.jpg', '2020/06/e-999x999.jpg', '2020/06/e.jpg.webp', '2020/09/m.jpg', '2020/07/g.jpg'].every(f => names(P).includes(f)), names(P).slice(0, 12));
check('no file of a USED, MAYBE or BACKGROUND item is in it, and no orphan', names(P).every(f => { const d = R2.disk.get(f); const id = d.owner !== null ? d.owner : d.strayOf; return id !== null && R2.attachments.find(a => a.id === id).status === 'UNUSED'; }) && !names(P).includes('2020/05/a.jpg') && !names(P).includes('2020/09/m.png') && !names(P).includes('2019/01/old.zip'));
check('the plan adds up: bytes = the sum of its files, items = the UNUSED count', P.bytes === P.files.reduce((s, f) => s + f[1], 0) && P.items === R2.totals.UNUSED.n && P.bytes === R2.totals.UNUSED.bytes, [P.bytes, R2.totals.UNUSED.bytes, P.items]);
P = buildPlan(R2, { status: ['UNUSED'], ids: [5, 1, 3, 99999] });
check('with a reviewed list: only the listed items; a USED or BACKGROUND one on the list is held back, an unknown id too', P.items === 1 && names(P).every(f => f.startsWith('2020/06/e')) && P.files.length === 4 && P.heldBack.length === 3 && P.heldBack.some(h => /item 1 .*USED/.test(h.join(' '))) && P.heldBack.some(h => /item 3 .*BACKGROUND/.test(h.join(' '))) && P.heldBack.some(h => /99999.*not in the scan/.test(h.join(' '))), P.heldBack);
P = buildPlan(R2, { status: ['UNUSED', 'BACKGROUND'], ids: [3] });
check('BACKGROUND moves only when asked for', names(P).join() === '2020/06/c.png' && buildPlan(R2, { status: ['UNUSED'], ids: [3] }).files.length === 0);
P = buildPlan(R2, { status: [], orphanFolders: ['2019'] });
check('orphan folder: the unreferenced file moves, the referenced one is held back, other folders are left', names(P).join() === '2019/01/old.zip' && P.heldBack.length === 1 && P.heldBack[0][0] === '2019/01/ref.pdf' && P.items === 0, [names(P), P.heldBack]);
check('... unless asked to include referenced files', names(buildPlan(R2, { status: [], orphanFolders: ['2019/'], includeReferenced: true })).join() === '2019/01/old.zip,2019/01/ref.pdf');
check('folder 201 does not match 2019', buildPlan(R2, { status: [], orphanFolders: ['201'] }).files.length === 0);
const twin = analyse({ info: raw.info, attachments: [att(1, 'x/a.jpg'), att(2, 'x/a.jpg'), att(3, 'x/m.jpg'), att(4, 'x/m.png')], files: [['x/a.jpg', 10, 1], ['x/m.jpg', 10, 1], ['x/m.png', 10, 1], ['x/m-9x9.gif', 5, 1]], refs: {}, crawl: { pages: 1, tokens: {} } });
P = buildPlan(twin, { status: ['UNUSED'], ids: [1, 3] });
check('a file that an item which stays also owns is held back; so is a copy that could belong to an item which stays', names(P).join() === 'x/m.jpg' && P.heldBack.length === 2 && /item 2/.test(P.heldBack[0][1]) && /item 4/.test(P.heldBack[1][1]), [names(P), P.heldBack]);
P = buildPlan(twin, { status: ['UNUSED'] });
check('... and both move when both items are chosen', names(P).join() === 'x/a.jpg,x/m-9x9.gif,x/m.jpg,x/m.png' && P.heldBack.length === 0, [names(P), P.heldBack]);

console.log('--- the report files');
const fs = require('fs'); const os = require('os');
const { writeReport } = require(process.env.MEDIA_AUDIT_FILE || path.join(__dirname, 'media-audit.js'));
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'media-audit-test-'));
const md = writeReport(tmp, { ...raw, meta: { finished: '2020-01-01T00:00:00Z', skippedTables: ['wp_ewwwio_images'], errors: [] }, du: { content: [{ path: 'plugins', dir: true, bytes: 5, files: 1 }], abspath: [{ path: 'old-backup.zip', bytes: 2097152 }], big: [['wp-content/x.zip', 6000000, 1600000000]], plugins: [{ path: 'plugins/a', dir: true, bytes: 5, files: 1 }] } }, R).join('\n');
const read = f => fs.readFileSync(path.join(tmp, f), 'utf8');
const unused = read('review-unused.csv').trim().split('\r\n');
check('review-unused.csv lists exactly the UNUSED items', unused.length - 1 === R.totals.UNUSED.n && unused.slice(1).every(l => l.startsWith('UNUSED,')), unused.length);
const all = read('attachments.csv').trim().split('\r\n');
check('attachments.csv has every item once, UNUSED first and USED last', all.length - 1 === attachments.length && /^(UNUSED|BACKGROUND),/.test(all[1]) && all[all.length - 1].startsWith('USED,'), [all[1], all[all.length - 1]]);
check('the row of item 7 says the database scan missed it; item 9 says its file is missing', /^USED,7,.*database scan alone missed it/m.test(read('attachments.csv')) && /,9,.*FILE MISSING/m.test(read('attachments.csv')));
check('orphan-files.csv: biggest first, and the referenced one says yes', /^8\.6,9000000,backup\.sql,/m.test(read('orphan-files.csv')) && /2019\/01\/ref\.pdf,2020-09-13,yes/.test(read('orphan-files.csv')), read('orphan-files.csv'));
check('REPORT.md says why items are BACKGROUND and which plugin table alone keeps an item USED', /- an old builder copy of a page \+ the table wp_filelist: 1 items/.test(md) && /- old revisions \+ the table wp_filelist: 1 items/.test(md) && /- the table wp_filelist \+ trashed posts: 1 items/.test(md) && /USED only because a plugin's own table mentions them: wp_oldplugin 1 items/.test(md), md.split('\n').filter(l => /^- /.test(l)).join(' | '));
check('REPORT.md names the missed item, the index table, the skipped table and the root backup',/1 library items are used on public pages but the database scan alone did not find them/.test(md) && /wp_filelist/.test(md) && /wp_ewwwio_images/.test(md) && /old-backup\.zip/.test(md));
fs.rmSync(tmp, { recursive: true, force: true });

console.log('\n' + (failed ? `FAILED: ${failed} of ${n}` : `ALL ${n} CHECKS PASSED`));
process.exit(failed ? 1 : 0);
