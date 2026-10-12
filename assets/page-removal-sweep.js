#!/usr/bin/env node
// Before removing a page from a Divi 5 site: SNAPSHOT it and find everything that points at it. Changes nothing.
//   node page-removal-sweep.js <site> <page-id> [--out <dir>]
// Writes into --out (default ./page-removal-<id>-<date>/):
//   page-<id>-edit.json / -content-raw.txt / -meta.json / -rendered.html     the page itself
//   layout-<id>.txt (+ -head.json)   every Theme Builder layout of a template that targets this page (_et_use_on)
//   tb-list.json, menus.json, canvases.txt, references.json                    and a printed summary
// Sweeps: all Theme Builder layouts, every WordPress menu (hidden locations included), all REST post types,
// every canvas, the site CSS. Not reachable over REST: WPCode snippets, widgets, Customizer text, Divi Library items.
// Afterwards the usual split: the tool trashes the page (`wp.js delete-page <id>`, no --force), the owner deletes
// the trash, Theme Builder templates, menus and media. A template trashed in the Theme Builder stays in the
// database, detached and marked `_et_theme_builder_marked_as_unused`, until Divi trashes it 7 days later.
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const argv = process.argv.slice(2); const SITE = argv[0]; const PAGE = +argv[1];
if (!SITE || !PAGE) { console.error('usage: node page-removal-sweep.js <site> <page-id> [--out <dir>]'); process.exit(1); }
const oi = argv.indexOf('--out'); const OUT = oi > 0 ? argv[oi + 1] : path.join(process.cwd(), `page-removal-${PAGE}-${new Date().toISOString().slice(0, 10)}`);
fs.mkdirSync(OUT, { recursive: true });
const WP = path.join(__dirname, '..', 'scripts', 'wp.js');
const run = (...a) => execFileSync('node', [WP, SITE, ...a], { encoding: 'utf8', maxBuffer: 1 << 27, timeout: 120000 });
const F = n => path.join(OUT, n); const J = s => { try { return JSON.parse(s); } catch (e) { return null; } };
const body = r => (r && r.body !== undefined ? r.body : r);
const get = route => { try { return body(J(run('rest-get', route))); } catch (e) { return null; } };   // a 404 route (types without content) is just skipped

// 1. the page
const pg = get(`wp/v2/pages/${PAGE}?context=edit`); if (!pg || !pg.id) throw new Error('page not readable: ' + PAGE);
fs.writeFileSync(F(`page-${PAGE}-edit.json`), JSON.stringify(pg, null, 1));
fs.writeFileSync(F(`page-${PAGE}-content-raw.txt`), pg.content.raw);
fs.writeFileSync(F(`page-${PAGE}-meta.json`), run('page-meta', String(PAGE)));
fs.writeFileSync(F(`page-${PAGE}-rendered.html`), run('rendered', String(PAGE)));
const slug = pg.slug, title = pg.title.raw || pg.title.rendered;
console.log(`page ${PAGE} "${title}" /${slug}/ ${pg.status}, ${pg.content.raw.length} bytes, featured media ${pg.featured_media}`);
const media = Array.from(new Set((pg.content.raw.match(/https?:\/\/[^"\\\s]+\/wp-content\/uploads\/[^"\\\s]+/g) || []).map(u => u.replace(/-\d+x\d+(\.\w+)$/, '$1'))));
if (pg.featured_media) media.push('featured media id ' + pg.featured_media);

// what counts as a reference
const refs = [];
const pats = [new RegExp('/' + slug.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/?(["\\\\?#\\s]|$)', 'gi'), new RegExp(title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), new RegExp('[?&]p(age_id)?=' + PAGE + '\\b', 'g'), new RegExp('"' + PAGE + '"', 'g')];
const findRefs = (where, text) => { if (!text) return; const hits = []; pats.forEach((re, i) => { const m = String(text).match(re); if (m) hits.push(['slug', 'title', 'p=', 'id'][i] + ' x' + m.length); }); if (hits.length) refs.push({ where, hits }); };

// 2. Theme Builder: templates that target the page, their layouts; then every other layout
const tb = J(run('tb-list', '--json')); fs.writeFileSync(F('tb-list.json'), JSON.stringify(tb, null, 1));
const templates = tb.items.filter(x => x.type === 'et_template');
const mine = templates.filter(t => (t.meta._et_use_on || []).some(u => new RegExp(':id:' + PAGE + '$').test(u)));
const layoutKeys = ['_et_header_layout_id', '_et_body_layout_id', '_et_footer_layout_id'];
const ownLayouts = new Set();
for (const t of mine) {
  console.log(`template ${t.id} "${t.title}" targets this page (use_on ${JSON.stringify(t.meta._et_use_on)})`);
  for (const k of layoutKeys) { const id = +(t.meta[k] && t.meta[k][0]); if (!id) continue; const others = templates.filter(o => o.id !== t.id && layoutKeys.some(kk => +(o.meta[kk] && o.meta[kk][0]) === id)); console.log(`  ${k.replace(/^_et_|_layout_id$/g, '')} layout ${id}` + (others.length ? ` SHARED with ${others.length} other template(s): ${others.map(o => o.id).join(', ')} (keep)` : ' (only this template: goes with it)')); if (!others.length) ownLayouts.add(id); }
}
for (const l of tb.items.filter(x => /_layout$/.test(x.type))) {
  const out = ownLayouts.has(l.id) ? F(`layout-${l.id}.txt`) : F('tmp-layout.txt');
  const head = run('tb-get', String(l.id), '--quiet', '--out', out); if (ownLayouts.has(l.id)) fs.writeFileSync(F(`layout-${l.id}-head.json`), head);
  findRefs(`TB layout ${l.id} (${l.type.replace(/^et_|_layout$/g, '')}, ${l.status}, "${l.title}")`, fs.readFileSync(out, 'utf8'));
}
try { fs.unlinkSync(F('tmp-layout.txt')); } catch (e) {}

// 3. menus: every menu, every item (hidden locations included)
const menus = get('wp/v2/menus?per_page=100') || []; const menuOut = [];
for (const m of menus) {
  const items = get(`wp/v2/menu-items?menus=${m.id}&per_page=100&context=edit`) || [];
  menuOut.push({ id: m.id, name: m.name, locations: m.locations, items: items.map(i => ({ id: i.id, title: i.title.raw || i.title.rendered, url: i.url, object: i.object, object_id: i.object_id, target: i.target })) });
  for (const i of items) { const t = (i.title.raw || i.title.rendered) + ' ' + i.url; if ((i.object === 'page' && i.object_id === PAGE)) refs.push({ where: `menu ${m.id} "${m.name}" item ${i.id} (page link)`, hits: ['object_id'] }); else findRefs(`menu ${m.id} "${m.name}" item ${i.id} "${i.title.raw || i.title.rendered}"`, t); }
  if (m.name && pats[1].test(m.name) && !(pats[1].lastIndex = 0)) refs.push({ where: `menu ${m.id} "${m.name}" is NAMED after the page (used by a layout? see menuId in the layouts)`, hits: ['menu name'] });
}
fs.writeFileSync(F('menus.json'), JSON.stringify(menuOut, null, 1));

// 4. content of every REST post type (edit context so drafts count)
const types = get('wp/v2/types') || {};
for (const t of Object.values(types)) {
  const rb = t.rest_base; if (!rb || /[()<]/.test(rb) || ['media', 'blocks', 'templates', 'template-parts', 'navigation', 'global-styles', 'font-families', 'menu-items'].includes(rb)) continue;
  for (const st of ['publish', 'draft,pending,private,future']) {
    let p = 1;
    for (;;) {
      const list = get(`wp/v2/${rb}?per_page=100&page=${p}&status=${st}&context=edit&_fields=id,title,slug,status,content,excerpt`);
      if (!Array.isArray(list) || !list.length) break;
      for (const it of list) { if (rb === 'pages' && it.id === PAGE) continue; findRefs(`${rb} ${it.id} ${it.status} /${it.slug}/ "${it.title && (it.title.raw || it.title.rendered)}"`, (it.content && it.content.raw) + ' ' + (it.excerpt && it.excerpt.raw)); }
      if (list.length < 100) break; p++;
    }
  }
}

// 5. canvases (popups) and the site CSS
const canv = run('list-canvases'); fs.writeFileSync(F('canvases.txt'), canv);
for (const line of canv.split('\n')) { const m = line.match(/^(\d+)\s*\|\s*\S+\s*\|[^|]*\|\s*(.*)$/); if (!m) continue; try { run('get-canvas', m[1], '--out', F('tmp-canvas.txt')); findRefs(`canvas ${m[1]} "${m[2].trim()}"`, fs.readFileSync(F('tmp-canvas.txt'), 'utf8')); } catch (e) { console.log('canvas', m[1], 'not read:', String(e.message).slice(0, 80)); } }
try { fs.unlinkSync(F('tmp-canvas.txt')); } catch (e) {}
findRefs('site CSS (css-get)', run('css-get'));

fs.writeFileSync(F('references.json'), JSON.stringify(refs, null, 1));
console.log('\nREFERENCES TO THE PAGE (' + refs.length + ')'); for (const r of refs) console.log(' -', r.where, '|', r.hits.join(', '));
console.log('\nMEDIA ON THE PAGE (check other users before deleting):'); for (const m of media) console.log(' -', m);
console.log('\nOWNER DELETES (the tool only trashes the page): the trash, template(s) ' + (mine.map(t => t.id).join(', ') || 'none') + ' with layout(s) ' + ([...ownLayouts].join(', ') || 'none') + ', any menu named after the page, the media above.');
console.log('NOT SWEPT (no REST): WPCode snippets, widgets, Customizer text, Divi Library items. Snapshots in', OUT);
