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
        _et_builder_version: f.version || 'VB|Divi|5.9.0',
        _et_pb_built_for_post_type: 'page',
      };
      const p = await jreq('POST', `${api}/pages/${id}`, c, { meta });
      const got = p.meta || {};
      const ok = got._et_pb_use_builder === (on ? 'on' : 'off');
      console.log(JSON.stringify({ ok, id: p.id, wrote: meta, readback: {
        _et_pb_use_builder: got._et_pb_use_builder, _et_pb_page_layout: got._et_pb_page_layout } }));
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
