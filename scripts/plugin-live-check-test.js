#!/usr/bin/env node
/**
 * plugin-live-check-test.js: scripts/plugin-live-check.js against the fake WordPress of stale-guard-test.js (issue #37, task 8).
 *   node scripts/plugin-live-check-test.js
 *   LIVE_CHECK_FILE=/path/to/copy.js node scripts/plugin-live-check-test.js    # mutation testing (the copy must sit in scripts/)
 * No site needed. Exit codes under test: 0 all passed, 1 a check failed, 2 something could not be checked.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const SCRIPT = process.env.LIVE_CHECK_FILE || path.join(__dirname, 'plugin-live-check.js');
const FAKE = path.join(__dirname, 'stale-guard-test.js');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-live-'));
const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini');
fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');

let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 500))); if (!ok) failed++; };

const d5 = (on, aside) => ({ version: '2.0.0', divi5: true, switches: Object.assign({ media_audit: false, media_clean_up: false, updates: false }, on), stood_aside: aside || {} });
const CORE = ['oembed/1.0', 'wp/v2', 'divi5-builder/v1'];
const run = state => {
  fs.writeFileSync(STATE, JSON.stringify(Object.assign({ pages: {}, posts: {}, et_pb_canvas: {}, recordMethods: true }, state)));
  const r = spawnSync(process.execPath, [SCRIPT, 'fake'], { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, NODE_OPTIONS: `--require ${FAKE.replace(/\\/g, '/')}` }) });
  return { code: r.status, out: r.stdout + r.stderr, st: JSON.parse(fs.readFileSync(STATE, 'utf8')) };
};

console.log('--- all switches off, nothing extra: clean');
let r = run({ d5: d5({}), index: CORE, settings: { title: 'fake' } });
check('all off and clean -> exit 0', r.code === 0, [r.code, r.out]);
check('... prints the namespaces present, the status JSON and the settings result', /divi5-builder\/v1/.test(r.out) && /"switches"/.test(r.out) && /d5b_switches/.test(r.out) && /not in \/wp\/v2\/settings/i.test(r.out), r.out);
check('... every request it made was a GET (none non-GET)', r.st.requests.length >= 3 && r.st.requests.every(x => x.startsWith('GET ')), r.st.requests);
check('... and it never printed the application password', !/aaaa ?bbbb/.test(r.out), r.out);

console.log('--- a switched-off part whose namespace is present');
r = run({ d5: d5({}), index: CORE.concat(['wp-media-audit/v1']), settings: { title: 'fake' } });
check('media_audit off but wp-media-audit/v1 present -> exit 1, names it', r.code === 1 && /FAIL/.test(r.out) && /wp-media-audit\/v1/.test(r.out) && /media_audit/.test(r.out), [r.code, r.out]);
r = run({ d5: d5({ updates: true }), index: CORE.concat(['wp-site-updates/v1']), settings: { title: 'fake' } });
check('updates ON and wp-site-updates/v1 present -> exit 0 (that is expected)', r.code === 0, [r.code, r.out]);
r = run({ d5: d5({ updates: true }), index: CORE.concat(['wp-media-quarantine/v1']), settings: { title: 'fake' } });
check('only updates on, but wp-media-quarantine/v1 present -> exit 1', r.code === 1 && /wp-media-quarantine\/v1/.test(r.out), [r.code, r.out]);

console.log('--- the option leaks into settings');
r = run({ d5: d5({}), index: CORE, settings: { title: 'fake', d5b_switches: { updates: true } } });
check('d5b_switches in /wp/v2/settings -> exit 1', r.code === 1 && /FAIL/.test(r.out) && /d5b_switches/.test(r.out), [r.code, r.out]);

console.log('--- a part that stood aside for an old copy');
r = run({ d5: d5({}, { media_clean_up: 'constant' }), index: CORE.concat(['wp-media-quarantine/v1']), settings: { title: 'fake' } });
check('switch off, namespace present, stood_aside -> exit 0', r.code === 0, [r.code, r.out]);
check('... with a note naming the part and how it stood aside', /media_clean_up/.test(r.out) && /stood aside/i.test(r.out) && /constant/.test(r.out) && /wp-media-quarantine\/v1/.test(r.out), r.out);
r = run({ d5: d5({}, { media_clean_up: 'route' }), index: CORE.concat(['wp-media-quarantine/v1', 'wp-media-audit/v1']), settings: { title: 'fake' } });
check('stood aside for one part does not excuse ANOTHER part\'s namespace -> exit 1', r.code === 1 && /wp-media-audit\/v1/.test(r.out), [r.code, r.out]);

console.log('--- settings cannot be read');
for (const code of [401, 403]) {
  r = run({ d5: d5({}), index: CORE, settings: code });
  check(`settings ${code} -> UNCHECKED, exit 2`, r.code === 2 && /UNCHECKED/.test(r.out) && /cannot read settings \(needs an administrator\)/.test(r.out), [r.code, r.out]);
}
r = run({ d5: d5({}), index: CORE.concat(['wp-media-audit/v1']), settings: 403 });
check('a real failure beats unchecked: namespace leak + settings 403 -> exit 1', r.code === 1 && /UNCHECKED/.test(r.out), [r.code, r.out]);

console.log('--- the plugin is not on the site');
r = run({ index: ['oembed/1.0', 'wp/v2'], settings: { title: 'fake' } });
check('status route 404 -> says the plugin is absent, exit 1', r.code === 1 && /not (installed|active)|absent/i.test(r.out) && /404/.test(r.out), [r.code, r.out]);

console.log('--- read-only, for real');
const src = fs.readFileSync(SCRIPT, 'utf8');
check('the source names no write command of wp.js and no POST/PUT/DELETE', !/['"`](rest-post|quarantine|updates|update-page|create-page|delete-|set-)/.test(src) && !/\b(POST|PUT|PATCH|DELETE)\b/.test(src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '')), 'a write-looking word is in the code');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
