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

console.log(failed ? failed + ' FAILED' : 'all passed'); process.exit(failed ? 1 : 0);
