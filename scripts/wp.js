#!/usr/bin/env node
/*
 * wp.js — minimal WordPress REST client for the divi5-builder skill.
 *
 * Reads credentials from ~/.web-creds.txt (override with WEB_CREDS_PATH) at
 * runtime and builds a Basic-auth header IN MEMORY. The Application Password is
 * NEVER printed, echoed, logged, or placed in argv/output. Read -> use -> discard.
 * See .web-creds.example.txt for the file format.
 *
 * Usage:
 *   node wp.js <site> whoami
 *   node wp.js <site> list-pages
 *   node wp.js <site> get-page <id> [--raw]        # --raw dumps content.raw only
 *   node wp.js <site> rendered <id>                # server-rendered Divi HTML (proof of render)
 *   node wp.js <site> builder-version              # detect site's Divi builderVersion
 *   node wp.js <site> global-colors                # discover gcid global-color IDs in use
 *   node wp.js <site> create-page --title T --content-file F [--status draft|publish] [--slug S]
 *   node wp.js <site> update-page <id> [--title T] [--content-file F] [--status ...]
 *   node wp.js <site> delete-page <id> [--force]   # --force = permanent (skip trash)
 *   node wp.js <site> set-homepage <id>            # show_on_front=page, page_on_front=id
 *   node wp.js <site> reset-homefront              # show_on_front=posts (undo)
 *   node wp.js <site> upload-media <filepath> [--alt "..."]
 *   node wp.js <site> list-categories              # id, post count, name, slug
 *   node wp.js <site> set-post-categories <id> --names "Setup,How it works"
 *                                                  # resolves names to ids, creating any that are missing
 *   --- Canvases (popups, off-canvas menus; see references/divi5-interactions-canvases.md) ---
 *   node wp.js <site> list-canvases                # id | status | modified | title
 *   node wp.js <site> get-canvas <id> [--raw | --out F]
 *   node wp.js <site> create-canvas --title T --content-file F
 *   node wp.js <site> update-canvas <id> --content-file F [--title T]
 *   node wp.js <site> link-canvas <canvas_id> <parent_id>   # parent = page, or a Theme Builder layout
 *   --- Theme Builder (needs divi5-builder-rest.php >= 1.5; see SKILL.md) ---
 *   node wp.js <site> plugin-version               # which mu-plugin version is installed
 *   node wp.js <site> tb-list [--json]             # templates + layouts, each layout's format and hash
 *   node wp.js <site> tb-get <id> [--out F]        # raw layout content + the hash tb-set needs
 *   node wp.js <site> tb-set <id> --content-file F --expect-hash H [--dry-run] [--mark-divi5]
 *                                                  # SITE-WIDE write; refuses if the layout changed since tb-get
 *   node wp.js <site> tb-restore <id>              # put back what the last tb-set replaced
 *
 * Output is compact JSON or plain lines on stdout; errors to stderr, exit 1.
 */
const fs = require('fs');
const path = require('path');
const os = require('os');
const https = require('https');
const { URL } = require('url');

// Credentials file: set WEB_CREDS_PATH, else ~/.web-creds.txt. Never commit it.
const CREDS_PATH = process.env.WEB_CREDS_PATH || path.join(os.homedir(), '.web-creds.txt');

function parseCreds(site) {
  let txt;
  try { txt = fs.readFileSync(CREDS_PATH, 'utf8'); }
  catch (e) { die(`cannot read creds file at ${CREDS_PATH}: ${e.message}`); }
  const sections = {};
  let cur = null;
  for (const line of txt.split(/\r?\n/)) {
    const s = line.trim();
    const m = s.match(/^\[(.+)\]$/);
    if (m) { cur = m[1]; sections[cur] = {}; continue; }
    const kv = s.match(/^(\w+)\s*=\s*(.+)$/);
    if (kv && cur) sections[cur][kv[1]] = kv[2].trim();
  }
  const c = sections[site];
  if (!c) die(`no [${site}] section in creds file. Sections: ${Object.keys(sections).join(', ')}`);
  if (!c.url || !c.user || !c.pass) die(`[${site}] missing url/user/pass`);
  return c;
}

function die(msg) { process.stderr.write('ERROR: ' + msg + '\n'); process.exit(1); }

// Build auth header in memory; never returned to caller as a string that gets logged.
function authHeader(c) {
  return 'Basic ' + Buffer.from(c.user + ':' + c.pass.replace(/\s+/g, '')).toString('base64');
}

function request(method, urlStr, headers, body) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const opts = { method, hostname: u.hostname, path: u.pathname + u.search,
      headers: Object.assign({ 'User-Agent': 'divi5-builder' }, headers) };
    const req = https.request(opts, (res) => {
      const chunks = [];
      res.on('data', (d) => chunks.push(d));
      res.on('end', () => resolve({ status: res.statusCode, body: Buffer.concat(chunks) }));
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

async function jreq(method, url, c, payload) {
  const headers = { Authorization: authHeader(c) };
  let body = null;
  if (payload) { body = Buffer.from(JSON.stringify(payload)); headers['Content-Type'] = 'application/json'; headers['Content-Length'] = body.length; }
  const r = await request(method, url, headers, body);
  const text = r.body.toString('utf8');
  let json = null;
  try { json = JSON.parse(text); } catch (e) { /* leave null */ }
  if (r.status >= 400) die(`HTTP ${r.status} ${method} ${url.replace(c.url, '')}\n${text.slice(0, 500)}`);
  return json !== null ? json : text;
}

// crude flag parser: --key value  (or --key for booleans)
function flags(argv) {
  const out = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i].startsWith('--')) {
      const k = argv[i].slice(2);
      if (i + 1 < argv.length && !argv[i + 1].startsWith('--')) { out[k] = argv[++i]; }
      else out[k] = true;
    } else out._.push(argv[i]);
  }
  return out;
}

(async () => {
  const [site, cmd, ...rest] = process.argv.slice(2);
  if (!site || !cmd) die('usage: node wp.js <site> <command> [...]. See header of wp.js.');
  const c = parseCreds(site);
  const api = c.url.replace(/\/$/, '') + '/wp-json/wp/v2';
  const f = flags(rest);

  switch (cmd) {
    case 'whoami': {
      const me = await jreq('GET', `${api}/users/me`, c);
      console.log(JSON.stringify({ ok: true, name: me.name, id: me.id, site: c.url }));
      break;
    }
    case 'list-pages': {
      const arr = await jreq('GET', `${api}/pages?per_page=100&status=any&_fields=id,title,status,link,slug`, c);
      for (const p of arr) console.log(`#${p.id}\t[${p.status}]\t${p.title.rendered}\t${p.link}`);
      break;
    }
    case 'get-page': {
      const id = f._[0]; if (!id) die('get-page needs <id>');
      const p = await jreq('GET', `${api}/pages/${id}?context=edit&_fields=id,title,status,slug,link,content`, c);
      if (f.raw) { process.stdout.write(p.content.raw); }
      else console.log(JSON.stringify({ id: p.id, title: p.title.raw, status: p.status, slug: p.slug, link: p.link, contentLength: (p.content.raw || '').length }, null, 2));
      break;
    }
    case 'rendered': {
      const id = f._[0]; if (!id) die('rendered needs <id>');
      const p = await jreq('GET', `${api}/pages/${id}?context=edit&_fields=content`, c);
      process.stdout.write(p.content.rendered || '');
      break;
    }
    case 'divi-check': {
      // Is the target site on Divi 5 (block markup) or Divi 4 (shortcodes)? This
      // skill only works on Divi 5 — Divi 4 pages use [et_pb_*] shortcodes, an
      // incompatible format. Scans recent pages for the tell-tale markup.
      const arr = await jreq('GET', `${api}/pages?per_page=20&status=any&_fields=id`, c);
      let d5 = 0, d4 = 0;
      for (const p of arr) {
        const pg = await jreq('GET', `${api}/pages/${p.id}?context=edit&_fields=content`, c);
        const raw = pg.content.raw || '';
        if (/<!--\s*wp:divi\//.test(raw)) d5++;
        else if (/\[et_pb_/.test(raw)) d4++;
      }
      const verdict = d5 > 0 ? 'divi5' : d4 > 0 ? 'divi4' : 'unknown';
      console.log(JSON.stringify({ verdict, pagesWithDivi5Blocks: d5, pagesWithDivi4Shortcodes: d4,
        ok: verdict === 'divi5', note: verdict === 'divi4' ? 'STOP: site is Divi 4 (shortcodes) — this skill is Divi 5 only.' : verdict === 'unknown' ? 'No Divi content found to sample.' : 'Divi 5 — good to build.' }));
      break;
    }
    case 'builder-version': {
      // scan first page that has a divi block for its builderVersion
      const arr = await jreq('GET', `${api}/pages?per_page=20&status=any&_fields=id`, c);
      for (const p of arr) {
        const pg = await jreq('GET', `${api}/pages/${p.id}?context=edit&_fields=content`, c);
        const m = (pg.content.raw || '').match(/"builderVersion":"([^"]+)"/);
        if (m) { console.log(m[1]); return; }
      }
      console.log('5.9.0'); // sane default
      break;
    }
    case 'global-colors': {
      // NOTE: this only reports gcids ALREADY USED by a page — it scrapes page
      // content, it does not read Divi's palette. On a site with no Divi pages
      // yet it returns [] whether or not a palette exists. Use `palette-get`
      // for the real answer (needs divi5-builder-rest.php >= 1.3).
      const arr = await jreq('GET', `${api}/pages?per_page=50&status=any&_fields=id`, c);
      const gcids = new Set();
      for (const p of arr) {
        const pg = await jreq('GET', `${api}/pages/${p.id}?context=edit&_fields=content`, c);
        for (const m of (pg.content.raw || '').matchAll(/gcid-[a-z0-9-]+/g)) gcids.add(m[0]);
      }
      console.log(JSON.stringify([...gcids], null, 2));
      break;
    }
    case 'list-media': {
      // usage: list-media [--search foo] [--per-page 100]
      // Prints id, mime, dimensions, filename and URL for each attachment, so a
      // page spec can reference images already in the library instead of
      // re-uploading them.
      const per = f['per-page'] || 100;
      const q = f.search ? `&search=${encodeURIComponent(f.search)}` : '';
      const items = await jreq('GET', `${api}/media?per_page=${per}&orderby=date&order=desc${q}`, c);
      const rows = items.map((m) => ({
        id: m.id,
        mime: m.mime_type,
        size: m.media_details ? `${m.media_details.width}x${m.media_details.height}` : '',
        file: (m.source_url || '').split('/').pop(),
        url: m.source_url,
      }));
      console.log(JSON.stringify(rows, null, 2));
      break;
    }
    case 'option-get': {
      // usage: option-get <name>   (whitelisted palette options only)
      const name = f._[0]; if (!name) die('option-get needs <option name>');
      const r = await jreq('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/option?name=${encodeURIComponent(name)}`, c);
      console.log(JSON.stringify(r, null, 2));
      break;
    }
    case 'option-set': {
      // usage: option-set <name> --value-file <json>
      const name = f._[0]; if (!name) die('option-set needs <option name>');
      if (!f['value-file']) die('option-set needs --value-file <path to json>');
      const value = JSON.parse(fs.readFileSync(f['value-file'], 'utf8'));
      const r = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/option`, c, { name, value });
      console.log(JSON.stringify(r, null, 2));
      break;
    }
    case 'palette-get': {
      // Divi's actual global-colour palette, read from the wp_option it lives
      // in. Requires divi5-builder-rest.php >= 1.3.
      const r = await jreq('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/global-colors`, c);
      console.log(JSON.stringify(r, null, 2));
      break;
    }
    case 'palette-set': {
      // usage: palette-set --colors-file palette.json [--option et_global_colors] [--replace]
      // palette.json: { "gcid-primary-color": "#2B5C7A", ... } or
      //               { "gcid-primary-color": {"color":"#2B5C7A","active":"yes"}, ... }
      // Merges into the existing palette unless --replace is passed, so colours
      // added by hand in the Visual Builder are never silently dropped.
      if (!f['colors-file']) die('palette-set needs --colors-file <path to json>');
      const colors = JSON.parse(fs.readFileSync(f['colors-file'], 'utf8'));
      const body = { colors };
      if (f.option) body.option = f.option;
      if (f.replace) body.replace = 1;
      const r = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/global-colors`, c, body);
      console.log(JSON.stringify(r, null, 2));
      break;
    }
    // ---- Blog posts -------------------------------------------------------
    // Posts, not pages: they want an archive, dates, categories and tags.
    // Content is ordinary semantic HTML rather than Divi block markup — a post
    // renders in the theme's post template, and Divi-building one makes it
    // harder to edit for no gain.
    case 'list-posts': {
      const arr = await jreq('GET', `${api}/posts?per_page=50&status=any&_fields=id,title,status,link,slug`, c);
      for (const p of arr) console.log(`#${p.id}\t[${p.status}]\t${p.title.rendered}\t${p.link}`);
      break;
    }
    case 'get-post': {
      const id = f._[0]; if (!id) die('get-post needs <id>');
      const p = await jreq('GET', `${api}/posts/${id}?context=edit`, c);
      console.log(f.raw ? p.content.raw : JSON.stringify({ id: p.id, status: p.status, slug: p.slug, title: p.title.raw }, null, 2));
      break;
    }
    case 'create-post': {
      if (!f.title) die('create-post needs --title');
      const content = f['content-file'] ? fs.readFileSync(f['content-file'], 'utf8') : (f.content || '');
      const payload = { title: f.title, content, status: f.status || 'draft' };
      if (f.slug) payload.slug = f.slug;
      if (f.excerpt) payload.excerpt = f.excerpt;
      const p = await jreq('POST', `${api}/posts`, c, payload);
      console.log(JSON.stringify({ ok: true, id: p.id, status: p.status, link: p.link, slug: p.slug }));
      break;
    }
    case 'update-post': {
      const id = f._[0]; if (!id) die('update-post needs <id>');
      const payload = {};
      if (f.title) payload.title = f.title;
      if (f.status) payload.status = f.status;
      if (f.slug) payload.slug = f.slug;
      if (f.excerpt) payload.excerpt = f.excerpt;
      if (f['content-file']) payload.content = fs.readFileSync(f['content-file'], 'utf8');
      // --now: publish IMMEDIATELY. Setting status=publish alone is not enough
      // on a scheduled post — WordPress keeps a future-dated post as 'future'
      // and answers {ok:true} while it stays queued. The date has to move too.
      if (f.now) {
        payload.status = 'publish';
        payload.date_gmt = new Date().toISOString().replace(/\.\d+Z$/, '');
      }
      const p = await jreq('POST', `${api}/posts/${id}`, c, payload);
      const warn = p.status === 'future' ? ' (STILL SCHEDULED — use --now to publish immediately)' : '';
      console.log(JSON.stringify({ ok: true, id: p.id, status: p.status + warn, slug: p.slug, link: p.link, date: p.date }));
      break;
    }
    // ---- Categories -------------------------------------------------------
    // WordPress files every uncategorised post under "Uncategorized", which
    // then renders as a real, indexable archive listing your best content under
    // a meaningless label. set-post-categories resolves names to ids and
    // CREATES any that do not exist, so one call does the whole job.
    case 'list-categories': {
      const arr = await jreq('GET', `${api}/categories?per_page=100&_fields=id,name,slug,count`, c);
      for (const t of arr) console.log(`#${t.id}\t${t.count} posts\t${t.name}\t(${t.slug})`);
      break;
    }
    case 'set-post-categories': {
      const id = f._[0]; if (!id) die('set-post-categories needs <id> --names "A,B"');
      const names = String(f.names || '').split(',').map(s => s.trim()).filter(Boolean);
      if (!names.length) die('set-post-categories needs --names "A,B"');
      const existing = await jreq('GET', `${api}/categories?per_page=100&_fields=id,name`, c);
      const ids = [];
      for (const n of names) {
        let hit = existing.find(t => t.name.toLowerCase() === n.toLowerCase());
        if (!hit) { hit = await jreq('POST', `${api}/categories`, c, { name: n }); existing.push(hit); }
        ids.push(hit.id);
      }
      const p = await jreq('POST', `${api}/posts/${id}`, c, { categories: ids });
      console.log(JSON.stringify({ ok: true, id: p.id, categories: p.categories, names }));
      break;
    }
    case 'create-page': {
      if (!f.title) die('create-page needs --title');
      const content = f['content-file'] ? fs.readFileSync(f['content-file'], 'utf8') : (f.content || '');
      const payload = { title: f.title, content, status: f.status || 'draft' };
      if (f.slug) payload.slug = f.slug;
      const p = await jreq('POST', `${api}/pages`, c, payload);
      console.log(JSON.stringify({ ok: true, id: p.id, status: p.status, link: p.link, slug: p.slug }));
      break;
    }
    case 'update-page': {
      const id = f._[0]; if (!id) die('update-page needs <id>');
      const payload = {};
      if (f.title) payload.title = f.title;
      if (f.status) payload.status = f.status;
      // create-page has always accepted --slug; update-page silently DROPPED it
      // and still answered {ok:true}, so a slug that never changed looked
      // exactly like one that had. The slug is echoed back below for the same
      // reason: an answer you cannot check is not an answer.
      if (f.slug) payload.slug = f.slug;
      if (f['content-file']) payload.content = fs.readFileSync(f['content-file'], 'utf8');
      const p = await jreq('POST', `${api}/pages/${id}`, c, payload);
      console.log(JSON.stringify({ ok: true, id: p.id, status: p.status, slug: p.slug, link: p.link }));
      break;
    }
    case 'delete-page': {
      const id = f._[0]; if (!id) die('delete-page needs <id>');
      const q = f.force ? '?force=true' : '';
      const p = await jreq('DELETE', `${api}/pages/${id}${q}`, c);
      console.log(JSON.stringify({ ok: true, deleted: id, force: !!f.force }));
      break;
    }
    case 'set-homepage': {
      const id = f._[0]; if (!id) die('set-homepage needs <id>');
      const s = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/wp/v2/settings`, c,
        { show_on_front: 'page', page_on_front: Number(id) });
      console.log(JSON.stringify({ ok: true, show_on_front: s.show_on_front, page_on_front: s.page_on_front }));
      break;
    }
    case 'reset-homefront': {
      const s = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/wp/v2/settings`, c, { show_on_front: 'posts' });
      console.log(JSON.stringify({ ok: true, show_on_front: s.show_on_front }));
      break;
    }
    case 'upload-media': {
      const fp = f._[0]; if (!fp) die('upload-media needs <filepath>');
      const data = fs.readFileSync(fp);
      const name = path.basename(fp);
      const ext = path.extname(fp).slice(1).toLowerCase();
      const mime = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml' }[ext] || 'application/octet-stream';
      const headers = { Authorization: authHeader(c), 'Content-Type': mime,
        'Content-Disposition': `attachment; filename="${name}"`, 'Content-Length': data.length };
      const r = await request('POST', `${api}/media`, headers, data);
      if (r.status >= 400) die(`HTTP ${r.status} upload\n${r.body.toString('utf8').slice(0, 400)}`);
      const m = JSON.parse(r.body.toString('utf8'));
      if (f.alt) await jreq('POST', `${api}/media/${m.id}`, c, { alt_text: f.alt });
      console.log(JSON.stringify({ ok: true, id: m.id, url: m.source_url }));
      break;
    }
    case 'set-builder': {
      // Flip a page into "Divi mode" by writing Divi's builder/layout meta.
      // REQUIRES the divi5-builder-rest.php mu-plugin (assets/) so these
      // protected _et_* meta keys are REST-writable. Without it, WP silently
      // drops the write and the page stays a non-Divi (sidebar) page.
      const id = f._[0]; if (!id) die('set-builder needs <id>');
      const on = !f.off;
      const meta = {
        _et_pb_use_builder: on ? 'on' : 'off',
        _et_pb_page_layout: f.layout || 'et_no_sidebar',
        _et_pb_side_nav: f.sidenav || 'off',
        _et_pb_built_for_post_type: 'page',
      };
      // _et_pb_use_divi_5 is what every builder-saved Divi 5 page carries, and the only marker found on
      // all of them, so set it rather than rely on something else setting it later.
      if (on) meta._et_pb_use_divi_5 = 'on';
      // The builder never rewrites the version stamp (migrated pages keep 'VB|Divi|4.27.4' after a Divi 5
      // save), so leave an existing stamp alone and only fill an empty one.
      const before = (await jreq('GET', `${api}/pages/${id}?context=edit&_fields=meta`, c)).meta || {};
      if (f.version || !before._et_builder_version) meta._et_builder_version = f.version || 'VB|Divi|5.9.0';
      const p = await jreq('POST', `${api}/pages/${id}`, c, { meta });
      const got = p.meta || {};
      const ok = got._et_pb_use_builder === (on ? 'on' : 'off') && (!on || got._et_pb_use_divi_5 === 'on');
      console.log(JSON.stringify({ ok, id: p.id, wrote: meta, readback: {
        _et_pb_use_builder: got._et_pb_use_builder, _et_pb_use_divi_5: got._et_pb_use_divi_5,
        _et_pb_page_layout: got._et_pb_page_layout, _et_builder_version: got._et_builder_version } }));
      if (!ok) die('meta did NOT stick — is the divi5-builder-rest.php mu-plugin installed? (see assets/)');
      break;
    }
    case 'check-plugin': {
      // Is the divi5-builder-rest.php mu-plugin active? It registers _et_* meta
      // for REST, so they appear in the pages meta schema once installed.
      const r = await request('OPTIONS', `${api}/pages`, { Authorization: authHeader(c) });
      let ok = false;
      try { ok = !!(((JSON.parse(r.body.toString('utf8')).schema || {}).properties || {}).meta || {}).properties?._et_pb_use_builder; } catch (e) { /* */ }
      console.log(JSON.stringify({ pluginActive: ok, hint: ok ? 'ready — set-builder will work' : 'install assets/divi5-builder-rest.php in wp-content/mu-plugins/' }));
      break;
    }
    case 'create-canvas': {
      // Create an et_pb_canvas post (holds Divi 5 popup/off-canvas content).
      if (!f.title) die('create-canvas needs --title');
      const content = f['content-file'] ? fs.readFileSync(f['content-file'], 'utf8') : (f.content || '');
      const cv = await jreq('POST', `${api}/et_pb_canvas`, c, { title: f.title, content, status: f.status || 'publish' });
      console.log(JSON.stringify({ ok: true, id: cv.id, status: cv.status }));
      break;
    }
    case 'design-system': {
      // READ-ONLY (mu-plugin >= 1.6): every preset and variable on the site, so a page can reference them.
      // --json = raw; --full also returns each preset's attrs.
      const r = await jreq('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/design-system${f.full ? '?full=1' : ''}`, c);
      if (f.json || f.full) { console.log(JSON.stringify(r, null, 2)); break; }
      const P = r.presets || { module: [], group: [] };
      console.log(`MODULE PRESETS (${P.module.length})  id | default? | module | name   -> use as "modulePreset":["<id>"]`);
      for (const p of P.module) console.log(`  ${p.id} | ${p.isDefault ? 'DEFAULT' : '-'} | ${p.for} | ${p.name}`);
      console.log(`\nOPTION GROUP PRESETS (${P.group.length})  id | default? | groupName | name   -> use in "groupPreset":{"<slot>":{"presetId":["<id>"],"groupName":"<groupName>"}}`);
      for (const p of P.group) console.log(`  ${p.id} | ${p.isDefault ? 'DEFAULT' : '-'} | ${p.for} | ${p.name}`);
      const V = r.variables || {}; const kinds = Object.keys(V);
      console.log(`\nVARIABLES (${kinds.map(k => k + ':' + Object.keys(V[k] || {}).length).join(', ') || 'none'})`);
      for (const k of kinds) for (const [id, v] of Object.entries(V[k] || {})) console.log(`  ${id} | ${k} | ${v.label || ''} | ${typeof v.value === 'string' ? v.value.slice(0, 60) : JSON.stringify(v.value).slice(0, 60)}`);
      const C = r.colors || {}; console.log(`\nGLOBAL COLOURS (${Object.keys(C).length})`);
      for (const [id, v] of Object.entries(C)) console.log(`  ${id} | ${v.label || ''} | ${v.color || ''} | ${v.status || ''}`);
      break;
    }
    case 'list-canvases': {
      const list = await jreq('GET', `${api}/et_pb_canvas?context=edit&per_page=100&status=any&_fields=id,title,status,modified`, c);
      for (const k of list) console.log(`${k.id} | ${k.status} | ${k.modified} | ${(k.title && (k.title.raw || k.title.rendered)) || ''}`);
      break;
    }
    case 'get-canvas': {
      // Raw, unrendered canvas content (--raw prints it; --out <file> saves it). Without either: a summary.
      const id = f._[0]; if (!id) die('get-canvas needs <id>');
      const k = await jreq('GET', `${api}/et_pb_canvas/${id}?context=edit&_fields=id,title,status,modified,content.raw`, c);
      const raw = (k.content && k.content.raw) || '';
      if (f.out) { fs.writeFileSync(f.out, raw, 'utf8'); console.log(JSON.stringify({ ok: true, id: k.id, saved: f.out, chars: raw.length })); }
      else if (f.raw) console.log(raw);
      else console.log(JSON.stringify({ id: k.id, title: k.title && k.title.raw, status: k.status, modified: k.modified, contentLength: raw.length }, null, 2));
      break;
    }
    case 'update-canvas': {
      // Overwrite an existing canvas's content. A canvas is only output where something targets it,
      // but once targeted from a header it is site-wide: get a nod first.
      const id = f._[0]; if (!id) die('update-canvas needs <id>');
      if (!f['content-file']) die('update-canvas needs --content-file');
      const content = fs.readFileSync(f['content-file'], 'utf8');
      const body = { content }; if (f.title) body.title = f.title;
      const k = await jreq('POST', `${api}/et_pb_canvas/${id}?context=edit&_fields=id,status,content.raw`, c, body);
      const stored = (k.content && k.content.raw) || '';
      console.log(JSON.stringify({ ok: true, id: k.id, status: k.status, sentChars: content.length, storedChars: stored.length, storedAsSent: stored === content }));
      break;
    }
    case 'link-canvas': {
      // Link a canvas to a page (sets post_parent) so Divi appends it on render.
      // Requires divi5-builder-rest.php >= 1.1.
      const canvas = f._[0], page = f._[1];
      if (!canvas || !page) die('usage: link-canvas <canvas_id> <page_id>');
      const r = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/link-canvas`, c, { canvas_id: Number(canvas), page_id: Number(page) });
      console.log(JSON.stringify(r));
      break;
    }
    case 'delete-canvas': {
      const id = f._[0]; if (!id) die('delete-canvas needs <id>');
      await jreq('DELETE', `${api}/et_pb_canvas/${id}${f.force ? '?force=true' : ''}`, c);
      console.log(JSON.stringify({ ok: true, deleted: id }));
      break;
    }
    case 'postinfo': {
      // Diagnostic (requires divi5-builder-rest.php >= 1.1): parent/type/meta[/options].
      const id = f._[0]; if (!id) die('postinfo needs <id>');
      const scan = f.scan ? `&scan=${encodeURIComponent(f.scan)}` : '';
      const r = await jreq('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/postinfo?id=${id}${scan}`, c);
      console.log(JSON.stringify(r, null, 2));
      break;
    }
    // ---- Theme Builder (requires divi5-builder-rest.php >= 1.5) ----------------
    case 'plugin-version': {
      const r = await request('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/version`, { Authorization: authHeader(c) });
      if (r.status === 404) { console.log(JSON.stringify({ version: '<1.5', note: 'no /version route: re-upload assets/divi5-builder-rest.php' })); break; }
      console.log(r.body.toString('utf8'));
      break;
    }
    case 'tb-list': {
      // Every template + layout: format (divi5/divi4), bytes, hash, and which layouts each template uses.
      const r = await jreq('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/tb-list`, c);
      if (f.json) { console.log(JSON.stringify(r, null, 1)); break; }
      const one = (m, k) => (m[k] && m[k][0]) || '-';
      const used = {};
      console.log(`Theme Builder posts: ${r.count}`);
      console.log('\nTEMPLATES  id | status | title | enabled | default | header/body/footer | use_on');
      for (const t of r.items.filter((x) => x.type === 'et_template')) {
        const h = one(t.meta, '_et_header_layout_id'), b = one(t.meta, '_et_body_layout_id'), ft = one(t.meta, '_et_footer_layout_id');
        for (const id of [h, b, ft]) if (+id) (used[id] = used[id] || []).push(t.id);
        console.log(`  ${t.id} | ${t.status} | ${t.title} | ${one(t.meta, '_et_enabled')} | ${one(t.meta, '_et_default')} | ${h}/${b}/${ft} | ${(t.meta._et_use_on || []).join(' + ') || '-'}`);
      }
      console.log('\nLAYOUTS  id | type | status | FORMAT | bytes | use_divi_5 | used by templates | hash');
      for (const l of r.items.filter((x) => /_layout$/.test(x.type))) {
        console.log(`  ${l.id} | ${l.type.replace(/^et_|_layout$/g, '')} | ${l.status} | ${l.format} | ${l.bytes} | ${one(l.meta, '_et_pb_use_divi_5')} | ${(used[l.id] || []).join(',') || 'NONE'} | ${l.hash}`);
      }
      break;
    }
    case 'tb-get': {
      // Raw, unrendered content of one layout. --out <file> saves it; always prints the hash tb-set needs.
      const id = f._[0]; if (!id) die('tb-get needs <id>');
      const r = await jreq('GET', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/tb-layout?id=${id}`, c);
      const { content, ...head } = r;
      if (f.out) { fs.writeFileSync(f.out, content, 'utf8'); head.saved_to = f.out; }
      console.log(JSON.stringify(head, null, 1));
      if (!f.out && !f.quiet) console.log(content);
      break;
    }
    case 'tb-set': {
      // Overwrite a header/body/footer layout. SITE-WIDE EFFECT. Needs the hash from a tb-get you just did.
      const id = f._[0];
      if (!id || !f['content-file'] || !f['expect-hash']) die('usage: tb-set <id> --content-file <f> --expect-hash <hash from tb-get> [--mark-divi5] [--dry-run]');
      const content = fs.readFileSync(f['content-file'], 'utf8');
      const r = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/tb-layout`, c,
        { id: Number(id), content, expect_hash: String(f['expect-hash']), mark_divi5: !!f['mark-divi5'], dry_run: !!f['dry-run'] });
      console.log(JSON.stringify(r, null, 1));
      break;
    }
    case 'tb-restore': {
      // Put back the content that the last tb-set replaced (the swap is itself undoable).
      const id = f._[0]; if (!id) die('tb-restore needs <id>');
      const r = await jreq('POST', `${c.url.replace(/\/$/, '')}/wp-json/divi5-builder/v1/tb-layout-restore`, c, { id: Number(id) });
      console.log(JSON.stringify(r, null, 1));
      break;
    }
    case 'dump-blocks': {
      // Reverse-engineer ANY Divi module: build it once in the VB on a scratch page,
      // then `dump-blocks <id>` prints the block tree + each block's attrs (JSON) so
      // its content keys are obvious. This is how new modules get added to divi.js.
      const id = f._[0]; if (!id) die('dump-blocks needs <id>');
      const p = await jreq('GET', `${api}/pages/${id}?context=edit&_fields=content`, c);
      const raw = p.content.raw || '';
      const re = /<!--\s*(\/?)wp:(divi\/[a-z0-9-]+)/g; let m, depth = 0; const blocks = [];
      while ((m = re.exec(raw))) {
        const closing = m[1] === '/', name = m[2]; let pp = re.lastIndex, attrs = null, selfClose = false;
        while (raw[pp] === ' ') pp++;
        if (raw[pp] === '{') { let d = 0, inS = false, esc = false; const st = pp; for (; pp < raw.length; pp++) { const ch = raw[pp]; if (inS) { if (esc) esc = false; else if (ch === '\\') esc = true; else if (ch === '"') inS = false; } else { if (ch === '"') inS = true; else if (ch === '{') d++; else if (ch === '}') { d--; if (d === 0) { pp++; break; } } } } try { attrs = JSON.parse(raw.slice(st, pp)); } catch (e) { attrs = 'PARSE_ERR'; } }
        const cl = raw.indexOf('-->', pp); if (raw[cl - 1] === '/') selfClose = true;
        if (closing) { depth--; } else {
          const lbl = attrs && attrs.module && attrs.module.meta && attrs.module.meta.adminLabel && attrs.module.meta.adminLabel.desktop && attrs.module.meta.adminLabel.desktop.value;
          blocks.push({ depth, name, lbl, attrs, selfClose });
          if (!selfClose) depth++;
        }
        re.lastIndex = cl + 3;
      }
      if (f.tree) { for (const b of blocks) console.log('  '.repeat(b.depth) + b.name.replace('divi/', '') + (b.lbl ? '  «' + b.lbl + '»' : '')); }
      else { for (const b of blocks) { console.log(`\n${'  '.repeat(b.depth)}=== ${b.name}${b.lbl ? ' «' + b.lbl + '»' : ''} ===`); if (b.attrs) console.log(JSON.stringify(b.attrs, null, 1)); } }
      break;
    }
    case 'page-meta': {
      const id = f._[0]; if (!id) die('page-meta needs <id>');
      const p = await jreq('GET', `${api}/pages/${id}?context=edit&_fields=id,meta`, c);
      console.log(JSON.stringify(p.meta || {}, null, 2));
      break;
    }
    default:
      die(`unknown command: ${cmd}`);
  }
})().catch((e) => die(e.stack || String(e)));
