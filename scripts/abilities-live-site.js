#!/usr/bin/env node
/**
 * abilities-live-site.js: the live run of the divi5-builder site abilities (issue #32, release 2.3.0), one step at a time.
 *
 *   node scripts/abilities-live-site.js <site> <step> [--page <id>] [--confirm go]
 *   steps: settings, homepage, theme-mod, css, widget, cache, cron, history, all
 *
 * It talks to the site only through scripts/wp.js (the helpers of abilities-live-write.js: rest-get for the readonly abilities, rest-post
 * for the writes; wp.js holds the credentials, this script never sees or prints them), and only to the run routes
 * wp-abilities/v1/abilities/divi5-builder/<name>/run. Every step reads first, changes, verifies by reading, and puts back exactly
 * what it changed. A step that fails halfway still runs its put-back.
 *
 *   settings    blogdescription: settings-get, settings-set to a d5b-live- text, settings-get to check, settings-set back to the value
 *               read first, settings-get to check. (Needs no risky switch.)
 *   homepage    --page <id> --confirm go: the page must be titled d5b-live-... (pages-get). Reads show_on_front, page_on_front and
 *               page_for_posts, prints the plan, and WITHOUT --confirm go stops there (exit 3, nothing changed). Then
 *               settings-set-homepage to that page (the posts page is left as it is), settings-get to check, and the three old values back.
 *               Needs the Settings risky switch and the Pages category.
 *   theme-mod   appearance-theme-mod-set d5b_live_mod, appearance-get to check, appearance-theme-mod-delete, appearance-get to check
 *               it is gone. Needs the Appearance risky switch.
 *   css         appearance-get (custom_css_hash and the stylesheet), appearance-custom-css-set with the previous stylesheet plus a
 *               a d5b-live comment and that hash, appearance-get to check, then the previous stylesheet back with the new hash; the
 *               hash must end up equal to the first one. Needs the Appearance risky switch.
 *   widget      widgets-list; a text widget titled d5b-live-widget in the first registered sidebar, updated, moved to
 *               wp_inactive_widgets, removed; widgets-list to check it is gone.
 *   cache       cache-flush (it needs no Updates switch).
 *   cron        cron-list; runs wp_privacy_delete_old_export_files (on every WordPress site) with cron-run and prints the duration,
 *               rescheduled and over_budget. Without that event: "no safe event found", nothing runs. Needs the Site health risky switch.
 *   history     history-changes-list for today, page by page while has_more (at most 10 pages of 50): every write this script made (kept
 *               in a small ledger file in the temp folder) must have a row; then history-change-get of each update, delete and move row
 *               must give a before copy that is there, not too_large, and withholds nothing unless its summary says so.
 *   all         settings, theme-mod, css, widget, cache, cron, history; the homepage too when --page is given (still needs --confirm go).
 *
 * It prints only names, ids, hashes, statuses, durations and byte counts: never a setting, a stylesheet, a widget or a task's
 * value. Everything it creates is named d5b-live-... or d5b_live_...
 *
 * Exit 0: every step passed. 1: an unexpected status, a failed expectation or a missing history row. 2: usage, or a page whose
 * title does not start with d5b-live-. 3: the homepage plan was printed and the run needs --confirm go.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { tok, getAbility, postAbility, stampOf, dayOf, Fail, Usage, MARK } = require('./abilities-live-write');

const STEPS = ['settings', 'homepage', 'theme-mod', 'css', 'widget', 'cache', 'cron', 'history'];
const ALL_STEPS = ['settings', 'homepage', 'theme-mod', 'css', 'widget', 'cache', 'cron', 'history'];
const NS = 'divi5-builder/';
const MOD = 'd5b_live_mod';
const CSS_MARK = '/* d5b-live */';
const CRON_HOOK = 'wp_privacy_delete_old_export_files';
const INACTIVE = 'wp_inactive_widgets';

// ---------------------------------------------------------------- printing and expectations

/** Only these keys of a result are ever printed, and only when they are plain values. */
const PRINT = ['id', 'status', 'hash', 'bytes', 'name', 'hook', 'sidebar', 'position', 'changed', 'deleted', 'removed', 'ran', 'duration_ms', 'rescheduled', 'unscheduled', 'over_budget', 'output_bytes'];
function fields(j, extra) {
  const o = [];
  if (j && typeof j === 'object') for (const k of PRINT) if (j[k] !== undefined && j[k] !== null && typeof j[k] !== 'object') o.push(k + '=' + tok(j[k]));
  return o.concat(extra || []).join('  ');
}

function line(ctx, short, r, extra) {
  ctx.lines.push(`${short}  ${r.ok ? 'ok' : 'refused:' + r.code}  HTTP ${r.status}` + (r.ok ? '  ' + fields(r.json, extra) : (extra && extra.length ? '  ' + extra.join('  ') : '')));
}

/** A call that has to succeed: prints it and throws Fail when it did not. */
function must(ctx, method, short, input, extra) {
  const r = method === 'GET' ? getAbility(ctx, short, input) : postAbility(ctx, short, input);
  line(ctx, short, r, extra);
  if (!r.ok) throw new Fail(`${short} wanted ok, got refused:${r.code} (HTTP ${r.status})`);
  if (!r.json || typeof r.json !== 'object') throw new Fail(`${short} gave no JSON answer`);
  return r;
}

/** A failed expectation: counted, printed, the run goes on (the put-back still has to happen). */
function expect(ctx, ok, what) { if (!ok) { ctx.failures++; ctx.lines.push('  FAILED: ' + what); } return ok; }

// ---------------------------------------------------------------- the ledger

function ledgerPath(site) { return process.env.D5B_LIVE_SITE_LEDGER || path.join(os.tmpdir(), 'd5b-live-site-' + String(site).replace(/[^A-Za-z0-9_-]/g, '_') + '.json'); }
function readLedger(ctx) {
  try { const j = JSON.parse(fs.readFileSync(ledgerPath(ctx.site), 'utf8')); return { entries: Array.isArray(j.entries) ? j.entries : [] }; }
  catch (e) { return { entries: [] }; }
}
/** One write that went through: the history step looks for its row. id null = any object id; needle = text the summary must hold. */
function rec(ctx, short, id, needle) {
  const led = readLedger(ctx);
  led.entries.push({ day: ctx.day, ability: short, id: id === undefined || id === null ? null : Number(id), needle: needle || null });
  fs.writeFileSync(ledgerPath(ctx.site), JSON.stringify(led));
}

// ---------------------------------------------------------------- reads shared by the steps

/** settings-get as an object of key => value; the values are never printed. */
function readSettings(ctx) {
  const r = must(ctx, 'GET', 'settings-get', {});
  if (!r.json.settings || typeof r.json.settings !== 'object') throw new Fail('settings-get gave no settings');
  return r.json.settings;
}

function readAppearance(ctx) {
  const r = must(ctx, 'GET', 'appearance-get', {}, []);
  return r.json;
}

/** Runs the put-back whatever happened in the step; a put-back that fails is reported with where to find the old value. */
function guarded(ctx, step, body, restore, how) {
  let err = null;
  try { body(); } catch (e) { err = e; }
  try { restore(); }
  catch (e) {
    if (e instanceof Usage) throw e;
    ctx.failures++; ctx.lines.push(`  FAILED: the put-back of the ${step} step did not work (${e instanceof Fail ? e.message : 'unexpected error'}). ${how}`);
  }
  if (err) throw err;
}

// ---------------------------------------------------------------- the steps

function stepSettings(ctx) {
  const prev = readSettings(ctx).blogdescription;
  if (typeof prev !== 'string') throw new Fail('settings-get gave no blogdescription');
  if (prev.includes('[redacted]')) throw new Fail('blogdescription came back masked ([redacted]); a masked read cannot be written back');
  const next = MARK + 'settings-' + ctx.stamp;
  let changed = false;
  guarded(ctx, 'settings', () => {
    must(ctx, 'POST', 'settings-set', { settings: { blogdescription: next } });
    changed = true; rec(ctx, 'settings-set', null, 'blogdescription');
    expect(ctx, readSettings(ctx).blogdescription === next, 'settings-get does not show the new blogdescription');
  }, () => {
    if (!changed) return;
    must(ctx, 'POST', 'settings-set', { settings: { blogdescription: prev } }); rec(ctx, 'settings-set', null, 'blogdescription');
    if (!expect(ctx, readSettings(ctx).blogdescription === prev, 'blogdescription is NOT back to the value read first')) throw new Fail('blogdescription differs after the put-back');
  }, 'Put blogdescription back in Settings > General (the change history keeps the old value).');
  return 'ok';
}

function stepHomepage(ctx) {
  if (!ctx.page) throw new Usage('the homepage step needs --page <id> (a published page titled d5b-live-...)');
  const pg = must(ctx, 'GET', 'pages-get', { id: ctx.page });
  const title = pg.json.title;
  if (typeof title !== 'string' || !title.startsWith(MARK)) throw new Usage(`page ${tok(ctx.page)} does not start with ${MARK}: nothing was changed`);
  const s = readSettings(ctx);
  const old = { show_on_front: String(s.show_on_front), page_on_front: Number(s.page_on_front) || 0, page_for_posts: Number(s.page_for_posts) || 0 };
  if (old.show_on_front !== 'posts' && old.show_on_front !== 'page') throw new Fail('settings-get gave an odd show_on_front');
  if (old.page_for_posts === Number(ctx.page)) throw new Usage('the nominated page is the posts page of the site; nominate another d5b-live- page');
  const keep = old.show_on_front === 'page' ? old.page_for_posts : 0;
  ctx.lines.push(`plan: show_on_front ${old.show_on_front} -> page, page_on_front ${old.page_on_front} -> ${tok(ctx.page)}, page_for_posts stays ${keep}; afterwards all three go back to ${old.show_on_front}, ${old.page_on_front}, ${old.page_for_posts}.`);
  if (ctx.confirm !== 'go') {
    ctx.lines.push('The homepage is not changed. Run again with --confirm go to do it (the Settings risky switch and the Pages category must be on).');
    return 'confirm';
  }
  let changed = false;
  guarded(ctx, 'homepage', () => {
    must(ctx, 'POST', 'settings-set-homepage', { show_on_front: 'page', page_on_front: Number(ctx.page), page_for_posts: keep });
    changed = true; rec(ctx, 'settings-set-homepage', null, null);
    const n = readSettings(ctx);
    expect(ctx, n.show_on_front === 'page' && Number(n.page_on_front) === Number(ctx.page), 'settings-get does not show the new homepage');
  }, () => {
    if (!changed) return;
    const back = old.show_on_front === 'page' ? { show_on_front: 'page', page_on_front: old.page_on_front, page_for_posts: old.page_for_posts } : { show_on_front: 'posts' };
    must(ctx, 'POST', 'settings-set-homepage', back); rec(ctx, 'settings-set-homepage', null, null);
    const n = readSettings(ctx);
    const same = String(n.show_on_front) === old.show_on_front && (Number(n.page_on_front) || 0) === (old.show_on_front === 'page' ? old.page_on_front : 0) && (Number(n.page_for_posts) || 0) === (old.show_on_front === 'page' ? old.page_for_posts : 0);
    if (!expect(ctx, same, 'the homepage settings are NOT back to the values read first')) throw new Fail('homepage differs after the put-back');
  }, `Put Settings > Reading back to show_on_front ${old.show_on_front}, page_on_front ${old.page_on_front}, page_for_posts ${old.page_for_posts} (the change history keeps the old values).`);
  return 'ok';
}

function stepThemeMod(ctx) {
  const a0 = readAppearance(ctx);
  const mods = a0.theme_mods && typeof a0.theme_mods === 'object' ? a0.theme_mods : {};
  if (Object.prototype.hasOwnProperty.call(mods, MOD)) ctx.lines.push(`  note: ${MOD} already existed (a leftover of an earlier run); it is deleted at the end like the new one.`);
  const val = MARK + 'mod-' + ctx.stamp;
  let set = false;
  guarded(ctx, 'theme-mod', () => {
    const r = must(ctx, 'POST', 'appearance-theme-mod-set', { name: MOD, value: val });
    set = true; rec(ctx, 'appearance-theme-mod-set', null, MOD);
    expect(ctx, r.json.changed === true || Object.prototype.hasOwnProperty.call(mods, MOD), 'appearance-theme-mod-set did not say changed');
    const a1 = readAppearance(ctx);
    expect(ctx, a1.theme_mods && a1.theme_mods[MOD] === val, `appearance-get does not show ${MOD}`);
  }, () => {
    if (!set) return;
    must(ctx, 'POST', 'appearance-theme-mod-delete', { name: MOD }); rec(ctx, 'appearance-theme-mod-delete', null, MOD);
    const a2 = readAppearance(ctx);
    if (!expect(ctx, !(a2.theme_mods && Object.prototype.hasOwnProperty.call(a2.theme_mods, MOD)), `${MOD} is still there after the delete`)) throw new Fail('theme mod still present');
  }, `Remove the theme mod ${MOD} (Customizer, or appearance-theme-mod-delete).`);
  return 'ok';
}

function stepCss(ctx) {
  const a0 = readAppearance(ctx);
  const h0 = a0.custom_css_hash, prev = a0.custom_css;
  if (typeof h0 !== 'string' || !h0) throw new Fail('appearance-get gave no custom_css_hash');
  if (typeof prev !== 'string') throw new Fail('appearance-get gave no custom_css');
  if (a0.custom_css_truncated === true) throw new Fail('the stylesheet is longer than appearance-get returns (256 KB); it cannot be put back safely, so nothing was changed');
  if (prev.includes('[redacted]')) throw new Fail('the stylesheet came back masked ([redacted]); a masked read cannot be written back');
  ctx.lines.push(`stylesheet  hash=${tok(h0)}  bytes=${tok(a0.custom_css_bytes)}`);
  let hash = h0, changed = false;
  guarded(ctx, 'css', () => {
    const r = must(ctx, 'POST', 'appearance-custom-css-set', { css: prev + '\n' + CSS_MARK, expect_hash: h0 });
    changed = true; hash = r.json.hash; rec(ctx, 'appearance-custom-css-set', r.json.id, null);
    expect(ctx, typeof hash === 'string' && hash !== h0, 'appearance-custom-css-set did not give a new hash');
    const a1 = readAppearance(ctx);
    expect(ctx, a1.custom_css_hash === hash && String(a1.custom_css).endsWith(CSS_MARK), 'appearance-get does not show the changed stylesheet under the new hash');
    hash = a1.custom_css_hash;
  }, () => {
    if (!changed) return;
    const r = must(ctx, 'POST', 'appearance-custom-css-set', { css: prev, expect_hash: hash });
    rec(ctx, 'appearance-custom-css-set', r.json.id, null);
    const a2 = readAppearance(ctx);
    ctx.lines.push(`hashes  before=${tok(h0)}  after-edit=${tok(hash)}  final=${tok(a2.custom_css_hash)}  ${a2.custom_css_hash === h0 && a2.custom_css === prev ? 'final equals the original' : 'FINAL DOES NOT EQUAL THE ORIGINAL'}`);
    if (!expect(ctx, a2.custom_css_hash === h0 && a2.custom_css === prev, 'the stylesheet is NOT back to what was read first')) throw new Fail('stylesheet differs after the put-back');
  }, 'Put the previous stylesheet back in Appearance > Customize > Additional CSS (the change history row appearance-custom-css-set keeps it whole).');
  return 'ok';
}

function stepWidget(ctx) {
  const l = must(ctx, 'GET', 'widgets-list', {});
  const sb = (Array.isArray(l.json.sidebars) ? l.json.sidebars : []).find(s => s && typeof s.id === 'string' && s.id !== INACTIVE && typeof s.name === 'string' && s.name !== '');
  if (!sb) throw new Fail('no registered sidebar to put a widget in');
  const WTITLE = MARK + 'widget';
  let id = null;
  guarded(ctx, 'widget', () => {
    const add = must(ctx, 'POST', 'widgets-add', { sidebar: sb.id, type: 'text', settings: { title: WTITLE, text: MARK + 'widget-' + ctx.stamp }, position: 0 });
    id = add.json.id;
    if (typeof id !== 'string' || !/^text-\d+$/.test(id)) { id = null; throw new Fail('widgets-add gave no text-N id'); }
    rec(ctx, 'widgets-add', id.split('-')[1], null);
    expect(ctx, add.json.sidebar === sb.id, 'widgets-add did not place the widget in the first sidebar');
    const l1 = must(ctx, 'GET', 'widgets-list', {});
    const here = (l1.json.sidebars || []).find(s => s.id === sb.id);
    expect(ctx, !!(here && (here.widgets || []).some(w => w.id === id)), 'widgets-list does not show the new widget');
    must(ctx, 'POST', 'widgets-update', { id, settings: { title: WTITLE, text: MARK + 'widget-edited-' + ctx.stamp } }); rec(ctx, 'widgets-update', id.split('-')[1], null);
    const mv = must(ctx, 'POST', 'widgets-move', { id, sidebar: INACTIVE, position: 0 }); rec(ctx, 'widgets-move', id.split('-')[1], null);
    expect(ctx, mv.json.sidebar === INACTIVE, 'widgets-move did not move the widget to wp_inactive_widgets');
  }, () => {
    if (id === null) return;
    must(ctx, 'POST', 'widgets-remove', { id }); rec(ctx, 'widgets-remove', id.split('-')[1], null);
    const l2 = must(ctx, 'GET', 'widgets-list', {});
    const still = (l2.json.sidebars || []).some(s => (s.widgets || []).some(w => w.id === id));
    if (!expect(ctx, !still, `the widget ${tok(id)} is still listed after the remove`)) throw new Fail('widget still present');
  }, `Remove the widget titled ${WTITLE} (Appearance > Widgets).`);
  return 'ok';
}

function stepCache(ctx) {
  const r = must(ctx, 'POST', 'cache-flush', {});
  rec(ctx, 'cache-flush', null, null);
  const names = Array.isArray(r.json.flushed) ? r.json.flushed.filter(x => typeof x === 'string').map(tok) : null;
  if (!expect(ctx, names !== null, 'cache-flush gave no flushed list')) return 'ok';
  ctx.lines.push(`flushed  ${names.length} cache(s): ${names.join(', ') || 'none'}`);
  return 'ok';
}

function stepCron(ctx) {
  const l = must(ctx, 'GET', 'cron-list', {});
  const ev = (Array.isArray(l.json.events) ? l.json.events : []).find(e => e && e.hook === CRON_HOOK && e.schedule && e.schedule !== 'single' && Number.isInteger(e.timestamp));
  if (!ev) { ctx.failures++; ctx.lines.push(`  FAILED: no safe event found (${CRON_HOOK} is not scheduled as a recurring event); nothing was run.`); return 'ok'; }
  const input = { hook: ev.hook, timestamp: ev.timestamp, schedule: ev.schedule };
  if (typeof ev.args_key === 'string' && ev.args_key) input.args_key = ev.args_key;
  const r = must(ctx, 'POST', 'cron-run', input);
  rec(ctx, 'cron-run', null, CRON_HOOK);
  expect(ctx, r.json.ran === true, 'cron-run did not say ran');
  expect(ctx, r.json.rescheduled === true, 'cron-run did not say rescheduled (the recurring event must be booked again)');
  ctx.lines.push(`cron  ${tok(ev.hook)}  duration_ms=${tok(r.json.duration_ms)}  rescheduled=${tok(r.json.rescheduled)}  over_budget=${tok(r.json.over_budget)}`);
  return 'ok';
}

// ---- history

const HISTORY_PAGES = 10;
const HISTORY_PER_PAGE = 50;

function historyRows(ctx) {
  const rows = [];
  for (let page = 1; page <= HISTORY_PAGES; page++) {
    const r = must(ctx, 'GET', 'history-changes-list', { since: ctx.day, per_page: HISTORY_PER_PAGE, page }, ['page=' + page]);
    rows.push(...(Array.isArray(r.json.items) ? r.json.items.filter(x => x && typeof x === 'object') : []));
    if (r.json.has_more !== true) return { rows, complete: true };
  }
  return { rows, complete: false };
}

/** The actions whose row must hold the state from before the change (history-change-get). */
const NEEDS_BEFORE = ['update', 'delete', 'move'];

function beforeProblem(row) {
  const b = row.before;
  if (!b || typeof b !== 'object' || Array.isArray(b) || !Object.keys(b).length) return 'no before copy';
  if (b.too_large === true) return 'before is too_large';
  if (/"withheld"\s*:/.test(JSON.stringify(b)) && !/withheld/i.test(String(row.summary || ''))) return 'before has a withheld value';
  return '';
}

function stepHistory(ctx) {
  const want = readLedger(ctx).entries.filter(e => e && e.day === ctx.day);
  const got = historyRows(ctx);
  const rows = got.rows;
  if (!got.complete) ctx.lines.push(`  note: stopped after ${HISTORY_PAGES} pages of ${HISTORY_PER_PAGE}; older rows of today were not read`);
  ctx.lines.push(`${rows.length} change row(s) today; this script made ${want.length} write(s) today.`);
  if (!want.length) { ctx.failures++; ctx.lines.push('  FAILED: this script has recorded no write for today, so there is nothing to check; run a step first'); return 'ok'; }
  const used = new Set(); let missing = 0;
  for (const e of want) {
    // By ability, then the object id where the script knows it, then a word the summary must hold.
    const i = rows.findIndex((x, k) => !used.has(k) && x.ability === NS + e.ability && (e.id === null || Number(x.object_id) === e.id) && (e.needle === null || String(x.summary || '').includes(e.needle)));
    if (i < 0) { missing++; ctx.lines.push(`  MISSING: no history row for ${tok(e.ability)}${e.id === null ? '' : ' on ' + e.id}`); } else used.add(i);
  }
  if (missing) ctx.failures++;
  ctx.lines.push(missing ? `history: ${missing} of ${want.length} write(s) have no row` : `history: all ${want.length} write(s) have a row`);
  let bad = 0, seen = 0;
  for (const k of used) {
    const x = rows[k];
    if (!NEEDS_BEFORE.includes(x.action)) continue;
    seen++;
    const g = getAbility(ctx, 'history-change-get', { id: x.id });
    const why = !g.ok ? 'refused:' + g.code : beforeProblem(g.json || {});
    if (why) { bad++; line(ctx, 'history-change-get', g, ['id=' + tok(x.id), tok(x.ability), tok(why)]); }
  }
  if (bad) ctx.failures++;
  ctx.lines.push(bad ? `history: ${bad} of ${seen} update/delete/move row(s) lack a usable before copy` : `history: all ${seen} update/delete/move row(s) hold their before copy`);
  return 'ok';
}

// ---------------------------------------------------------------- the run

function parseArgs(argv) {
  const site = argv[0], step = argv[1];
  if (!site || site.startsWith('-') || !step || ![...STEPS, 'all'].includes(step)) throw new Usage('usage: node abilities-live-site.js <site> <settings|homepage|theme-mod|css|widget|cache|cron|history|all> [--page <id>] [--confirm go]');
  const o = { site, step };
  const rest = argv.slice(2);
  for (let i = 0; i < rest.length; i++) {
    const a = rest[i];
    if (!['--page', '--confirm'].includes(a) || i + 1 >= rest.length) throw new Usage(`unknown or incomplete option ${tok(a)}`);
    o[a.slice(2)] = rest[++i];
  }
  if (o.page !== undefined && !/^[1-9][0-9]*$/.test(o.page)) throw new Usage('--page must be a page id');
  if (o.confirm !== undefined && o.confirm !== 'go') throw new Usage('--confirm must be go');
  if (o.page !== undefined && !['homepage', 'all'].includes(step)) throw new Usage('--page belongs to the homepage step');
  if (o.confirm !== undefined && !['homepage', 'all'].includes(step)) throw new Usage('--confirm belongs to the homepage step');
  return o;
}

function run(argv, wpjs) {
  const o = parseArgs(argv);
  const now = new Date();
  const ctx = { site: o.site, wpjs, stamp: stampOf(now), day: dayOf(now), page: o.page, confirm: o.confirm, lines: [], failures: 0 };
  const fns = { settings: stepSettings, homepage: stepHomepage, 'theme-mod': stepThemeMod, css: stepCss, widget: stepWidget, cache: stepCache, cron: stepCron, history: stepHistory };
  const list = o.step === 'all' ? ALL_STEPS.filter(s => s !== 'homepage' || ctx.page) : [o.step];
  let needConfirm = false;
  for (const s of list) {
    ctx.lines.push(`--- ${s}`);
    try { if (fns[s](ctx) === 'confirm') needConfirm = true; }
    catch (e) {
      if (e instanceof Usage) { return { code: 2, lines: ctx.lines.concat('USAGE: ' + e.message) }; }
      if (e instanceof Fail) { ctx.failures++; ctx.lines.push('  FAILED: ' + e.message); } else throw e;
    }
  }
  if (o.step === 'all' && !ctx.page) ctx.lines.push('--- homepage: skipped (no --page)');
  const code = ctx.failures ? 1 : (needConfirm ? 3 : 0);
  ctx.lines.push(ctx.failures ? `RESULT: ${ctx.failures} check(s) failed.` : (needConfirm ? 'RESULT: stopped at the homepage plan; run again with --confirm go for that step.' : 'RESULT: every step passed.'));
  return { code, lines: ctx.lines };
}

if (require.main === module) {
  let r;
  try { r = run(process.argv.slice(2), path.join(__dirname, 'wp.js')); }
  catch (e) {
    if (e instanceof Usage) { console.error(e.message); process.exit(2); }
    throw e;
  }
  console.log(r.lines.join('\n'));
  process.exit(r.code);
}
module.exports = { run, parseArgs };
