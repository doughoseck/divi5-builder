#!/usr/bin/env node
/**
 * Module catalogue: every Divi 5 module, read from YOUR OWN copy of the Divi theme.
 *
 *   node scripts/catalog.js build <path-to-Divi-theme-folder>   -> writes catalog/catalog.json (+ catalog/VERSION)
 *   node scripts/catalog.js list [filter]                        -> every module: block name, kind, children
 *   node scripts/catalog.js show <module>                        -> spec sheet: elements, fields, options, defaults
 *   node scripts/catalog.js find <text>                          -> which modules have a field/option matching text
 *   node scripts/catalog.js lint <content.html>                  -> check block markup against the catalogue
 *
 * Divi 5 ships a module.json per module (the same definition the Visual Builder is built from) plus the attribute
 * values each module renders with by default. This reads them and keeps only what is needed to WRITE a module:
 * which elements it has, which attribute paths exist under each, what options a field accepts, what the defaults are,
 * and which child blocks it may contain.
 *
 * The catalogue is derived from Divi (GPL) and is NOT shipped with this skill: it is generated locally, per Divi
 * version, from the copy your licence gives you. catalog/ is git-ignored.
 */
const fs = require('fs'), path = require('path');
const OUT_DIR = path.join(__dirname, '..', 'catalog'), OUT = path.join(OUT_DIR, 'catalog.json');
const die = (m) => { console.error('ERROR: ' + m); process.exit(1); };

// ---------- build ----------
function findModuleJsons(root) {
  const hits = []; const skip = new Set(['node_modules', '.git', 'languages', 'images', 'fonts']);
  (function walk(d, depth) { if (depth > 12) return; let ents; try { ents = fs.readdirSync(d, { withFileTypes: true }); } catch (e) { return; }
    for (const e of ents) { if (e.isDirectory()) { if (!skip.has(e.name)) walk(path.join(d, e.name), depth + 1); } else if (e.name === 'module.json') hits.push(path.join(d, e.name)); } })(root, 0);
  return hits;
}
// An EMPTY option list means the builder fills it at run time (menus, post types, sidebars): that is "unknown", not "nothing allowed".
const optionKeys = (o) => { const k = (o && typeof o === 'object' && !Array.isArray(o)) ? Object.keys(o) : (Array.isArray(o) ? o.map(x => (x && (x.value || x.name)) || String(x)) : []); return k.length ? k : undefined; };

// Walk an element's `settings.<section>` tree and collect fields. A field is any node carrying `item`, or a leaf keyed
// by a decoration group name. Path rules, as the builder uses them: item.attrName is the full attribute path; otherwise
// the path is <element>.<section> and item.subName is a KEY INSIDE that attribute's value.
function collectFields(element, section, node, out, trail) {
  if (!node || typeof node !== 'object') return;
  if (node.item && typeof node.item === 'object') {
    const it = node.item, comp = it.component || {}, props = comp.props || {};
    const f = { path: it.attrName || `${element}.${section}`, key: it.subName || undefined, label: it.label || props.fieldLabel || undefined,
      component: comp.name || undefined, options: optionKeys(props.options), group: trail.join('/') || undefined };
    if (it.features) { const ft = it.features; f.features = Object.keys(ft).filter(k => ft[k] && ft[k] !== false).join(',') || undefined; if (ft.dynamicContent) f.dynamic = ft.dynamicContent.type || true; }
    if (it.render === false) f.hidden = true;
    out.push(JSON.parse(JSON.stringify(f)));
    if (props.fields && typeof props.fields === 'object') for (const [k, v] of Object.entries(props.fields)) if (v && typeof v === 'object' && (v.options || v.label || v.component)) out.push(JSON.parse(JSON.stringify({ path: f.path, key: (v.subName || k), label: v.label, component: v.component && v.component.name, options: optionKeys(v.options || (v.component && v.component.props && v.component.props.options)), group: trail.join('/') || undefined })));
  }
  if (node.groups && typeof node.groups === 'object') for (const [k, v] of Object.entries(node.groups)) collectFields(element, section, v, out, trail.concat(k));
}
function summariseModule(file) {
  const m = JSON.parse(fs.readFileSync(file, 'utf8')); if (!m.name || !m.attributes) return null; const dir = path.dirname(file);
  const readJ = (n) => { try { const j = JSON.parse(fs.readFileSync(path.join(dir, n), 'utf8')); delete j._comment; return j; } catch (e) { return undefined; } };
  const elements = {};
  for (const [el, def] of Object.entries(m.attributes)) {
    if (!def || typeof def !== 'object') continue; const s = def.settings || {}; const e = { selector: def.selector || undefined, fields: [], decoration: [], advanced: [] };
    for (const [section, tree] of Object.entries(s)) {
      if (!tree || typeof tree !== 'object') continue;
      if (section === 'decoration') { e.decoration = Object.keys(tree); for (const [g, v] of Object.entries(tree)) collectFields(el, 'decoration.' + g, v, e.fields, [g]); continue; }
      if (section === 'advanced') { e.advanced = Object.keys(tree); for (const [g, v] of Object.entries(tree)) { const before = e.fields.length; collectFields(el, 'advanced.' + g, v, e.fields, [g]); if (e.fields.length === before) e.fields.push({ path: `${el}.advanced.${g}`, inherited: true }); } continue; }
      const before = e.fields.length; collectFields(el, section, tree, e.fields, []); if (e.fields.length === before) e.fields.push({ path: `${el}.${section}`, inherited: true });
    }
    elements[el] = e;
  }
  // module.json's `attributes` is NOT the full list of elements: divi/video never declares `thumbnail`, yet Divi's own PHP
  // reads attrs.thumbnail and its Divi 4 conversion map writes to it. So elements are also learned from that map and
  // from the default attributes. These extra ones carry no field detail, they are only known to exist.
  const d4 = readJ('conversion-outline.json'), defs = readJ('module-default-render-attributes.json'), extra = new Set();
  (function paths(o) { if (typeof o === 'string') { const head = o.split('.')[0]; if (/^[a-z][A-Za-z0-9]*\.(innerContent|decoration|advanced|meta)\b/.test(o)) extra.add(head); } /* only real attribute paths: the generated maps also hold bare Divi 4 names and "name.*" stubs */ else if (o && typeof o === 'object') Object.values(o).forEach(paths); })(d4 && { a: d4.advanced, m: d4.module });
  for (const k of Object.keys(defs || {})) extra.add(k);
  for (const k of extra) if (!elements[k] && !['css'].includes(k)) elements[k] = { undeclared: true, fields: [], decoration: [], advanced: [] };
  return { name: m.name, title: m.title, category: m.category, description: m.description, d4Shortcode: m.d4Shortcode || undefined,
    className: m.moduleClassName, children: (m.childrenName || []).length ? m.childrenName : undefined, elements,
    defaults: readJ('module-default-render-attributes.json'), printedStyleDefaults: readJ('module-default-printed-style-attributes.json'),
    d4Map: readJ('conversion-outline.json'), customCss: m.customCssFields ? Object.keys(m.customCssFields) : undefined, source: undefined };
}
function build(root) {
  if (!root || !fs.existsSync(root)) die('give the path to an unzipped Divi theme folder');
  const css = path.join(root, 'style.css'); const ver = fs.existsSync(css) ? ((fs.readFileSync(css, 'utf8').match(/^Version:\s*(.+)$/m) || [])[1] || '').trim() : '';
  if (!ver) die(`no Divi style.css with a Version under ${root}. Point at the folder that holds style.css.`);
  const files = findModuleJsons(root); const modules = {}; const dupes = [];
  for (const f of files) { let s; try { s = summariseModule(f); } catch (e) { console.error('skip ' + f + ': ' + e.message); continue; } if (!s) continue;
    s.source = path.relative(root, f).replace(/\\/g, '/'); if (modules[s.name]) dupes.push(s.name); modules[s.name] = s; }
  const parents = {}; for (const m of Object.values(modules)) for (const c of (m.children || [])) (parents[c] = parents[c] || []).push(m.name);
  for (const [c, ps] of Object.entries(parents)) if (modules[c]) modules[c].parents = ps;
  fs.mkdirSync(OUT_DIR, { recursive: true }); fs.writeFileSync(OUT, JSON.stringify({ diviVersion: ver, builtAt: new Date().toISOString(), count: Object.keys(modules).length, modules }));
  fs.writeFileSync(path.join(OUT_DIR, 'VERSION'), ver + '\n');
  console.log(`Divi ${ver}: ${Object.keys(modules).length} modules from ${files.length} module.json files -> ${OUT} (${Math.round(fs.statSync(OUT).size / 1024)} KB)` + (dupes.length ? `\nnote: defined more than once, last one kept: ${[...new Set(dupes)].join(', ')}` : ''));
}

// ---------- read ----------
function load() { if (!fs.existsSync(OUT)) die('no catalogue yet. Run: node scripts/catalog.js build <path-to-Divi-theme-folder>'); return JSON.parse(fs.readFileSync(OUT, 'utf8')); }
const resolve = (cat, q) => { const n = q.startsWith('divi/') ? q : 'divi/' + q; if (cat.modules[n]) return cat.modules[n]; const hit = Object.keys(cat.modules).filter(k => k.includes(q)); if (hit.length === 1) return cat.modules[hit[0]]; die(hit.length ? `"${q}" matches several: ${hit.join(', ')}` : `no module matching "${q}". Try: catalog.js list`); };
function list(filter) { const cat = load(); console.log(`Divi ${cat.diviVersion}, ${cat.count} modules`);
  const by = {}; for (const m of Object.values(cat.modules)) if (!filter || JSON.stringify([m.name, m.title, m.category]).toLowerCase().includes(filter.toLowerCase())) (by[m.category || '?'] = by[m.category || '?'] || []).push(m);
  for (const [c, ms] of Object.entries(by)) { console.log(`\n${c} (${ms.length})`); for (const m of ms.sort((a, b) => a.name < b.name ? -1 : 1)) console.log(`  ${m.name}  "${m.title}"` + (m.children ? `  children: ${m.children.join(', ')}` : '') + (m.parents ? `  inside: ${m.parents.join(', ')}` : '')); } }
function flat(o, p, out) { if (o && typeof o === 'object' && !Array.isArray(o)) { for (const k of Object.keys(o)) flat(o[k], p ? p + '.' + k : k, out); } else out.push(`${p} = ${JSON.stringify(o)}`); return out; }
function show(q, opts) { const cat = load(), m = resolve(cat, q);
  console.log(`${m.name}  "${m.title}"  [${m.category}]  Divi ${cat.diviVersion}\n${m.description || ''}`);
  console.log(`css class: ${m.className || '-'}   divi 4 shortcode: ${m.d4Shortcode || '-'}`);
  console.log(m.children ? `CONTAINER: write as open + children + close. Children: ${m.children.join(', ')}` : (m.parents ? `CHILD of: ${m.parents.join(', ')}` : 'children: none declared (check dump-blocks before assuming it is a leaf)'));
  const d4paths = []; (function p(o) { if (typeof o === 'string') d4paths.push(o); else if (o && typeof o === 'object') Object.values(o).forEach(p); })(m.d4Map);
  for (const [el, e] of Object.entries(m.elements)) { console.log(`\n== element "${el}"` + (e.selector ? `   selector ${e.selector}` : '') + (e.undeclared ? '   (not declared in module.json; known from Divi\'s conversion map / defaults)' : ''));
    if (e.undeclared) { [...new Set(d4paths.filter(x => x.startsWith(el + '.')))].slice(0, 12).forEach(x => console.log('   ' + x.replace('.*.', '.<breakpoint>.value.').replace(/\.\*$/, '.<breakpoint>.value'))); continue; }
    if (e.decoration.length) console.log(`   ${el}.decoration.* groups: ${e.decoration.join(', ')}`);
    const own = e.fields.filter(f => !f.inherited && (opts.all || !f.path.includes('.decoration.'))); const seen = new Set();
    for (const f of own) { const id = f.path + '|' + (f.key || ''); if (seen.has(id)) continue; seen.add(id);
      console.log(`   ${f.path}${f.key ? '  {' + f.key + '}' : ''}` + (f.label ? `  "${f.label}"` : '') + (f.component ? `  <${f.component.replace('divi/', '')}>` : '') + (f.options ? `  options: ${f.options.join(' | ')}` : '') + (f.dynamic ? `  dynamic:${f.dynamic}` : '') + (f.hidden ? '  (hidden in UI)' : '')); }
    const inh = e.fields.filter(f => f.inherited).map(f => f.path); if (inh.length) console.log(`   standard groups, shared shape: ${inh.join(', ')}`); }
  const d = m.defaults ? flat(m.defaults, '', []) : []; console.log(`\n== defaults the module renders with (${d.length})`); d.slice(0, opts.all ? 999 : 40).forEach(x => console.log('   ' + x)); if (!opts.all && d.length > 40) console.log(`   … ${d.length - 40} more (--all)`);
  if (m.customCss) console.log(`\n== custom CSS targets: ${m.customCss.join(', ')}`);
  console.log(`\nvalue shape reminder: <path>.desktop.value = <value or {key: value}>; tablet/phone/hover sit beside desktop. source: ${m.source}`); }
function find(text) { const cat = load(), t = text.toLowerCase(); let n = 0;
  for (const m of Object.values(cat.modules)) for (const [el, e] of Object.entries(m.elements)) for (const f of e.fields) { const hay = [f.path, f.key, f.label, (f.options || []).join(' ')].join(' ').toLowerCase(); if (hay.includes(t)) { n++; if (n <= 60) console.log(`${m.name}: ${f.path}${f.key ? ' {' + f.key + '}' : ''}${f.label ? '  "' + f.label + '"' : ''}${f.options ? '  options: ' + f.options.join(' | ') : ''}`); } }
  console.log(`${n} match(es)` + (n > 60 ? ', first 60 shown' : '')); }

// ---------- lint ----------
function parseBlocks(t) { const re = /<!--\s+(\/)?wp:([a-z0-9-]+\/[a-z0-9-]+)(?:\s+(\{[\s\S]*?\}))?\s+(\/)?-->/g; const out = [], stack = []; let m;
  while ((m = re.exec(t))) { const [, closing, name, json, self] = m; if (closing) { const top = stack.pop(); if (!top || top.name !== name) out.push({ error: `closer </${name}> at ${m.index} does not match ${top ? 'open <' + top.name + '>' : 'anything'}` }); continue; }
    let attrs = {}; if (json) { try { attrs = JSON.parse(json); } catch (e) { out.push({ error: `${name} at ${m.index}: attributes are not valid JSON (${e.message})` }); } }
    const b = { name, attrs, parent: stack.length ? stack[stack.length - 1].name : null, at: m.index }; out.push(b); if (!self) stack.push(b); }
  for (const s of stack) if (s.name !== 'divi/placeholder') out.push({ error: `<${s.name}> opened at ${s.at} is never closed` }); else out.push({ error: 'page wrapper divi/placeholder is never closed' });
  return out; }
// Top-level keys any block may carry (seen on builder-written blocks), so never "unknown elements".
const UNIVERSAL = new Set(['builderVersion', 'modulePreset', 'groupPreset', 'css', 'presets', 'locked', 'collapsed', 'label', 'globalParent', 'globalModule', 'themeBuilderArea']);
function lint(file, strict) { const cat = load(); const t = fs.readFileSync(file, 'utf8'); const blocks = parseBlocks(t); const errs = [], warns = []; let n = 0;
  for (const b of blocks) { if (b.error) { errs.push(b.error); continue; } if (b.name === 'divi/placeholder') continue; n++; const m = cat.modules[b.name];
    if (!b.name.startsWith('divi/')) { warns.push(`${b.name}: not a Divi block, not checked`); continue; }
    if (!m) { errs.push(`${b.name}: no such module in Divi ${cat.diviVersion}`); continue; }
    // A module that declares no elements at all (shortcode-module, global-layout) has free-form attributes: nothing to check.
    if (!Object.keys(m.elements).length) continue;
    for (const k of Object.keys(b.attrs)) { if (UNIVERSAL.has(k)) continue; if (!m.elements[k]) { errs.push(`${b.name}: unknown element "${k}". It has: ${Object.keys(m.elements).join(', ')}`); continue; }
      const el = m.elements[k]; const v = b.attrs[k]; if (!v || typeof v !== 'object') continue;
      // Divi itself writes attributes its settings tree never declares (row columnStructure, column type, htmlAttributes:
      // seen on 100+ builder-written blocks), so "not declared" proves nothing. Only reported with --strict, as a hint.
      if (strict) for (const sec of Object.keys(v)) { if (sec === 'decoration') { for (const g of Object.keys(v.decoration || {})) if (el.decoration.length && !el.decoration.includes(g) && !['interactionTarget', 'interactionTrigger'].includes(g)) warns.push(`${b.name}: ${k}.decoration.${g} is not a decoration group this element declares (${el.decoration.join(', ')})`); }
        else if (sec === 'advanced') { for (const g of Object.keys(v.advanced || {})) if (el.advanced.length && !el.advanced.includes(g)) warns.push(`${b.name}: ${k}.advanced.${g} is not declared (${el.advanced.join(', ')})`); } }
      for (const f of el.fields) { if (!f.options || !f.path.startsWith(k + '.')) continue; let cur = b.attrs; for (const part of f.path.split('.')) cur = cur && cur[part]; if (!cur || typeof cur !== 'object') continue;
        for (const [bp, holder] of Object.entries(cur)) { if (!holder || typeof holder !== 'object' || !('value' in holder)) continue; const val = f.key ? (holder.value && holder.value[f.key]) : holder.value; if (typeof val === 'string' && val !== '' && !val.startsWith('$variable(') && !f.options.includes(val)) errs.push(`${b.name}: ${f.path}${f.key ? '.' + f.key : ''} (${bp}) = "${val}" is not an option. Allowed: ${f.options.join(' | ')}`); } } }
    // Nesting is only enforced for true parent/child module pairs (accordion > accordion-item). Structure blocks declare far
    // less than Divi accepts: real pages hold fullwidth modules and columns directly in sections, and rows inside columns.
    const P = b.parent && cat.modules[b.parent];
    if (P && P.category !== 'structure' && P.children && P.children.every(ch => (cat.modules[ch] || {}).category === 'child-module') && !P.children.includes(b.name)) errs.push(`${b.name} sits inside ${b.parent}, which only takes: ${P.children.join(', ')}`);
    if (m.category === 'child-module' && m.parents && m.parents.length && (!b.parent || !m.parents.includes(b.parent))) errs.push(`${b.name} must sit inside one of: ${m.parents.join(', ')} (found inside ${b.parent || 'nothing'})`); }
  console.log(`${file}: ${n} blocks checked against Divi ${cat.diviVersion}`); warns.forEach(w => console.log('  warn   ' + w)); errs.forEach(e => console.log('  ERROR  ' + e));
  console.log(errs.length ? `\n${errs.length} error(s), ${warns.length} warning(s)` : `\nno errors, ${warns.length} warning(s)`); process.exit(errs.length ? 1 : 0); }

const [cmd, ...rest] = process.argv.slice(2); const flags = new Set(rest.filter(a => a.startsWith('--'))); const args = rest.filter(a => !a.startsWith('--'));
if (cmd === 'build') build(args[0]); else if (cmd === 'list') list(args[0]); else if (cmd === 'show') { if (!args[0]) die('show needs a module name'); show(args[0], { all: flags.has('--all') }); }
else if (cmd === 'find') { if (!args[0]) die('find needs some text'); find(args.join(' ')); } else if (cmd === 'lint') { if (!args[0]) die('lint needs a file'); lint(args[0], flags.has('--strict')); }
else { console.log(fs.readFileSync(__filename, 'utf8').split('*/')[0].replace(/^#!.*\n\/\*\*\n?/, '').replace(/^ \* ?/gm, '')); process.exit(cmd ? 2 : 0); }
