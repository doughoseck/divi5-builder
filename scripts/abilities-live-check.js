#!/usr/bin/env node
/**
 * abilities-live-check.js <site>: read-only check of the divi5-builder abilities on a live site (issue #32, release 2.1.0).
 *
 *   node scripts/abilities-live-check.js <site>
 *
 * Only ever sends GET requests, through wp.js rest-get (wp.js holds the credentials; this script never sees or prints them):
 *   1. GET /wp-json/wp-abilities/v1/abilities?per_page=100&page=N   every page, until a page comes back short
 *   2. GET /wp-json/wp-abilities/v1/abilities/<name>/run            each readonly divi5-builder ability once, with no
 *                                                                   input (or, for "x-get" style abilities that need one id,
 *                                                                   the first id of the matching "x-list" result)
 * WordPress core accepts only GET for a readonly ability; input goes in the query as input[key]=value.
 *
 * It never prints a result value. Per ability it prints the name, a status (ok, refused, error:<code>), the HTTP status, and
 * the result's top-level key names with its size in bytes. The newest audit rows are printed as id, time, ability, status and
 * duration only (never their arguments).
 *
 * Exit 0: master switch off ("master off: no abilities exposed"), or every ability answered ok or was refused.
 * Exit 1: an ability errored (internal_error, audit_unavailable, any other error code, HTTP 5xx).
 * Exit 2: the Abilities API could not be read at all (WordPress before 6.9, or an error on the list).
 */
const path = require('path');
const { execFileSync } = require('child_process');

const PER_PAGE = 100;
const MAX_PAGES = 20;

// GET through wp.js rest-get. Returns { status, json, bytes, code }: status 200 on success, else the HTTP code (0 unknown);
// code is the error code of a WordPress error body when there is one. The body itself is never kept for printing.
function get(site, route, wpjs) {
  try {
    const out = execFileSync(process.execPath, [wpjs, site, 'rest-get', route], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
    let json = null; try { json = JSON.parse(out); } catch (e) { /* not JSON */ }
    return { status: 200, json, bytes: Buffer.byteLength(out.trim(), 'utf8') };
  } catch (e) {
    const err = String(e.stderr || e.message);
    const m = /HTTP (\d{3})/.exec(err);
    const c = /"code"\s*:\s*"([A-Za-z0-9_:\-]+)"/.exec(err);
    return { status: m ? Number(m[1]) : 0, json: null, bytes: 0, code: c ? c[1] : '' };
  }
}

const runRoute = (name, input) => {
  const q = Object.keys(input || {}).map(k => 'input%5B' + encodeURIComponent(k) + '%5D=' + encodeURIComponent(String(input[k]))).join('&');
  return 'wp-abilities/v1/abilities/' + name + '/run' + (q ? '?' + q : '');
};

// Every ability of the site, following the pages.
function listAbilities(site, wpjs) {
  const all = [];
  for (let page = 1; page <= MAX_PAGES; page++) {
    const r = get(site, `wp-abilities/v1/abilities?per_page=${PER_PAGE}&page=${page}`, wpjs);
    // A page past the end may come back empty or as a 400, depending on the WordPress version: both mean "done".
    if (page > 1 && r.status === 400) break;
    if (r.status !== 200 || !Array.isArray(r.json)) return { error: r };
    if (!r.json.length) break;   // not "a short page": a server may cap per_page below what was asked for
    all.push(...r.json);
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
  const keys = j && typeof j === 'object' ? (Array.isArray(j) ? '[list of ' + j.length + ']' : '{' + Object.keys(j).join(',') + '}') : (j === null ? 'not-json' : typeof j);
  return keys + ' ' + r.bytes + ' bytes';
};

function check(site, wpjs) {
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
  // Lists first, so a '-get' can borrow an id from its '-list'.
  const order = a => (/-get$/.test(a.name) ? '1' : '0') + a.name;
  for (const a of ro.sort((x, y) => order(x).localeCompare(order(y)))) {
    if (a.name === 'divi5-builder/history-audit-list') continue; // read last, below
    const req = a.input_schema && Array.isArray(a.input_schema.required) ? a.input_schema.required : [];
    let input = {};
    if (req.length) {
      // Small rule: one required key, and an "x-list" ability for the "x-get" one, whose first item has an id.
      const base = a.name.replace(/-get$/, '');
      const listName = [base + '-list', base + 's-list'].find(n => n !== a.name && names.has(n));
      const items = listName && results[listName] && Array.isArray(results[listName].items) ? results[listName].items : [];
      const id = items.length ? items[0].id : undefined;
      if (req.length === 1 && id !== undefined && id !== null) input = { [req[0]]: id };
      else { say(`${a.name}  skipped: needs id`); continue; }
    }
    const r = get(site, runRoute(a.name, input), wpjs);
    const c = classify(r);
    if (c.bad) errors++;
    // Keep only the items of a list result, to find an id for the matching "-get"; never printed.
    if (r.status === 200 && r.json && Array.isArray(r.json.items)) results[a.name] = { items: r.json.items.slice(0, 1).map(x => ({ id: x && (x.id !== undefined ? x.id : x.ID) })) };
    say(`${a.name}  ${c.label}  HTTP ${r.status}` + (r.status === 200 ? '  ' + shape(r) : ''));
  }

  if (names.has('divi5-builder/history-audit-list')) {
    const r = get(site, runRoute('divi5-builder/history-audit-list', { per_page: 5 }), wpjs);
    const c = classify(r);
    if (c.bad) errors++;
    say(`divi5-builder/history-audit-list  ${c.label}  HTTP ${r.status}`);
    const rows = r.json && Array.isArray(r.json.items) ? r.json.items : [];
    if (r.status === 200) say(rows.length ? 'Newest audit rows (id, time_utc, ability, status, duration_ms):' : 'Audit log: no rows.');
    for (const x of rows) say(`  ${x.id}  ${x.time_utc}  ${x.ability}  ${x.status}  ${x.duration_ms}ms`);
  }

  say(errors ? `RESULT: ${errors} ability call(s) errored.` : 'RESULT: every ability answered ok or was refused.');
  return { code: errors ? 1 : 0, lines };
}

if (require.main === module) {
  const site = process.argv[2];
  if (!site || site.startsWith('-')) { console.error('usage: node abilities-live-check.js <site>'); process.exit(2); }
  const r = check(site, path.join(__dirname, 'wp.js'));
  console.log(r.lines.join('\n'));
  process.exit(r.code);
}
module.exports = { check, runRoute };
