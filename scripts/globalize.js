#!/usr/bin/env node
/*
 * globalize.js — move an existing Divi 5 site onto its design system (global colours, presets).
 * Playbook, rules and gotchas: references/divi5-globalize-site.md. READ IT FIRST.
 *
 *   node globalize.js <site> inventory                      # what is hard-coded, how often (read-only)
 *   node globalize.js <site> signatures [--min 5]           # modules grouped by identical design = preset candidates
 *   node globalize.js <site> colors --map map.json [--write]          # colour literals -> global colour tokens
 *   node globalize.js <site> presets-build --defs defs.json           # one spec per preset + which modules it covers
 *   node globalize.js <site> presets-create [--write]                 # create/update them (mu-plugin >= 1.8)
 *   node globalize.js <site> presets-assign [--write] [--only page-12]
 *   node globalize.js <site> restore --step presets-assign [--only page-12] [--write]   # put sources back
 *   node globalize.js - warm --urls urls.json               # hit every url twice (after the LAST write, before a diff)
 *
 * Every command is a dry run unless --write is given. Sources = every published page, every Theme Builder layout,
 * every canvas. Work files go to --dir (default ./globalize-work/<site>/): backup/<step>/ is written ONCE per source
 * and never replaced, out/<step>/ holds what was (or would be) written.
 *
 * map.json   { "#ffffff": "gcid-xxxx", "#000000": "gcid-yyyy", "black": "gcid-yyyy" }
 *            "black" (optional) = the colour rgba(0,0,0,a) becomes, with an opacity setting
 * defs.json  [ { "name": "Button Yellow", "type": "button", "src": "page-6921", "nth": 1,
 *                "omit": ["module.decoration.spacing", "module.advanced.alignment"] } ]
 *            src/nth = the example module (nth module of that type in that source, as `signatures` prints it)
 *            omit    = settings that are placement, not design, and differ per module
 * urls.json  [ "https://example.com/", "https://example.com/about/" ]
 */
const fs = require('fs'); const path = require('path'); const https = require('https'); const http = require('http'); const { execFileSync } = require('child_process');
const [site, cmd, ...rest] = process.argv.slice(2);
if (!site || !cmd) { console.error('usage: node globalize.js <site> <command> [...]   (see the header of this file)'); process.exit(1); }
const flag = (k, d) => { const i = rest.indexOf('--' + k); if (i < 0) return d; const v = rest[i + 1]; return (v === undefined || v.startsWith('--')) ? true : v; };
const WRITE = !!flag('write', false); const ONLY = flag('only', '');
const WP = path.join(__dirname, 'wp.js');
const run = a => execFileSync('node', [WP, site, ...a], { encoding: 'utf8', maxBuffer: 64e6 });
const DIR = path.resolve(String(flag('dir', path.join('globalize-work', site.toLowerCase().replace(/[^a-z0-9]+/g, '-')))));
const mk = (...p) => { const d = path.join(DIR, ...p); fs.mkdirSync(d, { recursive: true }); return d; };

// ---------- shared ----------
const RE = () => /<!-- wp:(divi\/[a-z0-9-]+) (\{[\s\S]*?\}) (\/)?-->/g;
const leaves = (n, p = '', o = {}) => { if (n && typeof n === 'object' && !Array.isArray(n)) { for (const k of Object.keys(n)) leaves(n[k], p ? p + '.' + k : k, o); } else o[p] = n; return o; };
const norm = v => typeof v === 'string' ? v.trim().toLowerCase().replace(/\s+/g, '') : JSON.stringify(v);
const prune = o => { if (o && typeof o === 'object' && !Array.isArray(o)) { for (const k of Object.keys(o)) { prune(o[k]); if (o[k] && typeof o[k] === 'object' && !Array.isArray(o[k]) && !Object.keys(o[k]).length) delete o[k]; } } };
const del = (o, p) => { const ks = p.split('.'); let n = o; for (let i = 0; i < ks.length - 1; i++) { n = n[ks[i]]; if (!n) return; } delete n[ks[ks.length - 1]]; };
const set = (o, p, v) => { const ks = p.split('.'); let n = o; for (let i = 0; i < ks.length - 1; i++) { if (!n[ks[i]] || typeof n[ks[i]] !== 'object') n[ks[i]] = {}; n = n[ks[i]]; } n[ks[ks.length - 1]] = v; };
// WordPress' own serialize_block_attributes(): the form the builder and core write
const ser = a => JSON.stringify(a).replace(/--/g, '\\u002d\\u002d').replace(/</g, '\\u003c').replace(/>/g, '\\u003e').replace(/&/g, '\\u0026').replace(/\\"/g, '\\u0022');
const block = (name, a, sc) => '<!-- wp:' + name + ' ' + ser(a) + ' ' + (sc ? '/' : '') + '-->';
// An option GROUP = the path up to and including <breakpoint>.<state>. layout + sizing of one element are one group.
const BP = '(?:desktop|tablet|phone|tabletOnly|phoneWide|tabletWide|widescreen|ultraWide|desktopAbove)';
const groupOf = k => { const m = k.match(new RegExp('^(.*?\\.' + BP + '\\.(?:value|hover|sticky))(\\..+)?$')); if (!m) return null; const box = /\.decoration\.(layout|sizing)\./.test(m[1]); if (!m[2] && !box) return null; return m[1].replace(/\.decoration\.layout\./, '.decoration.sizing.'); };
// What belongs to ONE module and never to a design
const NEVER = /(^|\.)(innerContent|meta|htmlAttributes|attributes|interactions|interactionTarget|interactionTrigger|conditions|loop|css|position|zIndex|order|animation|scroll|sticky|disabledOn)(\.|$)|^module\.advanced\.link|^builderVersion|^modulePreset|^groupPreset|\.url$|\.src$/;
const DROP = new Set(['innerContent', 'meta', 'interactions', 'interactionTrigger', 'interactionTarget', 'disabledOn', 'attributes', 'htmlAttributes', 'link', 'loop', 'conditions', 'builderVersion', 'modulePreset', 'groupPreset', 'css', 'animation', 'scroll', 'sticky', 'position', 'zIndex', 'order', 'image', 'video', 'icon']);
const design = n => { if (Array.isArray(n)) return n; if (n && typeof n === 'object') { const o = {}; for (const k of Object.keys(n)) { if (DROP.has(k)) continue; const v = design(n[k]); if (v === '' || v === null || v === undefined) continue; if (typeof v === 'object' && !Array.isArray(v) && !Object.keys(v).length) continue; o[k] = v; } return o; } return n; };
const outside = s => s.replace(/<!-- wp:[a-z0-9-]+\/[a-z0-9-]+ \{[\s\S]*?\} \/?-->/g, '<!--B-->');

function sources() {
  const out = [];
  const pages = run(['list-pages']).split('\n').filter(l => l.startsWith('#')).map(l => ({ id: l.slice(1).split('\t')[0], status: l.split('\t')[1], title: l.split('\t')[2] })).filter(p => /publish/.test(p.status));
  for (const p of pages) out.push({ key: 'page-' + p.id, label: p.title, get: () => run(['get-page', p.id, '--raw']), put: f => run(['update-page', p.id, '--content-file', f]) });
  let tb = []; try { tb = run(['tb-list']).split('\n').filter(l => /^\s+\d+ \| (header|body|footer) \|/.test(l)).map(l => l.trim().split('|').map(x => x.trim())); } catch (e) { console.error('note: no Theme Builder access (mu-plugin >= 1.5 needed), layouts skipped'); }
  for (const l of tb) { let hash; out.push({ key: 'tb-' + l[0], label: l[1] + ' layout', get: () => { const tmp = path.join(mk('tmp'), 'tb-' + l[0] + '.txt'); const meta = run(['tb-get', l[0], '--out', tmp]); hash = (meta.match(/"hash": ?"([a-f0-9]+)"/) || [])[1]; return fs.readFileSync(tmp, 'utf8'); }, put: f => run(['tb-set', l[0], '--content-file', f, '--expect-hash', hash]) }); }
  let cv = []; try { cv = run(['list-canvases']).split('\n').filter(l => /^\s*\d+ \|/.test(l)).map(l => l.trim().split('|').map(x => x.trim())); } catch (e) { /* none */ }
  for (const c of cv) out.push({ key: 'canvas-' + c[0], label: c[3] || 'canvas', get: () => run(['get-canvas', c[0], '--raw']), put: f => run(['update-canvas', c[0], '--content-file', f]) });
  return ONLY ? out.filter(s => s.key === ONLY) : out;
}
// Run `transform` over every source. Backup once, refuse to touch anything outside block comments, write on --write.
function apply(step, transform, summary) {
  const bdir = mk('backup', step), odir = mk('out', step); const report = [];
  for (const s of sources()) {
    const raw = s.get(); const bf = path.join(bdir, s.key + '.txt'); if (!fs.existsSync(bf)) fs.writeFileSync(bf, raw);
    const r = transform(raw, s); const row = { key: s.key, label: s.label, blocks: r.changed }; report.push(row); if (!r.changed) continue;
    if (outside(raw) !== outside(r.out)) { row.result = 'ABORTED: text outside block comments changed'; continue; }
    const f = path.join(odir, s.key + '.html'); fs.writeFileSync(f, r.out);
    if (WRITE) { let res; try { res = s.put(f); } catch (e) { res = String(e.stderr || e.message); } row.result = /"ok": ?true/.test(res) ? 'ok' : res.replace(/\s+/g, ' ').slice(0, 160); }
  }
  const ch = report.filter(r => r.blocks); fs.writeFileSync(path.join(DIR, step + '-report.json'), JSON.stringify({ write: WRITE, report }, null, 1));
  console.log((WRITE ? 'WRITTEN' : 'DRY RUN') + ': sources ' + report.length + ' | with changes ' + ch.length + ' | blocks changed ' + ch.reduce((n, r) => n + r.blocks, 0) + (summary ? ' | ' + summary() : ''));
  const bad = ch.filter(r => r.result && r.result !== 'ok'); if (WRITE || bad.length) { console.log('write results: ok ' + ch.filter(r => r.result === 'ok').length + ' | failed ' + bad.length); bad.forEach(b => console.log('  FAILED ' + b.key + ' ' + b.result)); }
  if (WRITE && ch.length) console.log('NEXT: a no-op save of the site CSS (wp.js css-get --raw > f; wp.js css-set --file f), warm every url twice, then diff.');
}
const designSystem = () => JSON.parse(run(['design-system', '--json', '--full']));

// ---------- commands ----------
const commands = {
  inventory() {
    const isColor = s => typeof s === 'string' && /^(#[0-9a-f]{3,8}|rgba?\([^)]*\))$/i.test(s.trim());
    const nc = c => { c = c.trim().toLowerCase().replace(/\s+/g, ''); const m = c.match(/^#([0-9a-f]{3})$/); return m ? '#' + m[1].split('').map(x => x + x).join('') : c; };
    const inv = { blocks: 0, withLiterals: 0, colors: {}, roles: {}, tokens: 0, size: {}, weight: {}, family: {}, lineHeight: {}, presets: {}, byModule: {} }; const bump = (o, k) => { o[k] = (o[k] || 0) + 1; };
    const walk = (n, p, ctx) => { if (typeof n === 'string') { const k = p[p.length - 1]; if (/^\$variable\(|^gcid-|^gvid-/.test(n)) { inv.tokens++; return; } if (isColor(n) && !/^rgba?\(0,0,0,0\)$/.test(nc(n))) { bump(inv.colors, nc(n)); ctx.lit++; const role = ctx.m + ' :: ' + p.filter(x => !new RegExp('^(' + BP.slice(3, -1) + '|value|hover|sticky)$').test(x)).join('.'); (inv.roles[nc(n)] = inv.roles[nc(n)] || {}); bump(inv.roles[nc(n)], role); return; } if ((p.includes('font') || p.some(x => /Font$/.test(x))) && ['size', 'weight', 'family', 'lineHeight'].includes(k) && n !== '') { bump(inv[k], n); ctx.lit++; } return; }
      if (n && typeof n === 'object') for (const k of Object.keys(n)) { if (k === 'modulePreset') { const v = [].concat(n[k]).join(','); if (v && v !== 'default') bump(inv.presets, ctx.m + ' -> ' + v); continue; } if (k === 'innerContent' || k === 'interactions' || k === 'css') continue; walk(n[k], p.concat(k), ctx); } };
    for (const s of sources()) { let m; const re = RE(); const raw = s.get(); while ((m = re.exec(raw))) { let a; try { a = JSON.parse(m[2]); } catch (e) { continue; } const ctx = { m: m[1].replace('divi/', ''), lit: 0 }; walk(a, [], ctx); inv.blocks++; if (ctx.lit) inv.withLiterals++; const b = inv.byModule[ctx.m] = inv.byModule[ctx.m] || { blocks: 0, literals: 0 }; b.blocks++; b.literals += ctx.lit; } }
    fs.writeFileSync(path.join(mk(), 'inventory.json'), JSON.stringify(inv, null, 1));
    const top = (o, n) => Object.entries(o).sort((a, b) => b[1] - a[1]).slice(0, n).map(([k, v]) => v + 'x ' + k).join(' | ');
    console.log('blocks ' + inv.blocks + ' | with a literal colour or font value ' + inv.withLiterals + ' | global references already in use ' + inv.tokens);
    console.log('COLOURS (' + Object.keys(inv.colors).length + ' distinct): ' + top(inv.colors, 12));
    for (const [c] of Object.entries(inv.colors).sort((a, b) => b[1] - a[1]).slice(0, 6)) console.log('   ' + c + ' is used as: ' + top(inv.roles[c], 4));
    console.log('FONT weight: ' + top(inv.weight, 6) + '\nFONT size: ' + top(inv.size, 10) + '\nFONT line height: ' + top(inv.lineHeight, 6) + '\nFONT family: ' + top(inv.family, 6));
    console.log('WHERE: ' + Object.entries(inv.byModule).sort((a, b) => b[1].literals - a[1].literals).slice(0, 8).map(([k, v]) => k + ' ' + v.literals + ' in ' + v.blocks + ' blocks').join(' | '));
    console.log('PRESETS in use (non-default): ' + (top(inv.presets, 10) || 'none') + '\nfull data: ' + path.join(DIR, 'inventory.json'));
  },
  signatures() {
    const min = parseInt(flag('min', '5'), 10); const groups = {}; const raw = mk('raw'); const cl = n => { if (Array.isArray(n)) return n.map(cl); if (n && typeof n === 'object') { const o = {}; for (const k of Object.keys(n).sort()) o[k] = cl(n[k]); return o; } return typeof n === 'string' ? norm(n) : n; };
    for (const s of sources()) { const r = s.get(); fs.writeFileSync(path.join(raw, s.key + '.txt'), r); let m; const re = RE(); const seen = {}; while ((m = re.exec(r))) { let a; try { a = JSON.parse(m[2]); } catch (e) { continue; } const t = m[1].replace('divi/', ''); seen[t] = (seen[t] || 0) + 1; const sig = JSON.stringify(cl(design(a))); if (sig === '{}') continue; const g = groups[t + ' :: ' + sig] = groups[t + ' :: ' + sig] || { type: t, n: 0, ex: [], sig }; g.n++; if (g.ex.length < 3) g.ex.push({ src: s.key, nth: seen[t], label: s.label }); } }
    const all = Object.values(groups).sort((a, b) => b.n - a.n); fs.writeFileSync(path.join(DIR, 'signatures.json'), JSON.stringify(all, null, 1));
    const types = {}; all.forEach(g => { (types[g.type] = types[g.type] || { n: 0, v: 0 }); types[g.type].n += g.n; types[g.type].v++; });
    console.log('modules with design settings, by type: ' + Object.entries(types).sort((a, b) => b[1].n - a[1].n).map(([k, v]) => k + ' ' + v.n + ' (' + v.v + ' variants)').join(' | '));
    const tok = s => s.replace(/\$variable\(\{\\"type\\":\\"color\\",\\"value\\":\{\\"name\\":\\"(gcid-[a-z0-9-]+)\\",\\"settings\\":\{(.*?)\}\}\}\)\$/g, (x, n, st) => n + (st ? '(' + st.replace(/\\"/g, '') + ')' : ''));
    for (const g of all.filter(x => x.n >= min)) console.log(g.n + 'x ' + g.type + ' | example {"src":"' + g.ex[0].src + '","nth":' + g.ex[0].nth + '} (' + g.ex[0].label + ')\n     ' + tok(g.sig).replace(/"desktop":\{"value":/g, 'D:{').replace(/"/g, '').slice(0, 300));
    console.log('raw sources cached in ' + raw + ' (presets-build reads them; re-run `signatures` after any write)');
  },
  colors() {
    const mf = flag('map'); if (!mf || mf === true) { console.error('colors needs --map map.json'); process.exit(1); } const MAP = {}; let BLACK = null;
    for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(mf, 'utf8')))) { if (k === 'black') BLACK = v; else MAP[k.trim().toLowerCase()] = v; }
    const have = designSystem().colors || {}; const builtin = ['gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color'];
    const missing = [...new Set(Object.values(MAP).concat(BLACK || []))].filter(id => !have[id] && !builtin.includes(id)); if (missing.length) { console.error('STOP: not on the site: ' + missing.join(', ') + '. A reference to a colour that does not exist fails silently.'); process.exit(1); }
    const token = (name, st) => '$variable(' + JSON.stringify({ type: 'color', value: { name, settings: st || {} } }) + ')$';
    const nc = c => { c = c.trim().toLowerCase().replace(/\s+/g, ''); const m = c.match(/^#([0-9a-f]{3})$/); return m ? '#' + m[1].split('').map(x => x + x).join('') : c; };
    const conv = s => { const c = nc(s); if (MAP[c]) return token(MAP[c]); const m = BLACK && c.match(/^rgba\(0,0,0,(0?\.\d+|1|0)\)$/); if (m) { const a = parseFloat(m[1]); if (a <= 0) return null; return a >= 1 ? token(BLACK) : token(BLACK, { opacity: Math.round(a * 100) }); } return null; };
    // left alone on purpose: Custom CSS, gradient stops, links, content, a global layout's local overrides, non-Divi blocks
    // (`link` is skipped only as the module's own link, module.advanced.link: a font group's `link` holds the colour of links)
    const SKIP = new Set(['css', 'freeForm', 'mainElement', 'before', 'after', 'gradient', 'localAttrs', 'innerContent', 'interactions', 'attributes', 'htmlAttributes', 'loop']); const st = {};
    const walk = (n, p, ctx) => { if (!n || typeof n !== 'object') return; for (const k of Object.keys(n)) { if (SKIP.has(k) || (k === 'link' && p[p.length - 1] === 'advanced')) continue; const v = n[k]; if (typeof v === 'string') { const isC = /color$/i.test(k) || (/^(value|hover|sticky)$/.test(k) && (/color$/i.test(p[p.length - 2] || '') || /color$/i.test(p[p.length - 1] || ''))); if (isC) { const t = conv(v); if (t) { n[k] = t; ctx.n++; st[nc(v)] = (st[nc(v)] || 0) + 1; } } } else walk(v, p.concat(k), ctx); } };
    apply('colors', raw => { let changed = 0; const out = raw.replace(RE(), (m, name, json, sc) => { let a; try { a = JSON.parse(json); } catch (e) { return m; } const ctx = { n: 0 }; walk(a, [], ctx); if (!ctx.n) return m; changed++; return block(name, a, sc); }); return { out, changed }; },
      () => 'colour values -> global: ' + Object.values(st).reduce((a, b) => a + b, 0) + ' (' + Object.entries(st).sort((a, b) => b[1] - a[1]).map(([k, v]) => v + 'x ' + k).join(', ') + ')');
  },
  'presets-build'() {
    const df = flag('defs'); if (!df || df === true) { console.error('presets-build needs --defs defs.json'); process.exit(1); } const defs = JSON.parse(fs.readFileSync(df, 'utf8')); const rawDir = path.join(DIR, 'raw');
    if (!fs.existsSync(rawDir)) { console.error('run `signatures` first: it caches the sources presets-build reads'); process.exit(1); }
    const all = {}; for (const f of fs.readdirSync(rawDir)) { const list = []; const seen = {}; let m; const re = RE(); const r = fs.readFileSync(path.join(rawDir, f), 'utf8'); while ((m = re.exec(r))) { let a; try { a = JSON.parse(m[2]); } catch (e) { continue; } const t = m[1].replace('divi/', ''); seen[t] = (seen[t] || 0) + 1; list.push({ type: t, nth: seen[t], attrs: a }); } all[f.replace('.txt', '')] = list; }
    const pd = mk('presets'); const ps = [];
    for (const d of defs) { const b = (all[d.src] || []).find(x => x.type === d.type && x.nth === d.nth); if (!b) { console.log('MISSING example for ' + d.name + ' (' + d.src + ' ' + d.type + ' #' + d.nth + ')'); continue; } const a = design(b.attrs); (d.omit || []).forEach(p => del(a, p)); prune(a); const lv = leaves(a); fs.writeFileSync(path.join(pd, d.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.json'), JSON.stringify({ moduleName: 'divi/' + d.type, name: d.name, attrs: a }, null, 1)); ps.push({ name: d.name, type: d.type, lv, n: Object.keys(lv).length, groups: new Set(Object.keys(lv).map(groupOf).filter(Boolean)), fits: 0, split: 0 }); }
    // A setting that EVERY module matching a preset carries, inside a group the preset touches, joins the preset.
    // Without it the preset would split that group on every one of them (a button's `icon.enable: off`).
    for (const p of ps) { const ms = []; for (const list of Object.values(all)) for (const b of list) { if (b.type !== p.type) continue; const lv = leaves(b.attrs); if (Object.keys(p.lv).every(k => k in lv && norm(lv[k]) === norm(p.lv[k]))) ms.push(lv); }
      if (!ms.length) continue; const add = Object.entries(ms[0]).filter(([k, v]) => !(k in p.lv) && v !== '' && !NEVER.test(k) && p.groups.has(groupOf(k)) && ms.every(lv => k in lv && norm(lv[k]) === norm(v)));
      if (!add.length) continue; const f = path.join(pd, p.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.json'); const spec = JSON.parse(fs.readFileSync(f, 'utf8'));
      add.forEach(([k, v]) => { set(spec.attrs, k, v); p.lv[k] = v; }); p.n = Object.keys(p.lv).length; fs.writeFileSync(f, JSON.stringify(spec, null, 1)); console.log('+ ' + p.name + ': shared by all ' + ms.length + ' matching modules, added to the preset: ' + add.map(([k, v]) => k.replace(/\.desktop\.value/, '') + '=' + String(v).slice(0, 30)).join('; ')); }
    let total = 0, none = 0;
    for (const list of Object.values(all)) for (const b of list) { if (!ps.some(p => p.type === b.type)) continue; total++; const lv = leaves(b.attrs); let best = null;
      for (const p of ps) { if (p.type !== b.type || !Object.keys(p.lv).every(k => k in lv && norm(lv[k]) === norm(p.lv[k]))) continue; if (Object.entries(lv).some(([k, v]) => v !== '' && !(k in p.lv) && !NEVER.test(k) && p.groups.has(groupOf(k)))) { p.split++; continue; } if (!best || p.n > best.n) best = p; }
      if (best) best.fits++; else none++; }
    console.log('preset | module | settings | modules it fits | matched but would SPLIT a group (not assigned)');
    ps.forEach(p => console.log(p.name + ' | ' + p.type + ' | ' + p.n + ' | ' + p.fits + ' | ' + p.split));
    console.log('fits ' + ps.reduce((n, p) => n + p.fits, 0) + ' of ' + total + ' modules of these types; no preset fits ' + none + '\nspecs in ' + pd + '. A high "would split" count = make a second, fuller preset for that cluster.');
  },
  'presets-create'() {
    const pd = path.join(DIR, 'presets'); const tmp = mk('tmp'); const ds = designSystem(); const known = {}; ds.presets.module.forEach(p => known[p.for + '|' + p.name] = p.id); const taken = new Set(ds.presets.module.concat(ds.presets.group).map(p => p.id));
    const newId = () => { let id; do { id = Array.from({ length: 10 }, () => 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)]).join(''); } while (taken.has(id)); taken.add(id); return id; }; // the builder's own id format
    const ids = {}; let bad = 0; const n = o => Object.keys(leaves(o || {})).length;
    for (const f of fs.readdirSync(pd).filter(x => x.endsWith('.json')).sort()) { const spec = JSON.parse(fs.readFileSync(path.join(pd, f), 'utf8')); if (!known[spec.moduleName + '|' + spec.name]) spec.id = newId(); const file = path.join(tmp, f); fs.writeFileSync(file, JSON.stringify(spec));
      let r; try { r = JSON.parse(run(['ds-preset-set', '--file', file].concat(WRITE ? [] : ['--dry-run']))); } catch (e) { bad++; console.log('FAILED ' + spec.name + ' | ' + String(e.stderr || e.message).replace(/\s+/g, ' ').slice(0, 200)); continue; }
      ids[spec.name] = { id: r.id, module: spec.moduleName }; console.log((r.dryRun ? 'DRY ' : '') + r.action + ' | ' + r.id + ' | ' + spec.moduleName + ' | ' + spec.name + ' | settings ' + n(r.item.attrs) + ((r.warnings || []).length ? ' | WARN ' + r.warnings.join('; ') : '') + ((r.strippedContent || []).length ? ' | content removed ' + r.strippedContent.length : '') + (r.defaultCreated ? ' | default created ' + r.defaultCreated : '')); }
    if (WRITE && !bad) fs.writeFileSync(path.join(DIR, 'presets-ids.json'), JSON.stringify(ids, null, 1));
    console.log(bad ? bad + ' FAILED' : (WRITE ? 'all written; ids in ' + path.join(DIR, 'presets-ids.json') : 'dry run clean'));
  },
  'presets-assign'() {
    const mine = JSON.parse(fs.readFileSync(path.join(DIR, 'presets-ids.json'), 'utf8')); const ds = designSystem(); const defaults = {}; const ps = [];
    for (const p of ds.presets.module) { if (p.isDefault) { defaults[p.for] = { id: p.id, has: !!(p.attrs && Object.keys(p.attrs).length) }; continue; } if (mine[p.name] && mine[p.name].id === p.id) { const lv = leaves(p.attrs || {}); ps.push({ id: p.id, name: p.name, type: p.for, lv, n: Object.keys(lv).length, groups: new Set(Object.keys(lv).map(groupOf).filter(Boolean)) }); } }
    const isDef = (x, t) => x === '' || x === 'default' || x === '_initial' || (defaults[t] && defaults[t].id === x); const stack = (t, id) => (defaults[t] && defaults[t].has) ? [defaults[t].id, id] : [id];
    const count = {}; let none = 0, other = 0, split = 0, total = 0;
    apply('presets-assign', raw => { let changed = 0; const out = raw.replace(RE(), (m, type, json, sc) => { if (!ps.some(p => p.type === type)) return m; let a; try { a = JSON.parse(json); } catch (e) { return m; } total++;
      if ([].concat(a.modulePreset || []).some(x => !isDef(x, type))) { other++; return m; } const lv = leaves(a); let best = null, wouldSplit = false;
      for (const p of ps) { if (p.type !== type || !p.n || !Object.keys(p.lv).every(k => k in lv && norm(lv[k]) === norm(p.lv[k]))) continue; if (Object.entries(lv).some(([k, v]) => v !== '' && !(k in p.lv) && !NEVER.test(k) && p.groups.has(groupOf(k)))) { wouldSplit = true; continue; } if (!best || p.n > best.n) best = p; }
      if (!best) { if (wouldSplit) split++; else none++; return m; } Object.keys(best.lv).forEach(k => del(a, k)); prune(a); a.modulePreset = stack(type, best.id); changed++; count[best.name] = (count[best.name] || 0) + 1; return block(type, a, sc); }); return { out, changed }; },
      () => 'modules of these types ' + total + ' | no preset fits ' + none + ' | matched but would split a group ' + split + ' | already on a preset ' + other + '\nper preset: ' + Object.entries(count).sort((a, b) => b[1] - a[1]).map(([k, v]) => k + ' ' + v).join(' | ') + '\nstacked on the default preset: ' + (Object.entries(defaults).filter(([, d]) => d.has).map(([k, d]) => k + ' ' + d.id).join(', ') || 'none (no default preset holds settings)'));
  },
  restore() {
    const step = flag('step'); if (!step || step === true) { console.error('restore needs --step <colors|presets-assign>'); process.exit(1); } const bdir = path.join(DIR, 'backup', step); if (!fs.existsSync(bdir)) { console.error('no backups for step ' + step + ' in ' + bdir); process.exit(1); } let n = 0, ok = 0;
    for (const s of sources()) { const bf = path.join(bdir, s.key + '.txt'); if (!fs.existsSync(bf)) continue; const was = fs.readFileSync(bf, 'utf8'); if (was === s.get()) continue; n++; console.log((WRITE ? 'restoring ' : 'would restore ') + s.key + ' (' + s.label + ')'); if (WRITE) { let res; try { res = s.put(bf); } catch (e) { res = String(e.stderr || e.message); } if (/"ok": ?true/.test(res)) ok++; else console.log('  FAILED ' + res.replace(/\s+/g, ' ').slice(0, 160)); } }
    console.log((WRITE ? 'restored ' + ok + ' of ' + n : n + ' source(s) differ from their backup') + '. Note: this puts back the WHOLE source as it was before step "' + step + '", including anything edited since.');
  },
  async warm() {
    const uf = flag('urls'); if (!uf || uf === true) { console.error('warm needs --urls urls.json'); process.exit(1); } const urls = JSON.parse(fs.readFileSync(uf, 'utf8'));
    const get = u => new Promise(res => { const t = Date.now(); (u.startsWith('http:') ? http : https).get(u, { headers: { 'User-Agent': 'Mozilla/5.0 divi5-builder-warm' } }, r => { r.on('data', () => {}); r.on('end', () => res({ u, s: r.statusCode, ms: Date.now() - t })); }).on('error', e => res({ u, s: 'ERR ' + e.message, ms: 0 })); });
    for (let pass = 1; pass <= 2; pass++) { const out = []; for (let i = 0; i < urls.length; i += 4) out.push(...await Promise.all(urls.slice(i, i + 4).map(get))); const bad = out.filter(o => o.s !== 200); console.log('pass ' + pass + ': ' + out.length + ' urls, avg ' + Math.round(out.reduce((n, o) => n + o.ms, 0) / out.length) + ' ms, slowest ' + Math.max(...out.map(o => o.ms)) + ' ms, not 200: ' + (bad.map(o => o.u + ' ' + o.s).join(', ') || 'none')); }
  },
};
if (!commands[cmd]) { console.error('unknown command "' + cmd + '". Commands: ' + Object.keys(commands).join(', ')); process.exit(1); }
Promise.resolve(commands[cmd]()).catch(e => { console.error('ERROR: ' + String(e.stderr || e.stack || e)); process.exit(1); });
