#!/usr/bin/env node
/**
 * abilities-live-write-test.js: scripts/abilities-live-write.js against the fake WordPress of stale-guard-test.js (issue #32, task 11).
 *   node scripts/abilities-live-write-test.js
 *   ABILITIES_WRITE_FILE=/path/to/copy.js node scripts/abilities-live-write-test.js    # mutation testing (the copy must sit in scripts/)
 * No site needed. Exit codes under test: 0 all good, 1 an unexpected status or a missing history row, 2 usage or a non-d5b-live- target,
 * 3 a preview was printed and the run needs --confirm.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const SCRIPT = process.env.ABILITIES_WRITE_FILE || path.join(__dirname, 'abilities-live-write.js');
const FAKE = path.join(__dirname, 'stale-guard-test.js');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-livew-'));
const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini'), LEDGER = path.join(tmp, 'ledger.json');
fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');

let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 700))); if (!ok) failed++; };

const SECTION = '<!-- wp:divi/section {"module":{"advanced":{"text":{}}}} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello World"}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
const DIVI = '<!-- wp:divi/placeholder -->' + SECTION + '<!-- wp:divi/section --><!-- wp:divi/row --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
const crypto = require('crypto');
const md5 = s => crypto.createHash('md5').update(s).digest('hex');

// A fresh site: state = the model's options, items = extra seeded items. The ledger file is removed with it.
const fresh = (mdl, items, revs) => {
  try { fs.rmSync(LEDGER); } catch (e) { /* none */ }
  const base = { pages: {}, posts: {}, et_pb_canvas: {}, recordMethods: true, mdl: Object.assign({ next: 1000, items: items || {}, revs: revs || {} }, mdl || {}) };
  fs.writeFileSync(STATE, JSON.stringify(base));
};
// A run keeps the state of the earlier runs (the confirm steps need it).
const run = args => {
  const r = spawnSync(process.execPath, [SCRIPT, 'fake'].concat(args || []), { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, D5B_LIVE_LEDGER: LEDGER, NODE_OPTIONS: `--require ${FAKE.replace(/\\/g, '/')}` }) });
  return { code: r.status, out: r.stdout + r.stderr, st: JSON.parse(fs.readFileSync(STATE, 'utf8')) };
};
const all = []; // every output and every request list, for the whole-suite checks
const go = args => { const r = run(args); all.push(r); return r; };
const calls = r => (r.st.mdl.calls || []);
const items = r => Object.values(r.st.mdl.items);
const rowsOf = r => r.st.mdl.hist;

console.log('--- posts');
fresh();
let r = go(['posts']);
check('exit 0, RESULT line', r.code === 0 && /RESULT: every step passed/.test(r.out), [r.code, r.out]);
check('the sequence: create x2, update x2, wrong-hash update, trash x2, restore, trash',
  JSON.stringify(calls(r).filter(c => c.startsWith('POST')).map(c => c.slice(5))) === JSON.stringify(['posts-create', 'pages-create', 'posts-update', 'pages-update', 'posts-update', 'posts-trash', 'pages-trash', 'posts-restore', 'posts-trash']), calls(r));
check('... the wrong hash line is refused:stale HTTP 409 and expected (it sends content: the fake, as the plugin, needs no hash for a title-only update; final wave C1)',/posts-update  refused:stale  HTTP 409  \(expected stale 409\)/.test(r.out), r.out);
check('... a line per call: ability, ok, HTTP 200, id, status, hash', /^posts-create  ok  HTTP 200  id=\d+  title=d5b-live-posts-\d{8}-\d{4}  status=draft  hash=[0-9a-f]{32}$/m.test(r.out) && /^posts-restore  ok  HTTP 200  id=\d+  .*status=draft/m.test(r.out), r.out);
check('... every item it made is titled d5b-live-posts-<yyyymmdd-hhmm>', items(r).length === 2 && items(r).every(x => /^d5b-live-posts-\d{8}-\d{4}( v2)?$/.test(x.title)), items(r));
check('... the restored post is a draft again then trashed; the page stays trashed', items(r).find(x => x.kind === 'post').status === 'trash' && items(r).find(x => x.kind === 'page').status === 'trash', items(r));
check('... the wrong-hash update changed nothing (title is v2, not "wrong")', items(r).every(x => !/wrong/.test(x.title)), items(r));

console.log('--- an unexpected status fails the run');
fresh({ badWrite: ['posts-update'] });
r = go(['posts']);
check('an unexpected 500 on an update -> exit 1, nothing after it ran', r.code === 1 && /posts-update  refused:internal_error  HTTP 500/.test(r.out) && /FAILED: posts-update wanted ok/.test(r.out) && !calls(r).includes('POST posts-trash'), [r.code, r.out]);

console.log('--- media: a site with a media trash');
fresh();
r = go(['media']);
check('exit 0, upload + update + trash, transport printed as curl', r.code === 0 && /media-upload  ok  HTTP 200  .*transport=curl/.test(r.out) && /media-update  ok/.test(r.out) && /media-trash  ok/.test(r.out), [r.code, r.out]);
check('... the upload asked for the site\'s own w-logo-blue.png (address from the REST index)', JSON.stringify(r.st.mdl.uploadUrls) === '["https://fake.test/wp-includes/images/w-logo-blue.png"]', r.st.mdl.uploadUrls);
check('... the item is trashed, titled d5b-live-media-...', items(r).length === 1 && items(r)[0].status === 'trash' && /^d5b-live-media-/.test(items(r)[0].title), items(r));
check('... the file is named d5b-live-media-<stamp>.png, so its history summaries carry d5b-live- (final wave C2)', /^d5b-live-media-\d{8}-\d{4}\.png$/.test(items(r)[0].file) && rowsOf(r).every(x => x.summary.includes('d5b-live-media-')), [items(r)[0].file, rowsOf(r).map(x => x.summary)]);
fresh({ transport: 'streams' });
r = go(['media', '--image-url', 'https://fake.test/x.png']);
check('a transport that is not curl -> exit 1, FAILED line', r.code === 1 && /FAILED: media-upload transport must be curl, got streams/.test(r.out), [r.code, r.out]);

console.log('--- media: no media trash -> preview, exit 3, then --confirm');
fresh({ mediaTrash: false });
r = go(['media']);
check('exit 3 and the trash was refused with cannot_trash', r.code === 3 && /media-trash  refused:cannot_trash  HTTP 400/.test(r.out), [r.code, r.out]);
const pv = /media-delete-preview  ok  HTTP 200  file=d5b-live-media-\d{8}-\d{4}\.png  used_by=1  expires_at=2026-10-29T12:10:00\+00:00/.test(r.out);
check('... the preview line: filename, used_by count, expires_at', pv, r.out);
const code1 = (/--confirm (CONFIRM-[0-9A-Za-z-]+)/.exec(r.out) || [])[1];
check('... "run again with --confirm <code>", the code appears exactly once', !!code1 && r.out.split(code1).length === 2, r.out);
check('... nothing was deleted', items(r).length === 1 && !calls(r).includes('POST media-delete'), calls(r));
const wrong = go(['media', '--confirm', 'CONFIRM-0-wrongcode']);
check('a wrong code -> exit 1, reported as refused:confirmation_invalid HTTP 409 (final wave C6), the item stays', wrong.code === 1 && /media-delete  refused:confirmation_invalid  HTTP 409/.test(wrong.out) && /FAILED: media-delete wanted ok, got refused:confirmation_invalid \(HTTP 409\)/.test(wrong.out) && items(wrong).length === 1, [wrong.code, wrong.out]);
check('... the wrong code is not echoed', !/wrongcode/.test(wrong.out), wrong.out);
r = go(['media']);
const code2 = (/--confirm (CONFIRM-[0-9A-Za-z-]+)/.exec(r.out) || [])[1];
check('a rerun without --confirm previews the same item again (a fresh code), uploads nothing new', r.code === 3 && !!code2 && code2 !== code1 && calls(r).filter(c => c === 'POST media-upload').length === 1, [r.code, r.out]);
r = go(['media', '--confirm', code2]);
check('--confirm <code> -> media-delete, exit 0, the item is gone', r.code === 0 && /media-delete  ok  HTTP 200  id=\d+  deleted=true/.test(r.out) && items(r).length === 0, [r.code, r.out]);
check('... the code is never printed back', !r.out.includes(code2), r.out);
r = go(['media', '--confirm', code2]);
check('--confirm with nothing waiting -> exit 2, nothing sent', r.code === 2 && /no media delete is waiting/.test(r.out), [r.code, r.out]);

console.log('--- tag');
fresh({}, { 40: { id: 40, kind: 'post', title: 'd5b-live-posts-20260101-0000', status: 'draft', content: '' }, 41: { id: 41, kind: 'post', title: 'My real post d5b-live- no', status: 'draft', content: '' } });
r = go(['tag']);
check('exit 3, assigns to the d5b-live-posts draft it found by title, creates no post', r.code === 3 && calls(r).includes('GET posts-list') && !calls(r).includes('POST posts-create'), [r.code, r.out]);
const tagItem = items(r).find(x => x.kind === 'term');
check('... the tag is named d5b-live-tag-..., assigned to post 40 only', tagItem && /^d5b-live-tag-/.test(tagItem.name) && JSON.stringify(tagItem.posts) === '[40]', tagItem);
check('... preview line: count, children, posts, expires_at; message with the code', /taxonomy-delete-preview  ok  HTTP 200  count=1  children=0  posts=1  expires_at=\S+/.test(r.out) && /--confirm CONFIRM-/.test(r.out), r.out);
const tcode = (/--confirm (CONFIRM-[0-9A-Za-z-]+)/.exec(r.out) || [])[1];
r = go(['tag', '--confirm', tcode]);
check('--confirm -> taxonomy-delete, exit 0, the tag is gone', r.code === 0 && /taxonomy-delete  ok  HTTP 200/.test(r.out) && !items(r).some(x => x.kind === 'term'), [r.code, r.out]);
fresh();
r = go(['tag']);
check('with no d5b-live- post to find: creates a draft post of its own, assigns, trashes it; exit 3', r.code === 3 && calls(r).includes('POST posts-create') && calls(r).includes('POST taxonomy-assign') && items(r).find(x => x.kind === 'post').status === 'trash' && /^d5b-live-tag-/.test(items(r).find(x => x.kind === 'post').title), [r.code, r.out, items(r)]);

console.log('--- menu');
fresh();
r = go(['menu']);
const menuItems = items(r).filter(x => x.kind === 'menuitem');
check('exit 3, a menu with two custom items, reordered (the second is first)', r.code === 3 && menuItems.length === 2 && menuItems.find(x => /b$/.test(x.title)).position === 1 && menuItems.find(x => /a$/.test(x.title)).position === 2 && calls(r).includes('POST menus-reorder'), [r.code, r.out, menuItems]);
check('... preview line prints the item count; the menu and items are named d5b-live-menu-', /menus-delete-preview  ok  HTTP 200  items=2  expires_at=\S+/.test(r.out) && items(r).every(x => /d5b-live-menu-/.test(x.title)), r.out);
const mcode = (/--confirm (CONFIRM-[0-9A-Za-z-]+)/.exec(r.out) || [])[1];
r = go(['menu', '--confirm', mcode]);
check('--confirm -> menus-delete, exit 0, menu and items gone', r.code === 0 && /menus-delete  ok  HTTP 200/.test(r.out) && items(r).length === 0, [r.code, r.out]);

console.log('--- divi');
const rev0 = { id: 50, content: DIVI };
fresh({}, { 7: { id: 7, kind: 'page', title: 'd5b-live-divi-copy', status: 'draft', content: DIVI }, 8: { id: 8, kind: 'page', title: 'Real Home Page', status: 'publish', content: DIVI } }, { 7: [rev0] });
r = go(['divi', '--page', '7']);
check('exit 0: text edit, section no-op, restore; the page ends with its original content', r.code === 0 && items(r).find(x => x.id === 7).content === DIVI, [r.code, r.out]);
check('... the first heading was edited ("Hello World" + " (edited)"), the section replaced with its own markup (changed=false)', /divi-text-edit  ok/.test(r.out) && /divi-section-edit  ok  HTTP 200  .*changed=false/.test(r.out), r.out);
check('... hashes before, after the edit and final are printed; final equals the original', new RegExp(`hashes  before=${md5(DIVI)}  after-edit=[0-9a-f]{32}  final=${md5(DIVI)}  final equals the original`).test(r.out) && !r.out.includes(md5(DIVI) + '  after-edit=' + md5(DIVI)), r.out);
check('... the order: pages-get, revisions-list + revisions-get (BEFORE any edit, final wave C5), text-edit, pages-get, section-edit, revisions-restore', JSON.stringify(calls(r).filter(c => !/^GET revisions-get/.test(c))) === JSON.stringify(['GET pages-get', 'GET revisions-list', 'POST divi-text-edit', 'GET pages-get', 'POST divi-section-edit', 'POST revisions-restore']) && calls(r).indexOf('GET revisions-get') < calls(r).indexOf('POST divi-text-edit'), calls(r));
fresh({}, { 7: { id: 7, kind: 'page', title: 'd5b-live-divi-copy', status: 'draft', content: DIVI } }, { 7: [rev0] });
r = go(['divi', '--page', '7', '--old', 'Hello World', '--new', 'Hi there']);
check('--old/--new are used as given', r.code === 0 && calls(r).includes('POST divi-text-edit'), [r.code, r.out]);
fresh({}, { 8: { id: 8, kind: 'page', title: 'Real Home Page', status: 'publish', content: DIVI } });
r = go(['divi', '--page', '8']);
check('a page not titled d5b-live- -> exit 2, nothing written', r.code === 2 && /does not start with d5b-live-/.test(r.out) && !calls(r).some(c => c.startsWith('POST')), [r.code, r.out]);
r = go(['divi']);
check('no --page -> exit 2, nothing sent', r.code === 2 && /needs --page/.test(r.out), [r.code, r.out]);
fresh({ noRev: true }, { 7: { id: 7, kind: 'page', title: 'd5b-live-divi-copy', status: 'draft', content: DIVI } }, { 7: [{ id: 50, content: 'something else' }] });
r = go(['divi', '--page', '7']);
check('no revision holds the page as it is -> exit 2 "save the copy page once in wp-admin first", NOTHING written (final wave C5)', r.code === 2 && /save the copy page once in wp-admin first/.test(r.out) && !calls(r).some(c => c.startsWith('POST')) && items(r).find(x => x.id === 7).content === DIVI, [r.code, r.out, calls(r)]);

console.log('--- history');
fresh();
go(['posts']);
r = go(['history']);
check('after posts: exit 0, every write has a row', r.code === 0 && /history: all 8 write\(s\) have a row/.test(r.out), [r.code, r.out]);
check('... rows printed as id, time, ability, action, summary, revision_id; only d5b-live- rows', /^  \d+  \S+ \S+  divi5-builder\/posts-create  create  Created post \d+ 'd5b-live-posts-\d{8}-\d{4}'  $/m.test(r.out) || /divi5-builder\/posts-create  create  Created post/.test(r.out), r.out);
check('... history was read with GET and since = today', calls(r).includes('GET history-changes-list'), calls(r));
check('... every update and trash row was read with history-change-get and holds its before copy (final wave C4)', /history: all 5 update\/trash\/delete row\(s\) hold their before copy/.test(r.out) && calls(r).filter(c => c === 'GET history-change-get').length === 5, [r.out, calls(r)]);
for (const [spoil, why] of [['too_large', 'before is too_large'], ['withheld', 'before has a withheld value'], ['missing', 'no before copy']]) {
  fresh({ badBefore: { 'posts-update': spoil } });
  go(['posts']);
  r = go(['history']);
  check(`a posts-update row whose before is ${spoil} -> exit 1, the row named with why`, r.code === 1 && new RegExp('history-change-get  ok  HTTP 200  id=\\d+  id=\\d+  divi5-builder/posts-update  ' + why).test(r.out) && /history: 1 of 5 update\/trash\/delete row\(s\) lack a usable before copy/.test(r.out), [r.code, r.out]);
}
console.log('--- history: rows matched by ability and object id, and paged (final wave C2, C3)');
fresh();
go(['posts']);
{ const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.hist.forEach(x => { x.summary = 'Changed item ' + x.object_id; }); const day = st0.mdl.hist[0].time_utc; for (let i = 0; i < 120; i++) st0.mdl.hist.push({ id: 900 + i, time_utc: day, user_id: 1, ability: 'divi5-builder/other-write', audit_id: 1, object_type: 'post', object_id: 5, action: 'update', revision_id: null, summary: 'Someone else', before: { id: 5 } }); fs.writeFileSync(STATE, JSON.stringify(st0)); }
r = go(['history']);
check('summaries without d5b-live- still match by ability and object id: every write has a row', r.code === 0 && /history: all 8 write\(s\) have a row/.test(r.out), [r.code, r.out]);
check('... has_more is followed: pages 1, 2 and 3 of 50 were read (128 rows)', JSON.stringify(r.st.mdl.histPages) === '[1,2,3]' && /128 change row\(s\) today/.test(r.out), [r.st.mdl.histPages, r.out]);
{ const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.histPages = []; const day = st0.mdl.hist[0].time_utc; for (let i = 0; i < 600; i++) st0.mdl.hist.push({ id: 2000 + i, time_utc: day, user_id: 1, ability: 'divi5-builder/other-write', audit_id: 1, object_type: 'post', object_id: 5, action: 'update', revision_id: null, summary: 'Someone else', before: { id: 5 } }); fs.writeFileSync(STATE, JSON.stringify(st0)); }
r = go(['history']);
check('... at most 10 pages, and the run says it stopped there', r.st.mdl.histPages.length === 10 && /stopped after 10 pages of 50/.test(r.out) && r.code === 0, [r.st.mdl.histPages, r.code, r.out]);
fresh({ noHistory: ['posts-trash'] });
go(['posts']);
r = go(['history']);
check('a write without a row (posts-trash) -> exit 1, MISSING lines', r.code === 1 && /MISSING: no history row for posts-trash/.test(r.out), [r.code, r.out]);
fresh();
r = go(['history']);
check('nothing recorded -> exit 1 (nothing to check)', r.code === 1 && /recorded no write for today/.test(r.out), [r.code, r.out]);

console.log('--- all');
fresh();
r = go(['all']);
check('all without --page: posts, media, tag, menu, history; divi skipped; exit 3 (the tag and the menu wait for a code)', r.code === 3 && /--- divi: skipped/.test(r.out) && /--- history/.test(r.out) && /history: all \d+ write\(s\) have a row/.test(r.out), [r.code, r.out]);
r = go(['all', '--confirm', 'CONFIRM-1-abcdef']);
check('all with --confirm -> exit 2', r.code === 2, [r.code, r.out]);

console.log('--- the d5b-live- guard');
fresh({ mediaTrash: false }, { 5: { id: 5, kind: 'media', title: 'Home photo', status: 'inherit', content: '', file_url: 'https://fake.test/a.png' } });
fs.writeFileSync(LEDGER, JSON.stringify({ entries: [], pending: { media: { id: 5, title: 'Home photo' } } }));
r = go(['media', '--confirm', 'CONFIRM-5-abcdef']);
check('a waiting item whose title is not d5b-live- is never deleted: exit 2, no POST', r.code === 2 && !calls(r).some(c => c.startsWith('POST')) && items(r).length === 1, [r.code, r.out, calls(r)]);
fs.writeFileSync(LEDGER, JSON.stringify({ entries: [], pending: { media: { id: 5, title: 'd5b-live-media-x' } } }));
r = go(['media']);
check('a preview that shows another title is refused (exit 2) and prints no code', r.code === 2 && !/--confirm CONFIRM/.test(r.out), [r.code, r.out]);

console.log('--- usage');
fresh();
for (const [label, args] of [['no step', []], ['a bad step', ['nope']], ['an unknown option', ['posts', '--bogus', 'x']], ['--page not a number', ['divi', '--page', 'abc']], ['--new without --old', ['divi', '--page', '7', '--new', 'x']], ['--confirm on posts', ['posts', '--confirm', 'CONFIRM-1-abcdef']], ['--image-url not http', ['media', '--image-url', 'file:///etc/passwd']]]) {
  r = go(args);
  check(`${label} -> exit 2 and nothing sent`, r.code === 2 && !(r.st.requests || []).length, [r.code, r.out]);
}

console.log('--- what crosses the wire and what is printed');
const allReqs = all.reduce((a, x) => a.concat(x.st.requests || []), []);
check('every request is a GET or POST to a divi5-builder run route, or the REST index (GET)', allReqs.length > 50 && allReqs.every(x => /^(GET|POST) \/wp-json\/wp-abilities\/v1\/abilities\/divi5-builder\/[a-z0-9-]+\/run$/.test(x) || x === 'GET /wp-json/'), allReqs.filter(x => !/^(GET|POST) \/wp-json\/wp-abilities\/v1\/abilities\/divi5-builder\/[a-z0-9-]+\/run$/.test(x) && x !== 'GET /wp-json/'));
check('... a POST only ever goes to a write ability (never to a preview or a read)', all.every(x => (x.st.mdl.calls || []).every(c => !/^POST (pages-get|posts-list|revisions-list|revisions-get|history-|.*-preview)/.test(c))), 'POST to a read');
const outAll = all.map(x => x.out).join('\n');
check('no result value beyond the allowed fields is ever printed (planted secret absent from every run)', !/SECRET-LEAK-VALUE|ERR-MSG-/.test(outAll), outAll.split('\n').filter(l => /SECRET-LEAK|ERR-MSG/.test(l)).slice(0, 3));
check('the application password never appears', !/aaaa ?bbbb/.test(outAll + JSON.stringify(all.map(x => x.st))), 'password found');
const codes = (outAll.match(/--confirm CONFIRM-[0-9A-Za-z-]+/g) || []);
check('every confirmation code was printed in a "run again with --confirm" message only', codes.length > 0 && (outAll.match(/CONFIRM-\d+-\d+/g) || []).length === codes.length, [codes.length, (outAll.match(/CONFIRM-\d+-\d+/g) || []).length]);
check('no ledger holds a code', !/CONFIRM-/.test((fs.existsSync(LEDGER) ? fs.readFileSync(LEDGER, 'utf8') : '')), 'a code is in the ledger');

console.log('--- the source');
const src = fs.readFileSync(SCRIPT, 'utf8').replace(/\r\n?/g, '\n');
const code = src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
check('it names no wp.js command except rest-get and rest-post, and reads no credentials file', !/['"`](update-page|create-page|delete-|set-|quarantine|updates)\b/.test(code) && !/web-creds|WEB_CREDS|\.ini/.test(code), 'a forbidden word is in the code');
check('it never sends DELETE or PUT', !/\b(DELETE|PUT|PATCH)\b/.test(code), 'a forbidden method');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
