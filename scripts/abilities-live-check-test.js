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
const run = state => {
  fs.writeFileSync(STATE, JSON.stringify(Object.assign({ pages: {}, posts: {}, et_pb_canvas: {}, recordMethods: true }, state)));
  const r = spawnSync(process.execPath, [SCRIPT, 'fake'], { encoding: 'utf8',
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
r = run({ abilities: withGet, abilityReplies: { 'divi5-builder/posts-list': { status: 200, body: { items: [{ id: 42, title: 'T' }] } } } });
const getRun = (r.st.abilityRuns || []).find(x => x.name === 'divi5-builder/posts-get');
check('posts-get gets the first id of posts-list as input[id]=42, through GET query', getRun && decodeURIComponent(getRun.query) === 'input[id]=42' && onlyGet(r.st), r.st.abilityRuns);
check('... users-get has no users-list: "skipped: needs id", and is not run', /users-get  skipped: needs id/.test(r.out) && !(r.st.abilityRuns || []).some(x => /users-get/.test(x.name)), r.out);
check('... two required inputs: skipped too', /cpt-get  skipped: needs id/.test(r.out), r.out);
check('... exit 0, the list value title is never printed', r.code === 0 && !/"T"|title/.test(r.out), [r.code, r.out]);

console.log('--- paging');
const many = []; for (let i = 0; i < 5; i++) many.push(ab('thing' + i + '-list'));
r = run({ abilities: [ab('core-version')].concat(many), abilitiesPerPage: 2 });
check('follows pages until a short page: every ability run (page size 2, 6 abilities)', (r.st.abilityRuns || []).length === 6, r.st.abilityRuns);

console.log('--- the API is not there');
r = run({});
check('404 on the list (WordPress before 6.9) -> exit 2, UNCHECKED, not "master off"', r.code === 2 && /UNCHECKED/.test(r.out) && !/master off/.test(r.out), [r.code, r.out]);

console.log('--- read-only, for real');
const src = fs.readFileSync(SCRIPT, 'utf8');
check('the source names no write command of wp.js and no POST/PUT/DELETE', !/['"`](rest-post|quarantine|updates|update-page|create-page|delete-|set-)/.test(src) && !/\b(POST|PUT|PATCH|DELETE)\b/.test(src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '')), 'a write-looking word is in the code');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
