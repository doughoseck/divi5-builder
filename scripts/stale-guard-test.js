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
      // st.recordMethods: keep every request's method and path (the live-check test asserts it only ever GETs).
      if (st.recordMethods) { st.requests = st.requests || []; st.requests.push(opts.method + ' ' + p); fs.writeFileSync(STATE, JSON.stringify(st)); }
      // The site-updates plugin (st.su = { version }), recording what it is asked: GET status, GET with ?refresh, POST refresh.
      const suStatus = () => ({ plugin_version: st.su.version, site: 'https://fake.test', wordpress: { version: '6.8.1' }, php: '8.2', multisite: false, plugins: [], themes: [], translations: 0, last_checked: 'now' });
      const suNew = st.su && st.su.version >= '1.0.2';
      if (p.startsWith('/wp-json/wp-site-updates/v1/') && !st.su) { status = 404; out = { code: 'rest_no_route' }; }
      else if (p === '/wp-json/wp-site-updates/v1/status' && opts.method === 'GET') {
        if (/refresh=1/.test(q)) { st.su.getRefresh = (st.su.getRefresh || 0) + 1; if (suNew) { status = 405; out = { code: 'wpsu_use_post', message: 'since 1.0.2 it is POST /refresh' }; } else { st.su.refreshes = (st.su.refreshes || 0) + 1; out = suStatus(); } }
        else { st.su.gets = (st.su.gets || 0) + 1; out = suStatus(); }
        fs.writeFileSync(STATE, JSON.stringify(st));
      } else if (p === '/wp-json/wp-site-updates/v1/refresh' && opts.method === 'POST') {
        if (!suNew) { status = 404; out = { code: 'rest_no_route', message: 'No route was found' }; }
        else { st.su.refreshes = (st.su.refreshes || 0) + 1; out = suStatus(); fs.writeFileSync(STATE, JSON.stringify(st)); }
      }
      // The combined plugin's status route (st.d5 = the status answer); no st.d5 = the plugin is not on the site.
      else if (p === '/wp-json/divi5-builder/v1/status' && opts.method === 'GET') { if (!st.d5) { status = 404; out = { code: 'rest_no_route' }; } else out = st.d5; }
      // The REST index (st.index = namespaces) and core settings (st.settings = an object, or a number = that HTTP error).
      else if (p === '/wp-json/' && opts.method === 'GET') out = { name: 'fake', url: 'https://fake.test', home: st.home === undefined ? 'https://fake.test' : st.home, namespaces: st.index || ['oembed/1.0', 'wp/v2'] };
      else if (p === '/wp-json/wp/v2/settings' && opts.method === 'GET') { if (typeof st.settings === 'number') { status = st.settings; out = { code: 'rest_forbidden' }; } else out = st.settings || { title: 'fake' }; }
      else if (p === '/wp-json/divi5-builder/v1/design-system') out = { colors: st.colors || {}, presets: st.presets || { module: [], group: [] }, variables: st.variables || [] };
      // Design-system writes (issue #45): the body is recorded; st.dsReply = { status, body } is the plugin's answer.
      else if (/^\/wp-json\/divi5-builder\/v1\/design-system\/(variable(\/archive)?|restore)$/.test(p) && opts.method === 'POST') {
        st.dsPosts = st.dsPosts || []; st.dsPosts.push({ path: p, body: JSON.parse(body || '{}') }); fs.writeFileSync(STATE, JSON.stringify(st));
        if (st.dsReply) { status = st.dsReply.status; out = st.dsReply.body; } else out = { ok: true };
      }
      // The Abilities API (WordPress 6.9+, issue #32). st.abilities = the list (none = the route is absent, i.e. WordPress < 6.9),
      // st.abilitiesPerPage = a page size smaller than the request's, st.abilityReplies = { name: { status, body } },
      // st.abilityRuns = every run asked for (name, raw query, and whether it carried credentials). Core refuses anything but GET
      // on a readonly ability (405). st.pastEnd400: a page past the last one answers 400 rest_invalid_page (as core's list does).
      // A run with no Authorization header is anonymous: st.anonReply = { status, body } (default 401 rest_ability_cannot_execute,
      // the answer core gives a logged-out caller), and when st.auditRows is a list the plugin's refusal row is added to it, one per
      // 10 minutes (st.anonRowsPerCall = n adds n instead, to play a broken limit). history-audit-list answers from st.auditRows.
      else if (/^\/wp-json\/wp-abilities\/v1\/abilities\/?$/.test(p) && opts.method === 'GET') {
        if (!st.abilities) { status = 404; out = { code: 'rest_no_route' }; }
        else {
          const qp = new URLSearchParams(q), per = Math.min(st.abilitiesPerPage || 100, +qp.get('per_page') || 100), pg = +qp.get('page') || 1;
          out = st.abilities.slice((pg - 1) * per, pg * per);
          if (st.pastEnd400 && pg > 1 && !out.length) { status = 400; out = { code: 'rest_invalid_page', message: 'The page number requested is larger than the number of pages available.' }; }
        }
      }
      else if (/^\/wp-json\/wp-abilities\/v1\/abilities\/(.+)\/run$/.test(p)) {
        const name = p.match(/\/v1\/abilities\/(.+)\/run$/)[1];
        const auth = !!(opts.headers && (opts.headers.Authorization || opts.headers.authorization || opts.headers.Cookie || opts.headers.cookie));
        if (opts.method === 'POST' && name !== 'divi5-builder/history-audit-list') {
          // A write ability takes a POST with {"input":{...}} (st.abilityPosts records every one); st.abilityReplies[name] = { status, body } answers.
          st.abilityPosts = st.abilityPosts || []; st.abilityPosts.push({ name, body: JSON.parse(body || '{}'), auth, type: opts.headers['Content-Type'] });
          const rep = (st.abilityReplies || {})[name]; if (rep) { status = rep.status; out = rep.body; } else out = { ok: true };
          fs.writeFileSync(STATE, JSON.stringify(st));
        }
        else if (opts.method !== 'GET') { status = 405; out = { code: 'rest_ability_invalid_method' }; }
        else {
          st.abilityRuns = st.abilityRuns || []; st.abilityRuns.push({ name, query: q, auth });
          const rep = (st.abilityReplies || {})[name];
          if (!auth) {
            const a = st.anonReply || { status: 401, body: { code: 'rest_ability_cannot_execute', message: 'Sorry, you are not allowed to execute this ability.' } };
            status = a.status; out = a.body;
            if (Array.isArray(st.auditRows)) {
              const now = Date.now(); const n = st.anonRowsPerCall || ((!st.anonLast || now - st.anonLast > 600000) ? 1 : 0);
              for (let i = 0; i < n; i++) st.auditRows.unshift({ id: 9000 + st.auditRows.length, time_utc: 'now', user_id: 0, ability: name, args: '{}', status: 'forbidden_anonymous', duration_ms: 1, ip: '203.0.113.50' });
              if (n) st.anonLast = now;
            }
          } else if (name === 'divi5-builder/history-audit-list' && Array.isArray(st.auditRows) && !rep) {
            const want = new URLSearchParams(q).get('input[ability]');
            out = { items: st.auditRows.filter(x => !want || x.ability === want).slice(0, 100), page: 1, per_page: 100, has_more: false };
          } else if (rep) { status = rep.status; out = rep.body; } else out = { items: [], note: 'SECRET-RESULT-VALUE' };
          fs.writeFileSync(STATE, JSON.stringify(st));
        }
      }
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

console.log('--- site-updates.js: reading the status is a GET, refreshing it is a POST (issue #22)');
{
  const SU = process.env.SITE_UPDATES_FILE || path.join(path.dirname(WPJS), 'site-care', 'site-updates.js');
  const su = (version, ...a) => {
    fs.writeFileSync(STATE, JSON.stringify({ pages: {}, posts: {}, et_pb_canvas: {}, su: { version } }));
    const r = spawnSync(process.execPath, [SU, ...a], { encoding: 'utf8', env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: `--require ${__filename.replace(/\\/g, '/')}` }) });
    return { code: r.status, out: r.stdout + r.stderr, su: st().su };
  };
  let r = su('1.0.2', 'status', 'fake', '--dir', path.join(tmp, 'su'));
  check('status refreshes with ONE POST /refresh and no GET ?refresh=1', r.code === 0 && r.su.refreshes === 1 && !r.su.getRefresh && /up to date/.test(r.out), [r.code, r.su, r.out.slice(-300)]);
  r = su('1.0.2', 'status', 'fake', '--no-refresh', '--dir', path.join(tmp, 'su'));
  check('status --no-refresh only reads (a GET), nothing refreshed', r.code === 0 && r.su.gets === 1 && !r.su.refreshes && !r.su.getRefresh, [r.code, r.su]);
  r = su('1.0.1', 'status', 'fake', '--dir', path.join(tmp, 'su'));
  check('a site with the old plugin (1.0.1) is told to install divi5-builder.zip, and nothing is refreshed', r.code !== 0 && /divi5-builder\.zip/.test(r.out) && !r.su.refreshes, [r.code, r.su, r.out.slice(-300)]);
}

console.log('--- the scripts say which switch to flip, and never flip one (issue #37)');
{
  const d5 = on => ({ version: '2.0.0', divi5: true, switches: Object.assign({ media_audit: false, media_clean_up: false, updates: false }, on), stood_aside: {} });
  const run = (script, state, ...a) => {
    fs.writeFileSync(STATE, JSON.stringify(Object.assign({ pages: {}, posts: {}, et_pb_canvas: {} }, state)));
    const r = spawnSync(process.execPath, [path.join(path.dirname(WPJS), script), ...a], { encoding: 'utf8', env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: `--require ${__filename.replace(/\\/g, '/')}` }) });
    return { code: r.status, out: r.stdout + r.stderr };
  };
  const sentence = l => new RegExp('Switch ' + l + ' on in Settings > Divi 5 Builder \\(an administrator must do it in wp-admin\\)\\.');
  const old = /mu-plugins|assets\//;
  let r = run('site-care/site-updates.js', { d5: d5({}) }, 'status', 'fake', '--dir', path.join(tmp, 'su6'));
  check('site-updates.js, Updates switch off: prints the Updates switch sentence, exit 1', r.code === 1 && sentence('Updates').test(r.out) && !old.test(r.out), [r.code, r.out.slice(-300)]);
  r = run('site-care/site-updates.js', {}, 'status', 'fake', '--dir', path.join(tmp, 'su6'));
  check('site-updates.js, no plugin at all: prints the install-the-zip sentence', r.code === 1 && r.out.includes('Install divi5-builder.zip from the latest release (Plugins > Add New > Upload Plugin), then switch Updates on in Settings > Divi 5 Builder.'), [r.code, r.out.slice(-300)]);
  r = run('site-care/media-audit.js', { d5: d5({ updates: true }) }, 'scan', 'fake', '--dir', path.join(tmp, 'ma6'));
  check('media-audit.js scan, Media audit switch off: prints the Media audit switch sentence', r.code === 1 && sentence('Media audit').test(r.out) && !old.test(r.out), [r.code, r.out.slice(-300)]);
  r = run('site-care/media-audit.js', { d5: d5({}) }, 'db', 'fake', '--dir', path.join(tmp, 'ma6'));
  check('media-audit.js db, Media audit switch off: prints the Media audit switch sentence', r.code === 1 && sentence('Media audit').test(r.out) && !old.test(r.out), [r.code, r.out.slice(-300)]);
  r = run('site-care/media-quarantine.js', { d5: d5({}) }, 'status', 'fake', '--dir', path.join(tmp, 'mq6'));
  check('media-quarantine.js status, Media clean-up switch off: prints the Media clean-up switch sentence', r.code === 1 && sentence('Media clean-up').test(r.out) && !old.test(r.out), [r.code, r.out.slice(-300)]);
  r = run('site-care/media-quarantine.js', {}, 'status', 'fake', '--dir', path.join(tmp, 'mq6'));
  check('media-quarantine.js status, no plugin: install-the-zip sentence', r.out.includes('Install divi5-builder.zip') && r.out.includes('switch Media clean-up on'), r.out.slice(-300));
  fs.writeFileSync(STATE, JSON.stringify({ d5: d5({ updates: true }) }));
  r = wp('status');
  check('wp.js status prints the status route as JSON', r.code === 0 && r.json && r.json.switches && r.json.switches.updates === true && r.json.version === '2.0.0', [r.code, r.out, r.err]);
  run('site-care/site-updates.js', { d5: d5({}) }, 'status', 'fake', '--dir', path.join(tmp, 'su6'));
  check("a run with the switch off flips nothing (the fake site's switches and writes unchanged)", st().d5 && st().d5.switches.updates === false && !st().writes, st());
}

console.log('--- divi.js compile: --site checks colours and presets exist, the lint runs, --strict fails (issue #17)');
{
  const DIVI = path.join(path.dirname(WPJS), 'divi.js');
  const dv = (spec, ...a) => {
    const f = file('spec17.json', JSON.stringify(spec)); const o = path.join(tmp, 'out17.html');
    try { fs.unlinkSync(o); } catch (e) {}
    const r = spawnSync(process.execPath, [DIVI, 'compile', f, '--out', o, ...a], { encoding: 'utf8',
      env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: `--require ${__filename.replace(/\\/g, '/')}` }) });
    return { code: r.status, err: r.stderr || '', written: fs.existsSync(o) };
  };
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, colors: { 'gcid-red': { label: 'Red', status: 'active' }, 'gcid-old': { label: 'Old', status: 'archived' } },
    presets: { module: [{ id: 'btnyellow', name: 'Yellow', for: 'divi/button' }], group: [] } }));
  const page = (mods) => ({ sections: [{ rows: [{ columns: [{ modules: mods }] }] }] });
  let r = dv(page([{ type: 'button', text: 'b', bg: 'gcid-red', modulePreset: 'btnyellow' }, { type: 'text', html: '<p>x</p>', color: 'gcid-primary-color' }]), '--site', 'fake');
  check('--site: everything exists (incl. a built-in gcid) -> exit 0 and says so', r.code === 0 && /site check: every colour, variable and preset exists on fake/.test(r.err), r.err);
  r = dv(page([{ type: 'button', text: 'b', bg: 'gcid-nope', modulePreset: 'missingpreset' }, { type: 'text', html: '<p>x</p>', color: 'gcid-old' }]), '--site', 'fake');
  check('--site: a missing colour, a missing preset and an archived colour are each reported; exit 1', r.code === 1 && /gcid-nope does not exist/.test(r.err) && /preset missingpreset does not exist/.test(r.err) && /gcid-old \("Old"\) is archived/.test(r.err), r.err);
  r = dv(page([{ type: 'text', html: '<p>x</p>' }]));
  check('the catalogue lint runs after compile and reports', r.code === 0 && /^lint: /m.test(r.err), r.err);
  r = dv(page([{ type: 'text', html: '<p>x</p>' }]), '--no-lint');
  check('--no-lint skips it', r.code === 0 && !/^lint: /m.test(r.err), r.err);
  const badOption = page([{ type: 'raw', block: 'divi/button', attrs: { button: { innerContent: { desktop: { value: { text: 'x' } } } }, module: { advanced: { alignment: { desktop: { value: 'middle' } } } } } }]);
  r = dv(badOption);
  check('a lint error without --strict: reported, exit 0', r.code === 0 && /"middle" is not an option/.test(r.err) && /use --strict to fail/.test(r.err), r.err);
  r = dv(badOption, '--strict');
  check('a lint error with --strict: exit 1', r.code === 1 && /"middle" is not an option/.test(r.err), r.err);
  r = dv(page([{ type: 'button', text: 'b', colour: '#fff' }]));
  check('an unknown spec key: a WARNING, still compiled (exit 0)', r.code === 0 && /WARNING: unknown key "colour".*did you mean "color"/.test(r.err), r.err);
  r = dv(page([{ type: 'button', text: 'b', colour: '#fff' }]), '--strict');
  check('an unknown spec key with --strict: exit 1, nothing written', r.code === 1 && /spec check failed/.test(r.err) && !r.written, [r.code, r.written, r.err]);
}

console.log('--- the creds file: warn when other users can read it (issue #22)');
{
  const perms = (platform) => { const r = spawnSync(process.execPath, ['-r', __filename, WPJS, 'fake', 'get-page', '10'], { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, WP_BACKUP_DIR: BACKUPS, D5B_PERMS_PLATFORM: platform }) }); return r.stderr; };
  fs.writeFileSync(STATE, JSON.stringify({ pages: { 10: { id: 10, title: 'x', status: 'draft', content: 'x' } } }));
  try { fs.chmodSync(CREDS, 0o644); } catch (e) {}
  const open = (fs.statSync(CREDS).mode & 0o077) !== 0;   // Windows reports every file as readable by all
  check('a creds file readable by others: WARNING with the chmod 600 fix (macOS/Linux)', open && /WARNING: .*can be read by other users.*chmod 600/.test(perms('linux')), perms('linux'));
  check('on Windows the check stays quiet (the permission bits mean nothing there)', !/WARNING/.test(perms('win32')));
  if (process.platform !== 'win32') { fs.chmodSync(CREDS, 0o600); check('a creds file only its owner can read: no warning', !/WARNING/.test(perms('linux'))); }
  else console.log('  SKIP  "chmod 600 gives no warning" (cannot make a 600 file on Windows)');
}

console.log('--- wp.js palette-set is retired: it refuses, points at ds-color-set, and sends nothing (issue #20)');
{
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, recordMethods: true }));
  const colorsFile = file('palette.json', JSON.stringify({ 'gcid-primary-color': '#2B5C7A' }));
  let r = wp('palette-set', '--colors-file', colorsFile);
  check('palette-set exits 1 and names ds-color-set and design-system', r.code === 1 && /ds-color-set/.test(r.err) && /design-system/.test(r.err) && /retired/.test(r.err), [r.code, r.err]);
  r = wp('palette-set', '--colors-file', colorsFile, '--option', 'et_global_colors', '--replace');
  check('... also with the old flags (--option, --replace)', r.code === 1 && /ds-color-set/.test(r.err), [r.code, r.err]);
  check('... and no request reached the site (nothing was written)', !(st().requests || []).length && !st().writes, st().requests);
}

console.log('--- nested variables: {{gvid-…}} placeholders, --site, and the plugin\'s refusals printed (issue #45)');
{
  // The value the Visual Builder stored for "Fluid Heading" on a live Divi 5.18.0 site (wp.js design-system --json --full).
  const LIVE_FLUID = 'clamp( $variable({"type":"content","value":{"name":"gvid-k1iggz3omp","settings":{}}})$, 4vw, $variable({"type":"content","value":{"name":"gvid-4az4m3i650","settings":{}}})$)';
  fs.writeFileSync(STATE, JSON.stringify({ pages: {} }));
  let r = wp('ds-variable-set', '--type', 'numbers', '--label', 'Fluid Heading', '--value', 'clamp( {{gvid-k1iggz3omp}}, 4vw, {{gvid-4az4m3i650}})');
  const sent = (st().dsPosts || [])[0];
  check('ds-variable-set: {{gvid-…}} placeholders are sent as the token the builder stores (byte for byte)', r.code === 0 && sent && sent.path === '/wp-json/divi5-builder/v1/design-system/variable' && sent.body.value === LIVE_FLUID, [r.code, r.err, sent && sent.body.value]);
  fs.writeFileSync(STATE, JSON.stringify({ pages: {} }));
  r = wp('ds-variable-set', '--type', 'strings', '--label', 'X', '--value', 'by {{dc:post_author}}');
  check('ds-variable-set: a {{dc:…}} placeholder is refused, nothing sent', r.code === 1 && /dynamic content/.test(r.err) && !(st().dsPosts || []).length, [r.code, r.err]);
  for (const bad of ['{{gvid-bad$id}} * 2', '{{gvid-Not An Id}} * 2']) {
    r = wp('ds-variable-set', '--type', 'numbers', '--label', 'X', '--value', bad);
    check(`ds-variable-set: a placeholder that is not a variable id ("${bad}") is refused, nothing sent`, r.code === 1 && /is not a variable id/.test(r.err) && !(st().dsPosts || []).length, [r.code, r.err]);
  }
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, dsReply: { status: 409, body: { code: 'variable_in_use', message: 'gvid-k1iggz3omp is used by other active variables', data: { status: 409, id: 'gvid-k1iggz3omp',
    dependents: [{ id: 'gvid-lk82fchj2p', label: 'Fluid Heading', type: 'numbers', direct: true }, { id: 'gvid-hero000001', label: 'Hero heading', type: 'numbers', direct: false }] } } } }));
  r = wp('ds-variable-archive', '--id', 'gvid-k1iggz3omp');
  check('ds-variable-archive: a 409 variable_in_use is printed with every user by id and label (and which are indirect); exit 1', r.code === 1 && /409 variable_in_use/.test(r.err) && /gvid-lk82fchj2p\s+"Fluid Heading"/.test(r.err) && /gvid-hero000001\s+"Hero heading"\s+\(numbers, indirectly\)/.test(r.err) && /nothing was written/.test(r.err) && r.out === '', [r.code, r.err, r.out]);
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, dsReply: { status: 409, body: { code: 'variable_in_use', message: 'x', data: { status: 409, id: 'gvid-new0000001', dependents: [{ id: 'gvid-b', label: 'B', type: 'numbers', direct: true }] } } } }));
  r = wp('ds-restore', '--store', 'variables', '--index', '0');
  check('ds-restore: the same 409 is printed with its users', r.code === 1 && /variable_in_use/.test(r.err) && /gvid-b\s+"B"/.test(r.err), [r.code, r.err]);
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, dsReply: { status: 400, body: { code: 'bad_references', message: 'x', data: { status: 400, references: [{ id: 'gvid-nope', problem: 'missing', message: 'gvid-nope does not exist on this site' }, { id: 'gvid-a', problem: 'cycle', message: 'a cycle: gvid-a -> gvid-b -> gvid-a' }] } } } }));
  r = wp('ds-variable-set', '--type', 'numbers', '--label', 'A', '--id', 'gvid-a', '--value', '{{gvid-b}} + {{gvid-nope}}');
  check('ds-variable-set: a 400 bad_references lists each bad reference and why; exit 1', r.code === 1 && /400 bad_references/.test(r.err) && /gvid-nope: missing/.test(r.err) && /gvid-a -> gvid-b -> gvid-a/.test(r.err), [r.code, r.err]);
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, dsReply: { status: 400, body: { code: 'value_too_complex', message: 'x', data: { status: 400, ids: ['gvid-hostile0001'] } } } }));
  r = wp('ds-variable-archive', '--id', 'gvid-plain000009');
  check('ds-variable-archive: a 400 value_too_complex is one readable line naming the variable; exit 1', r.code === 1 && /400 value_too_complex: the value of gvid-hostile0001 is too convoluted to check for variable references; simplify it\. Nothing was written\./.test(r.err) && !/\{"code"/.test(r.err), [r.code, r.err]);
  // divi.js compile --site: placeholders become tokens, so the site check sees the ids inside a calculation and a mixed text
  const DIVI = path.join(path.dirname(WPJS), 'divi.js');
  const dv = (spec) => { const f = file('spec45.json', JSON.stringify(spec)); const r = spawnSync(process.execPath, [DIVI, 'compile', f, '--out', path.join(tmp, 'out45.html'), '--site', 'fake', '--no-lint'], { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: `--require ${__filename.replace(/\\/g, '/')}` }) }); return { code: r.status, err: r.stderr || '' }; };
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, variables: { numbers: { 'gvid-xdtsp8ywr8': { id: 'gvid-xdtsp8ywr8', label: 'Space', value: '40px', status: 'active' }, 'gvid-old0000001': { id: 'gvid-old0000001', label: 'Old gap', value: '1px', status: 'archived' } },
    strings: { 'gvid-zca7w37pd4': { id: 'gvid-zca7w37pd4', label: 'Copyright', value: 'c', status: 'active' } } } }));
  const sec = (padTop, html) => ({ sections: [{ padding: { top: padTop }, rows: [{ columns: [{ modules: [{ type: 'text', html }] }] }] }] });
  r = dv(sec('{{gvid-xdtsp8ywr8}} * -1', '<p>By {{dc:post_author}} · {{gvid-zca7w37pd4}}</p>'));
  check('--site: variables inside a calculation and a mixed text that exist and are active -> exit 0', r.code === 0 && /site check: every colour, variable and preset exists on fake/.test(r.err), r.err);
  r = dv(sec('{{gvid-old0000001}} / 2', '<p>By {{dc:post_author}} · {{gvid-nosuchvar1}}</p>'));
  check('--site: an archived variable in a calculation and a missing one in a mixed text are each reported; exit 1', r.code === 1 && /design variable gvid-old0000001 \("Old gap"\) is archived/.test(r.err) && /design variable gvid-nosuchvar1 does not exist/.test(r.err) && !/post_author/.test(r.err), r.err);
}

console.log('--- ds-live-test.js: which parts run on which plugin version (no site touched)');
{
  const { plan } = require(path.join(path.dirname(WPJS), 'ds-live-test.js'));
  let p = plan('all', '1.8.4');
  check('all on 1.8.4: colors, variables, presets run; nested is skipped and says it needs 2.0.1', JSON.stringify(p.run) === '["colors","variables","presets"]' && p.skipped.length === 1 && p.skipped[0].part === 'nested' && /2\.0\.1/.test(p.skipped[0].why) && !p.stop, p);
  p = plan('all', '2.0.1');
  check('all on 2.0.1: every part runs, nested included', JSON.stringify(p.run) === '["colors","variables","nested","presets"]' && !p.skipped.length && !p.stop, p);
  p = plan('nested', '2.0.0');
  check('nested on 2.0.0: STOP, needs 2.0.1', !p.run.length && /STOP: "nested" needs the divi5-builder plugin >= 2\.0\.1/.test(p.stop || ''), p);
  p = plan('colors', '1.8.4');
  check('colors on 1.8.4: runs (the minimum stays 1.8.4)', JSON.stringify(p.run) === '["colors"]' && !p.stop, p);
  p = plan('variables', '1.8.3');
  check('variables on 1.8.3: STOP, needs 1.8.4', !p.run.length && /1\.8\.4/.test(p.stop || ''), p);
  p = plan('all', '');
  check('all with no plugin found: STOP', !p.run.length && !!p.stop, p);
  check('2.10.0 counts as newer than 2.0.1 (numeric, not text, compare)', plan('nested', '2.10.0').run.length === 1 && plan('nested', '10.0.0').run.length === 1);
}

console.log('--- rest-post: the run route of a write ability');
{
  const body = file('ability-body.json', JSON.stringify({ input: { id: 12, title: 'New title', expect_hash: 'abc' } }));
  let r = wp('rest-post', 'wp-abilities/v1/abilities/divi5-builder/posts-update/run', '--data-file', body);
  const posts = (st().abilityPosts || []);
  check('rest-post sends one POST to the ability run route with the {input} body as JSON, with credentials', r.code === 0 && posts.length === 1 && posts[0].name === 'divi5-builder/posts-update' && posts[0].body.input.id === 12 && posts[0].body.input.expect_hash === 'abc' && posts[0].auth === true && /json/.test(posts[0].type), r.out || r.err);
  check('the answer is printed as the site gave it', r.json && r.json.ok === true, r.out);
  const s0 = st(); s0.abilityReplies = { 'divi5-builder/posts-update': { status: 409, body: { code: 'stale', message: 'The content changed since you read it.' } } }; fs.writeFileSync(STATE, JSON.stringify(s0));
  r = wp('rest-post', 'wp-abilities/v1/abilities/divi5-builder/posts-update/run', '--data-file', body);
  check('a refusal from the site is shown and exits non-zero', r.code !== 0 && /409/.test(r.err) && /stale/.test(r.err), r.err);
  const before = (st().abilityPosts || []).length;
  for (const bad of ['wp-abilities/v1/abilities', 'wp-abilities/v1/abilities/divi5-builder/posts-update', 'wp-abilities/v1/abilities/divi5-builder/posts-update/run/extra', 'wp-abilities/v1/abilities/a/b/c/run', 'wp-abilities/v1/abilities/../x/y/run', 'wp/v1/abilities/a/b/run', 'wp-json/wp-abilities/v1/abilities/a/b/run']) {
    r = wp('rest-post', bad, '--data-file', body);
    check('rest-post refuses the path ' + bad, r.code !== 0 && /rest-post needs/.test(r.err) && (st().abilityPosts || []).length === before, r.err);
  }
  r = wp('rest-post', 'wp/v2/posts', '--data-file', file('p.json', '{"title":"T"}'));
  check('a wp/v2 route still works', r.code === 0, r.err);
}

console.log('--- secrets');
const all = backups().map(f => fs.readFileSync(f, 'utf8')).join('\n') + JSON.stringify(st());
check('the application password never appears in output or backups', !/aaaa ?bbbb/.test(all), 'password found');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
