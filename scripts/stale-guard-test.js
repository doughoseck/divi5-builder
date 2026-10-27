#!/usr/bin/env node
/**
 * stale-guard-test.js: the "changed since you read it" guard on page, post and canvas writes (issue #14).
 *
 *   node scripts/stale-guard-test.js            # tests scripts/wp.js
 *   WPJS_FILE=/path/to/wp.js node scripts/stale-guard-test.js   # test another copy (mutation testing)
 *
 * No site needed: wp.js runs against an in-memory fake WordPress. This same file, loaded with `node -r`,
 * replaces https.request with a fake that serves pages/posts/canvases from a JSON state file, so the test
 * can change a page "behind wp.js's back" exactly like a save in the Visual Builder. Exits non-zero on failure.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

// ---------------------------------------------------------------- the fake WordPress (preload mode)
if (process.env.FAKE_WP_STATE && require.main !== module) {
  const https = require('https');
  const { EventEmitter } = require('events');
  const STATE = process.env.FAKE_WP_STATE;
  https.request = (opts, cb) => {
    const req = new EventEmitter(); let body = '';
    req.write = d => { body += d; }; req.end = () => setImmediate(() => {
      const st = JSON.parse(fs.readFileSync(STATE, 'utf8'));
      const [p, q = ''] = opts.path.split('?');
      const m = p.match(/^\/wp-json\/wp\/v2\/(pages|posts|et_pb_canvas)(?:\/(\d+))?$/);
      let status = 200, out;
      if (p === '/wp-json/divi5-builder/v1/design-system') out = { colors: st.colors || {}, presets: { module: [], group: [] } };
      else if (!m) { status = 404; out = { code: 'rest_no_route' }; }
      else {
        const [, kind, id] = m; st[kind] = st[kind] || {};
        if (opts.method === 'POST') {
          const b = body ? JSON.parse(body) : {};
          let rec = id ? st[kind][id] : null;
          if (id && !rec) { status = 404; out = { code: 'rest_post_invalid_id' }; }
          else {
            if (!rec) { const nid = String(Object.keys(st[kind]).reduce((a, k) => Math.max(a, +k), 100) + 1); rec = st[kind][nid] = { id: +nid, content: '', title: '', status: 'draft' }; }
            if ('content' in b) rec.content = b.content;
            if ('title' in b) rec.title = b.title;
            if ('status' in b) rec.status = b.status;
            rec.modified_gmt = new Date().toISOString().slice(0, 19);
            st.writes = (st.writes || 0) + 1;
            fs.writeFileSync(STATE, JSON.stringify(st));
            out = view(rec);
          }
        } else if (!id) {
          out = Object.values(st[kind]).map(view);   // a list (list-pages, list-canvases)
        } else {
          // bumpOnGet { "pages/10": n }: just before serving the n-th single GET of that post, change its content,
          // i.e. a builder save that lands between a script's read and its write.
          const key = kind + '/' + id; st.gets = st.gets || {}; st.gets[key] = (st.gets[key] || 0) + 1;
          if (st.bumpOnGet && st.bumpOnGet[key] === st.gets[key] && st[kind][id]) st[kind][id].content = 'BUILDER SAVE MEANWHILE';
          fs.writeFileSync(STATE, JSON.stringify(st));
          const rec = st[kind][id];
          if (!rec) { status = 404; out = { code: 'rest_post_invalid_id' }; } else out = view(rec);
        }
      }
      function view(r) { return { id: r.id, status: r.status, slug: 's' + r.id, link: 'https://fake.test/?p=' + r.id, date: '2026-01-01T00:00:00',
        modified_gmt: r.modified_gmt || '2026-01-01T00:00:00', modified: r.modified_gmt || '2026-01-01T00:00:00',
        title: { raw: r.title, rendered: r.title }, content: { raw: r.content, rendered: r.content } }; }
      const res = new EventEmitter(); res.statusCode = status;
      cb(res); res.emit('data', Buffer.from(JSON.stringify(out))); res.emit('end');
    });
    return req;
  };
  return;
}

// ---------------------------------------------------------------- the test (main mode)
const { spawnSync } = require('child_process');
const WPJS = process.env.WPJS_FILE || path.join(__dirname, 'wp.js');
const md5 = s => crypto.createHash('md5').update(s, 'utf8').digest('hex');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-stale-'));
const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini'), BACKUPS = path.join(tmp, 'backups');
fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');
const PAGE = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"a":"é ü \\u003c"} /--><!-- /wp:divi/placeholder -->';
fs.writeFileSync(STATE, JSON.stringify({
  pages: { 10: { id: 10, title: 'Home', status: 'publish', content: PAGE } },
  posts: { 20: { id: 20, title: 'News', status: 'publish', content: '<p>post v1</p>' } },
  et_pb_canvas: { 30: { id: 30, title: 'Popup', status: 'publish', content: '<!-- wp:divi/section /-->' } },
}));
const st = () => JSON.parse(fs.readFileSync(STATE, 'utf8'));
const edit = (kind, id, content) => { const s = st(); s[kind][id].content = content; fs.writeFileSync(STATE, JSON.stringify(s)); }; // "the Visual Builder saves"
const file = (name, text) => { const f = path.join(tmp, name); fs.writeFileSync(f, text); return f; };
const wp = (...args) => {
  const r = spawnSync(process.execPath, ['-r', __filename, WPJS, 'fake', ...args], { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, WP_BACKUP_DIR: BACKUPS }) });
  let json = null; try { json = JSON.parse(r.stdout); } catch (e) {}
  return { code: r.status, out: r.stdout, err: r.stderr, json };
};
let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 400))); if (!ok) failed++; };
const backups = () => fs.existsSync(BACKUPS) ? fs.readdirSync(BACKUPS, { recursive: true }).filter(f => /\.txt$/.test(f)).map(f => path.join(BACKUPS, f)) : [];

console.log('--- reading gives the hash');
let r = wp('get-page', '10');
check('get-page prints the hash of the stored content', r.code === 0 && r.json && r.json.hash === md5(PAGE), r.out || r.err);
const h1 = r.json && r.json.hash;
r = wp('get-page', '10', '--raw');
check('get-page --raw prints the content byte for byte (md5 of it = the hash)', r.out === PAGE && md5(r.out) === h1, JSON.stringify(r.out));
r = wp('get-canvas', '30', '--raw');
check('get-canvas --raw adds no newline (it used to, and globalize wrote it back)', r.out === '<!-- wp:divi/section /-->', JSON.stringify(r.out));
r = wp('get-post', '20');
check('get-post prints the hash', r.json && r.json.hash === md5('<p>post v1</p>'), r.out || r.err);

console.log('--- a content write needs the hash');
const v2 = file('v2.html', PAGE.replace('é', 'e2'));
let w0 = st().writes || 0;
r = wp('update-page', '10', '--content-file', v2);
check('without --expect-hash or --force: refused, nothing written', r.code !== 0 && (st().writes || 0) === w0 && st().pages[10].content === PAGE, [r.code, r.err]);
r = wp('update-page', '10', '--content-file', v2, '--expect-hash', h1);
check('with the hash from the read: written', r.code === 0 && st().pages[10].content === fs.readFileSync(v2, 'utf8'), [r.code, r.err]);
check('... it reports the NEW hash, the previous one, and storedAsSent', r.json && r.json.hash === md5(fs.readFileSync(v2, 'utf8')) && r.json.previousHash === h1 && r.json.storedAsSent === true, r.json);
check('... and kept what it replaced in the backup folder', r.json && r.json.backup && fs.existsSync(r.json.backup) && fs.readFileSync(r.json.backup, 'utf8') === PAGE, r.json);
const h2 = r.json && r.json.hash;
r = wp('update-page', '10', '--content-file', v2, '--expect-hash', String(h2).toUpperCase());
check('the hash is not case-sensitive', r.code === 0, [r.code, r.err]);

console.log('--- the page changed since it was read (a save in the Visual Builder)');
const hRead = wp('get-page', '10').json.hash;
edit('pages', 10, 'BUILDER SAVE');
w0 = st().writes || 0;
const v3 = file('v3.html', 'MY CHANGE');
r = wp('update-page', '10', '--content-file', v3, '--expect-hash', hRead);
check('the write is refused with exit 3 and a STALE message', r.code === 3 && /STALE/.test(r.err) && /current hash/.test(r.err), [r.code, r.err]);
check('... which tells the agent to STOP and ask the user (someone may be in the Visual Builder), not to retry', /STOP and ask the user/.test(r.err) && /Visual Builder/.test(r.err) && /Do not retry/.test(r.err) && /--force/.test(r.err), r.err);
check('... and says how long ago it was saved (here: just now, so "saved very recently")', /last saved (less than a minute ago|\d+ minutes ago)/.test(r.err) && /saved very recently/.test(r.err), r.err);
check('... and the builder\'s save is untouched', st().pages[10].content === 'BUILDER SAVE' && (st().writes || 0) === w0, st().pages[10].content);
const nb = backups().length;
r = wp('update-page', '10', '--content-file', v3, '--force');
check('--force overwrites anyway', r.code === 0 && st().pages[10].content === 'MY CHANGE', [r.code, r.err]);
check('... after keeping the builder\'s version in a backup', backups().length === nb + 1 && r.json && fs.readFileSync(r.json.backup, 'utf8') === 'BUILDER SAVE', r.json);

console.log('--- the md5 of what a script read works as the hash (what globalize.js does)');
const raw = wp('get-page', '10', '--raw').out;
r = wp('update-page', '10', '--content-file', file('v4.html', raw + '<!-- x -->'), '--expect-hash', md5(raw));
check('md5(get-page --raw) is accepted', r.code === 0 && st().pages[10].content === raw + '<!-- x -->', [r.code, r.err]);

console.log('--- writes that do not touch the content need no hash');
const before = st().pages[10].content;
r = wp('update-page', '10', '--title', 'Home 2');
check('a title-only update goes through without a hash and leaves the content alone', r.code === 0 && st().pages[10].title === 'Home 2' && st().pages[10].content === before, [r.code, r.err]);

console.log('--- posts and canvases have the same guard');
const hp = wp('get-post', '20').json.hash;
edit('posts', 20, '<p>edited elsewhere</p>');
r = wp('update-post', '20', '--content-file', file('p.html', '<p>mine</p>'), '--expect-hash', hp);
check('update-post: stale write refused (exit 3), content untouched', r.code === 3 && st().posts[20].content === '<p>edited elsewhere</p>', [r.code, r.err]);
r = wp('update-post', '20', '--content-file', file('p.html', '<p>mine</p>'), '--expect-hash', md5('<p>edited elsewhere</p>'));
check('update-post: with the current hash, written', r.code === 0 && st().posts[20].content === '<p>mine</p>' && r.json.hash === md5('<p>mine</p>'), [r.code, r.err]);
const hc = wp('get-canvas', '30').json.hash;
edit('et_pb_canvas', 30, '<!-- wp:divi/section {"b":1} /-->');
r = wp('update-canvas', '30', '--content-file', file('c.html', '<!-- wp:divi/section {"c":1} /-->'), '--expect-hash', hc);
check('update-canvas: stale write refused (exit 3), content untouched', r.code === 3 && st().et_pb_canvas[30].content === '<!-- wp:divi/section {"b":1} /-->', [r.code, r.err]);
r = wp('update-canvas', '30', '--content-file', file('c.html', '<!-- wp:divi/section {"c":1} /-->'));
check('update-canvas: no hash and no --force is refused', r.code !== 0 && st().et_pb_canvas[30].content === '<!-- wp:divi/section {"b":1} /-->', [r.code, r.err]);

console.log('--- a new page comes back with its hash, ready for the next write');
r = wp('create-page', '--title', 'New', '--content-file', file('n.html', '<p>new</p>'));
check('create-page prints the hash of what was stored', r.code === 0 && r.json && r.json.hash === md5('<p>new</p>'), [r.code, r.out, r.err]);
const nid = r.json && r.json.id;
r = wp('update-page', String(nid), '--content-file', file('n2.html', '<p>new 2</p>'), '--expect-hash', md5('<p>new</p>'));
check('... and that hash is accepted by update-page', r.code === 0 && st().pages[nid].content === '<p>new 2</p>', [r.code, r.err]);

console.log('--- globalize.js passes the hash of what it read (a bulk writer on real sites)');
{
  const GL = process.env.GLOBALIZE_FILE || path.join(path.dirname(WPJS), 'globalize.js');
  const RED = '<!-- wp:divi/placeholder --><!-- wp:divi/text {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ff0000"}}}}},"builderVersion":"5.15.1"} /--><!-- /wp:divi/placeholder -->';
  const reset = bump => fs.writeFileSync(STATE, JSON.stringify({ pages: { 50: { id: 50, title: 'Red', status: 'publish', content: RED } }, et_pb_canvas: {}, colors: { 'gcid-red': { color: '#ff0000', label: 'Red' } }, bumpOnGet: bump || null }));
  const map = file('map.json', JSON.stringify({ '#ff0000': 'gcid-red' }));
  const glob = (...a) => spawnSync(process.execPath, [GL, 'fake', ...a], { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, WP_BACKUP_DIR: BACKUPS, NODE_OPTIONS: `--require ${__filename.replace(/\\/g, '/')}` }) });   // backslashes get eaten in NODE_OPTIONS
  if (!fs.existsSync(GL)) check('globalize.js found next to wp.js', false, GL);
  else {
    reset();
    let g = glob('colors', '--map', map, '--write', '--dir', path.join(tmp, 'gw1'));
    check('globalize colors --write: the page gets the global colour token', /gcid-red/.test(st().pages[50].content) && !/#ff0000/.test(st().pages[50].content), [g.status, g.stdout.slice(-300), g.stderr.slice(-300)]);
    reset({ 'pages/50': 2 });   // GET 1 = globalize's read, GET 2 = the guard's re-read just before the write
    g = glob('colors', '--map', map, '--write', '--dir', path.join(tmp, 'gw2'));
    check('a builder save between globalize\'s read and write: NOT overwritten', st().pages[50].content === 'BUILDER SAVE MEANWHILE', [st().pages[50].content.slice(0, 80), g.stdout.slice(-300)]);
    check('... and globalize reports that page as not written (STALE), not "ok"', /STALE/.test(g.stdout + g.stderr) , (g.stdout + g.stderr).slice(-400));
  }
}

console.log('--- secrets');
const all = backups().map(f => fs.readFileSync(f, 'utf8')).join('\n') + JSON.stringify(st());
check('the application password never appears in output or backups', !/aaaa ?bbbb/.test(all), 'password found');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
