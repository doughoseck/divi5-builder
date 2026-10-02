#!/usr/bin/env node
/*
 * site-updates.js — see what is out of date on a WordPress site and update it: plugins, themes, WordPress itself,
 * translations. Uses WordPress's own updater (the code behind the Updates screen). Works on any WordPress site.
 *
 *   node site-updates.js status <site> [--no-refresh] [--json]
 *   node site-updates.js update <site> <what> [--careful | --fast] [--major] [--skip a,b] [--check /path,/path]
 *                                      [--dir D] [--write]
 *   node site-updates.js clear  <site>            # Divi's generated CSS and the page cache
 *
 *   <what>:  --all                       every plugin and theme, WordPress, translations
 *            --plugins | --themes        all of one kind
 *            --plugin folder/file.php    one plugin (repeat the flag value with commas for several)
 *            --theme folder              one theme
 *            --core                      WordPress
 *            --translations
 *
 * Needs assets/wp-site-updates.php in wp-content/mu-plugins/ (the user uploads it) and an application password of a
 * user who may update (an administrator; on a multisite a network administrator).
 *
 * Without --write it only prints the plan. With --write:
 *   --careful (default)  one update at a time; after each one the check pages are loaded again, and the run STOPS at
 *                        the first update that fails or the first page that got worse. For sites that matter.
 *   --fast               everything in one go, pages checked once at the end. For small brochure sites.
 * After every update the site clears Divi's generated CSS and its page cache by itself (stale CSS after an update is
 * the classic "the site looks broken" on Divi).
 *
 * WordPress itself: a maintenance release of the branch the site is on (6.8.1 -> 6.8.3) is taken by default. A new
 * release (6.8 -> 6.9) only with --major. Plugin and theme updates are taken whatever their size; a first-number
 * change is marked MAJOR in the plan so you can --skip it.
 * Never updated: anything "blocked" (no download offered = premium without an active licence; needs a newer PHP or
 * WordPress). They are listed with the reason.
 *
 * There is NO rollback. A bad update is undone by restoring a backup or re-uploading the old version. The log of each
 * run (what was updated from which version, and the page checks) is saved in --dir (default ./site-updates/<site>/).
 */
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const WP = path.join(__dirname, 'wp.js');
const MIN_PLUGIN = '1.0.1';

// ---- pure ----

// What to update, in order, and what is left out and why. opts: { plugins: 'all'|[items]|null, themes: same, core, translations, major, skip: [] }
function planUpdates(status, opts) {
  const todo = []; const skipped = []; const skip = new Set(opts.skip || []);
  for (const [type, rows, want] of [['plugin', status.plugins || [], opts.plugins], ['theme', status.themes || [], opts.themes]]) {
    if (!want) continue;
    if (Array.isArray(want)) for (const w of want) if (!rows.some(r => r.item === w)) skipped.push([w, `no installed ${type} has that name`]);
    for (const r of rows) {
      if (Array.isArray(want) && !want.includes(r.item)) continue;
      if (r.new_version === null || r.new_version === undefined) { if (Array.isArray(want)) skipped.push([r.name, 'already up to date']); continue; }
      if (skip.has(r.item)) { skipped.push([r.name, 'skipped on request']); continue; }
      if (r.blocked) { skipped.push([r.name, r.blocked]); continue; }
      todo.push({ type, item: r.item, name: r.name, from: r.version, to: r.new_version, kind: r.kind, active: !!r.active });
    }
  }
  const wp = status.wordpress || {};
  if (opts.core) {
    if (!wp.new_version) { /* up to date */ }
    else if (skip.has('core') || skip.has('wordpress')) skipped.push(['WordPress', 'skipped on request']);
    else {
      const branch = v => String(v).split('.').slice(0, 2).join('.');
      const same = (wp.offered || []).filter(v => branch(v) === branch(wp.version)); const newestSame = same.length ? same[same.length - 1] : null;
      if (opts.major) todo.push({ type: 'core', item: wp.new_version, name: 'WordPress', from: wp.version, to: wp.new_version, kind: wp.kind, active: true });
      else if (newestSame) { todo.push({ type: 'core', item: newestSame, name: 'WordPress', from: wp.version, to: newestSame, kind: 'minor', active: true });
        if (newestSame !== wp.new_version) skipped.push(['WordPress ' + wp.new_version, 'a new release, not a maintenance update: add --major to take it']); }
      else skipped.push(['WordPress ' + wp.new_version, 'a new release, not a maintenance update: add --major to take it']);
    }
  }
  if (opts.translations && status.translations > 0 && !skip.has('translations')) todo.push({ type: 'translations', item: '', name: `translations (${status.translations})`, from: '', to: '', kind: 'patch', active: false });
  return { todo, skipped };
}

// Signs of a broken page in its HTML.
const FATAL = /There has been a critical error on (this|your) website|Fatal error<\/b>|<b>Parse error<\/b>|Error establishing a database connection|Briefly unavailable for scheduled maintenance/i;
function pageHealth(url, status, html) {
  const title = (String(html).match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [, ''])[1].trim().slice(0, 80);
  const fatal = (String(html).match(FATAL) || [''])[0];
  return { url, status, bytes: String(html).length, closed: /<\/html>/i.test(html), fatal, title };
}

// What got worse between two rounds of page checks. A page that was already bad before is not blamed on the update.
function compareHealth(before, after) {
  const problems = []; const b = new Map(before.map(p => [p.url, p]));
  for (const a of after) {
    const p = b.get(a.url); if (!p) continue;
    const wasOk = p.status === 200 && !p.fatal && p.closed;
    if (!wasOk) continue;
    if (a.status !== 200) problems.push(`${a.url}: answered ${a.status || 'nothing'} (was 200)`);
    else if (a.fatal) problems.push(`${a.url}: shows "${a.fatal}"`);
    else if (!a.closed) problems.push(`${a.url}: the page is cut off (no closing </html>)`);
    else if (p.bytes > 2000 && a.bytes < p.bytes * 0.5) problems.push(`${a.url}: the page shrank from ${p.bytes} to ${a.bytes} characters`);
  }
  return problems;
}

const cmp = (a, b) => { const x = String(a).split('.').map(Number), y = String(b).split('.').map(Number); for (let i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) - (y[i] || 0); } return 0; };

module.exports = { planUpdates, compareHealth, pageHealth, FATAL };

// ---- command line ----
if (require.main === module) (async () => {
  const argv = process.argv.slice(2); const cmd = argv[0]; const site = argv[1];
  const has = k => argv.includes('--' + k); const flag = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : d; };
  const die = m => { console.error('ERROR: ' + m); process.exit(1); };
  if (!['status', 'update', 'clear'].includes(cmd) || !site || site.startsWith('--')) die('usage: site-updates.js status|update|clear <site> … (see the top of this file)');
  const run = args => execFileSync('node', [WP, site, ...args], { encoding: 'utf8', maxBuffer: 64e6, stdio: ['ignore', 'pipe', 'pipe'] });
  const get = p => { try { return JSON.parse(run(['rest-get', p])); } catch (e) { const msg = String(e.stderr || e.message).replace(/\s+/g, ' ').slice(0, 300);
    die(/404|rest_no_route/.test(msg) ? 'the site has no wp-site-updates plugin: upload assets/wp-site-updates.php to wp-content/mu-plugins/' : /401|403|rest_forbidden/.test(msg) ? 'this user may not update on that site (needs an administrator; on a multisite a network administrator)' : msg); } };
  const dir = path.resolve(String(flag('dir', path.join('site-updates', site.replace(/[^\w.-]+/g, '_'))))); const WRITE = has('write');
  const post = (act, body) => { fs.mkdirSync(dir, { recursive: true }); const f = path.join(dir, '.request.json'); fs.writeFileSync(f, JSON.stringify(body || {}));
    let out; try { out = run(['updates', act, '--data-file', f]); } catch (e) { out = JSON.stringify({ http: 0, code: 'no_answer', message: String(e.stderr || e.message).replace(/\s+/g, ' ').slice(0, 300) }); } finally { try { fs.unlinkSync(f); } catch (e) {} }
    try { return JSON.parse(out); } catch (e) { return { http: 0, code: 'not_json', message: out.slice(0, 300) }; } };
  const getStatus = refresh => { const s = get('wp-site-updates/v1/status' + (refresh ? '?refresh=1' : '')); if (cmp(s.plugin_version, MIN_PLUGIN) < 0) die(`the site has wp-site-updates ${s.plugin_version}; upload ${MIN_PLUGIN} or newer`); return s; };
  const show = s => {
    console.log(`${s.site}   WordPress ${s.wordpress.version}, PHP ${s.php}${s.multisite ? ', multisite' : ''}   (checked ${s.last_checked || 'never'})`);
    if (s.site_blocked) console.log('  THIS SITE REFUSES UPDATES: ' + s.site_blocked);
    const line = (kind, r) => console.log(`  ${kind.padEnd(7)} ${r.name.slice(0, 44).padEnd(44)} ${String(r.version).padEnd(12)} -> ${String(r.new_version).padEnd(12)} ${r.kind === 'major' ? 'MAJOR ' : ''}${r.active ? '' : '(inactive) '}${r.blocked ? 'BLOCKED: ' + r.blocked : ''}`);
    const p = s.plugins.filter(r => r.new_version), t = s.themes.filter(r => r.new_version);
    if (s.wordpress.new_version) line('core', { name: 'WordPress', version: s.wordpress.version, new_version: s.wordpress.new_version, kind: s.wordpress.kind, active: true, blocked: '' });
    p.forEach(r => line('plugin', r)); t.forEach(r => line('theme', r));
    if (s.translations) console.log(`  ${s.translations} translation update(s)`);
    if (!s.wordpress.new_version && !p.length && !t.length && !s.translations) console.log('  everything is up to date');
    console.log(`  (${s.plugins.length} plugins, ${s.themes.length} themes installed)`);
  };

  if (cmd === 'status') { const s = getStatus(!has('no-refresh')); if (has('json')) console.log(JSON.stringify(s, null, 1)); else show(s); return; }
  if (cmd === 'clear') { const r = post('clear-caches'); console.log(r.ok ? 'cleared: ' + (r.caches_cleared.join(', ') || 'nothing to clear') : 'FAILED: ' + (r.message || r.code)); process.exit(r.ok ? 0 : 1); }

  // update
  const list = k => { const v = flag(k, null); return v ? v.split(',').map(x => x.trim()).filter(Boolean) : null; };
  const all = has('all');
  const opts = { plugins: all || has('plugins') ? 'all' : list('plugin'), themes: all || has('themes') ? 'all' : list('theme'), core: all || has('core'), translations: all || has('translations'), major: has('major'), skip: list('skip') || [] };
  if (!opts.plugins && !opts.themes && !opts.core && !opts.translations) die('say what to update: --all, --plugins, --themes, --plugin <folder/file.php>, --theme <folder>, --core, --translations');
  if (has('careful') && has('fast')) die('--careful or --fast, not both');
  const fast = has('fast');
  const s = getStatus(true); show(s);
  if (s.site_blocked) die('this site refuses updates: ' + s.site_blocked);
  const { todo, skipped } = planUpdates(s, opts);
  console.log(`\nPLAN (${fast ? 'fast: all in one go, pages checked at the end' : 'careful: one at a time, pages checked after each, stops at the first problem'})`);
  todo.forEach((u, i) => console.log(`  ${String(i + 1).padStart(2)}. ${u.type.padEnd(12)} ${u.name.slice(0, 44).padEnd(44)} ${u.from} -> ${u.to}${u.kind === 'major' ? '   MAJOR' : ''}`));
  if (!todo.length) console.log('  nothing to update');
  skipped.forEach(([n, why]) => console.log(`  left out: ${n}: ${why}`));
  if (!todo.length) return;

  // which pages to watch: the home page, the paths given, else a few published pages and the newest post
  const base = s.site.replace(/\/$/, ''); let paths = list('check');
  if (!paths) { paths = ['/']; try { for (const p of JSON.parse(run(['rest-get', 'wp/v2/pages?per_page=5&orderby=modified&_fields=link']))) paths.push(p.link); for (const p of JSON.parse(run(['rest-get', 'wp/v2/posts?per_page=1&_fields=link']))) paths.push(p.link); } catch (e) { /* the home page alone */ } }
  const urls = [...new Set(paths.map(p => /^https?:/.test(p) ? p : base + (p.startsWith('/') ? p : '/' + p)))];
  // A page that does not answer, or answers 5xx, is asked again (twice, 6 s apart) before it counts: the first request
  // right after an update can fail while the site comes out of maintenance mode and its caches are empty. (First real
  // run, 2026-10-02: the home page gave no answer once, straight after a clean update, and was fine seconds later.)
  const loadOnce = async u => { let st = 0, html = ''; const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 60000);
    try { const r = await fetch(u + (u.includes('?') ? '&' : '?') + 'wpsu=' + Date.now(), { signal: ctl.signal, headers: { 'cache-control': 'no-cache' } }); st = r.status; html = await r.text(); } catch (e) { st = 0; html = ''; } finally { clearTimeout(t); }
    return pageHealth(u, st, html); };
  const checkPages = async () => { const out = []; for (const u of urls) { let h = await loadOnce(u); let tries = 1;
      while ((h.status === 0 || h.status >= 500) && tries < 3) { await new Promise(r => setTimeout(r, 6000)); h = await loadOnce(u); tries++; }
      h.tries = tries; out.push(h); } return out; };
  console.log(`\nwatching ${urls.length} page(s): ${urls.map(u => u.replace(base, '') || '/').join('  ')}`);
  if (!WRITE) { console.log('\nDRY RUN: nothing was changed. Add --write to run it.'); return; }

  const log = { site: s.site, started: new Date().toISOString(), mode: fast ? 'fast' : 'careful', plan: todo, skipped, results: [], checks: [] };
  const save = () => { fs.mkdirSync(dir, { recursive: true }); const f = path.join(dir, 'run-' + log.started.replace(/[:.]/g, '-') + '.json'); fs.writeFileSync(f, JSON.stringify(log, null, 1)); return f; };
  const before = await checkPages(); log.checks.push({ when: 'before', pages: before });
  const bad = before.filter(p => p.status !== 200 || p.fatal || !p.closed);
  if (bad.length) console.log('already not right BEFORE any update (not blamed on the updates): ' + bad.map(p => `${p.url} [${p.status}${p.fatal ? ' ' + p.fatal : ''}]`).join(', '));
  let stop = '';
  for (const u of todo) {
    process.stdout.write(`\n${u.type} ${u.name} ${u.from} -> ${u.to} … `);
    const r = post('update', { type: u.type, item: u.item });
    const ok = r.ok === true && r.done === true; log.results.push({ ...u, ok, now: r.now, error: r.error || r.note || r.message || '', http: r.http, caches_cleared: r.caches_cleared });
    console.log(ok ? `done${r.caches_cleared && r.caches_cleared.length ? ' (cleared: ' + r.caches_cleared.join(', ') + ')' : ''}` : r.ok === true && r.note ? r.note : `FAILED: ${r.error || r.message || r.code || 'no answer'}${r.http && r.http >= 500 ? ' [HTTP ' + r.http + ']' : ''}`);
    if (!ok && !(r.ok === true && r.note)) { if (!fast) { stop = `${u.name} did not update`; } }
    if (!fast || stop) { const after = await checkPages(); log.checks.push({ when: 'after ' + u.name, pages: after }); const problems = compareHealth(before, after);
      if (problems.length) { stop = `pages got worse after ${u.name}`; problems.forEach(p => console.log('   PAGE: ' + p)); } else if (!stop) console.log('   pages: all as before'); }
    if (stop) break;
  }
  if (fast && !stop) { const after = await checkPages(); log.checks.push({ when: 'after all', pages: after }); const problems = compareHealth(before, after); if (problems.length) { stop = 'pages got worse after the updates'; problems.forEach(p => console.log('   PAGE: ' + p)); } else console.log('\npages: all as before'); }
  const failed = log.results.filter(r => !r.ok && !/already up to date/.test(r.error)); const done = log.results.filter(r => r.ok);
  log.stopped = stop; log.finished = new Date().toISOString(); const f = save();
  console.log(`\n${done.length} updated, ${failed.length} failed, ${todo.length - log.results.length} not attempted.${stop ? ' STOPPED: ' + stop + '.' : ''}`);
  if (stop) console.log('Nothing is rolled back. Look at the site; restore from backup or re-upload the old version if needed.');
  console.log('log: ' + f);
  process.exit(stop || failed.length ? 1 : 0);
})();
