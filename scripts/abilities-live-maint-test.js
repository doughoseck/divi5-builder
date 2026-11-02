#!/usr/bin/env node
/**
 * abilities-live-maint-test.js: scripts/abilities-live-maint.js against a fake WordPress (issue #32, release 2.4.0).
 *   node scripts/abilities-live-maint-test.js
 *   ABILITIES_MAINT_FILE=/path/to/copy.js node scripts/abilities-live-maint-test.js    # mutation testing (the copy must sit in scripts/)
 * No site needed, nothing is installed, deleted or sent: this file, loaded with `node -r` into wp.js, replaces https.request with a
 * fake of the 2.4.0 maintenance abilities that keeps its state in a JSON file. Exit codes under test: 0 all good, 1 an unexpected
 * status, a missing history row or a missing kept copy, 2 usage or a guard (hello-dolly, d5b-live-user), 3 a plan was printed and
 * the step needs --confirm go.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

// The plugin's own sentence (d5b_human_check() in maint-common.php): the script must print it once per run.
const HC = 'Please look at the site yourself now, in a browser and on a phone: check for layout breaks, missing images, errors in the browser console, forms that no longer work and raw shortcodes showing as text. The page check only compares status, error text and size, so finding nothing there does not mean the site is fine, and it cannot see any of these. There is no substitute for the human check and it only takes a few seconds.';

// ---------------------------------------------------------------- the fake WordPress (preload mode)
if (process.env.FAKE_MAINT_STATE && require.main !== module) {
  const https = require('https');
  const { EventEmitter } = require('events');
  const STATE = process.env.FAKE_MAINT_STATE;
  // m.bad = { short: code } answers that ability 400 with the code; m.noHistory = shorts that write no row; m.keptNull = shorts
  // whose result AND row keep no copy; m.rowKeptNull = shorts whose row keeps no copy (the result still says it did);
  // m.revertActivate / m.revertSwitch = the page check got worse and the plugin put things back; m.noHuman = no human_check;
  // m.installFile = the plugin file plugins-install reports; m.wrongLogin = the login users-create stores; m.mailFail = a failed send;
  // m.noKeptCopies = plugins-status lists no kept copy; m.previewLogin = the login users-delete-preview shows; m.noMarker = a role
  // change sets no identity marker (so the second reset is not refused: a broken 24 h block).
  // Every answer carries a planted secret (SECRET-LEAK-VALUE) that the script must never print.
  const READ = ['plugins-status', 'themes-status', 'plugins-delete-preview', 'users-delete-preview', 'users-list', 'users-get', 'history-changes-list', 'history-change-get'];
  const modelRun = (m, short, method, q, body) => {
    m.calls.push(method + ' ' + short);
    const E = (s, code, data) => [s, { code, message: 'ERR-MSG-' + code, data: Object.assign({ status: s }, data || {}) }];
    if (READ.includes(short) !== (method === 'GET')) return [405, { code: 'rest_ability_invalid_method' }];
    const input = {};
    if (method === 'GET') { for (const [k, v] of new URLSearchParams(q)) { const mm = k.match(/^input\[([a-z_]+)\]$/); if (mm) input[mm[1]] = v; } }
    else Object.assign(input, JSON.parse(body || '{}').input || {});
    if ((m.bad || {})[short]) return E(400, m.bad[short]);
    const nid = () => ++m.next;
    const human = () => ((m.noHuman || []).includes(short) ? {} : { human_check: HC });
    const pages = { before: [{ url: 'https://fake.test/', status: 200 }], after: [{ url: 'https://fake.test/', status: 200 }], problems: [], unavailable: false, secret: 'SECRET-LEAK-VALUE' };
    const keptOf = s => ((m.keptNull || []).includes(s) ? null : { bytes: 2048, files: 3 });
    const row = (ability, type, oid, action, summary, before, after) => {
      if ((m.noHistory || []).includes(ability)) return 0;
      const id = m.hist.length + 1;
      m.hist.unshift({ id, time_utc: new Date().toISOString().slice(0, 19).replace('T', ' '), user_id: 1, user_login: 'tester', ability: 'divi5-builder/' + ability, audit_id: 1, object_type: type, object_id: oid, action, revision_id: null, summary, before, after });
      return id;
    };
    const rowKept = s => ((m.keptNull || []).includes(s) || (m.rowKeptNull || []).includes(s) ? null : { path: '/srv/site/wp-content/uploads/d5b-history/9-abc/plugin/x', bytes: 2048, files: 3 });
    const code = (k) => { const c = 'CONFIRM-' + nid(); m.codes[k] = c; return c; };
    const useCode = (k) => { if (!input.confirmation || m.codes[k] !== input.confirmation) return E(409, 'confirmation_invalid', { reason: 'missing' }); delete m.codes[k]; return null; };
    const prow = f => { const p = m.plugins[f]; const nv = p.nv && p.nv !== p.version ? p.nv : null; return { item: f, name: p.name || f, version: p.version, active: !!p.active, new_version: nv, kind: nv ? 'minor' : null, blocked: p.blocked || '' }; };
    const self = f => /^divi5-builder\//.test(f);
    const urec = id => { const u = m.users[id]; return { id: +id, login: u.login, email: u.email, display_name: u.login, roles: u.roles.slice(), registered: '2026-11-02 10:00:00', meta: { nickname: ['SECRET-LEAK-VALUE'] } }; };

    switch (short) {
      case 'plugins-status': {
        const kept = m.hist.filter(r => r.object_type === 'plugin' && ['update', 'rollback', 'delete'].includes(r.action) && r.after && r.after.kept).map(r => ({ plugin: r.before.plugin, version: r.before.version, history_id: r.id, kept_at: '2026-11-02T10:00:00+00:00' }));
        return [200, { plugin_version: '2.4.0', site: 'https://fake.test/', plugins: Object.keys(m.plugins).sort().map(prow), themes: [], wordpress: { version: '6.9' }, kept_copies: m.noKeptCopies ? [] : kept, secret: 'SECRET-LEAK-VALUE' }];
      }
      case 'themes-status':
        return [200, { themes: Object.keys(m.themes).sort().map(t => ({ item: t, name: t, version: m.themes[t].version, active: t === m.stylesheet || t === m.template, new_version: null, kind: null, blocked: '' })), kept_copies: [], secret: 'SECRET-LEAK-VALUE' }];
      case 'plugins-install': {
        const slug = String(input.slug || '');
        if (Object.keys(m.plugins).some(f => f.startsWith(slug + '/'))) return E(400, 'exists');
        const file = m.installFile || slug + '/hello.php';
        m.plugins[file] = { name: 'Hello Dolly', version: '1.7.2', active: false, nv: null };
        const hid = row('plugins-install', 'plugin', 0, 'install', 'Installed plugin ' + slug, null, { slug, version: '1.7.2' });
        return [200, { installed: true, slug, plugin: file, version: '1.7.2', active: false, history_id: hid, secret: 'SECRET-LEAK-VALUE' }];
      }
      case 'plugins-activate': case 'plugins-deactivate': {
        const f = String(input.plugin || ''); const p = m.plugins[f];
        if (self(f)) return E(400, 'self');
        if (!p) return E(404, 'not_found');
        const on = short === 'plugins-activate';
        if (!!p.active === on) return E(400, on ? 'already_active' : 'already_inactive');
        const reverted = on && !!m.revertActivate;
        if (!reverted) p.active = on;
        const hid = row(short, 'plugin', 0, on ? 'activate' : 'deactivate', (on ? 'Activated' : 'Deactivated') + ' plugin ' + f, { plugin: f, active: [] }, { active: [], reverted });
        return [200, Object.assign({ plugin: f, network: false, active: !!p.active, reverted, problems: reverted ? ['home: fatal error text'] : [], pages, history_id: hid }, human())];
      }
      case 'plugins-delete-preview': {
        const f = String(input.plugin || ''); const p = m.plugins[f];
        if (self(f)) return E(400, 'self');
        if (!p) return E(404, 'not_found');
        if (p.active) return E(400, 'active');
        return [200, { plugin: f, version: p.version, bytes: 2048, has_uninstall: false, note: 'SECRET-LEAK-VALUE', confirmation: code('pd:' + f), expires_at: '2026-11-02T10:10:00+00:00' }];
      }
      case 'plugins-delete': {
        const f = String(input.plugin || ''); const p = m.plugins[f];
        if (!p) return E(404, 'not_found');
        const bad = useCode('pd:' + f); if (bad) return bad;
        if (p.active) return E(400, 'active');
        const hid = row(short, 'plugin', 0, 'delete', 'Deleted plugin ' + f, { plugin: f, version: p.version }, { deleted: true, kept: rowKept(short), uninstall_ran: false });
        delete m.plugins[f];
        return [200, { plugin: f, version: p.version, deleted: true, keep_data: false, uninstall_ran: false, kept: keptOf(short), history_id: hid }];
      }
      case 'plugins-update': {
        const f = String(input.plugin || ''); const p = m.plugins[f];
        if (self(f)) return E(400, 'self');
        if (!p) return E(404, 'not_found');
        if (!p.nv || p.nv === p.version) return E(400, 'already_up_to_date');
        const from = p.version; p.version = p.nv;
        const hid = row(short, 'plugin', 0, 'update', 'Updated plugin ' + f + ' from ' + from + ' to ' + p.version, { plugin: f, version: from }, { to: p.version, kept: rowKept(short), pages });
        return [200, Object.assign({ plugin: f, from, to: p.version, version: p.version, updated: true, kept: keptOf(short), history_id: hid, rolled_back: false, problems: [], pages }, human())];
      }
      case 'plugins-rollback': {
        const f = String(input.plugin || ''); const hid0 = Number(input.history_id);
        if (self(f)) return E(400, 'self');
        const r = m.hist.find(x => x.id === hid0 && x.object_type === 'plugin' && ['update', 'rollback', 'delete'].includes(x.action) && x.before && x.before.plugin === f);
        if (!r || !r.after || !r.after.kept) return E(404, 'not_found');
        const p = m.plugins[f] || (m.plugins[f] = { name: f, version: '', active: false, nv: null });
        const replaced = p.version; p.version = r.before.version;
        const hid = row(short, 'plugin', 0, 'rollback', 'Rolled back plugin ' + f + ' to ' + p.version, { plugin: f, version: replaced, from_history_id: hid0 }, { kept: rowKept(short) });
        return [200, Object.assign({ plugin: f, restored_from: hid0, history_id: hid, version: p.version, replaced_version: replaced, kept: keptOf(short), active: !!p.active, problems: [], pages }, human())];
      }
      case 'themes-switch': {
        const s = String(input.stylesheet || '');
        if (!m.themes[s]) return E(404, 'not_found');
        if (s === m.stylesheet) return E(400, 'already_active');
        const previous = { stylesheet: m.stylesheet, template: m.template };
        const reverted = !!m.revertSwitch;
        if (!reverted) { m.stylesheet = s; m.template = s; }
        const hid = row(short, 'theme', 0, 'switch', 'Switched the theme from ' + previous.stylesheet + ' to ' + s, previous, { stylesheet: m.stylesheet, reverted });
        return [200, Object.assign({ stylesheet: m.stylesheet, template: m.template, previous, active: true, reverted, problems: reverted ? ['home: cut off'] : [], pages, history_id: hid }, human())];
      }
      case 'users-create': {
        const login = String(input.login || ''), email = String(input.email || '');
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) || !login || !input.role) return E(400, 'invalid_input');
        if (Object.values(m.users).some(u => u.login === login || u.email === email)) return E(400, 'exists');
        const id = nid(); m.users[id] = { login: m.wrongLogin || login, email, roles: [input.role] };
        const hid = row(short, 'user', id, 'create', 'Created user ' + id + " '" + login + "' (" + input.role + ')', null, urec(id));
        return [200, Object.assign(urec(id), { notified: true, note: 'WordPress emailed the user its own link to set a password.', history_id: hid })];
      }
      case 'users-list': {
        const s = String(input.search || '');
        return [200, { items: Object.keys(m.users).filter(id => !s || m.users[id].login.includes(s)).map(id => { const r = urec(id); delete r.meta; return r; }), page: 1, per_page: 20, has_more: false }];
      }
      case 'users-password-reset': {
        const id = Number(input.id);
        if (!m.users[id]) return E(404, 'not_found');
        if (id === 1) return E(400, 'own_account');
        if (m.marker[id]) return E(400, 'recently_changed');
        if (m.resets[id]) return E(400, 'too_soon');
        m.resets[id] = true;
        row(short, 'user', id, 'password_reset', 'Sent a password reset link to user ' + id, null, { sent: true });
        return [200, { sent: true }];
      }
      case 'users-update': {
        const id = Number(input.id);
        if (!m.users[id]) return E(404, 'not_found');
        if (id === 1) return E(400, 'own_account');
        const before = urec(id);
        if (input.role) { m.users[id].roles = [input.role]; m.marker[id] = !m.noMarker; }
        const hid = row(short, 'user', id, 'update', 'Updated user ' + id, before, urec(id));
        return [200, Object.assign(urec(id), { history_id: hid })];
      }
      case 'users-delete-preview': {
        const id = Number(input.id), to = Number(input.reassign_to);
        if (!m.users[id]) return E(404, 'not_found');
        if (id === 1) return E(400, 'own_account');
        if (!m.users[to] || to === id) return E(400, 'invalid_input');
        return [200, { user: Object.assign(urec(id), m.previewLogin ? { login: m.previewLogin } : {}), posts_by_type: { post: 0 }, posts_total: 0, comments: 0, reassign_to: to, confirmation: code('ud:' + id + ':' + to), expires_at: '2026-11-02T10:10:00+00:00' }];
      }
      case 'users-delete': {
        const id = Number(input.id), to = Number(input.reassign_to);
        if (!m.users[id]) return E(404, 'not_found');
        const bad = useCode('ud:' + id + ':' + to); if (bad) return bad;
        const hid = row(short, 'user', id, 'delete', 'Deleted user ' + id, Object.assign(urec(id), { reassign_to: to, posts: [] }), null);
        delete m.users[id];
        return [200, { deleted: true, reassigned_to: to, posts_reassigned: 0, comments_left: 0, history_id: hid }];
      }
      case 'email-send-test': {
        const to = String(input.to || '');
        if (!/^(me|admin|user:[1-9][0-9]*)$/.test(to)) return E(400, 'invalid_input');
        const sent = !m.mailFail;
        m.mails.push(to);
        const hid = row(short, 'email', 1, 'send', 'Sent a test email to ' + to, null, { to, sent });
        return [200, { sent, mailer_error: sent ? null : 'Could not instantiate mail function. SECRET-LEAK-VALUE', history_id: hid }];
      }
      case 'history-changes-list': {
        let rows = m.hist.slice();
        if (input.since) rows = rows.filter(r => r.time_utc.slice(0, 10) >= input.since);
        if (input.ability) rows = rows.filter(r => r.ability === input.ability);
        if (input.object_type) rows = rows.filter(r => r.object_type === input.object_type);
        if (input.object_id) rows = rows.filter(r => String(r.object_id) === String(input.object_id));
        const per = +input.per_page || 20, page = +input.page || 1;
        return [200, { items: rows.slice((page - 1) * per, page * per).map(r => Object.assign({}, r, { before: undefined, after: undefined })), page, per_page: per, has_more: rows.length > page * per }];
      }
      case 'history-change-get': {
        const r = m.hist.find(x => String(x.id) === String(input.id)); if (!r) return E(404, 'not_found');
        const pub = side => { if (!side || typeof side !== 'object') return side; const o = Object.assign({}, side); if (o.kept && typeof o.kept === 'object') o.kept = { bytes: o.kept.bytes, files: o.kept.files }; return o; };
        return [200, Object.assign({}, r, { before: pub(r.before), after: pub(r.after) })];
      }
    }
    return E(404, 'rest_ability_not_found');
  };

  https.request = (opts, cb) => {
    const req = new EventEmitter(); let body = '';
    req.write = d => { body += d; }; req.end = () => setImmediate(() => {
      const st = JSON.parse(fs.readFileSync(STATE, 'utf8'));
      const [p, q = ''] = opts.path.split('?');
      st.requests = st.requests || []; st.requests.push(opts.method + ' ' + p);
      let status = 404, out = { code: 'rest_no_route' };
      if (p === '/wp-json/' && opts.method === 'GET') { status = 200; out = { name: 'fake', url: 'https://fake.test', home: 'https://fake.test', namespaces: ['wp/v2', 'wp-abilities/v1'] }; }
      else if (/^\/wp-json\/wp-abilities\/v1\/abilities\/divi5-builder\/[a-z0-9-]+\/run$/.test(p)) {
        const r = modelRun(st.mdl, p.match(/\/divi5-builder\/([a-z0-9-]+)\/run$/)[1], opts.method, q, body); status = r[0]; out = r[1];
      }
      fs.writeFileSync(STATE, JSON.stringify(st));
      const res = new EventEmitter(); res.statusCode = status;
      cb(res); res.emit('data', Buffer.from(JSON.stringify(out))); res.emit('end');
    });
    req.on = (ev, fn) => EventEmitter.prototype.on.call(req, ev, fn);
    return req;
  };
  return;
}

// ---------------------------------------------------------------- the tests
const { spawnSync } = require('child_process');
const SCRIPT = process.env.ABILITIES_MAINT_FILE || path.join(__dirname, 'abilities-live-maint.js');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-livem-'));
const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini'), LEDGER = path.join(tmp, 'ledger.json');
fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');

let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 900))); if (!ok) failed++; };

const fresh = mdl => {
  try { fs.rmSync(LEDGER); } catch (e) { /* none */ }
  fs.writeFileSync(STATE, JSON.stringify({ mdl: Object.assign({
    next: 100, hist: [], codes: {}, calls: [], resets: {}, marker: {}, mails: [],
    plugins: { 'akismet/akismet.php': { name: 'Akismet', version: '5.0', active: true, nv: '5.1' }, 'classic-editor/classic-editor.php': { name: 'Classic Editor', version: '1.6', active: false, nv: null }, 'blocked-one/blocked-one.php': { name: 'Blocked', version: '1.0', active: true, nv: '2.0', blocked: 'php' }, 'divi5-builder/divi5-builder.php': { name: 'Divi 5 Builder', version: '2.4.0', active: true, nv: '2.4.1' } },
    themes: { Divi: { version: '5.0' }, twentytwentyfive: { version: '1.1' } }, stylesheet: 'Divi', template: 'Divi',
    users: { 1: { login: 'tester', email: 'tester@fake.test', roles: ['administrator'] }, 2: { login: 'editor-anna', email: 'anna@fake.test', roles: ['editor'] } },
  }, mdl || {}) }));
};
const all = [];
let seen = 0;
const go = args => {
  const r = spawnSync(process.execPath, [SCRIPT, 'fake'].concat(args || []), { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_MAINT_STATE: STATE, WEB_CREDS_PATH: CREDS, D5B_LIVE_MAINT_LEDGER: LEDGER, NODE_OPTIONS: `--require ${__filename.replace(/\\/g, '/')}` }) });
  const st = JSON.parse(fs.readFileSync(STATE, 'utf8'));
  const x = { code: r.status, out: r.stdout + r.stderr, st, m: st.mdl, calls: st.mdl.calls.slice(seen) };
  seen = st.mdl.calls.length; all.push(x); return x;
};
const start = mdl => { fresh(mdl); seen = 0; };
const posts = r => r.calls.filter(c => c.startsWith('POST')).map(c => c.slice(5));
const hcCount = r => r.out.split('There is no substitute for the human check').length - 1;
let r;

console.log('--- plugin');
start();
r = go(['plugin']);
check('without --confirm go: the plan, exit 3, nothing sent', r.code === 3 && /^plan: /m.test(r.out) && /hello-dolly/.test(r.out) && /--confirm go/.test(r.out) && !r.calls.length, [r.code, r.out, r.calls]);
r = go(['plugin', '--confirm', 'go']);
check('with --confirm go: exit 0, every step passed', r.code === 0 && /RESULT: every step passed/.test(r.out), [r.code, r.out]);
check('... install, activate, deactivate, delete preview, delete, the delete row, the status', JSON.stringify(r.calls) === JSON.stringify(['POST plugins-install', 'POST plugins-activate', 'POST plugins-deactivate', 'GET plugins-delete-preview', 'POST plugins-delete', 'GET history-change-get', 'GET plugins-status']), r.calls);
check('... hello-dolly is gone, nothing else was touched', !Object.keys(r.m.plugins).some(f => f.startsWith('hello-dolly/')) && r.m.plugins['akismet/akismet.php'].active === true && Object.keys(r.m.plugins).length === 4, r.m.plugins);
check('... the kept copy of the delete is named (row and kept_copies)', /kept copy: plugins-delete row \d+ keeps 3 file\(s\)/.test(r.out) && /kept_copies lists hello-dolly\/hello\.php/.test(r.out), r.out);
check('... human_check printed exactly once, at the end', hcCount(r) === 1 && /HUMAN CHECK: Please look at the site yourself/.test(r.out) && r.out.indexOf('HUMAN CHECK') > r.out.indexOf('--- plugin'), [hcCount(r), r.out]);
start({ keptNull: ['plugins-delete'] });
r = go(['plugin', '--confirm', 'go']);
check('KEPT COPY: a delete with no kept copy -> exit 1, named', r.code === 1 && /plugins-delete kept no copy/.test(r.out), [r.code, r.out]);
start({ rowKeptNull: ['plugins-delete'] });
r = go(['plugin', '--confirm', 'go']);
check('KEPT COPY: a delete whose result says kept but whose row holds none -> exit 1', r.code === 1 && /row \d+ holds no kept copy/.test(r.out), [r.code, r.out]);
start({ noKeptCopies: true });
r = go(['plugin', '--confirm', 'go']);
check('KEPT COPY: plugins-status kept_copies does not list the deleted plugin -> exit 1', r.code === 1 && /kept_copies does not list hello-dolly\/hello\.php/.test(r.out), [r.code, r.out]);
start({ revertActivate: true });
r = go(['plugin', '--confirm', 'go']);
check('an activation the plugin reverted -> exit 1, no deactivate, still deleted (clean-up)', r.code === 1 && /was reverted/.test(r.out) && !posts(r).includes('plugins-deactivate') && posts(r).includes('plugins-delete') && !r.m.plugins['hello-dolly/hello.php'], [r.code, r.out, r.calls]);
start({ installFile: 'akismet-two/akismet.php' });
r = go(['plugin', '--confirm', 'go']);
check('GUARD: an install that reports another plugin file -> exit 2, nothing activated or deleted', r.code === 2 && /hello-dolly/.test(r.out) && JSON.stringify(posts(r)) === '["plugins-install"]', [r.code, r.out, r.calls]);
start({ plugins: { 'hello-dolly/hello.php': { name: 'Hello Dolly', version: '1.7.2', active: false, nv: null }, 'akismet/akismet.php': { name: 'Akismet', version: '5.0', active: true, nv: '5.1' } } });
r = go(['plugin', '--confirm', 'go']);
check('a hello-dolly left by an earlier run (exists): used as it is, exit 0, gone after', r.code === 0 && /already installed/.test(r.out) && !r.m.plugins['hello-dolly/hello.php'], [r.code, r.out]);
start({ noHuman: ['plugins-activate'] });
r = go(['plugin', '--confirm', 'go']);
check('an activate result without human_check -> exit 1', r.code === 1 && /plugins-activate gave no human_check/.test(r.out), [r.code, r.out]);
start({ bad: { 'plugins-activate': 'activation_failed' } });
r = go(['plugin', '--confirm', 'go']);
check('a failed activation -> exit 1, the plugin is still deleted', r.code === 1 && /refused:activation_failed/.test(r.out) && !r.m.plugins['hello-dolly/hello.php'], [r.code, r.out]);

console.log('--- theme-switch');
start();
r = go(['theme-switch', '--confirm', 'go']);
check('no --theme -> exit 2, nothing sent', r.code === 2 && /--theme/.test(r.out) && !r.calls.length, [r.code, r.out]);
r = go(['theme-switch', '--theme', 'twentytwentyfive']);
check('without --confirm go: the plan, exit 3, no POST', r.code === 3 && /^plan: switch the theme from Divi to twentytwentyfive/m.test(r.out) && !posts(r).length, [r.code, r.out]);
r = go(['theme-switch', '--theme', 'twentytwentyfive', '--confirm', 'go']);
check('with --confirm go: switch, switch back, exit 0, Divi active again', r.code === 0 && JSON.stringify(posts(r)) === '["themes-switch","themes-switch"]' && r.m.stylesheet === 'Divi', [r.code, r.out, r.calls]);
check('... human_check printed once', hcCount(r) === 1, hcCount(r));
r = go(['theme-switch', '--theme', 'nope', '--confirm', 'go']);
check('a theme that is not installed -> exit 2, no POST', r.code === 2 && /not installed/.test(r.out) && !posts(r).length, [r.code, r.out]);
r = go(['theme-switch', '--theme', 'Divi', '--confirm', 'go']);
check('the active theme -> exit 2, no POST', r.code === 2 && /already active/.test(r.out) && !posts(r).length, [r.code, r.out]);
start({ revertSwitch: true });
r = go(['theme-switch', '--theme', 'twentytwentyfive', '--confirm', 'go']);
check('a switch the plugin reverted -> exit 1, no second switch, Divi active', r.code === 1 && /was reverted/.test(r.out) && JSON.stringify(posts(r)) === '["themes-switch"]' && r.m.stylesheet === 'Divi', [r.code, r.out, r.calls]);

console.log('--- update');
start();
r = go(['update', '--item', 'akismet/akismet.php']);
check('an update waiting: status, update, rollback, status; exit 0', r.code === 0 && JSON.stringify(r.calls) === JSON.stringify(['GET plugins-status', 'POST plugins-update', 'POST plugins-rollback', 'GET plugins-status']), [r.code, r.out, r.calls]);
check('... back on 5.0 and still active', r.m.plugins['akismet/akismet.php'].version === '5.0' && r.m.plugins['akismet/akismet.php'].active === true, r.m.plugins['akismet/akismet.php']);
check('... kept copies printed for the update and the rollback; human_check once', /plugins-update  ok .*kept=2048 bytes\/3 files/.test(r.out) && /plugins-rollback  ok .*kept=2048 bytes\/3 files/.test(r.out) && hcCount(r) === 1, [r.out, hcCount(r)]);
r = go(['update', '--item', 'classic-editor/classic-editor.php']);
check('no update waiting: a skip note, exit 0, no POST', r.code === 0 && /skipped: no update is waiting for classic-editor\/classic-editor\.php/.test(r.out) && !posts(r).length, [r.code, r.out]);
r = go(['update', '--item', 'blocked-one/blocked-one.php']);
check('an update that cannot run (blocked): a skip note, exit 0, no POST', r.code === 0 && /skipped: .*blocked/.test(r.out) && !posts(r).length, [r.code, r.out]);
r = go(['update']);
check('no --item -> exit 2, nothing sent', r.code === 2 && /--item/.test(r.out) && !r.calls.length, [r.code, r.out]);
r = go(['update', '--item', 'nope/nope.php']);
check('an --item that is not installed -> exit 2, no POST', r.code === 2 && /not installed/.test(r.out) && !posts(r).length, [r.code, r.out]);
r = go(['update', '--item', 'divi5-builder/divi5-builder.php']);
check('this plugin itself -> exit 2, nothing sent', r.code === 2 && !r.calls.length, [r.code, r.out]);
start({ keptNull: ['plugins-update'] });
r = go(['update', '--item', 'akismet/akismet.php']);
check('KEPT COPY: an update with no kept copy -> exit 1, the old version named, no rollback tried', r.code === 1 && /plugins-update kept no copy/.test(r.out) && !posts(r).includes('plugins-rollback'), [r.code, r.out]);
start({ bad: { 'plugins-rollback': 'copy_missing' } });
r = go(['update', '--item', 'akismet/akismet.php']);
check('a rollback that fails -> exit 1 and the put-back message names the history row', r.code === 1 && /plugins-rollback with history_id \d+/.test(r.out), [r.code, r.out]);

console.log('--- user');
start();
r = go(['user']);
check('without --confirm go: the plan, exit 3, nothing sent', r.code === 3 && /^plan: /m.test(r.out) && /d5b-live-user/.test(r.out) && !r.calls.length, [r.code, r.out]);
r = go(['user', '--confirm', 'go']);
check('with --confirm go: exit 0', r.code === 0 && /RESULT: every step passed/.test(r.out), [r.code, r.out]);
check('... create, reset, role, reset refused, caller from the history, preview, delete, list', JSON.stringify(r.calls) === JSON.stringify(['POST users-create', 'POST users-password-reset', 'POST users-update', 'POST users-password-reset', 'GET history-changes-list', 'GET users-delete-preview', 'POST users-delete', 'GET users-list']), r.calls);
check('... the user is gone, the others untouched', Object.keys(r.m.users).join() === '1,2' && r.m.users[2].roles[0] === 'editor', r.m.users);
check('... the reset after the role change was refused with recently_changed (the 24 h block)', /users-password-reset  refused:recently_changed/.test(r.out) && /\(expected recently_changed\)/.test(r.out), r.out);
check('... created as a subscriber on the site\'s own domain, deleted reassigning to the caller (1)', r.m.hist.some(x => x.ability === 'divi5-builder/users-create' && /\(subscriber\)/.test(x.summary) && x.after.email === 'd5b-live-user@fake.test') && r.m.hist.some(x => x.ability === 'divi5-builder/users-delete' && x.before.reassign_to === 1), r.m.hist);
check('... no email address printed', !/@fake\.test/.test(r.out), r.out);
start({ wrongLogin: 'someone-else' });
r = go(['user', '--confirm', 'go']);
check('GUARD: users-create reports a login that is not d5b-live-user -> exit 2, nothing more sent', r.code === 2 && /d5b-live-user/.test(r.out) && JSON.stringify(posts(r)) === '["users-create"]', [r.code, r.out, r.calls]);
start({ previewLogin: 'editor-anna' });
r = go(['user', '--confirm', 'go']);
check('GUARD: a delete preview that names another login -> exit 2, never deleted', r.code === 2 && /is not d5b-live-user/.test(r.out) && !posts(r).includes('users-delete'), [r.code, r.out, r.calls]);
start({ noMarker: true });
r = go(['user', '--confirm', 'go']);
check('a second reset link that is NOT refused after the role change -> exit 1 (the 24 h block is checked)', r.code === 1 && /\(WANTED recently_changed\)/.test(r.out), [r.code, r.out]);
start({ users: { 1: { login: 'tester', email: 'tester@fake.test', roles: ['administrator'] }, 7: { login: 'd5b-live-user', email: 'd5b-live-user@fake.test', roles: ['subscriber'] } } });
r = go(['user', '--confirm', 'go']);
check('a d5b-live-user left by an earlier run (exists): used, exit 0, deleted', r.code === 0 && /already exists/.test(r.out) && !r.m.users[7], [r.code, r.out]);
start({ bad: { 'users-update': 'not_allowed' } });
r = go(['user', '--confirm', 'go']);
check('a role change that fails -> exit 1 and the user is still deleted', r.code === 1 && Object.keys(r.m.users).join() === '1,2', [r.code, r.out]);

console.log('--- email');
start();
r = go(['email']);
check('sends to me, exit 0', r.code === 0 && JSON.stringify(posts(r)) === '["email-send-test"]' && JSON.stringify(r.m.mails) === '["me"]' && /sent=true/.test(r.out), [r.code, r.out]);
check('... the human check line is printed once (nothing a page shows changed)', /^HUMAN CHECK: /m.test(r.out) && r.out.split('HUMAN CHECK:').length === 2, r.out);
start({ mailFail: true });
r = go(['email']);
check('a failed send -> exit 1, the mailer error is not printed', r.code === 1 && /mailer_error/.test(r.out) && !/Could not instantiate/.test(r.out), [r.code, r.out]);

console.log('--- history');
start();
go(['plugin', '--confirm', 'go']); go(['update', '--item', 'akismet/akismet.php']); go(['theme-switch', '--theme', 'twentytwentyfive', '--confirm', 'go']); go(['user', '--confirm', 'go']); go(['email']);
r = go(['history']);
check('after every step: exit 0, every write has a row', r.code === 0 && /history: all 13 write\(s\) have a row/.test(r.out), [r.code, r.out]);
check('... every update, rollback and delete row of a plugin or theme holds its kept copy (3)', /history: all 3 update\/rollback\/delete row\(s\) hold their kept copy/.test(r.out), r.out);
start({ noHistory: ['plugins-rollback'] });
go(['update', '--item', 'akismet/akismet.php']);
r = go(['history']);
check('a write without a row -> exit 1, MISSING line', r.code === 1 && /MISSING: no history row for plugins-rollback/.test(r.out), [r.code, r.out]);
start({ rowKeptNull: ['plugins-rollback'] });
go(['update', '--item', 'akismet/akismet.php']);
r = go(['history']);
check('KEPT COPY: a rollback row without kept -> exit 1, the row named', r.code === 1 && /history-change-get .*plugins-rollback  no kept copy/.test(r.out) && /1 of 2 update\/rollback\/delete row\(s\) lack their kept copy/.test(r.out), [r.code, r.out]);
start();
r = go(['history']);
check('nothing recorded -> exit 1 (nothing to check)', r.code === 1 && /recorded no write for today/.test(r.out), [r.code, r.out]);

console.log('--- all');
start();
r = go(['all']);
check('all without --confirm go, --item or --theme: plans for plugin and user, update and theme-switch skipped, email and history run; exit 3', r.code === 3 && /--- update: skipped \(no --item\)/.test(r.out) && /--- theme-switch: skipped \(no --theme\)/.test(r.out) && JSON.stringify(posts(r)) === '["email-send-test"]' && /history: all 1 write\(s\) have a row/.test(r.out), [r.code, r.out, r.calls]);
start();
r = go(['all', '--item', 'akismet/akismet.php', '--theme', 'twentytwentyfive', '--confirm', 'go']);
check('all with everything: exit 0, everything put back, human_check once', r.code === 0 && r.m.stylesheet === 'Divi' && r.m.plugins['akismet/akismet.php'].version === '5.0' && !r.m.plugins['hello-dolly/hello.php'] && Object.keys(r.m.users).join() === '1,2' && hcCount(r) === 1, [r.code, r.out]);

console.log('--- usage');
start();
for (const [label, args] of [['no step', []], ['a bad step', ['nope']], ['an unknown option', ['email', '--bogus', 'x']], ['--confirm with another word', ['plugin', '--confirm', 'yes']], ['--item on email', ['email', '--item', 'a/b.php']], ['--theme on user', ['user', '--theme', 'x']], ['--confirm on email', ['email', '--confirm', 'go']], ['--item with no value', ['update', '--item']]]) {
  r = go(args);
  check(`${label} -> exit 2 and nothing sent`, r.code === 2 && !(r.st.requests || []).length, [r.code, r.out]);
}

console.log('--- what crosses the wire and what is printed');
const allReqs = all.reduce((a, x) => a.concat(x.st.requests || []), []);
check('every request is the REST index (GET) or a GET/POST to a divi5-builder run route', allReqs.length > 100 && allReqs.every(x => x === 'GET /wp-json/' || /^(GET|POST) \/wp-json\/wp-abilities\/v1\/abilities\/divi5-builder\/[a-z0-9-]+\/run$/.test(x)), allReqs.filter(x => x !== 'GET /wp-json/' && !/run$/.test(x)).slice(0, 3));
const outAll = all.map(x => x.out).join('\n');
check('no planted secret, error message or confirmation code is printed', !/SECRET-LEAK-VALUE|ERR-MSG-|CONFIRM-/.test(outAll), outAll.split('\n').filter(l => /SECRET-LEAK|ERR-MSG|CONFIRM-/.test(l)).slice(0, 3));
check('the application password never appears', !/aaaa ?bbbb/.test(outAll), 'password found');
check('no kept-copy path is printed', !/d5b-history|\/srv\//.test(outAll), 'a path was printed');

console.log('--- the source');
const src = fs.readFileSync(SCRIPT, 'utf8').replace(/\r\n?/g, '\n');
const code = src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
check('it reads no credentials file and names no wp.js command beyond rest-get --index', !/web-creds|WEB_CREDS|\.ini/.test(code) && !/['"`](update-page|create-page|delete-|set-|quarantine|updates)\b/.test(code), 'a forbidden word is in the code');
check('it never sends DELETE or PUT', !/\b(DELETE|PUT|PATCH)\b/.test(code), 'a forbidden method');
check('the test plugin and the test login are fixed in the code', /'hello-dolly'/.test(code) && /'d5b-live-user'/.test(code), 'constants missing');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
