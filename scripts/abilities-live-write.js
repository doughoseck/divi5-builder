#!/usr/bin/env node
/**
 * abilities-live-write.js: the live write run of the divi5-builder abilities (issue #32, release 2.2.0), one step at a time.
 *
 *   node scripts/abilities-live-write.js <site> <step> [--page <id>] [--confirm <code>] [--image-url <url>] [--old <text> --new <text>]
 *   steps: posts, media, tag, menu, divi, history, all
 *
 * It talks to the site only through scripts/wp.js (rest-get for the readonly abilities, rest-post for the writes; wp.js holds the
 * credentials, this script never sees or prints them), and only to the run routes wp-abilities/v1/abilities/divi5-builder/<name>/run
 * plus the REST index. Core takes GET for a readonly ability and POST for one that changes things; the delete previews are readonly.
 *
 * Every item it creates is titled or named d5b-live-<step>-<yyyymmdd-hhmm> (UTC), and it only ever touches an item whose title starts
 * with d5b-live-: that is asserted before every update, trash and delete, and the run stops (exit 2) when it fails. The divi step
 * works on a page the maintainer nominates (a COPY of a Divi page renamed d5b-live-...), and refuses any other page.
 *
 *   posts   create a draft post and a draft page, update each with the hash, update one with a WRONG hash (stale, HTTP 409), trash
 *           both, restore one (back to draft), trash it again.
 *   media   upload a small PNG from --image-url (default: the site's own /wp-includes/images/w-logo-blue.png) as d5b-live-media-<stamp>.png, update its alt text,
 *           trash it. A site with no media trash answers cannot_trash: the script then previews the permanent delete and stops
 *           (exit 3) with "run again with --confirm <code>"; the next run with --confirm deletes it.
 *   tag     create a tag, assign it to a d5b-live- post (the posts step's draft, or one it creates), preview the delete (exit 3);
 *           with --confirm, delete it.
 *   menu    create a menu, add two custom items, reorder them, preview the delete (exit 3); with --confirm, delete it.
 *   divi    --page <id>: first checks that a revision holds the page as it is now (else: "save the copy page once in wp-admin first",
 *           exit 2, nothing written); divi-text-edit the first heading (or --old/--new), divi-section-edit replace section 1 with its
 *           own markup (changed false), then revisions-restore to that revision; the final hash must equal the original.
 *   history history-changes-list for today, page by page while has_more (at most 10 pages of 50): every write this script made (kept
 *           in a small ledger file in the temp folder) must have a row, matched by ability and object id (by ability and a d5b-live-
 *           summary where the id is not known); then history-change-get of each update, trash and delete row must give a before copy
 *           that is there, not too_large, and withholds nothing unless its summary says so.
 *   all     posts, media, tag, menu, divi (when --page is given), history. Not with --confirm: a code belongs to one step.
 *
 * It never prints a result value beyond ids, titles, statuses, hashes, addresses and short excerpts. A --confirm code is printed once,
 * in the preview message; it is never stored, only the object it belongs to is (in a ledger file in the temp folder).
 *
 * Exit 0: every step passed. 1: an unexpected status, a failed expectation or a missing history row. 2: usage, or a target whose
 * title does not start with d5b-live-. 3: a preview was printed and the run needs --confirm.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { runRoute } = require('./abilities-live-check');

const MARK = 'd5b-live-';
const STEPS = ['posts', 'media', 'tag', 'menu', 'divi', 'history'];
const NS = 'divi5-builder/';

class Fail extends Error {}   // exit 1
class Usage extends Error {}  // exit 2

/** Any value as a printable token: a string of at most 100 characters, line breaks and tabs turned into spaces. */
const tok = v => String(v === undefined || v === null ? '' : v).replace(/[\r\n\t]+/g, ' ').slice(0, 100);

const pad = n => String(n).padStart(2, '0');
function stampOf(d) { return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}-${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}`; }
const dayOf = d => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;

// ---------------------------------------------------------------- talking to the site (wp.js only)

function parseErr(e) {
  const err = String(e.stderr || e.message);
  const m = /HTTP (\d{3})/.exec(err);
  const c = /"code"\s*:\s*"([A-Za-z0-9_:\-]+)"/.exec(err);
  return { ok: false, status: m ? Number(m[1]) : 0, code: c ? tok(c[1]) : 'http_' + (m ? m[1] : '0'), json: null };
}

function wp(ctx, args) {
  return execFileSync(process.execPath, [ctx.wpjs, ctx.site].concat(args), { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 64 * 1024 * 1024 });
}

const okShort = s => /^[a-z0-9]+(-[a-z0-9]+)*$/.test(s);

/** GET of a readonly ability. @return {ok, status, code, json} */
function getAbility(ctx, short, input) {
  if (!okShort(short)) throw new Usage('bad ability name');
  try {
    const out = wp(ctx, ['rest-get', runRoute(NS + short, input)]);
    let json = null; try { json = JSON.parse(out); } catch (e) { /* not JSON */ }
    return { ok: true, status: 200, code: '', json };
  } catch (e) { return parseErr(e); }
}

/** POST of a write ability with the body {"input":{...}}, through a temp file that is removed at once. */
function postAbility(ctx, short, input) {
  if (!okShort(short)) throw new Usage('bad ability name');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'd5b-live-body-'));
  const file = path.join(dir, 'body.json');
  try {
    fs.writeFileSync(file, JSON.stringify({ input }));
    const out = wp(ctx, ['rest-post', 'wp-abilities/v1/abilities/' + NS + short + '/run', '--data-file', file]);
    let json = null; try { json = JSON.parse(out); } catch (e) { /* not JSON */ }
    return { ok: true, status: 200, code: '', json };
  } catch (e) { return parseErr(e); }
  finally { try { fs.rmSync(dir, { recursive: true, force: true }); } catch (e) { /* best effort */ } }
}

// ---------------------------------------------------------------- printing and expectations

/** Only these keys of a result are ever printed, and only when they are plain values. */
function fields(j, extra) {
  const o = [];
  if (j && typeof j === 'object') for (const k of ['id', 'title', 'status', 'hash', 'changed', 'deleted', 'transport']) if (j[k] !== undefined && typeof j[k] !== 'object') o.push(k + '=' + tok(j[k]));
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
  return r;
}

/** A write that has to be refused with this code and HTTP status. Records a failure (and goes on) when it is not. */
function refused(ctx, short, input, code, http) {
  const r = postAbility(ctx, short, input);
  line(ctx, short, r, [r.ok || (r.code === code && r.status === http) ? '(expected ' + code + ' ' + http + ')' : '(WANTED ' + code + ' ' + http + ')']);
  if (r.ok || r.code !== code || r.status !== http) { ctx.failures++; ctx.lines.push(`  FAILED: ${short} wanted refused:${code} HTTP ${http}`); }
  return r;
}

const idOf = j => (j && typeof j === 'object' ? (j.id !== undefined ? j.id : (j.item && j.item.id)) : undefined);

// ---------------------------------------------------------------- the d5b-live- guard and the ledger

function live(title) {
  if (typeof title !== 'string' || !title.startsWith(MARK)) throw new Usage(`target "${tok(title)}" does not start with ${MARK}: nothing was changed`);
}

/** Remember an item this run made or was nominated. */
function know(ctx, id, title) { live(title); ctx.titles.set(String(id), title); }

/** Assert, before an update, trash or delete, that the item is one this script knows and that its title starts with d5b-live-. */
function guard(ctx, id) {
  const t = ctx.titles.get(String(id));
  if (t === undefined) throw new Usage(`item ${tok(id)} is not one this run created or was nominated: nothing was changed`);
  live(t);
}

function ledgerPath(site) { return process.env.D5B_LIVE_LEDGER || path.join(os.tmpdir(), 'd5b-live-write-' + String(site).replace(/[^A-Za-z0-9_-]/g, '_') + '.json'); }
function readLedger(ctx) {
  try { const j = JSON.parse(fs.readFileSync(ledgerPath(ctx.site), 'utf8')); return { entries: Array.isArray(j.entries) ? j.entries : [], pending: j.pending && typeof j.pending === 'object' ? j.pending : {} }; }
  catch (e) { return { entries: [], pending: {} }; }
}
function saveLedger(ctx, led) { fs.writeFileSync(ledgerPath(ctx.site), JSON.stringify(led)); }
/** One write that went through: the history step looks for its row. id null = match by ability and summary only. */
function rec(ctx, short, id) {
  const led = readLedger(ctx); led.entries.push({ day: ctx.day, ability: short, id: id === undefined || id === null ? null : Number(id) }); saveLedger(ctx, led);
}
function setPending(ctx, step, item) { const led = readLedger(ctx); if (item) led.pending[step] = item; else delete led.pending[step]; saveLedger(ctx, led); }

// ---------------------------------------------------------------- the steps

function name(ctx, step) { return `${MARK}${step}-${ctx.stamp}`; }

function stepPosts(ctx) {
  const n = name(ctx, 'posts');
  const post = must(ctx, 'POST', 'posts-create', { title: n, status: 'draft' }); rec(ctx, 'posts-create', idOf(post.json));
  know(ctx, idOf(post.json), n);
  const page = must(ctx, 'POST', 'pages-create', { title: n, status: 'draft' }); rec(ctx, 'pages-create', idOf(page.json));
  know(ctx, idOf(page.json), n);
  const pid = idOf(post.json), gid = idOf(page.json);
  guard(ctx, pid);
  must(ctx, 'POST', 'posts-update', { id: pid, expect_hash: post.json.hash, title: n + ' v2' }); rec(ctx, 'posts-update', pid);
  guard(ctx, gid);
  must(ctx, 'POST', 'pages-update', { id: gid, expect_hash: page.json.hash, title: n + ' v2' }); rec(ctx, 'pages-update', gid);
  guard(ctx, pid);
  // A content change needs the hash (a title-only update needs none, so it would not be refused): send content.
  refused(ctx, 'posts-update', { id: pid, expect_hash: '0'.repeat(32), content: n + ' wrong' }, 'stale', 409);
  guard(ctx, pid); const t1 = must(ctx, 'POST', 'posts-trash', { id: pid }); rec(ctx, 'posts-trash', pid);
  guard(ctx, gid); must(ctx, 'POST', 'pages-trash', { id: gid }); rec(ctx, 'pages-trash', gid);
  if (t1.json.status !== 'trash') { ctx.failures++; ctx.lines.push('  FAILED: posts-trash did not report status trash'); }
  guard(ctx, pid); const rs = must(ctx, 'POST', 'posts-restore', { id: pid }); rec(ctx, 'posts-restore', pid);
  if (rs.json.status !== 'draft') { ctx.failures++; ctx.lines.push(`  FAILED: posts-restore wanted status draft (the status before the trash), got ${tok(rs.json.status)}`); }
  guard(ctx, pid); must(ctx, 'POST', 'posts-trash', { id: pid }); rec(ctx, 'posts-trash', pid);
  return 'ok';
}

function siteHome(ctx) {
  try { const j = JSON.parse(wp(ctx, ['rest-get', '--index'])); const h = typeof j.home === 'string' && j.home ? j.home : j.url; return typeof h === 'string' ? h.replace(/\/+$/, '') : ''; } catch (e) { return ''; }
}

function stepMedia(ctx) {
  const led = readLedger(ctx); let pend = led.pending.media;
  if (ctx.confirm && !pend) throw new Usage('--confirm given but no media delete is waiting for it: run the media step first');
  if (!ctx.confirm && !pend) {
    const n = name(ctx, 'media');
    let url = ctx.imageUrl;
    if (!url) { const home = siteHome(ctx); if (!/^https?:\/\//i.test(home)) throw new Fail('could not read the site address from the REST index; give --image-url'); url = home + '/wp-includes/images/w-logo-blue.png'; }
    // The file is named after the run too: the media history summaries name the file, so they carry d5b-live-.
    const up = must(ctx, 'POST', 'media-upload', { url, title: n, filename: n + '.png' }); const id = idOf(up.json); rec(ctx, 'media-upload', id);
    know(ctx, id, n);
    if (up.json.transport !== 'curl') { ctx.failures++; ctx.lines.push(`  FAILED: media-upload transport must be curl, got ${tok(up.json.transport)}`); }
    guard(ctx, id); must(ctx, 'POST', 'media-update', { id, alt: n + ' alt' }); rec(ctx, 'media-update', id);
    guard(ctx, id);
    const tr = postAbility(ctx, 'media-trash', { id });
    line(ctx, 'media-trash', tr);
    if (tr.ok) { rec(ctx, 'media-trash', id); return 'ok'; }
    if (tr.code !== 'cannot_trash') throw new Fail(`media-trash wanted ok or refused:cannot_trash, got refused:${tr.code} (HTTP ${tr.status})`);
    pend = { id, title: n };
  }
  live(pend.title); know(ctx, pend.id, pend.title);
  if (ctx.confirm) {
    guard(ctx, pend.id);
    must(ctx, 'POST', 'media-delete', { id: pend.id, confirmation: ctx.confirm }); rec(ctx, 'media-delete', pend.id);
    setPending(ctx, 'media', null);
    return 'ok';
  }
  guard(ctx, pend.id);
  const pv = getAbility(ctx, 'media-delete-preview', { id: pend.id });
  const j = pv.json && typeof pv.json === 'object' ? pv.json : {};
  if (pv.ok) live(j.item && j.item.title);
  const file = j.item && typeof j.item.file_url === 'string' ? path.basename(j.item.file_url.split('?')[0]) : '';
  line(ctx, 'media-delete-preview', pv, pv.ok ? [`file=${tok(file)}`, `used_by=${Array.isArray(j.used_by) ? j.used_by.length : 0}`, `expires_at=${tok(j.expires_at)}`] : []);
  if (!pv.ok || typeof j.confirmation !== 'string') throw new Fail(`media-delete-preview wanted ok with a confirmation code, got ${pv.ok ? 'no code' : 'refused:' + pv.code}`);
  setPending(ctx, 'media', { id: pend.id, title: pend.title });
  ctx.lines.push(`The media item is not deleted. Run again with --confirm ${j.confirmation} to delete it (the code works once, for 10 minutes).`);
  return 'confirm';
}

function stepTag(ctx) {
  const led = readLedger(ctx); const pend = led.pending.tag;
  if (ctx.confirm && !pend) throw new Usage('--confirm given but no tag delete is waiting for it: run the tag step first');
  if (ctx.confirm) {
    live(pend.title); know(ctx, pend.id, pend.title); guard(ctx, pend.id);
    must(ctx, 'POST', 'taxonomy-delete', { taxonomy: 'post_tag', id: pend.id, confirmation: ctx.confirm }); rec(ctx, 'taxonomy-delete', pend.id);
    setPending(ctx, 'tag', null);
    return 'ok';
  }
  const n = name(ctx, 'tag');
  let tag = pend ? { id: pend.id } : null;
  if (!pend) {
    // The test post: the posts step's draft (found by title), or a draft of its own.
    let pid = null, made = false;
    const l = getAbility(ctx, 'posts-list', { search: MARK + 'posts-', status: 'draft', per_page: 20 });
    line(ctx, 'posts-list', l);
    const hit = l.ok && l.json && Array.isArray(l.json.items) ? l.json.items.find(x => x && typeof x.title === 'string' && x.title.startsWith(MARK)) : null;
    if (hit) { pid = hit.id; know(ctx, pid, hit.title); }
    else { const c = must(ctx, 'POST', 'posts-create', { title: n, status: 'draft' }); pid = idOf(c.json); rec(ctx, 'posts-create', pid); know(ctx, pid, n); made = true; }
    const t = must(ctx, 'POST', 'taxonomy-create', { taxonomy: 'post_tag', name: n }); tag = { id: idOf(t.json) }; rec(ctx, 'taxonomy-create', tag.id);
    know(ctx, tag.id, n);
    guard(ctx, pid); guard(ctx, tag.id);
    must(ctx, 'POST', 'taxonomy-assign', { post_id: pid, taxonomy: 'post_tag', terms: [tag.id] }, ['post_id=' + tok(pid)]); rec(ctx, 'taxonomy-assign', pid);
    if (made) { guard(ctx, pid); must(ctx, 'POST', 'posts-trash', { id: pid }); rec(ctx, 'posts-trash', pid); }
    setPending(ctx, 'tag', { id: tag.id, title: n });
  } else { live(pend.title); know(ctx, pend.id, pend.title); }
  guard(ctx, tag.id);
  const pv = getAbility(ctx, 'taxonomy-delete-preview', { taxonomy: 'post_tag', id: tag.id });
  const j = pv.json && typeof pv.json === 'object' ? pv.json : {};
  if (pv.ok) live(j.term && j.term.name);
  line(ctx, 'taxonomy-delete-preview', pv, pv.ok ? [`count=${tok(j.count)}`, `children=${tok(j.children_total)}`, `posts=${tok(j.posts_total)}`, `expires_at=${tok(j.expires_at)}`] : []);
  if (!pv.ok || typeof j.confirmation !== 'string') throw new Fail(`taxonomy-delete-preview wanted ok with a confirmation code, got ${pv.ok ? 'no code' : 'refused:' + pv.code}`);
  ctx.lines.push(`The tag is not deleted. Run again with --confirm ${j.confirmation} to delete it (the code works once, for 10 minutes).`);
  return 'confirm';
}

function stepMenu(ctx) {
  const led = readLedger(ctx); const pend = led.pending.menu;
  if (ctx.confirm && !pend) throw new Usage('--confirm given but no menu delete is waiting for it: run the menu step first');
  if (ctx.confirm) {
    live(pend.title); know(ctx, pend.id, pend.title); guard(ctx, pend.id);
    must(ctx, 'POST', 'menus-delete', { menu: pend.id, confirmation: ctx.confirm }); rec(ctx, 'menus-delete', null);
    setPending(ctx, 'menu', null);
    return 'ok';
  }
  const n = name(ctx, 'menu');
  let mid;
  if (!pend) {
    const m = must(ctx, 'POST', 'menus-create', { name: n }); mid = idOf(m.json); rec(ctx, 'menus-create', null); know(ctx, mid, n);
    guard(ctx, mid);
    const a = must(ctx, 'POST', 'menus-item-add', { menu: mid, type: 'custom', object: '/' + MARK + 'menu-a', title: n + ' a' }); rec(ctx, 'menus-item-add', null);
    guard(ctx, mid);
    const b = must(ctx, 'POST', 'menus-item-add', { menu: mid, type: 'custom', object: '/' + MARK + 'menu-b', title: n + ' b' }); rec(ctx, 'menus-item-add', null);
    guard(ctx, mid);
    must(ctx, 'POST', 'menus-reorder', { menu: mid, items: [{ id: idOf(b.json), parent: 0 }, { id: idOf(a.json), parent: 0 }] }); rec(ctx, 'menus-reorder', null);
    setPending(ctx, 'menu', { id: mid, title: n });
  } else { live(pend.title); know(ctx, pend.id, pend.title); mid = pend.id; }
  guard(ctx, mid);
  const pv = getAbility(ctx, 'menus-delete-preview', { menu: mid });
  const j = pv.json && typeof pv.json === 'object' ? pv.json : {};
  if (pv.ok) live(j.menu && j.menu.name);
  line(ctx, 'menus-delete-preview', pv, pv.ok ? [`items=${tok(j.items_total)}`, `expires_at=${tok(j.expires_at)}`] : []);
  if (!pv.ok || typeof j.confirmation !== 'string') throw new Fail(`menus-delete-preview wanted ok with a confirmation code, got ${pv.ok ? 'no code' : 'refused:' + pv.code}`);
  ctx.lines.push(`The menu is not deleted. Run again with --confirm ${j.confirmation} to delete it (the code works once, for 10 minutes).`);
  return 'confirm';
}

/** The text of the first divi/heading as a visitor reads it, or null when it cannot be told safely. */
function firstHeading(content) {
  const m = /<!-- wp:divi\/heading\b(?:(?!-->)[\s\S])*?"title"\s*:\s*\{(?:(?!-->)[\s\S])*?"innerContent"\s*:\s*\{\s*"desktop"\s*:\s*\{\s*"value"\s*:\s*("(?:[^"\\]|\\.)*")/.exec(content);
  if (!m) return null;
  let s; try { s = JSON.parse(m[1]); } catch (e) { return null; }
  if (typeof s !== 'string' || !s.trim() || /[<>&$]/.test(s)) return null;
  return s;
}

/** The markup of the first top-level divi/section block, or null. */
function firstSection(content) {
  const re = /<!-- (\/?)wp:divi\/section\b(?:(?!-->)[\s\S])*?-->/g;
  let depth = 0, start = -1, m;
  while ((m = re.exec(content))) {
    if (m[1] !== '/') {
      if (depth === 0) start = m.index;
      if (/\/-->$/.test(m[0])) { if (depth === 0) return content.slice(start, re.lastIndex); } else depth++;
    } else { depth--; if (depth === 0 && start >= 0) return content.slice(start, re.lastIndex); }
  }
  return null;
}

function stepDivi(ctx) {
  if (!ctx.page) throw new Usage('the divi step needs --page <id> (a COPY of a Divi page, renamed d5b-live-...)');
  const pg = must(ctx, 'GET', 'pages-get', { id: ctx.page });
  const title = pg.json && pg.json.title;
  live(title); know(ctx, ctx.page, title);
  const h0 = pg.json.hash; let content = String(pg.json.content || '');
  if (!h0) throw new Fail('pages-get gave no hash');
  if (content.includes('[redacted]')) throw new Fail('the page content came back masked ([redacted]); a masked read cannot be written back');
  let oldT = ctx.old, newT = ctx.new;
  if (oldT === undefined) {
    oldT = firstHeading(content);
    if (!oldT) throw new Fail('could not tell the first heading text safely; give --old <text> --new <text>');
  }
  if (newT === undefined) newT = oldT + ' (edited)';
  // Before any edit: a revision must hold the page as it is now, or nothing could put it back (the restore at the end needs it).
  const rl = must(ctx, 'GET', 'revisions-list', { post_id: ctx.page, per_page: 20 });
  const items = rl.json && Array.isArray(rl.json.items) ? rl.json.items : [];
  let rid = null;
  for (const it of items.slice(0, 20)) {
    if (!it || it.id === undefined) continue;
    const rv = getAbility(ctx, 'revisions-get', { id: it.id });
    if (rv.ok && rv.json && rv.json.hash === h0) { rid = it.id; line(ctx, 'revisions-get', rv, []); break; }
  }
  if (rid === null) throw new Usage('no revision holds the page as it is now (hash ' + tok(h0) + '): save the copy page once in wp-admin first, then run again. Nothing was changed.');
  guard(ctx, ctx.page);
  const te = must(ctx, 'POST', 'divi-text-edit', { post_id: ctx.page, expect_hash: h0, old: oldT, new: newT }, ['before=' + tok(h0)]); rec(ctx, 'divi-text-edit', ctx.page);
  const h1 = te.json.hash;
  if (!h1 || h1 === h0) { ctx.failures++; ctx.lines.push('  FAILED: divi-text-edit did not change the hash'); }
  const pg2 = must(ctx, 'GET', 'pages-get', { id: ctx.page });
  content = String(pg2.json.content || '');
  const sec = firstSection(content);
  if (!sec) throw new Fail('could not find section 1 in the page content');
  guard(ctx, ctx.page);
  const se = must(ctx, 'POST', 'divi-section-edit', { post_id: ctx.page, expect_hash: pg2.json.hash, op: 'replace', at: '1', with: sec });
  if (se.json.changed !== false || se.json.hash !== pg2.json.hash) { ctx.failures++; ctx.lines.push('  FAILED: replacing section 1 with its own markup must answer changed false and keep the hash'); }
  // A no-op edit writes nothing, so it leaves no history row either. The restore goes to the revision found before the edit.
  guard(ctx, ctx.page);
  const rr = must(ctx, 'POST', 'revisions-restore', { post_id: ctx.page, revision_id: rid, expect_hash: pg2.json.hash }); rec(ctx, 'revisions-restore', ctx.page);
  const ok = rr.json.hash === h0;
  ctx.lines.push(`hashes  before=${tok(h0)}  after-edit=${tok(h1)}  final=${tok(rr.json.hash)}  ${ok ? 'final equals the original' : 'FINAL DOES NOT EQUAL THE ORIGINAL'}`);
  if (!ok) ctx.failures++;
  return 'ok';
}

const HISTORY_PAGES = 10;
const HISTORY_PER_PAGE = 50;

/** Every change row of today: page after page while has_more says another follows, at most HISTORY_PAGES pages. */
function historyRows(ctx) {
  const rows = [];
  for (let page = 1; page <= HISTORY_PAGES; page++) {
    const r = must(ctx, 'GET', 'history-changes-list', { since: ctx.day, per_page: HISTORY_PER_PAGE, page }, ['page=' + page]);
    const items = r.json && Array.isArray(r.json.items) ? r.json.items.filter(x => x && typeof x === 'object') : [];
    rows.push(...items);
    if (!(r.json && r.json.has_more === true)) return { rows, complete: true };
  }
  return { rows, complete: false };
}

/** The actions whose row must hold the state from before the change (history-change-get). */
const NEEDS_BEFORE = ['update', 'trash', 'delete'];

/**
 * The before copy of one row, checked: present (a non-empty object), not cut for size (too_large), and nothing withheld
 * (a secret-named key) unless the summary says so. @return '' when good, else why not
 */
function beforeProblem(row) {
  const b = row.before;
  if (!b || typeof b !== 'object' || Array.isArray(b) || !Object.keys(b).length) return 'no before copy';
  if (b.too_large === true) return 'before is too_large';
  if (/"withheld"\s*:/.test(JSON.stringify(b)) && !/withheld/i.test(String(row.summary || ''))) return 'before has a withheld value';
  return '';
}

function stepHistory(ctx) {
  const led = readLedger(ctx);
  const want = led.entries.filter(e => e && e.day === ctx.day);
  const got = historyRows(ctx);
  const rows = got.rows;
  if (!got.complete) ctx.lines.push(`  note: stopped after ${HISTORY_PAGES} pages of ${HISTORY_PER_PAGE}; older rows of today were not read`);
  const ids = new Set(want.filter(e => e.id !== null).map(e => NS + e.ability + '#' + e.id));
  // A row is this script's when its summary carries d5b-live- or its ability and object id are a write of the ledger.
  const isMine = x => (typeof x.summary === 'string' && x.summary.includes(MARK)) || ids.has(String(x.ability) + '#' + Number(x.object_id));
  const mine = rows.filter(isMine);
  ctx.lines.push(`${rows.length} change row(s) today, ${mine.length} of this script (${MARK} in the summary, or its ability and object id). Rows (id, time, ability, action, summary, revision_id):`);
  for (const x of mine) ctx.lines.push(`  ${tok(x.id)}  ${tok(x.time_utc)}  ${tok(x.ability)}  ${tok(x.action)}  ${tok(x.summary)}  ${tok(x.revision_id)}`);
  if (!want.length) { ctx.failures++; ctx.lines.push('  FAILED: this script has recorded no write for today, so there is nothing to check; run a step first'); return 'ok'; }
  const used = new Set(); let missing = 0;
  for (const e of want) {
    // By ability and object id; a write whose object id the script does not know (menus) by ability and the d5b-live- summary.
    const i = mine.findIndex((x, k) => !used.has(k) && x.ability === NS + e.ability && (e.id === null ? String(x.summary || '').includes(MARK) : Number(x.object_id) === e.id));
    if (i < 0) { missing++; ctx.lines.push(`  MISSING: no history row for ${e.ability}${e.id === null ? '' : ' on ' + e.id}`); } else used.add(i);
  }
  if (missing) ctx.failures++;
  ctx.lines.push(missing ? `history: ${missing} of ${want.length} write(s) have no row` : `history: all ${want.length} write(s) have a row`);
  // Each update, trash and delete row must hold the state it replaced.
  let bad = 0, seen = 0;
  for (const k of used) {
    const x = mine[k];
    if (!NEEDS_BEFORE.includes(x.action)) continue;
    seen++;
    const g = getAbility(ctx, 'history-change-get', { id: x.id });
    const why = !g.ok ? 'refused:' + g.code : beforeProblem(g.json || {});
    if (why) { bad++; line(ctx, 'history-change-get', g, ['id=' + tok(x.id), tok(x.ability), tok(why)]); }
  }
  if (bad) ctx.failures++;
  ctx.lines.push(bad ? `history: ${bad} of ${seen} update/trash/delete row(s) lack a usable before copy` : `history: all ${seen} update/trash/delete row(s) hold their before copy`);
  return 'ok';
}

// ---------------------------------------------------------------- the run

function parseArgs(argv) {
  const site = argv[0], step = argv[1];
  if (!site || site.startsWith('-') || !step || ![...STEPS, 'all'].includes(step)) throw new Usage('usage: node abilities-live-write.js <site> <posts|media|tag|menu|divi|history|all> [--page <id>] [--confirm <code>] [--image-url <url>] [--old <text> --new <text>]');
  const o = { site, step };
  const rest = argv.slice(2);
  for (let i = 0; i < rest.length; i++) {
    const a = rest[i];
    if (!['--page', '--confirm', '--image-url', '--old', '--new'].includes(a) || i + 1 >= rest.length) throw new Usage(`unknown or incomplete option ${tok(a)}`);
    o[a.slice(2).replace('-u', 'U')] = rest[++i];
  }
  if (o.page !== undefined && !/^[1-9][0-9]*$/.test(o.page)) throw new Usage('--page must be a page id');
  if (o.imageUrl !== undefined && !/^https?:\/\/\S+$/i.test(o.imageUrl)) throw new Usage('--image-url must be an http or https address');
  if (o.confirm !== undefined && !/^[A-Za-z0-9_.:-]{6,200}$/.test(o.confirm)) throw new Usage('--confirm must be the code a preview printed');
  if (o.confirm !== undefined && ['all', 'posts', 'divi', 'history'].includes(step)) throw new Usage('--confirm belongs to the media, tag or menu step, one at a time');
  if ((o.old === undefined) !== (o.new === undefined) && o.old === undefined) throw new Usage('--new needs --old');
  return o;
}

function run(argv, wpjs) {
  const o = parseArgs(argv);
  const now = new Date();
  const ctx = { site: o.site, wpjs, stamp: stampOf(now), day: dayOf(now), page: o.page, confirm: o.confirm, imageUrl: o.imageUrl, old: o.old, new: o.new, lines: [], failures: 0, titles: new Map() };
  const fns = { posts: stepPosts, media: stepMedia, tag: stepTag, menu: stepMenu, divi: stepDivi, history: stepHistory };
  const list = o.step === 'all' ? STEPS.filter(s => s !== 'divi' || ctx.page) : [o.step];
  let needConfirm = false;
  for (const s of list) {
    ctx.lines.push(`--- ${s}`);
    try { if (fns[s](ctx) === 'confirm') needConfirm = true; }
    catch (e) {
      if (e instanceof Usage) { return { code: 2, lines: ctx.lines.concat('USAGE: ' + e.message) }; }
      if (e instanceof Fail) { ctx.failures++; ctx.lines.push('  FAILED: ' + e.message); } else throw e;
    }
  }
  if (o.step === 'all' && !ctx.page) ctx.lines.push('--- divi: skipped (no --page)');
  const code = ctx.failures ? 1 : (needConfirm ? 3 : 0);
  ctx.lines.push(ctx.failures ? `RESULT: ${ctx.failures} check(s) failed.` : (needConfirm ? 'RESULT: stopped at a preview; run again with --confirm for that step.' : 'RESULT: every step passed.'));
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
module.exports = { run, parseArgs, firstHeading, firstSection };
