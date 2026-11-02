#!/usr/bin/env node
/**
 * abilities-live-maint.js: the live run of the divi5-builder maintenance abilities (issue #32, release 2.4.0), one step at a time.
 *
 *   node scripts/abilities-live-maint.js <site> <step> [--item <plugin file>] [--theme <stylesheet>] [--confirm go]
 *   steps: plugin, theme-switch, update, user, email, history, all
 *
 * It talks to the site only through scripts/wp.js (the helpers of abilities-live-write.js: rest-get for the readonly abilities,
 * rest-post for the writes, rest-get --index once for the site's address, rest-get wp/v2/users/me once for the caller's id; wp.js
 * holds the credentials, this script never sees or prints them), and otherwise only to the run routes
 * wp-abilities/v1/abilities/divi5-builder/<name>/run. A failed plugins-update prints the history row that keeps the copy, and the
 * HUMAN CHECK line after a failed step names what the run changed (never "no change" after a write). It is for the maintainer to run on
 * a dev site; nothing in its Node test touches a site.
 *
 *   plugin        --confirm go: installs hello-dolly from wordpress.org (plugins-install; one left by an earlier run is used as it
 *                 is), activates it (plugins-activate, checked), deactivates it (plugins-deactivate), previews the delete and
 *                 deletes it with the code (plugins-delete-preview, plugins-delete), then asserts the kept copy: the delete result's
 *                 kept, the delete row's after.kept (history-change-get) and plugins-status kept_copies. Only a plugin file in the
 *                 hello-dolly/ folder is ever deactivated or deleted (exit 2 otherwise). Without --confirm go: the plan, exit 3.
 *                 Needs the Plugins category and risky switch.
 *   theme-switch  --theme <stylesheet> --confirm go: themes-switch to that installed, inactive theme (checked), then back to the
 *                 previous stylesheet the switch reports. A switch the plugin reverted (the pages got worse) is a failure and
 *                 needs no switch back. Without --confirm go: the plan, exit 3. Needs the Themes category and risky switch.
 *   update        --item <plugin file>: when plugins-status offers an update for it that can run, plugins-update (checked, with
 *                 a kept copy), then plugins-rollback to that kept copy, then plugins-status: the version and the active state
 *                 must be the ones before. Nothing waiting (or blocked): a skip note, nothing changed (updates-check refreshes
 *                 the list; it is not called here). This plugin itself is refused. Needs the Plugins risky switch.
 *   user          --confirm go: users-create d5b-live-user (subscriber, d5b-live-user@<the site's own domain>; no inbox is read,
 *                 only results), users-password-reset, users-update role author, users-password-reset again (must be refused
 *                 recently_changed: the 24 h block after an identity change), then users-delete-preview and users-delete
 *                 reassigning to the caller (its id from GET wp/v2/users/me). Only the login d5b-live-user is ever
 *                 deleted (exit 2 otherwise). Without --confirm go: the plan, exit 3. Needs the Users category and risky switch.
 *   email         email-send-test to me (the caller's own address); sent must be true. Needs the Email category.
 *   history       history-changes-list for today, page by page while has_more (at most 10 pages of 50): every write this script
 *                 made (kept in a small ledger file in the temp folder) must have a row; then history-change-get of every
 *                 update, rollback and delete row of a plugin or theme must show after.kept (the copy kept for 30 days).
 *   all           plugin, theme-switch (with --theme), update (with --item), user, email, history.
 *
 * The plugin's human_check sentence (on every activate, deactivate, update, rollback and switch result; one missing is a failure)
 * is printed once, at the end of the run: the page check cannot see layout breaks, missing images, console errors, broken forms
 * or raw shortcodes, so the maintainer looks at the site in a browser and on a phone after every run that changed something.
 *
 * It prints only names, ids, versions, statuses and counts: never an email address, a confirmation code, a mailer error, a
 * kept-copy path or a user's fields.
 *
 * Exit 0: every step passed. 1: an unexpected status, a failed expectation, a missing history row or a missing kept copy.
 * 2: usage, or a guard (a plugin outside hello-dolly/, a login other than d5b-live-user, this plugin itself, a theme not
 * installed or already active). 3: a plan was printed and the step needs --confirm go.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { tok, getAbility, postAbility, stampOf, dayOf, Fail, Usage } = require('./abilities-live-write');

const STEPS = ['plugin', 'theme-switch', 'update', 'user', 'email', 'history'];
const NS = 'divi5-builder/';
const TEST_SLUG = 'hello-dolly';
const TEST_LOGIN = 'd5b-live-user';
const CONFIRM_STEPS = ['plugin', 'theme-switch', 'user'];

// ---------------------------------------------------------------- printing and expectations

/** Only these keys of a result are ever printed, and only when they are plain values. */
const PRINT = ['plugin', 'stylesheet', 'version', 'from', 'to', 'replaced_version', 'restored_from', 'history_id', 'id', 'login', 'active',
  'reverted', 'updated', 'rolled_back', 'installed', 'deleted', 'keep_data', 'uninstall_ran', 'has_uninstall', 'sent', 'posts_total',
  'posts_reassigned', 'comments_left', 'reassigned_to'];
function fields(j, extra) {
  const o = [];
  if (j && typeof j === 'object') for (const k of PRINT) if (j[k] !== undefined && j[k] !== null && typeof j[k] !== 'object') o.push(k + '=' + tok(j[k]));
  return o.concat(extra || []).join('  ');
}
const keptText = k => (k && typeof k === 'object' ? `kept=${tok(k.bytes)} bytes/${tok(k.files)} files` : 'kept=none');
const problemsText = j => (j && Array.isArray(j.problems) ? `problems=${j.problems.length}` : '');

function line(ctx, short, r, extra) {
  ctx.lines.push(`${short}  ${r.ok ? 'ok' : 'refused:' + r.code}  HTTP ${r.status}` + (r.ok ? '  ' + fields(r.json, extra) : (extra && extra.length ? '  ' + extra.join('  ') : '')));
}

/** A call that has to succeed: prints it and throws Fail when it did not. */
function must(ctx, method, short, input, extraOf) {
  const r = method === 'GET' ? getAbility(ctx, short, input) : postAbility(ctx, short, input);
  line(ctx, short, r, r.ok && extraOf ? extraOf(r.json || {}) : []);
  if (!r.ok) throw new Fail(`${short} wanted ok, got refused:${r.code} (HTTP ${r.status})`);
  if (!r.json || typeof r.json !== 'object') throw new Fail(`${short} gave no JSON answer`);
  return r;
}

/** A failed expectation: counted, printed, the run goes on (the put-back still has to happen). */
function expect(ctx, ok, what) { if (!ok) { ctx.failures++; ctx.lines.push('  FAILED: ' + what); } return ok; }

/** The human check of a checked result: it must be there; kept to be printed once at the end of the run. */
function human(ctx, short, j) {
  if (typeof j.human_check !== 'string' || !j.human_check.trim()) { expect(ctx, false, `${short} gave no human_check`); return; }
  ctx.human = j.human_check.replace(/[\r\n\t]+/g, ' ').slice(0, 1000);
}

/** A kept copy in a result: an object with at least one file. */
function keptOk(k) { return !!(k && typeof k === 'object' && Number(k.files) > 0); }

/** Runs the put-back whatever happened in the step; a put-back that fails is reported with what to do by hand. */
function guarded(ctx, step, body, restore, how) {
  let err = null;
  try { body(); } catch (e) { err = e; }
  try { restore(); }
  catch (e) {
    if (e instanceof Usage) throw e;
    ctx.failures++; ctx.lines.push(`  FAILED: the put-back of the ${step} step did not work (${e instanceof Fail ? e.message : 'unexpected error'}). ${typeof how === 'function' ? how() : how}`);
  }
  if (err) throw err;
}

// ---------------------------------------------------------------- the ledger

function ledgerPath(site) { return process.env.D5B_LIVE_MAINT_LEDGER || path.join(os.tmpdir(), 'd5b-live-maint-' + String(site).replace(/[^A-Za-z0-9_-]/g, '_') + '.json'); }
function readLedger(ctx) {
  try { const j = JSON.parse(fs.readFileSync(ledgerPath(ctx.site), 'utf8')); return { entries: Array.isArray(j.entries) ? j.entries : [] }; }
  catch (e) { return { entries: [] }; }
}
/** One write that went through: the history step looks for its row, by its history id when the result gave one, else by ability,
 *  object id and a word of the summary. It is also named on the HUMAN CHECK line when a step fails (what this run changed). */
function rec(ctx, short, hid, id, needle) {
  if (Array.isArray(ctx.changed)) ctx.changed.push(short + (needle ? ' ' + tok(needle) : (id === undefined || id === null ? '' : ' (user ' + tok(id) + ')')));
  const led = readLedger(ctx);
  led.entries.push({ day: ctx.day, ability: short, hid: Number.isInteger(hid) && hid > 0 ? hid : null, id: id === undefined || id === null ? null : Number(id), needle: needle || null });
  fs.writeFileSync(ledgerPath(ctx.site), JSON.stringify(led));
}

// ---------------------------------------------------------------- guards

function guardPlugin(file) {
  if (typeof file !== 'string' || !file.startsWith(TEST_SLUG + '/')) throw new Usage(`the plugin step only changes the ${TEST_SLUG} plugin, and the site reported ${tok(file)}: nothing more was changed`);
}
function isSelf(file) { return /^divi5-builder(\/|\.php$)/.test(String(file)); }

/** The site's own host name, from the REST index (wp.js rest-get --index). */
function siteHost(ctx) {
  try {
    const j = JSON.parse(execFileSync(process.execPath, [ctx.wpjs, ctx.site, 'rest-get', '--index'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }));
    const h = typeof j.home === 'string' && j.home ? j.home : j.url;
    return typeof h === 'string' ? new URL(h).hostname : '';
  } catch (e) { return ''; }
}

// ---------------------------------------------------------------- the steps

function stepPlugin(ctx) {
  ctx.lines.push(`plan: install ${TEST_SLUG} from wordpress.org, activate it (checked), deactivate it, preview the delete and delete it with the code (its files are kept for 30 days), then check the kept copy in the change history and in plugins-status.`);
  if (ctx.confirm !== 'go') {
    ctx.lines.push('Nothing is installed. Run again with --confirm go to do it (the Plugins category and its risky switch must be on).');
    return 'confirm';
  }
  let file = null, wasActive = false;
  const ins = postAbility(ctx, 'plugins-install', { slug: TEST_SLUG });
  line(ctx, 'plugins-install', ins, []);
  if (ins.ok && ins.json && typeof ins.json === 'object') {
    file = ins.json.plugin;
    rec(ctx, 'plugins-install', ins.json.history_id, null, TEST_SLUG);
    // Installed, but not a hello-dolly/ plugin: it stays (inactive); the message names it for the maintainer to delete.
    if (typeof file !== 'string' || !file.startsWith(TEST_SLUG + '/')) {
      throw new Usage(`plugins-install installed ${tok(file) || 'a plugin it did not name'}, not a ${TEST_SLUG}/ plugin. It is left installed and inactive: delete it on the Plugins screen (history row ${tok(ins.json.history_id)}). Nothing more was changed`);
    }
  } else if (!ins.ok && ins.code === 'exists') {
    const s = must(ctx, 'GET', 'plugins-status', {});
    const row = (Array.isArray(s.json.plugins) ? s.json.plugins : []).find(p => p && typeof p.item === 'string' && p.item.startsWith(TEST_SLUG + '/'));
    if (!row) throw new Fail(`plugins-install says ${TEST_SLUG} exists, but plugins-status lists no ${TEST_SLUG}/ plugin`);
    file = row.item; wasActive = row.active === true;
    ctx.lines.push(`  note: ${tok(file)} is already installed (left by an earlier run); it is used as it is and deleted at the end.`);
  } else throw new Fail(`plugins-install wanted ok, got refused:${ins.code} (HTTP ${ins.status})`);
  guardPlugin(file);

  let active = wasActive;
  guarded(ctx, 'plugin', () => {
    if (!active) {
      const a = must(ctx, 'POST', 'plugins-activate', { plugin: file }, j => [problemsText(j)]);
      rec(ctx, 'plugins-activate', a.json.history_id, null, file);
      human(ctx, 'plugins-activate', a.json);
      if (a.json.reverted === true) expect(ctx, false, `the activation of ${tok(file)} was reverted by the plugin: the pages got worse (${Array.isArray(a.json.problems) ? a.json.problems.length : '?'} problem(s))`);
      else { expect(ctx, a.json.active === true, 'plugins-activate did not say active'); active = a.json.active === true; }
    }
  }, () => {
    if (active) {
      guardPlugin(file);
      const d = must(ctx, 'POST', 'plugins-deactivate', { plugin: file }, j => [problemsText(j)]);
      rec(ctx, 'plugins-deactivate', d.json.history_id, null, file);
      human(ctx, 'plugins-deactivate', d.json);
      if (!expect(ctx, d.json.active === false, 'plugins-deactivate did not say inactive')) throw new Fail('the plugin is still active');
    }
    guardPlugin(file);
    const p = must(ctx, 'GET', 'plugins-delete-preview', { plugin: file });
    if (typeof p.json.confirmation !== 'string' || !p.json.confirmation) throw new Fail('plugins-delete-preview gave no confirmation code');
    const del = must(ctx, 'POST', 'plugins-delete', { plugin: file, confirmation: p.json.confirmation }, j => [keptText(j.kept)]);
    rec(ctx, 'plugins-delete', del.json.history_id, null, file);
    expect(ctx, del.json.deleted === true, 'plugins-delete did not say deleted');
    // The kept copy: in the result, in the row (history-change-get) and in plugins-status.
    if (!keptOk(del.json.kept)) { expect(ctx, false, 'plugins-delete kept no copy: the plugin cannot put these files back'); return; }
    const hid = del.json.history_id;
    const g = getAbility(ctx, 'history-change-get', { id: hid });
    const after = g.ok && g.json && g.json.after && typeof g.json.after === 'object' ? g.json.after : null;
    if (!expect(ctx, !!(after && keptOk(after.kept)), `the plugins-delete row ${tok(hid)} holds no kept copy (${g.ok ? 'after.kept missing' : 'refused:' + g.code})`)) return;
    ctx.lines.push(`kept copy: plugins-delete row ${tok(hid)} keeps ${tok(after.kept.files)} file(s), ${tok(after.kept.bytes)} bytes`);
    const s = must(ctx, 'GET', 'plugins-status', {});
    const listed = (Array.isArray(s.json.kept_copies) ? s.json.kept_copies : []).some(k => k && k.plugin === file && Number(k.history_id) === Number(hid));
    if (expect(ctx, listed, `plugins-status kept_copies does not list ${tok(file)} with row ${tok(hid)}`)) ctx.lines.push(`kept_copies lists ${tok(file)} (row ${tok(hid)})`);
    expect(ctx, !(Array.isArray(s.json.plugins) ? s.json.plugins : []).some(x => x && x.item === file), `${tok(file)} is still installed after the delete`);
  }, `Deactivate and delete ${TEST_SLUG} on the Plugins screen.`);
  return 'ok';
}

function stepThemeSwitch(ctx) {
  if (!ctx.theme) throw new Usage('the theme-switch step needs --theme <stylesheet> (an installed theme that is not active)');
  const s = must(ctx, 'GET', 'themes-status', {});
  const rows = (Array.isArray(s.json.themes) ? s.json.themes : []).filter(t => t && typeof t.item === 'string');
  const target = rows.find(t => t.item === ctx.theme);
  if (!target) throw new Usage(`the theme ${tok(ctx.theme)} is not installed (the name must be the folder, as themes-status lists it): nothing was changed`);
  if (target.active === true) throw new Usage(`the theme ${tok(ctx.theme)} is already active (or the parent of the active theme): nothing was changed`);
  const act = rows.filter(t => t.active === true).map(t => tok(t.item));
  ctx.lines.push(`plan: switch the theme from ${act.join(' + ') || '?'} to ${tok(ctx.theme)} (checked), then switch back to the previous theme the switch reports.`);
  if (ctx.confirm !== 'go') {
    ctx.lines.push('The theme is not switched. Run again with --confirm go to do it (the Themes category and its risky switch must be on).');
    return 'confirm';
  }
  let switched = false, prev = null;
  guarded(ctx, 'theme-switch', () => {
    const r = must(ctx, 'POST', 'themes-switch', { stylesheet: ctx.theme }, j => [problemsText(j)]);
    rec(ctx, 'themes-switch', r.json.history_id, null, ctx.theme);
    human(ctx, 'themes-switch', r.json);
    if (r.json.reverted === true) { expect(ctx, false, `the switch to ${tok(ctx.theme)} was reverted by the plugin: the pages got worse (${Array.isArray(r.json.problems) ? r.json.problems.length : '?'} problem(s)); the previous theme is back`); return; }
    switched = true;
    prev = r.json.previous && typeof r.json.previous.stylesheet === 'string' ? r.json.previous.stylesheet : null;
    expect(ctx, r.json.stylesheet === ctx.theme, 'themes-switch did not say the new theme is active');
  }, () => {
    if (!switched) return;
    if (!prev) throw new Fail('themes-switch gave no previous stylesheet');
    const b = must(ctx, 'POST', 'themes-switch', { stylesheet: prev }, j => [problemsText(j)]);
    rec(ctx, 'themes-switch', b.json.history_id, null, prev);
    human(ctx, 'themes-switch', b.json);
    if (!expect(ctx, b.json.stylesheet === prev && b.json.reverted !== true, 'the previous theme is NOT active again')) throw new Fail('the previous theme is not back');
  }, () => `Switch back to ${prev ? tok(prev) : 'the previous theme'} in Appearance > Themes (the themes-switch row of the change history names it).`);
  return 'ok';
}

function stepUpdate(ctx) {
  if (!ctx.item) throw new Usage('the update step needs --item <plugin file> (as plugins-status lists it, for example akismet/akismet.php)');
  if (isSelf(ctx.item)) throw new Usage('the update step does not update this plugin itself (the abilities refuse it: self)');
  const s = must(ctx, 'GET', 'plugins-status', {});
  const row = (Array.isArray(s.json.plugins) ? s.json.plugins : []).find(p => p && p.item === ctx.item);
  if (!row) throw new Usage(`the plugin ${tok(ctx.item)} is not installed: nothing was changed`);
  if (!row.new_version) { ctx.lines.push(`skipped: no update is waiting for ${tok(ctx.item)} (installed ${tok(row.version)}); updates-check refreshes the list. Nothing was changed.`); return 'ok'; }
  if (row.blocked) { ctx.lines.push(`skipped: the update of ${tok(ctx.item)} to ${tok(row.new_version)} cannot run here (blocked: ${tok(row.blocked)}). Nothing was changed.`); return 'ok'; }
  ctx.lines.push(`plan: update ${tok(ctx.item)} from ${tok(row.version)} to ${tok(row.new_version)} (a copy kept first, checked), then roll it back to that kept copy.`);
  let hid = null;
  guarded(ctx, 'update', () => {
    const u = postAbility(ctx, 'plugins-update', { plugin: ctx.item });
    line(ctx, 'plugins-update', u, u.ok && u.json && typeof u.json === 'object' ? [keptText(u.json.kept), problemsText(u.json)] : (u.history_id ? ['history_id=' + tok(u.history_id)] : []));
    if (!u.ok) {
      // update_failed: WordPress may have changed the files part-way. Its row keeps the copy of the version before.
      if (u.code === 'update_failed' || u.history_id) {
        rec(ctx, 'plugins-update', u.history_id, null, ctx.item);
        ctx.changed[ctx.changed.length - 1] += ' (FAILED part-way: the files may have changed)';
        throw new Fail(`plugins-update failed (refused:${tok(u.code)}, HTTP ${u.status}). ` + (u.history_id
          ? `History row ${tok(u.history_id)} keeps the copy of ${tok(row.version)}: if ${tok(ctx.item)} is broken, put it back with plugins-rollback with history_id ${tok(u.history_id)}`
          : 'No history row was named: read the change history (history-changes-list) for the row of this update') + ', and look at the site now');
      }
      throw new Fail(`plugins-update wanted ok, got refused:${tok(u.code)} (HTTP ${u.status})`);
    }
    if (!u.json || typeof u.json !== 'object') throw new Fail('plugins-update gave no JSON answer');
    rec(ctx, 'plugins-update', u.json.history_id, null, ctx.item);
    human(ctx, 'plugins-update', u.json);
    if (!keptOk(u.json.kept)) throw new Fail(`plugins-update kept no copy, so the plugin cannot roll it back: put ${tok(row.version)} back by hand (Plugins > Add New > Upload)`);
    hid = u.json.history_id;
    expect(ctx, u.json.updated === true && u.json.version === row.new_version, `plugins-update did not say updated to ${tok(row.new_version)}`);
    if (u.json.rolled_back === true) { hid = null; expect(ctx, false, 'plugins-update rolled back by itself (the pages got worse)'); }
  }, () => {
    if (!hid) return;
    const b = must(ctx, 'POST', 'plugins-rollback', { plugin: ctx.item, history_id: hid }, j => [keptText(j.kept), problemsText(j)]);
    rec(ctx, 'plugins-rollback', b.json.history_id, null, ctx.item);
    human(ctx, 'plugins-rollback', b.json);
    expect(ctx, keptOk(b.json.kept), 'plugins-rollback kept no copy of the version it replaced');
    if (!expect(ctx, b.json.version === row.version, `the version after the rollback is not ${tok(row.version)}`)) throw new Fail('the old version is not back');
    const s2 = must(ctx, 'GET', 'plugins-status', {});
    const now = (Array.isArray(s2.json.plugins) ? s2.json.plugins : []).find(p => p && p.item === ctx.item) || {};
    expect(ctx, now.version === row.version && now.active === row.active, 'plugins-status does not show the version and active state from before the update');
  }, () => `Put ${tok(row.version)} back with plugins-rollback with history_id ${tok(hid)} (plugins-status lists it under kept_copies).`);
  return 'ok';
}

/** The caller's user id, as the REST API knows the account wp.js signs in with (GET wp/v2/users/me; only its id is read). */
function callerId(ctx) {
  let j = null;
  try { j = JSON.parse(execFileSync(process.execPath, [ctx.wpjs, ctx.site, 'rest-get', 'wp/v2/users/me'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })); }
  catch (e) { j = null; }
  const id = j && typeof j === 'object' ? Number(j.id) : NaN;
  if (!Number.isInteger(id) || id < 1) throw new Fail('the REST API did not say who you are (wp/v2/users/me), so there is no one to reassign to');
  return id;
}

function stepUser(ctx) {
  ctx.lines.push(`plan: create the user ${TEST_LOGIN} (subscriber, an address on the site's own domain), send it a reset link, make it an author, check that a second reset link is refused (recently_changed), then delete it reassigning its content to you.`);
  if (ctx.confirm !== 'go') {
    ctx.lines.push('No user is created. Run again with --confirm go to do it (the Users category and its risky switch must be on).');
    return 'confirm';
  }
  const host = siteHost(ctx);
  if (!host || !host.includes('.')) throw new Fail('the site address could not be read from the REST index, so there is no address for the test user');
  let id = null;
  const c = postAbility(ctx, 'users-create', { login: TEST_LOGIN, email: TEST_LOGIN + '@' + host, role: 'subscriber' });
  line(ctx, 'users-create', c, []);
  if (c.ok && c.json && typeof c.json === 'object') {
    if (c.json.login !== TEST_LOGIN) throw new Usage(`users-create made a user whose login is ${tok(c.json.login)}, not ${TEST_LOGIN}: it is never changed or deleted by this script`);
    id = Number(c.json.id);
    rec(ctx, 'users-create', c.json.history_id, id, null);
    expect(ctx, Array.isArray(c.json.roles) && c.json.roles.includes('subscriber'), 'users-create did not make a subscriber');
    expect(ctx, !/pass|key/i.test(Object.keys(c.json).join(' ')), 'users-create returned a password or key field');
  } else if (!c.ok && c.code === 'exists') {
    const l = must(ctx, 'GET', 'users-list', { search: TEST_LOGIN });
    const u = (Array.isArray(l.json.items) ? l.json.items : []).find(x => x && x.login === TEST_LOGIN);
    if (!u) throw new Fail(`users-create says the user exists, but no user has the login ${TEST_LOGIN} (another user has its address?)`);
    id = Number(u.id);
    ctx.lines.push(`  note: ${TEST_LOGIN} already exists (left by an earlier run, user ${tok(id)}); it is used as it is and deleted at the end.`);
  } else throw new Fail(`users-create wanted ok, got refused:${c.code} (HTTP ${c.status})`);
  if (!Number.isInteger(id) || id < 1) throw new Fail('users-create gave no user id');

  guarded(ctx, 'user', () => {
    const r1 = must(ctx, 'POST', 'users-password-reset', { id });
    rec(ctx, 'users-password-reset', null, id, null);
    expect(ctx, r1.json.sent === true, 'users-password-reset did not say sent');
    const u = must(ctx, 'POST', 'users-update', { id, role: 'author' });
    rec(ctx, 'users-update', u.json.history_id, id, null);
    expect(ctx, Array.isArray(u.json.roles) && u.json.roles.includes('author'), 'users-update did not make the user an author');
    const r2 = postAbility(ctx, 'users-password-reset', { id });
    const want = !r2.ok && r2.code === 'recently_changed';
    line(ctx, 'users-password-reset', r2, [want ? '(expected recently_changed)' : '(WANTED recently_changed)']);
    expect(ctx, want, 'a reset link right after the role change was not refused with recently_changed');
  }, () => {
    const me = callerId(ctx);
    if (me === id) throw new Usage('the caller is the test user itself: nothing is deleted');
    const p = must(ctx, 'GET', 'users-delete-preview', { id, reassign_to: me }, j => ['posts_total=' + tok(j.posts_total)]);
    if (!p.json.user || p.json.user.login !== TEST_LOGIN) throw new Usage(`user ${tok(id)} is not ${TEST_LOGIN}: it is never deleted by this script`);
    if (typeof p.json.confirmation !== 'string' || !p.json.confirmation) throw new Fail('users-delete-preview gave no confirmation code');
    const d = must(ctx, 'POST', 'users-delete', { id, reassign_to: me, confirmation: p.json.confirmation });
    rec(ctx, 'users-delete', d.json.history_id, id, null);
    expect(ctx, d.json.deleted === true && Number(d.json.reassigned_to) === me, 'users-delete did not say deleted and reassigned to you');
    const l = must(ctx, 'GET', 'users-list', { search: TEST_LOGIN });
    if (!expect(ctx, !(Array.isArray(l.json.items) ? l.json.items : []).some(x => x && x.login === TEST_LOGIN), `${TEST_LOGIN} is still listed after the delete`)) throw new Fail('the test user is still there');
  }, `Delete the user ${TEST_LOGIN} in Users (attribute its content to yourself).`);
  return 'ok';
}

function stepEmail(ctx) {
  const r = must(ctx, 'POST', 'email-send-test', { to: 'me', note: 'd5b-live-email-' + ctx.stamp }, j => [j.mailer_error ? 'mailer_error=present' : 'mailer_error=none']);
  rec(ctx, 'email-send-test', r.json.history_id, null, null);
  expect(ctx, r.json.sent === true, 'email-send-test did not say sent (the mailer_error is in the response and the change history row; it is not printed here)');
  return 'ok';
}

// ---- history

const HISTORY_PAGES = 10;
const HISTORY_PER_PAGE = 50;

function historyRows(ctx) {
  const rows = [];
  for (let page = 1; page <= HISTORY_PAGES; page++) {
    const r = must(ctx, 'GET', 'history-changes-list', { since: ctx.day, per_page: HISTORY_PER_PAGE, page }, () => ['page=' + page]);
    rows.push(...(Array.isArray(r.json.items) ? r.json.items.filter(x => x && typeof x === 'object') : []));
    if (r.json.has_more !== true) return { rows, complete: true };
  }
  return { rows, complete: false };
}

/** The rows that must hold a kept copy in after.kept: an update, rollback or delete of a plugin or theme. */
const KEEPS = /^divi5-builder\/(plugins|themes)-(update|rollback|delete)$/;

function stepHistory(ctx) {
  const want = readLedger(ctx).entries.filter(e => e && e.day === ctx.day);
  const got = historyRows(ctx);
  const rows = got.rows;
  if (!got.complete) ctx.lines.push(`  note: stopped after ${HISTORY_PAGES} pages of ${HISTORY_PER_PAGE}; older rows of today were not read`);
  ctx.lines.push(`${rows.length} change row(s) today; this script made ${want.length} write(s) today.`);
  if (!want.length) { ctx.failures++; ctx.lines.push('  FAILED: this script has recorded no write for today, so there is nothing to check; run a step first'); return 'ok'; }
  const used = new Set(); let missing = 0;
  for (const e of want) {
    const i = rows.findIndex((x, k) => !used.has(k) && x.ability === NS + e.ability && (e.hid !== null ? Number(x.id) === e.hid
      : (e.id === null || Number(x.object_id) === e.id) && (e.needle === null || String(x.summary || '').includes(e.needle))));
    if (i < 0) { missing++; ctx.lines.push(`  MISSING: no history row for ${tok(e.ability)}${e.hid !== null ? ' (row ' + e.hid + ')' : (e.id === null ? '' : ' on ' + e.id)}`); } else used.add(i);
  }
  if (missing) ctx.failures++;
  ctx.lines.push(missing ? `history: ${missing} of ${want.length} write(s) have no row` : `history: all ${want.length} write(s) have a row`);
  let bad = 0, seen = 0;
  for (const k of used) {
    const x = rows[k];
    if (!KEEPS.test(String(x.ability))) continue;
    seen++;
    const g = getAbility(ctx, 'history-change-get', { id: x.id });
    const ok = g.ok && g.json && g.json.after && keptOk(g.json.after.kept);
    if (!ok) { bad++; line(ctx, 'history-change-get', g, ['id=' + tok(x.id), tok(x.ability), g.ok ? 'no kept copy' : '']); }
  }
  if (bad) ctx.failures++;
  ctx.lines.push(bad ? `history: ${bad} of ${seen} update/rollback/delete row(s) lack their kept copy` : `history: all ${seen} update/rollback/delete row(s) hold their kept copy`);
  return 'ok';
}

// ---------------------------------------------------------------- the run

const USAGE = 'usage: node abilities-live-maint.js <site> <plugin|theme-switch|update|user|email|history|all> [--item <plugin file>] [--theme <stylesheet>] [--confirm go]';

function parseArgs(argv) {
  const site = argv[0], step = argv[1];
  if (!site || site.startsWith('-') || !step || ![...STEPS, 'all'].includes(step)) throw new Usage(USAGE);
  const o = { site, step };
  const rest = argv.slice(2);
  for (let i = 0; i < rest.length; i++) {
    const a = rest[i];
    if (!['--item', '--theme', '--confirm'].includes(a) || i + 1 >= rest.length || String(rest[i + 1]).startsWith('--')) throw new Usage(`unknown or incomplete option ${tok(a)}`);
    o[a.slice(2)] = rest[++i];
  }
  if (o.confirm !== undefined && o.confirm !== 'go') throw new Usage('--confirm must be go');
  if (o.item !== undefined && !/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/.test(o.item)) throw new Usage('--item must be a plugin file, for example akismet/akismet.php');
  if (o.theme !== undefined && !/^[A-Za-z0-9._-]+$/.test(o.theme)) throw new Usage('--theme must be a theme folder name (stylesheet)');
  if (o.item !== undefined && !['update', 'all'].includes(step)) throw new Usage('--item belongs to the update step');
  if (o.theme !== undefined && !['theme-switch', 'all'].includes(step)) throw new Usage('--theme belongs to the theme-switch step');
  if (o.confirm !== undefined && ![...CONFIRM_STEPS, 'all'].includes(step)) throw new Usage('--confirm belongs to the plugin, theme-switch and user steps');
  return o;
}

function run(argv, wpjs) {
  const o = parseArgs(argv);
  const now = new Date();
  const ctx = { site: o.site, wpjs, stamp: stampOf(now), day: dayOf(now), item: o.item, theme: o.theme, confirm: o.confirm, lines: [], failures: 0, human: null, changed: [] };
  const fns = { plugin: stepPlugin, 'theme-switch': stepThemeSwitch, update: stepUpdate, user: stepUser, email: stepEmail, history: stepHistory };
  let list = [o.step];
  if (o.step === 'all') list = ['plugin', 'theme-switch', 'update', 'user', 'email', 'history'];
  let needConfirm = false;
  for (const s of list) {
    if (o.step === 'all' && s === 'update' && !ctx.item) { ctx.lines.push('--- update: skipped (no --item)'); continue; }
    if (o.step === 'all' && s === 'theme-switch' && !ctx.theme) { ctx.lines.push('--- theme-switch: skipped (no --theme)'); continue; }
    ctx.lines.push(`--- ${s}`);
    try { if (fns[s](ctx) === 'confirm') needConfirm = true; }
    catch (e) {
      if (e instanceof Usage) { return { code: 2, lines: ctx.lines.concat('USAGE: ' + e.message) }; }
      if (e instanceof Fail) { ctx.failures++; ctx.lines.push('  FAILED: ' + e.message); } else throw e;
    }
  }
  // The human check, truthful about what this run did: after a failed step it names every write that went through (or may
  // have, part-way); "made no change" only when no write went through at all.
  const changed = ctx.changed.length ? 'This run changed: ' + ctx.changed.join('; ') + '. ' : '';
  if (ctx.human) ctx.lines.push('HUMAN CHECK: ' + (ctx.failures ? changed : '') + ctx.human);
  else if (changed) ctx.lines.push('HUMAN CHECK: ' + changed + 'Look at the site in a browser and on a phone now.');
  else ctx.lines.push('HUMAN CHECK: this run made no change; after a plugin, theme or update step, look at the site in a browser and on a phone.');
  const code = ctx.failures ? 1 : (needConfirm ? 3 : 0);
  ctx.lines.push(ctx.failures ? `RESULT: ${ctx.failures} check(s) failed.` : (needConfirm ? 'RESULT: stopped at a plan; run again with --confirm go for that step.' : 'RESULT: every step passed.'));
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
