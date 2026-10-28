#!/usr/bin/env node
/**
 * abilities-live-check-test.js: scripts/abilities-live-check.js against the fake WordPress of stale-guard-test.js (issue #32, task 7).
 *   node scripts/abilities-live-check-test.js
 *   ABILITIES_CHECK_FILE=/path/to/copy.js node scripts/abilities-live-check-test.js    # mutation testing (the copy must sit in scripts/)
 * No site needed. Exit codes under test: 0 master off or all ok/refused, 1 an ability errored, 2 the API could not be read.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const SCRIPT = process.env.ABILITIES_CHECK_FILE || path.join(__dirname, 'abilities-live-check.js');
const FAKE = path.join(__dirname, 'stale-guard-test.js');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-abil-'));
const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini');
fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');

let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 600))); if (!ok) failed++; };

const ab = (short, extra) => Object.assign({ name: 'divi5-builder/' + short, label: short, meta: { annotations: { readonly: true, destructive: false, idempotent: true }, show_in_rest: true }, input_schema: { type: 'object' } }, extra || {});
const run = (state, args) => {
  fs.writeFileSync(STATE, JSON.stringify(Object.assign({ pages: {}, posts: {}, et_pb_canvas: {}, recordMethods: true }, state)));
  const r = spawnSync(process.execPath, [SCRIPT, 'fake'].concat(args || []), { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: `--require ${FAKE.replace(/\\/g, '/')}` }) });
  return { code: r.status, out: r.stdout + r.stderr, st: JSON.parse(fs.readFileSync(STATE, 'utf8')) };
};
const onlyGet = st => st.requests.length > 0 && st.requests.every(x => x.startsWith('GET '));

console.log('--- master off');
let r = run({ abilities: [{ name: 'core/get-site-info', meta: { annotations: { readonly: true } } }] });
check('no divi5-builder ability in the list -> exit 0, the exact message', r.code === 0 && r.out.trim() === 'master off: no abilities exposed', [r.code, r.out]);
check('... only GETs, and no ability was run', onlyGet(r.st) && !(r.st.abilityRuns || []).length, r.st.requests);
r = run({ abilities: [] });
check('an empty list is the same', r.code === 0 && /^master off: no abilities exposed$/m.test(r.out), [r.code, r.out]);

console.log('--- three abilities');
const three = [
  ab('core-version'),
  ab('posts-list'),
  ab('history-audit-list'),
];
r = run({ abilities: three, abilityReplies: {
  'divi5-builder/history-audit-list': { status: 200, body: { items: [{ id: 7, time_utc: '2026-10-28 10:00:00', user_id: 1, ability: 'divi5-builder/core-version', args: '{"secret_arg":"ARGS-LEAK"}', status: 'ok', duration_ms: 12, ip: '203.0.113.9' }], page: 1, per_page: 5, has_more: false } },
} });
check('exit 0', r.code === 0, [r.code, r.out]);
check('... a line per ability with name, ok, HTTP 200, the top-level keys and the size', /divi5-builder\/core-version  ok  HTTP 200  \{items,note\} \d+ bytes/.test(r.out) && /divi5-builder\/posts-list  ok  HTTP 200/.test(r.out) && /history-audit-list  ok  HTTP 200/.test(r.out), r.out);
check('... only GETs, each ability run once, no input for these', onlyGet(r.st) && (r.st.abilityRuns || []).filter(x => x.name === 'divi5-builder/core-version').length === 1 && (r.st.abilityRuns || []).every(x => x.query === '' || x.name.endsWith('history-audit-list')), r.st.abilityRuns);
check('... audit rows: id, time, ability, status, duration', /7  2026-10-28 10:00:00  divi5-builder\/core-version  ok  12ms/.test(r.out), r.out);
check('... never a result value, never the audit arguments, never the IP, never the password', !/SECRET-RESULT-VALUE|ARGS-LEAK|203\.0\.113\.9|aaaa ?bbbb/.test(r.out), r.out);

console.log('--- a write-capable ability is not run, a POST is never sent');
r = run({ abilities: three.concat([ab('posts-update', { meta: { annotations: { readonly: false } } })]) });
check('posts-update (not readonly) is not run', !(r.st.abilityRuns || []).some(x => /posts-update/.test(x.name)) && onlyGet(r.st), r.st.abilityRuns);

console.log('--- refused is reported, not an error');
r = run({ abilities: three, abilityReplies: { 'divi5-builder/history-audit-list': { status: 403, body: { code: 'forbidden', message: 'ERR-MSG-LEAK' } } } });
check('403 on one ability -> exit 0, reported as refused with its HTTP status', r.code === 0 && /history-audit-list  refused  HTTP 403/.test(r.out), [r.code, r.out]);
check('... the error message text is not printed', !/ERR-MSG-LEAK/.test(r.out), r.out);

console.log('--- errors fail the run');
for (const [label, rep, want] of [
  ['internal_error (HTTP 500)', { status: 500, body: { code: 'internal_error', message: 'x' } }, 'error:internal_error'],
  ['audit_unavailable (HTTP 503)', { status: 503, body: { code: 'audit_unavailable', message: 'x' } }, 'error:audit_unavailable'],
  ['a 200-less 502 with no code', { status: 502, body: 'bad gateway' }, 'error:http_502'],
]) {
  r = run({ abilities: three, abilityReplies: { 'divi5-builder/posts-list': rep } });
  check(`${label} -> exit 1, shown as ${want}`, r.code === 1 && r.out.includes('posts-list  ' + want), [r.code, r.out]);
  check('... the rest still ran (core-version and the audit rows)', /core-version  ok/.test(r.out) && /history-audit-list  ok/.test(r.out), r.out);
}

console.log('--- abilities that need an id');
const withGet = [ab('posts-list'), ab('posts-get', { input_schema: { type: 'object', required: ['id'] } }), ab('cpt-get', { input_schema: { type: 'object', required: ['type', 'id'] } }), ab('users-get', { input_schema: { type: 'object', required: ['id'] } })];
// cpt-get answers 403 here: the fixed et_pb_layout check (below) asks it, and a refusal is what a real site gives.
r = run({ abilities: withGet, abilityReplies: { 'divi5-builder/posts-list': { status: 200, body: { items: [{ id: 42, title: 'T' }] } }, 'divi5-builder/cpt-get': { status: 403, body: { code: 'not_allowed' } } } });
const getRun = (r.st.abilityRuns || []).find(x => x.name === 'divi5-builder/posts-get');
check('posts-get gets the first id of posts-list as input[id]=42, through GET query', getRun && decodeURIComponent(getRun.query) === 'input[id]=42' && onlyGet(r.st), r.st.abilityRuns);
check('... users-get has no users-list: "skipped: needs id", and is not run', /users-get  skipped: needs id/.test(r.out) && !(r.st.abilityRuns || []).some(x => /users-get/.test(x.name)), r.out);
check('... two required inputs: skipped too', /cpt-get  skipped: needs id/.test(r.out), r.out);
check('... exit 0, the list value title is never printed', r.code === 0 && !/"T"|title/.test(r.out), [r.code, r.out]);

console.log('--- paging');
const many = []; for (let i = 0; i < 5; i++) many.push(ab('thing' + i + '-list'));
r = run({ abilities: [ab('core-version')].concat(many), abilitiesPerPage: 2 });
check('follows pages until a short page: every ability run (page size 2, 6 abilities)', (r.st.abilityRuns || []).length === 6, r.st.abilityRuns);

const listGets = st => st.requests.filter(x => /^GET \/wp-json\/wp-abilities\/v1\/abilities\/?$/.test(x)).length;
r = run({ abilities: [ab('core-version')].concat(many.slice(0, 4)), abilitiesPerPage: 2 });
check('stops after a short page: 5 abilities, 2 a page -> pages of 2, 2, 1 and no fourth request', (r.st.abilityRuns || []).length === 5 && listGets(r.st) === 3, [listGets(r.st), (r.st.abilityRuns || []).length]);
r = run({ abilities: [ab('core-version')].concat(many.slice(0, 3)), abilitiesPerPage: 2, pastEnd400: true });
check('a 400 rest_invalid_page past the last page (4 abilities, 2 a page): the run completes, exit 0, all 4 run', r.code === 0 && (r.st.abilityRuns || []).length === 4 && listGets(r.st) === 3 && /RESULT: every ability answered ok/.test(r.out), [r.code, r.out]);

console.log('--- input encoding (runRoute)');
const mod = require(SCRIPT);
const dq = s => decodeURIComponent(s.split('?')[1] || '');
const nested = { a: ['x', 'y'], b: { c: true, d: false, e: { f: 2 } }, g: null, h: 'a&b=c' };
check('nested arrays and objects in brackets, booleans as 1/0, null left out', dq(mod.runRoute('divi5-builder/x', nested)) === 'input[a][0]=x&input[a][1]=y&input[b][c]=1&input[b][d]=0&input[b][e][f]=2&input[h]=a&b=c', dq(mod.runRoute('divi5-builder/x', nested)));
check('a value with & and = is escaped in the raw query (one input, not two)', /input%5Bh%5D=a%26b%3Dc$/.test(mod.runRoute('divi5-builder/x', { h: 'a&b=c' })), mod.runRoute('divi5-builder/x', { h: 'a&b=c' }));
check('no input: no query at all', mod.runRoute('divi5-builder/x', {}) === 'wp-abilities/v1/abilities/divi5-builder/x/run');

console.log('--- printed fields are strings of at most 100 characters');
const longs = 'L'.repeat(500);
r = run({ abilities: three, abilityReplies: {
  'divi5-builder/history-audit-list': { status: 200, body: { items: [{ id: { nested: longs }, time_utc: longs, ability: ['a', 'b'], status: 'ok\nINJECTED LINE', duration_ms: 12345 }] } },
  'divi5-builder/posts-list': { status: 418, body: { code: 'c'.repeat(300), message: 'x' } },
} });
const auditLine = r.out.split('\n').find(x => /^  \[object Object\]/.test(x)) || '';
check('audit row fields are coerced to strings and cut to 100 characters; a line break in a field does not start a new line', auditLine.length > 0 && !/L{101}/.test(r.out) && !/^INJECTED LINE/m.test(r.out) && /12345/.test(auditLine), r.out);
check('an error code token is cut to 100 characters', /posts-list  refused:c{100}  HTTP 418/.test(r.out) && !/c{101}/.test(r.out), r.out);

console.log('--- fixed checks: cpt-get et_pb_layout 403, divi-design-system full=true 200');
const fixed = [ab('posts-list'), ab('cpt-get', { input_schema: { type: 'object', required: ['type', 'id'] } }), ab('divi-design-system')];
r = run({ abilities: fixed, abilityReplies: { 'divi5-builder/cpt-get': { status: 403, body: { code: 'not_allowed', message: 'x' } } } });
const cptRun = (r.st.abilityRuns || []).find(x => x.name === 'divi5-builder/cpt-get');
const dsRuns = (r.st.abilityRuns || []).filter(x => x.name === 'divi5-builder/divi-design-system');
check('cpt-get is asked with input[type]=et_pb_layout and an id; 403 -> "ok"', cptRun && /^input\[type\]=et_pb_layout&input\[id\]=1$/.test(decodeURIComponent(cptRun.query)) && /check cpt-get type=et_pb_layout  ok  HTTP 403 not_allowed/.test(r.out), [cptRun, r.out]);
check('divi-design-system is asked with input[full]=true (the text, as a query string sends it); 200 -> "ok"; exit 0', dsRuns.some(x => decodeURIComponent(x.query) === 'input[full]=true') && /check divi-design-system input\[full\]=true  ok  HTTP 200/.test(r.out) && r.code === 0, [dsRuns, r.code, r.out]);
r = run({ abilities: fixed, abilityReplies: { 'divi5-builder/cpt-get': { status: 500, body: { code: 'not_allowed', message: 'x' } } } });
check('cpt-get answering 500 (a refusal without a status) -> FAILED, exit 1', r.code === 1 && /check cpt-get type=et_pb_layout  FAILED  HTTP 500/.test(r.out), [r.code, r.out]);
r = run({ abilities: fixed, abilityReplies: { 'divi5-builder/cpt-get': { status: 403, body: { code: 'not_allowed' } }, 'divi5-builder/divi-design-system': { status: 400, body: { code: 'invalid_input', message: 'full must be true or false.' } } } });
check('divi-design-system answering 400 to full=true -> FAILED, exit 1', r.code === 1 && /check divi-design-system input\[full\]=true  FAILED  HTTP 400 invalid_input/.test(r.out), [r.code, r.out]);
r = run({ abilities: [ab('posts-list')] });
check('neither ability exposed: both checks say skipped, exit 0', r.code === 0 && /cpt-get type=et_pb_layout  skipped/.test(r.out) && /divi-design-system input\[full\]=true  skipped/.test(r.out), r.out);

console.log('--- --anonymous: one GET with no credentials');
const anonSet = [ab('core-version'), ab('posts-get', { input_schema: { type: 'object', required: ['id'] } }), ab('history-audit-list')];
r = run({ abilities: anonSet, auditRows: [] }, ['--anonymous']);
const anonRuns = (r.st.abilityRuns || []).filter(x => !x.auth);
check('exactly one run without credentials, to an ability that needs no input, and every other run carried them', anonRuns.length === 1 && anonRuns[0].name === 'divi5-builder/core-version' && anonRuns[0].query === '' && (r.st.abilityRuns || []).filter(x => x.auth).length === (r.st.abilityRuns || []).length - 1, r.st.abilityRuns);
check('... refused with 401 -> "refused", one forbidden_anonymous row -> "ok", exit 0', r.code === 0 && /check anonymous GET divi5-builder\/core-version  refused  HTTP 401 rest_ability_cannot_execute/.test(r.out) && /check anonymous audit rows  ok  1 new forbidden_anonymous row/.test(r.out), [r.code, r.out]);
check('... only GETs; the home address came from the REST index', onlyGet(r.st) && r.st.requests.includes('GET /wp-json/'), r.st.requests);
check('... before and after were read through history-audit-list, filtered to that ability, as the logged-in user', (r.st.abilityRuns || []).filter(x => x.name === 'divi5-builder/history-audit-list' && x.auth && /input%5Bability%5D=divi5-builder%2Fcore-version/.test(x.query)).length === 2, r.st.abilityRuns);
r = run({ abilities: anonSet, auditRows: [], anonReply: { status: 403, body: { code: 'forbidden' } } }, ['--anonymous']);
check('a 403 is a refusal too', r.code === 0 && /anonymous GET divi5-builder\/core-version  refused  HTTP 403 forbidden/.test(r.out), [r.code, r.out]);
r = run({ abilities: anonSet, auditRows: [], anonLast: Date.now() }, ['--anonymous']);
check('within the 10 minutes: 0 new rows is fine (at most one)', r.code === 0 && /audit rows  ok  0 new/.test(r.out), r.out);
r = run({ abilities: anonSet, auditRows: [], anonRowsPerCall: 3 }, ['--anonymous']);
check('3 rows from one anonymous call (a broken limit) -> FAILED, exit 1', r.code === 1 && /audit rows  FAILED  3 new/.test(r.out), [r.code, r.out]);
r = run({ abilities: anonSet, auditRows: [], anonReply: { status: 200, body: { items: [] } } }, ['--anonymous']);
check('an anonymous call that is answered 200 -> FAILED, exit 1', r.code === 1 && /anonymous GET divi5-builder\/core-version  FAILED  HTTP 200/.test(r.out), [r.code, r.out]);
r = run({ abilities: anonSet, auditRows: [], home: 'http://fake.test' }, ['--anonymous']);
check('no https home address: FAILED without sending anything anonymous', r.code === 1 && /check anonymous  FAILED: no https home address/.test(r.out) && !(r.st.abilityRuns || []).some(x => !x.auth), [r.code, r.out]);
r = run({ abilities: [ab('core-version')] }, ['--anonymous']);
check('without history-audit-list: the refusal is checked, the rows are reported unverified', r.code === 0 && /anonymous GET divi5-builder\/core-version  refused/.test(r.out) && /audit rows  unverified/.test(r.out), [r.code, r.out]);
r = run({ abilities: anonSet, auditRows: [] });
check('without --anonymous nothing is sent without credentials', !(r.st.abilityRuns || []).some(x => !x.auth) && !/check anonymous/.test(r.out), r.st.abilityRuns);
r = run({ abilities: anonSet }, ['--bogus']);
check('an unknown option is a usage error (exit 2), nothing sent', r.code === 2 && /usage/.test(r.out) && !(r.st.requests || []).length, [r.code, r.out]);
const anonSrc = fs.readFileSync(SCRIPT, 'utf8');
const anonFn = (anonSrc.match(/function anonymousGet[\s\S]*?\n}\n/) || [''])[0];
check('the anonymous GET is built by hand with https.request, method GET, and no Authorization or Cookie header', /https\.request\(/.test(anonFn) && /method: 'GET'/.test(anonFn) && !/Authorization|Cookie|authHeader|wp\.js|web-creds/i.test(anonFn), anonFn.slice(0, 300));

console.log('--- the API is not there');
r = run({});
check('404 on the list (WordPress before 6.9) -> exit 2, UNCHECKED, not "master off"', r.code === 2 && /UNCHECKED/.test(r.out) && !/master off/.test(r.out), [r.code, r.out]);

console.log('--- read-only, for real');
const src = fs.readFileSync(SCRIPT, 'utf8');
check('the source names no write command of wp.js and no POST/PUT/DELETE', !/['"`](rest-post|quarantine|updates|update-page|create-page|delete-|set-)/.test(src) && !/\b(POST|PUT|PATCH|DELETE)\b/.test(src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '')), 'a write-looking word is in the code');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
