#!/usr/bin/env node
/**
 * abilities-live-site-test.js: scripts/abilities-live-site.js against the fake WordPress of stale-guard-test.js (issue #32, release 2.3.0).
 *   node scripts/abilities-live-site-test.js
 *   ABILITIES_SITE_FILE=/path/to/copy.js node scripts/abilities-live-site-test.js    # mutation testing (the copy must sit in scripts/)
 * No site needed. Exit codes under test: 0 all good, 1 an unexpected status or a missing history row, 2 usage or a non-d5b-live- page,
 * 3 the homepage plan was printed and the run needs --confirm go.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const SCRIPT = process.env.ABILITIES_SITE_FILE || path.join(__dirname, 'abilities-live-site.js');
const FAKE = path.join(__dirname, 'stale-guard-test.js');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-lives-'));
const STATE = path.join(tmp, 'state.json'), CREDS = path.join(tmp, 'creds.ini'), LEDGER = path.join(tmp, 'ledger.json');
fs.writeFileSync(CREDS, '[fake]\nurl  = https://fake.test\nuser = tester\npass = aaaa bbbb cccc dddd\n');

let failed = 0, n = 0;
let seen;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 700))); if (!ok) failed++; };

const PAGES = {
  7: { id: 7, kind: 'page', title: 'd5b-live-home-test', status: 'publish', content: '' },
  8: { id: 8, kind: 'page', title: 'Real Home Page', status: 'publish', content: '' },
  9: { id: 9, kind: 'page', title: 'd5b-live-draft', status: 'draft', content: '' },
};
// A fresh site: mdl = the model's switches, items = seeded pages. The ledger file goes with it.
const fresh = (mdl, site) => {
  seen = 0;
  try { fs.rmSync(LEDGER); } catch (e) { /* none */ }
  fs.writeFileSync(STATE, JSON.stringify({ pages: {}, posts: {}, et_pb_canvas: {}, recordMethods: true, mdl: Object.assign({ next: 1000, items: JSON.parse(JSON.stringify(PAGES)), revs: {}, site }, mdl || {}) }));
};
const run = args => {
  const r = spawnSync(process.execPath, [SCRIPT, 'fake'].concat(args || []), { encoding: 'utf8',
    env: Object.assign({}, process.env, { FAKE_WP_STATE: STATE, WEB_CREDS_PATH: CREDS, D5B_LIVE_SITE_LEDGER: LEDGER, NODE_OPTIONS: `--require ${FAKE.replace(/\\/g, '/')}` }) });
  return { code: r.status, out: r.stdout + r.stderr, st: JSON.parse(fs.readFileSync(STATE, 'utf8')) };
};
const all = [];
seen = 0;   // calls of the earlier runs of the same fresh site are not this run's
const go = args => { const r = run(args); r.calls = (r.st.mdl.calls || []).slice(seen); seen = (r.st.mdl.calls || []).length; all.push(r); return r; };
const calls = r => r.calls;
const posts = r => calls(r).filter(c => c.startsWith('POST')).map(c => c.slice(5));
const site = r => r.st.mdl.site;
// The first run of a site seeds its state; remember it to compare with the state after a step.
const seed = () => { fresh(); const r = go(['cache']); fs.writeFileSync(path.join(tmp, 'seed.json'), JSON.stringify(site(r))); const s = JSON.parse(fs.readFileSync(path.join(tmp, 'seed.json'), 'utf8')); s.flushed = 0; return s; };
const ORIG = seed();

const sameState = (r, keys) => keys.every(k => JSON.stringify(site(r)[k]) === JSON.stringify(ORIG[k]));

console.log('--- settings');
fresh();
let r = go(['settings']);
check('exit 0, RESULT line', r.code === 0 && /RESULT: every step passed/.test(r.out), [r.code, r.out]);
check('the sequence: settings-get, set, get, set, get', JSON.stringify(calls(r)) === JSON.stringify(['GET settings-get', 'POST settings-set', 'GET settings-get', 'POST settings-set', 'GET settings-get']), calls(r));
check('... the final options equal the originals (restored)', sameState(r, ['opts']), site(r).opts);
check('... two history rows, both settings-set', r.st.mdl.hist.length === 2 && r.st.mdl.hist.every(x => x.ability === 'divi5-builder/settings-set'), r.st.mdl.hist);
check('... a settings-set failing on the first write -> exit 1 and nothing to put back', (() => { fresh({ siteBad: { 'settings-set': 'not_allowed' } }); const x = go(['settings']); return x.code === 1 && /settings-set  refused:not_allowed  HTTP 400/.test(x.out) && posts(x).length === 1 && sameState(x, ['opts']); })());

console.log('--- theme-mod');
fresh();
r = go(['theme-mod']);
check('exit 0: set, get, delete, get', r.code === 0 && JSON.stringify(calls(r)) === JSON.stringify(['GET appearance-get', 'POST appearance-theme-mod-set', 'GET appearance-get', 'POST appearance-theme-mod-delete', 'GET appearance-get']), [r.code, r.out, calls(r)]);
check('... d5b_live_mod is gone, the other mods are untouched', !('d5b_live_mod' in site(r).mods) && sameState(r, ['mods']), site(r).mods);
fresh({ siteBad: { 'appearance-theme-mod-delete': 'not_found' } });
r = go(['theme-mod']);
check('a delete that fails -> exit 1 and the failed put-back is reported', r.code === 1 && /the put-back of the theme-mod step did not work/.test(r.out) && /Remove the theme mod d5b_live_mod/.test(r.out), [r.code, r.out]);

console.log('--- css');
fresh();
r = go(['css']);
check('exit 0: get, set (with the hash), get, set back (with the new hash), get', r.code === 0 && JSON.stringify(calls(r)) === JSON.stringify(['GET appearance-get', 'POST appearance-custom-css-set', 'GET appearance-get', 'POST appearance-custom-css-set', 'GET appearance-get']), [r.code, r.out, calls(r)]);
check('... the stylesheet and its hash are back to the originals', sameState(r, ['css']) && /final equals the original/.test(r.out), [site(r).css, r.out]);
check('... before, after-edit and final hashes printed', /hashes  before=[0-9a-f]{32}  after-edit=[0-9a-f]{32}  final=[0-9a-f]{32}/.test(r.out) && !/before=([0-9a-f]{32})  after-edit=\1/.test(r.out), r.out);
fresh();
{ const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.site = JSON.parse(JSON.stringify(ORIG)); st0.mdl.site.css = 'a{}'; fs.writeFileSync(STATE, JSON.stringify(st0)); }
r = go(['css']);
check('with another stylesheet the same holds (put back exactly)', r.code === 0 && site(r).css === 'a{}', [r.code, site(r).css]);

console.log('--- widget');
fresh();
r = go(['widget']);
check('exit 0: list, add, list, update, move, remove, list', r.code === 0 && JSON.stringify(calls(r)) === JSON.stringify(['GET widgets-list', 'POST widgets-add', 'GET widgets-list', 'POST widgets-update', 'POST widgets-move', 'POST widgets-remove', 'GET widgets-list']), [r.code, r.out, calls(r)]);
check('... no widget is left; it was added to the first registered sidebar (not the inactive one)', Object.keys(site(r).widgets).length === 0 && /widgets-add  ok  HTTP 200  id=text-\d+  sidebar=sidebar-1/.test(r.out), [site(r).widgets, r.out]);
check('... moved to wp_inactive_widgets', /widgets-move  ok  HTTP 200  id=text-\d+  sidebar=wp_inactive_widgets/.test(r.out), r.out);
fresh({ siteBad: { 'widgets-move': 'invalid_input' } });
r = go(['widget']);
check('a move that fails -> exit 1, but the widget is still removed', r.code === 1 && /widgets-move  refused:invalid_input/.test(r.out) && posts(r).includes('widgets-remove') && Object.keys(site(r).widgets).length === 0, [r.code, r.out]);
fresh({ strayWidget: true });
r = go(['widget']);
check('the layout after the step differs from before (a widget left in footer-1) -> exit 1, the layout check names it', r.code === 1 && /the widget layout \(sidebars_widgets\) is NOT back to what it was before the step/.test(r.out) && !/RESULT: every step passed/.test(r.out), [r.code, r.out]);

console.log('--- cache');
fresh();
r = go(['cache']);
check('exit 0, flushed names printed, no Updates switch needed (one POST only)', r.code === 0 && /flushed  2 cache\(s\): object, fake-page-cache/.test(r.out) && JSON.stringify(posts(r)) === '["cache-flush"]', [r.code, r.out]);

console.log('--- cron');
fresh();
r = go(['cron']);
check('exit 0: cron-list then cron-run of wp_privacy_delete_old_export_files only', r.code === 0 && JSON.stringify(calls(r)) === '["GET cron-list","POST cron-run"]' && JSON.stringify(site(r).cronRan) === '["wp_privacy_delete_old_export_files"]', [r.code, r.out, site(r).cronRan]);
check('... prints duration, rescheduled, over_budget', /cron  wp_privacy_delete_old_export_files  duration_ms=12  rescheduled=true  over_budget=false/.test(r.out), r.out);
fresh({}, JSON.parse(JSON.stringify(ORIG)));
{ const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.site.noCron = true; fs.writeFileSync(STATE, JSON.stringify(st0)); }
r = go(['cron']);
check('without the event: "no safe event found", nothing run, exit 1', r.code === 1 && /no safe event found/.test(r.out) && !posts(r).length && !site(r).cronRan.length, [r.code, r.out]);

console.log('--- homepage');
fresh();
r = go(['homepage', '--page', '7']);
check('without --confirm go: the plan, exit 3, no write', r.code === 3 && /^plan: /m.test(r.out) && /--confirm go/.test(r.out) && !posts(r).length && sameState(r, ['opts']), [r.code, r.out]);
r = go(['homepage', '--page', '7', '--confirm', 'go']);
check('with --confirm go: pages-get, settings-get, set, get, set back, get; exit 0', r.code === 0 && JSON.stringify(calls(r)) === JSON.stringify(['GET pages-get', 'GET settings-get', 'POST settings-set-homepage', 'GET settings-get', 'POST settings-set-homepage', 'GET settings-get']), [r.code, r.out, calls(r)]);
check('... the three options are back to the originals', sameState(r, ['opts']) && r.st.mdl.hist.length === 2, site(r).opts);
r = go(['homepage', '--page', '8', '--confirm', 'go']);
check('a page not titled d5b-live- -> exit 2, no write', r.code === 2 && /does not start with d5b-live-/.test(r.out) && !posts(r).length, [r.code, r.out]);
r = go(['homepage', '--confirm', 'go']);
check('no --page -> exit 2', r.code === 2 && /needs --page/.test(r.out), [r.code, r.out]);
{
  fresh();
  const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.site = JSON.parse(JSON.stringify(ORIG)); Object.assign(st0.mdl.site.opts, { show_on_front: 'page', page_on_front: '8', page_for_posts: '9' }); fs.writeFileSync(STATE, JSON.stringify(st0));
  r = go(['homepage', '--page', '7', '--confirm', 'go']);
  check('a site that already has a static homepage and a posts page: set to the nominated page, then all three back', r.code === 0 && site(r).opts.show_on_front === 'page' && site(r).opts.page_on_front === '8' && site(r).opts.page_for_posts === '9', [r.code, r.out, site(r).opts]);
  r = go(['homepage', '--page', '9', '--confirm', 'go']);
  check('... the nominated page being the posts page -> exit 2', r.code === 2 && /posts page/.test(r.out) && !posts(r).length, [r.code, r.out]);
}
fresh();
r = go(['homepage', '--page', '9', '--confirm', 'go']);
check('a draft page: the plugin refuses it (400) -> exit 1, nothing to put back, options unchanged', r.code === 1 && /settings-set-homepage  refused:invalid_input  HTTP 400/.test(r.out) && posts(r).length === 1 && sameState(r, ['opts']), [r.code, r.out]);

{
  // leftover homepage ids under show_on_front posts cannot be restored through an ability: refuse before any write
  fresh();
  const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.site = JSON.parse(JSON.stringify(ORIG)); Object.assign(st0.mdl.site.opts, { show_on_front: 'posts', page_on_front: '8', page_for_posts: '0' }); fs.writeFileSync(STATE, JSON.stringify(st0));
  r = go(['homepage', '--page', '7', '--confirm', 'go']);
  check('show_on_front posts with a leftover page_on_front -> exit 2, the message, no write', r.code === 2 && /leftover homepage ids cannot be restored through an ability/.test(r.out) && !posts(r).length && sameState(r, []) && site(r).opts.page_on_front === '8', [r.code, r.out]);
  r = go(['homepage', '--page', '7']);
  check('... also without --confirm go (nothing to plan)', r.code === 2 && !posts(r).length, [r.code, r.out]);
}

console.log('--- css guards');
for (const [label, patch, why] of [['a truncated stylesheet', { cssTruncated: true }, /longer than appearance-get returns/], ['a masked ([redacted]) stylesheet', { css: 'a{} [redacted]' }, /masked/]]) {
  fresh();
  const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); st0.mdl.site = Object.assign(JSON.parse(JSON.stringify(ORIG)), patch); fs.writeFileSync(STATE, JSON.stringify(st0));
  r = go(['css']);
  check(label + ' -> exit 1 and no POST', r.code === 1 && why.test(r.out) && !posts(r).length, [r.code, r.out]);
}

console.log('--- history');
fresh();
for (const s of ['settings', 'theme-mod', 'css', 'widget', 'cache', 'cron']) go([s]);
r = go(['history']);
check('after all steps: exit 0, every write has a row', r.code === 0 && /history: all 12 write\(s\) have a row/.test(r.out), [r.code, r.out]);
check('... every update/delete/move row holds its before copy', /history: all 9 update\/delete\/move row\(s\) hold their before copy/.test(r.out) && calls(r).filter(c => c === 'GET history-change-get').length === 9, [r.out]);
for (const [spoil, why] of [['too_large', 'before is too_large'], ['missing', 'no before copy']]) {
  fresh({ badBefore: { 'widgets-update': spoil } });
  for (const s of ['widget']) go([s]);
  r = go(['history']);
  check(`a widgets-update row whose before is ${spoil} -> exit 1, the row named with why`, r.code === 1 && new RegExp('history-change-get  ok  HTTP 200  id=\\d+  id=\\d+  divi5-builder/widgets-update  ' + why).test(r.out), [r.code, r.out]);
}
fresh({ noHistory: ['widgets-move'] });
go(['widget']);
r = go(['history']);
check('a write without a row (widgets-move) -> exit 1, MISSING line', r.code === 1 && /MISSING: no history row for widgets-move on \d+/.test(r.out), [r.code, r.out]);
fresh({ noHistory: ['cache-flush'] });
go(['cache']);
r = go(['history']);
check('a cache-flush without a row -> exit 1', r.code === 1 && /MISSING: no history row for cache-flush/.test(r.out), [r.code, r.out]);
fresh();
r = go(['history']);
check('nothing recorded -> exit 1 (nothing to check)', r.code === 1 && /recorded no write for today/.test(r.out), [r.code, r.out]);
fresh();
go(['settings']);
{ const st0 = JSON.parse(fs.readFileSync(STATE, 'utf8')); const day = st0.mdl.hist[0].time_utc; for (let i = 0; i < 120; i++) st0.mdl.hist.push({ id: 900 + i, time_utc: day, user_id: 1, ability: 'divi5-builder/other-write', audit_id: 1, object_type: 'post', object_id: 5, action: 'update', revision_id: null, summary: 'Someone else', before: { id: 5 } }); st0.mdl.histPages = []; fs.writeFileSync(STATE, JSON.stringify(st0)); }
r = go(['history']);
check('has_more is followed: pages 1, 2 and 3 of 50 were read, the rows of the run still found', JSON.stringify(r.st.mdl.histPages) === '[1,2,3]' && r.code === 0, [r.st.mdl.histPages, r.code, r.out]);

console.log('--- all');
fresh();
r = go(['all']);
check('all without --page: every step but the homepage, history last; exit 0; homepage skipped', r.code === 0 && /--- homepage: skipped/.test(r.out) && /--- history/.test(r.out) && /history: all \d+ write\(s\) have a row/.test(r.out), [r.code, r.out]);
check('... everything it changed is back: options, mods, stylesheet, widgets', sameState(r, ['opts', 'mods', 'css']) && Object.keys(site(r).widgets).length === 0, site(r));
r = go(['all', '--page', '7']);
check('all --page without --confirm go: the rest runs, exit 3 at the homepage plan', r.code === 3 && /^plan: /m.test(r.out) && /--- history/.test(r.out), [r.code, r.out]);
r = go(['all', '--page', '7', '--confirm', 'go']);
check('all --page --confirm go: exit 0, homepage included and restored', r.code === 0 && posts(r).filter(c => c === 'settings-set-homepage').length === 2 && sameState(r, ['opts']), [r.code, r.out]);

console.log('--- usage');
fresh();
for (const [label, args] of [['no step', []], ['a bad step', ['nope']], ['an unknown option', ['settings', '--bogus', 'x']], ['--page not a number', ['homepage', '--page', 'abc']], ['--confirm with another word', ['homepage', '--page', '7', '--confirm', 'yes']], ['--page on settings', ['settings', '--page', '7']], ['--confirm on css', ['css', '--confirm', 'go']]]) {
  r = go(args);
  check(`${label} -> exit 2 and nothing sent`, r.code === 2 && !(r.st.requests || []).length, [r.code, r.out]);
}

console.log('--- what crosses the wire and what is printed');
const RUN = /^(GET|POST) \/wp-json\/wp-abilities\/v1\/abilities\/divi5-builder\/[a-z0-9-]+\/run(\?.*)?$/;
const allReqs = all.reduce((a, x) => a.concat(x.st.requests || []), []);
check('every request is a GET or POST to a divi5-builder run route', allReqs.length > 100 && allReqs.every(x => RUN.test(x) || /^(GET|POST) \/wp-json\/wp-abilities\/v1\/abilities\/divi5-builder\/[a-z0-9-]+\/run$/.test(x)), allReqs.filter(x => !RUN.test(x)).slice(0, 3));
check('... a POST only ever goes to a write ability (never to a read)', all.every(x => (x.st.mdl.calls || []).every(c => !/^POST (pages-get|settings-get|appearance-get|widgets-list|cron-list|history-)/.test(c))), 'POST to a read');
const outAll = all.map(x => x.out).join('\n');
check('no value beyond the allowed fields is printed (planted secret absent from every run)', !/SECRET-LEAK-VALUE|ERR-MSG-/.test(outAll), outAll.split('\n').filter(l => /SECRET-LEAK|ERR-MSG/.test(l)).slice(0, 3));
check('... nor the old tagline text, nor any text of a stylesheet', !/Fake tagline|color: red/.test(outAll), 'a value was printed');
check('the application password never appears', !/aaaa ?bbbb/.test(outAll + JSON.stringify(all.map(x => x.st))), 'password found');

console.log('--- the source');
const src = fs.readFileSync(SCRIPT, 'utf8').replace(/\r\n?/g, '\n');
const code = src.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
check('it names no wp.js command except through the shared helpers, and reads no credentials file', !/['"`](update-page|create-page|delete-|set-|quarantine|updates)\b/.test(code) && !/web-creds|WEB_CREDS|\.ini/.test(code), 'a forbidden word is in the code');
check('it never sends DELETE or PUT', !/\b(DELETE|PUT|PATCH)\b/.test(code), 'a forbidden method');

fs.rmSync(tmp, { recursive: true, force: true });
console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
