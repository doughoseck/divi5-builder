#!/usr/bin/env node
/*
 * compile-test.js — module types that have no other test: link, swiper-posts, and the exported buildModule().
 *   node scripts/compile-test.js
 * No site needed. Also runs the output through `catalog.js lint`.
 */
const fs = require('fs'); const path = require('path'); const os = require('os'); const { spawnSync } = require('child_process');
const divi = require('./divi.js'); let failed = 0; const check = (n, ok, d) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + n + (!ok && d ? '\n          ' + String(d).slice(0, 300) : '')); if (!ok) failed++; };
const spec = { builderVersion: '5.13.0', sections: [{ rows: [{ columns: [{ modules: [
  { type: 'link', text: 'Tickets & "more" -- now', url: 'https://example.com/tickets/', icon: '&#xf054;', iconColor: 'gcid-primary-color', size: '26px', border: { bottom: { width: '1px', color: '#ffffff', style: 'solid' } } },
  { type: 'swiper-posts', postType: 'post', count: 6, slidesPerView: { desktop: 3, tablet: 2, phone: 1 }, spaceBetween: 20, showTitle: 'on', autoplay: 'on', delay: 4000 },
  { type: 'text', html: '<p>after</p>' } ] }] }] }] };
const out = divi.compile(spec); const B = [...out.matchAll(/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)(?: (\{[\s\S]*?\}))? (\/)?-->/g)].map(m => ({ name: m[1], json: m[2] || '', attrs: m[2] ? JSON.parse(m[2]) : {} }));
const link = B.find(b => b.name === 'divi/link'), sw = B.find(b => b.name === 'dp-dss/posts-slider');
check('link: compiled as divi/link with text and url', link && link.attrs.content.innerContent.desktop.value.text === 'Tickets & "more" -- now' && link.attrs.content.innerContent.desktop.value.linkUrl === 'https://example.com/tickets/');
check('link: icon, colour token and one-sided border are there', link && link.attrs.icon.innerContent.desktop.value.unicode === '&#xf054;' && /gcid-primary-color/.test(JSON.stringify(link.attrs.icon)) && link.attrs.module.decoration.border.desktop.value.styles.bottom.width === '1px', link && JSON.stringify(link.attrs).slice(0, 300));
check('swiper-posts: compiled as the add-on\'s block, per-breakpoint values kept', sw && sw.attrs.slidesPerView.innerContent.desktop.value === '3' && sw.attrs.slidesPerView.innerContent.phone.value === '1' && sw.attrs.post_number.innerContent.desktop.value === '6', sw && JSON.stringify(sw.attrs).slice(0, 300));
check('every opening block has a closing one', B.filter(b => b.name !== 'divi/placeholder').every(b => out.includes('<!-- /wp:' + b.name + ' -->')));
check('block comments stay comment-safe', B.every(b => !/[<>&]|--|\\"/.test(b.json)));
check('buildModule is exported and compiles one module to opener + closer', typeof divi.buildModule === 'function' && (() => { const l = divi.buildModule({ type: 'text', html: '<p>x</p>' }); return Array.isArray(l) && l.length === 2 && /^<!-- wp:divi\/text /.test(l[0]) && l[1] === '<!-- /wp:divi/text -->'; })());
check('buildModule honours modulePreset', (() => { const l = divi.buildModule({ type: 'button', text: 'x', url: '#', modulePreset: 'abcdefghij' }); return /"modulePreset":\["abcdefghij"\]/.test(l[0]); })());
let threw = false; try { divi.compile({ sections: [{ rows: [{ columns: [{ modules: [{ type: 'no-such-module' }] }] }] }] }); } catch (e) { threw = /unknown module type/.test(e.message); } check('an unknown module type is still refused', threw);
const tmp = path.join(os.tmpdir(), 'd5b-newmods.html'); fs.writeFileSync(tmp, out); const r = spawnSync('node', [path.join(__dirname, 'catalog.js'), 'lint', tmp], { encoding: 'utf8' }); const lint = (r.stdout || '') + (r.stderr || '');
console.log('  catalog lint says: ' + lint.trim().split('\n').slice(-4).join(' | ').slice(0, 400)); check('catalog lint finds no error in the divi/link block', !/divi\/link[^\n]*(ERROR|not an option|unknown)/i.test(lint));
// Pricing currency: the spec states it; otherwise this computer's region decides, ALWAYS with a warning.
// Each case runs in its own process so the region (DIVI_LOCALE) can differ per case. DIVI_JS tests another copy.
const DIVI_FILE = path.join(__dirname, process.env.DIVI_JS || 'divi.js');
const priced = (tiers, mod, locale) => {
  const code = `const d=require(${JSON.stringify(DIVI_FILE)});` +
    `const o=d.compile({sections:[{rows:[{columns:[{modules:[Object.assign({type:'pricing',tiers:${JSON.stringify(tiers)}},${JSON.stringify(mod || {})})]}]}]}]});` +
    `process.stdout.write(JSON.stringify([...o.matchAll(/"currency":"([^"]*)"/g)].map(m=>m[1])));`;
  const r = spawnSync(process.execPath, ['-e', code], { encoding: 'utf8', env: Object.assign({}, process.env, { DIVI_LOCALE: locale || '' }) });
  let cur = null; try { cur = JSON.parse(r.stdout); } catch (e) {}
  return { cur, warn: r.stderr || '' };
};
let p = priced([{ title: 'A', price: '9', currency: '£' }, { title: 'B', price: '19', currency: '€' }], null, 'en-ZA');
check('pricing: the currency each tier states is used, with no warning', JSON.stringify(p.cur) === '["£","€"]' && !/WARNING/.test(p.warn), [p.cur, p.warn]);
p = priced([{ title: 'A', price: '9' }, { title: 'B', price: '19', currency: '€' }], { currency: 'US$' }, 'en-ZA');
check('pricing: a currency on the module covers every tier that states none', JSON.stringify(p.cur) === '["US$","€"]' && !/WARNING/.test(p.warn), [p.cur, p.warn]);
p = priced([{ title: 'A', price: '9' }], null, 'en-ZA');
check('pricing: none stated, South African computer -> "R", with a warning', JSON.stringify(p.cur) === '["R"]' && /WARNING: pricing table 1 \("A"\) has no "currency"/.test(p.warn) && /en-ZA -> ZAR/.test(p.warn), [p.cur, p.warn]);
p = priced([{ title: 'A', price: '9' }], null, 'en-GB');
check('pricing: none stated, UK computer -> "£", with a warning', JSON.stringify(p.cur) === '["£"]' && /WARNING/.test(p.warn), [p.cur, p.warn]);
p = priced([{ title: 'A', price: '9' }], null, 'de-DE');
check('pricing: none stated, German computer -> "€"', JSON.stringify(p.cur) === '["€"]' && /WARNING/.test(p.warn), [p.cur, p.warn]);
p = priced([{ title: 'A', price: '9' }], null, 'en-AQ');
check('pricing: a region not in the table -> Divi\'s "$", and the warning says why', JSON.stringify(p.cur) === '["$"]' && /not in the table/.test(p.warn), [p.cur, p.warn]);

// ---- issue #33: compiler gaps. Every attribute path below was read from builder-saved markup on live sites and
// from Divi 5.15.1's own Divi 4 converter (image: borders/box_shadow -> image.decoration.*, max_width ->
// module.advanced.sizing; any element's CSS id/class -> module.advanced.htmlAttributes).
const D = require(DIVI_FILE);
const blocksOf = (o) => [...o.matchAll(/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)(?: (\{[\s\S]*?\}))? (\/)?-->/g)].map(m => ({ name: m[1], attrs: m[2] ? JSON.parse(m[2]) : {} }));
const g = (o, p) => p.split('.').reduce((x, k) => (x == null ? undefined : x[k]), o);
const page = (mods, rowExtra, colExtra, secExtra) => blocksOf(D.compile({ sections: [Object.assign({ rows: [Object.assign({ columns: [Object.assign({ modules: mods }, colExtra || {})] }, rowExtra || {})] }, secExtra || {})] }));
// 1. image frame
let ib = page([{ type: 'image', src: 'https://example.com/a.png', alt: 'a', frame: { radius: '12px', shadow: true, border: { width: '2px', color: '#000' }, maxWidth: '300px' }, align: 'right' }]).find(b => b.name === 'divi/image').attrs;
check('#33 image: frame radius + border on the IMAGE element (image.decoration.border)', g(ib, 'image.decoration.border.desktop.value.radius.topLeft') === '12px' && g(ib, 'image.decoration.border.desktop.value.styles.all.width') === '2px', JSON.stringify(ib));
check('#33 image: frame shadow on the IMAGE element (image.decoration.boxShadow)', !!g(ib, 'image.decoration.boxShadow.desktop.value.blur'), JSON.stringify(ib));
check('#33 image: max-width in module.advanced.sizing, alignment in module.advanced.align', g(ib, 'module.advanced.sizing.desktop.value.maxWidth') === '300px' && g(ib, 'module.advanced.align.desktop.value') === 'right', JSON.stringify(ib));
check('#33 image: nothing left in module.decoration border / boxShadow / sizing (not image groups)', g(ib, 'module.decoration.border') === undefined && g(ib, 'module.decoration.boxShadow') === undefined && g(ib, 'module.decoration.sizing') === undefined, JSON.stringify(ib));
ib = page([{ type: 'image', src: 'https://example.com/a.png', frame: { maxWidth: '300px' } }]).find(b => b.name === 'divi/image').attrs;
check('#33 image: max-width without an alignment is centred (as before)', g(ib, 'module.advanced.align.desktop.value') === 'center', JSON.stringify(ib));
// 2. CSS id and class on every element
let B33 = page([{ type: 'text', html: '<p>x</p>', cssId: 'intro', cssClass: 'lead big' }], { cssId: 'r1' }, { cssClass: 'card' }, { cssId: 'contact', cssClass: 'dark' });
const of33 = (n) => B33.find(b => b.name === n).attrs;
check('#33 section: cssId + cssClass -> module.advanced.htmlAttributes (anchors like #contact)', JSON.stringify(g(of33('divi/section'), 'module.advanced.htmlAttributes.desktop.value')) === '{"id":"contact","class":"dark"}', JSON.stringify(of33('divi/section')));
check('#33 row, column and module take them too', g(of33('divi/row'), 'module.advanced.htmlAttributes.desktop.value.id') === 'r1' && g(of33('divi/column'), 'module.advanced.htmlAttributes.desktop.value.class') === 'card' && JSON.stringify(g(of33('divi/text'), 'module.advanced.htmlAttributes.desktop.value')) === '{"id":"intro","class":"lead big"}', JSON.stringify(B33.map(b => [b.name, g(b.attrs, 'module.advanced.htmlAttributes')])));
let threw33 = false; try { D.compile({ sections: [{ cssId: 'has space', rows: [] }] }); } catch (e) { threw33 = /cssId/.test(e.message); }
check('#33 a cssId that cannot be an HTML id is refused', threw33);
// 3. hide on a device
B33 = page([{ type: 'text', html: '<p>x</p>', hideOn: ['tablet', 'desktop'] }], null, { hideOn: ['phone'] });
check('#33 column hideOn ["phone"] -> disabledOn.phone only', JSON.stringify(g(of33('divi/column'), 'module.decoration.disabledOn')) === '{"phone":{"value":"on"}}', JSON.stringify(of33('divi/column')));
check('#33 hideOn ["tablet","desktop"] -> tabletOnly + desktopAbove, NEVER "desktop" (Divi\'s base device: that would hide it everywhere)', JSON.stringify(g(of33('divi/text'), 'module.decoration.disabledOn')) === '{"tabletOnly":{"value":"on"},"desktopAbove":{"value":"on"}}', JSON.stringify(of33('divi/text')));
threw33 = false; try { page([{ type: 'text', html: '<p>x</p>', hideOn: ['mobile'] }]); } catch (e) { threw33 = /hideOn/.test(e.message); }
check('#33 an unknown device in hideOn is refused', threw33);
// 4. unequal column layouts (Divi 5.15.1's own offset-columns templates)
const rowOf = (layout, n) => blocksOf(D.compile({ sections: [{ rows: [{ layout, columns: Array.from({ length: n }, () => ({ modules: [{ type: 'text', html: '<p>x</p>' }] })) }] }] }));
B33 = rowOf('2-1', 2);
const cols33 = B33.filter(b => b.name === 'divi/column').map(b => g(b.attrs, 'module.decoration.sizing'));
check('#33 layout "2-1" = Divi\'s offset-columns_5: 16_24 + 8_24, full width on phone', g(B33.find(b => b.name === 'divi/row').attrs, 'module.advanced.flexColumnStructure.desktop.value') === 'offset-columns_5' && cols33[0].desktop.value.flexType === '16_24' && cols33[1].desktop.value.flexType === '8_24' && cols33[0].phone.value.flexType === '24_24', JSON.stringify(cols33));
B33 = rowOf('1-2-1', 3);
check('#33 layout "1-2-1" = offset-columns_11: 6_24 + 12_24 + 6_24', B33.filter(b => b.name === 'divi/column').map(b => g(b.attrs, 'module.decoration.sizing.desktop.value.flexType')).join() === '6_24,12_24,6_24');
B33 = rowOf('3-1-1-1', 4);
check('#33 layout "3-1-1-1" = offset-columns_7: tablet puts the wide column alone, then three thirds', B33.filter(b => b.name === 'divi/column').map(b => g(b.attrs, 'module.decoration.sizing.tablet.value.flexType')).join() === '24_24,8_24,8_24,8_24');
threw33 = false; try { rowOf('5-5', 2); } catch (e) { threw33 = /layout "5-5"/.test(e.message) && /2-1/.test(e.message); }
check('#33 an unknown layout is refused, listing the known ones (it used to become ONE column silently)', threw33);
threw33 = false; try { rowOf('2-1', 3); } catch (e) { threw33 = /2 columns/.test(e.message); }
check('#33 a layout with the wrong number of columns is refused', threw33);
// the new markup passes the catalogue lint (block names, groups, options)
const t33 = path.join(os.tmpdir(), 'd5b-33.html');
fs.writeFileSync(t33, D.compile({ sections: [{ cssId: 'contact', rows: [{ layout: '2-1', columns: [{ hideOn: ['phone'], modules: [{ type: 'image', src: 'https://example.com/a.png', frame: { radius: '8px', shadow: true, maxWidth: '200px' } }] }, { modules: [{ type: 'text', html: '<p>x</p>', cssClass: 'a' }] }] }] }] }));
const l33 = spawnSync('node', [path.join(__dirname, 'catalog.js'), 'lint', t33], { encoding: 'utf8' }); const lo33 = (l33.stdout || '') + (l33.stderr || '');
// NOTE: the lint checks block names, option values and nesting, but NOT which element owns a style group: it also
// passes the OLD, wrong image markup (border in module.decoration). The path checks above are what prove #33.
check('#33 catalogue lint (block names, options, nesting) finds no error in the new markup', /no errors/.test(lo33) && !/^\s+ERROR\s/m.test(lo33), lo33.slice(-500));

console.log(failed ? failed + ' FAILED' : 'all passed'); process.exit(failed ? 1 : 0);
