#!/usr/bin/env node
/**
 * abilities-live-check.js <site>: read-only check of the divi5-builder abilities on a live site (issue #32, release 2.1.0).
 *
 *   node scripts/abilities-live-check.js <site> [--anonymous]
 *
 * Only ever sends GET requests, through wp.js rest-get (wp.js holds the credentials; this script never sees or prints them):
 *   1. GET /wp-json/wp-abilities/v1/abilities?per_page=100&page=N   page after page, until a page comes back shorter than the
 *                                                                   first one (or empty, or a 400 past the last page)
 *   2. GET /wp-json/wp-abilities/v1/abilities/<name>/run            each readonly divi5-builder ability once, with no
 *                                                                   input (or, for "x-get" style abilities that need one id,
 *                                                                   the first id of the matching "x-list" result)
 *   3. two fixed calls: cpt-get with type et_pb_layout must answer 403 (an internal type, refused with not_allowed), and
 *      divi-design-system with input[full]=true must answer 200 (a query string sends the boolean as text).
 * WordPress core accepts only GET for a readonly ability; input goes in the query as input[key]=value (nested keys in brackets,
 * booleans as 1 or 0).
 *
 * --anonymous adds one GET with NO credentials at all: Node's https module straight to the site's public REST URL (the home
 * address the REST index gives), never through wp.js. It must be refused (401 or 403), and the activity log, read afterwards
 * as the logged-in user through history-audit-list, must have gained at most one forbidden_anonymous row for it.
 *
 * It never prints a result value. Per ability it prints the name, a status (ok, refused, error:<code>), the HTTP status, and
 * the result's top-level key names with its size in bytes. The newest audit rows are printed as id, time, ability, status and
 * duration only (never their arguments), each field cut to 100 characters.
 *
 * Exit 0: master switch off ("master off: no abilities exposed"), or every check passed.
 * Exit 1: an ability errored (internal_error, audit_unavailable, any other error code, HTTP 5xx), or a fixed check failed.
 * Exit 2: the Abilities API could not be read at all (WordPress before 6.9, or an error on the list).
 */
const path = require('path');
const https = require('https');
const { execFileSync } = require('child_process');

const PER_PAGE = 100;
const MAX_PAGES = 20;

/** Any value as a printable token: a string of at most 100 characters, line breaks and tabs turned into spaces. */
const tok = v => String(v === undefined || v === null ? '' : v).replace(/[\r\n\t]+/g, ' ').slice(0, 100);

// GET through wp.js rest-get (route null = the REST index). Returns { status, json, bytes, code }: status 200 on success, else
// the HTTP code (0 unknown); code is the error code of a WordPress error body when there is one. The body is never printed.
function get(site, route, wpjs) {
  try {
    const args = [wpjs, site, 'rest-get'].concat(route === null ? ['--index'] : [route]);
    const out = execFileSync(process.execPath, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
    let json = null; try { json = JSON.parse(out); } catch (e) { /* not JSON */ }
    return { status: 200, json, bytes: Buffer.byteLength(out.trim(), 'utf8') };
  } catch (e) {
    const err = String(e.stderr || e.message);
    const m = /HTTP (\d{3})/.exec(err);
    const c = /"code"\s*:\s*"([A-Za-z0-9_:\-]+)"/.exec(err);
    return { status: m ? Number(m[1]) : 0, json: null, bytes: 0, code: c ? tok(c[1]) : '' };
  }
}

/** The query string of an input: input[a]=x, input[b][0]=y, input[c][d]=1; booleans as 1 or 0; null and undefined left out. */
function encodeInput(prefix, v, out) {
  if (v === null || v === undefined) return out;
  if (typeof v === 'boolean') out.push(prefix + '=' + (v ? '1' : '0'));
  else if (Array.isArray(v)) v.forEach((x, i) => encodeInput(prefix + '%5B' + i + '%5D', x, out));
  else if (typeof v === 'object') Object.keys(v).forEach(k => encodeInput(prefix + '%5B' + encodeURIComponent(k) + '%5D', v[k], out));
  else out.push(prefix + '=' + encodeURIComponent(String(v)));
  return out;
}

const runRoute = (name, input) => {
  const q = Object.keys(input || {}).reduce((out, k) => encodeInput('input%5B' + encodeURIComponent(k) + '%5D', input[k], out), []).join('&');
  return 'wp-abilities/v1/abilities/' + name + '/run' + (q ? '?' + q : '');
};

// Every ability of the site, following the pages. The first page's length is the server's page size (it may cap per_page
// below what was asked for): a later page shorter than that is the last one. An empty page, or a 400 past the end
// (rest_invalid_page on some WordPress versions), means "done" too.
function listAbilities(site, wpjs) {
  const all = []; let size = 0;
  for (let page = 1; page <= MAX_PAGES; page++) {
    const r = get(site, `wp-abilities/v1/abilities?per_page=${PER_PAGE}&page=${page}`, wpjs);
    if (page > 1 && r.status === 400) break;
    if (r.status !== 200 || !Array.isArray(r.json)) return { error: r };
    if (!r.json.length) break;
    all.push(...r.json);
    if (page === 1) size = r.json.length;
    else if (r.json.length < size) break; // a short page: the last one
  }
  return { all };
}

// ok / refused / error:<code>, from a get() answer. 401 and 403 and other client errors are refusals (reported, not failures).
function classify(r) {
  if (r.status === 200) return { label: 'ok', bad: false };
  if (r.status >= 500 || r.status === 0 || r.code === 'internal_error' || r.code === 'audit_unavailable') return { label: 'error:' + (r.code || 'http_' + r.status), bad: true };
  return { label: r.status === 401 || r.status === 403 ? 'refused' : 'refused:' + (r.code || 'http_' + r.status), bad: false };
}

const shape = r => {
  const j = r.json;
  const keys = j && typeof j === 'object' ? (Array.isArray(j) ? '[list of ' + j.length + ']' : '{' + Object.keys(j).map(tok).join(',') + '}') : (j === null ? 'not-json' : typeof j);
  return keys + ' ' + r.bytes + ' bytes';
};

// One GET with no credentials of any kind: Node's https module, no Authorization header, no cookie. Never through wp.js.
function anonymousGet(url) {
  return new Promise(resolve => {
    let u;
    try { u = new URL(url); } catch (e) { resolve({ status: 0, code: 'bad_url' }); return; }
    if (u.protocol !== 'https:') { resolve({ status: 0, code: 'not_https' }); return; }
    const req = https.request({ hostname: u.hostname, port: u.port || 443, path: u.pathname + u.search, method: 'GET', headers: { Accept: 'application/json', 'User-Agent': 'divi5-builder-abilities-live-check' } }, res => {
      let body = '';
      res.on('data', d => { if (body.length < 65536) body += d; });
      res.on('end', () => { let code = ''; try { const j = JSON.parse(body); if (j && typeof j.code === 'string') code = j.code; } catch (e) { /* not JSON */ } resolve({ status: res.statusCode || 0, code: tok(code) }); });
    });
    if (typeof req.on === 'function') req.on('error', () => resolve({ status: 0, code: 'network_error' }));
    if (typeof req.setTimeout === 'function') req.setTimeout(30000, () => { if (typeof req.destroy === 'function') req.destroy(); resolve({ status: 0, code: 'timeout' }); });
    req.end();
  });
}

// The forbidden_anonymous rows of one ability in the activity log (newest 100), read as the logged-in user. null when unreadable.
function anonRows(site, wpjs, name) {
  const r = get(site, runRoute('divi5-builder/history-audit-list', { ability: name, per_page: 100 }), wpjs);
  if (r.status !== 200 || !r.json || !Array.isArray(r.json.items)) return null;
  return r.json.items.filter(x => x && x.status === 'forbidden_anonymous').length;
}

async function check(site, wpjs, opts) {
  opts = opts || {};
  const lines = []; const say = s => lines.push(s);
  const l = listAbilities(site, wpjs);
  if (l.error) {
    say(`UNCHECKED: could not read the abilities list (HTTP ${l.error.status}${l.error.code ? ' ' + l.error.code : ''}); the Abilities API needs WordPress 6.9 or newer.`);
    return { code: 2, lines };
  }
  const ours = l.all.filter(a => a && typeof a.name === 'string' && a.name.startsWith('divi5-builder/'));
  if (!ours.length) { say('master off: no abilities exposed'); return { code: 0, lines }; }
  const ro = ours.filter(a => a.meta && a.meta.annotations && a.meta.annotations.readonly === true);
  say(`${ours.length} divi5-builder abilities exposed, ${ro.length} readonly. Running each readonly one once (GET).`);

  let errors = 0; const results = {};
  const names = new Set(ours.map(a => a.name));
  const required = a => (a.input_schema && Array.isArray(a.input_schema.required) ? a.input_schema.required : []);
  // Lists first, so a '-get' can borrow an id from its '-list'.
  const order = a => (/-get$/.test(a.name) ? '1' : '0') + a.name;
  for (const a of ro.sort((x, y) => order(x).localeCompare(order(y)))) {
    if (a.name === 'divi5-builder/history-audit-list') continue; // read last, below
    const req = required(a);
    let input = {};
    if (req.length) {
      // Small rule: one required key, and an "x-list" ability for the "x-get" one, whose first item has an id.
      const base = a.name.replace(/-get$/, '');
      const listName = [base + '-list', base + 's-list'].find(n => n !== a.name && names.has(n));
      const items = listName && results[listName] && Array.isArray(results[listName].items) ? results[listName].items : [];
      const id = items.length ? items[0].id : undefined;
      if (req.length === 1 && id !== undefined && id !== null) input = { [req[0]]: id };
      else { say(`${tok(a.name)}  skipped: needs id`); continue; }
    }
    const r = get(site, runRoute(a.name, input), wpjs);
    const c = classify(r);
    if (c.bad) errors++;
    // Keep only the items of a list result, to find an id for the matching "-get"; never printed.
    if (r.status === 200 && r.json && Array.isArray(r.json.items)) results[a.name] = { items: r.json.items.slice(0, 1).map(x => ({ id: x && (x.id !== undefined ? x.id : x.ID) })) };
    say(`${tok(a.name)}  ${c.label}  HTTP ${r.status}` + (r.status === 200 ? '  ' + shape(r) : ''));
  }

  // Fixed checks: the HTTP status of a refusal (403, never 500) and a boolean sent as text in a query string.
  if (names.has('divi5-builder/cpt-get')) {
    const r = get(site, runRoute('divi5-builder/cpt-get', { type: 'et_pb_layout', id: 1 }), wpjs);
    const ok = r.status === 403;
    if (!ok) errors++;
    say(`check cpt-get type=et_pb_layout  ${ok ? 'ok' : 'FAILED'}  HTTP ${r.status}${r.code ? ' ' + r.code : ''} (want 403)`);
  } else say('check cpt-get type=et_pb_layout  skipped: cpt-get not exposed');
  if (names.has('divi5-builder/divi-design-system')) {
    const r = get(site, runRoute('divi5-builder/divi-design-system', { full: 'true' }), wpjs);
    const ok = r.status === 200;
    if (!ok) errors++;
    say(`check divi-design-system input[full]=true  ${ok ? 'ok' : 'FAILED'}  HTTP ${r.status}${r.code ? ' ' + r.code : ''} (want 200)`);
  } else say('check divi-design-system input[full]=true  skipped: divi-design-system not exposed');

  if (opts.anonymous) {
    // One ability with no required input: a logged-out caller must be refused before anything runs, and leave at most one row.
    const target = (ro.find(a => a.name !== 'divi5-builder/history-audit-list' && !required(a).length) || {}).name;
    const idx = get(site, null, wpjs);
    const home = idx.json && typeof idx.json === 'object' ? (typeof idx.json.home === 'string' && idx.json.home ? idx.json.home : idx.json.url) : '';
    if (!target || typeof home !== 'string' || !/^https:\/\//i.test(home)) {
      errors++;
      say(`check anonymous  FAILED: ${!target ? 'no ability without required input to call' : 'no https home address in the REST index'}`);
    } else {
      const canAudit = names.has('divi5-builder/history-audit-list');
      const before = canAudit ? anonRows(site, wpjs, target) : null;
      const r = await anonymousGet(home.replace(/\/+$/, '') + '/wp-json/' + runRoute(target, {}));
      const refused = r.status === 401 || r.status === 403;
      if (!refused) errors++;
      say(`check anonymous GET ${tok(target)}  ${refused ? 'refused' : 'FAILED'}  HTTP ${r.status}${r.code ? ' ' + r.code : ''} (want 401 or 403)`);
      const after = canAudit ? anonRows(site, wpjs, target) : null;
      if (before === null || after === null) say('check anonymous audit rows  unverified: history-audit-list not readable');
      else {
        const added = after - before; const ok = added >= 0 && added <= 1;
        if (!ok) errors++;
        say(`check anonymous audit rows  ${ok ? 'ok' : 'FAILED'}  ${added} new forbidden_anonymous row(s) (want at most 1)`);
      }
    }
  }

  if (names.has('divi5-builder/history-audit-list')) {
    const r = get(site, runRoute('divi5-builder/history-audit-list', { per_page: 5 }), wpjs);
    const c = classify(r);
    if (c.bad) errors++;
    say(`divi5-builder/history-audit-list  ${c.label}  HTTP ${r.status}`);
    const rows = r.json && Array.isArray(r.json.items) ? r.json.items : [];
    if (r.status === 200) say(rows.length ? 'Newest audit rows (id, time_utc, ability, status, duration_ms):' : 'Audit log: no rows.');
    for (const x of rows) { if (x && typeof x === 'object') say(`  ${tok(x.id)}  ${tok(x.time_utc)}  ${tok(x.ability)}  ${tok(x.status)}  ${tok(x.duration_ms)}ms`); }
  }

  say(errors ? `RESULT: ${errors} ability call(s) or check(s) failed.` : 'RESULT: every ability answered ok or was refused, and every check passed.');
  return { code: errors ? 1 : 0, lines };
}

if (require.main === module) {
  const site = process.argv[2];
  const rest = process.argv.slice(3);
  if (!site || site.startsWith('-') || rest.some(a => a !== '--anonymous')) { console.error('usage: node abilities-live-check.js <site> [--anonymous]'); process.exit(2); }
  check(site, path.join(__dirname, 'wp.js'), { anonymous: rest.includes('--anonymous') }).then(r => {
    console.log(r.lines.join('\n'));
    process.exit(r.code);
  });
}
module.exports = { check, runRoute, encodeInput };
