#!/usr/bin/env node
/*
 * preset-spec-test.js — `modulePreset` in a page spec (divi.js). No site needed.
 *   node scripts/preset-spec-test.js
 * Proves: the preset lands on the right block, as an array, for every element kind; nothing else in the output
 * changes; a spec without presets compiles exactly as before; a bad id is refused.
 */
const { compile } = require('./divi.js');
let failed = 0; const check = (name, ok, detail) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail ? '\n          ' + String(detail).slice(0, 300) : '')); if (!ok) failed++; };
const blocks = (out) => [...out.matchAll(/<!-- wp:(divi\/[a-z0-9-]+)(?: (\{[\s\S]*?\}))? (\/)?-->/g)].map((m) => ({ name: m[1], attrs: m[2] ? JSON.parse(m[2]) : {}, json: m[2] || '' }));
const strip = (spec) => JSON.parse(JSON.stringify(spec, (k, v) => (k === 'modulePreset' ? undefined : v)));

const spec = {
  builderVersion: '5.13.0',
  sections: [{
    modulePreset: ['defsection', 'sectionblack'],
    rows: [{
      layout: '1-1', modulePreset: 'rowwide',
      columns: [
        { modulePreset: 'cardcol', modules: [
          { type: 'text', html: '<h2>Title & "quotes" -- dashes</h2><p>Body</p>', modulePreset: ['deftext', 'titlecentre'] },
          { type: 'button', text: 'Book now', url: 'https://example.com', modulePreset: 'buttonyellow' },
        ] },
        { modules: [
          { type: 'blurb', title: 'A', text: 'B', modulePreset: 'locationtile' },
          { type: 'divider', height: '40px', showLine: false, modulePreset: 'tileoverlay' },
          { type: 'accordion', items: [{ title: 'Q', text: 'A' }], modulePreset: 'faq' },
          { type: 'text', html: '<p>No preset here</p>' },
          { type: 'row', layout: '1', modulePreset: 'innerrow', columns: [{ modules: [{ type: 'image', src: 'https://example.com/a.png', alt: 'a', modulePreset: 'img' }] }] },
        ] },
      ],
    }, {
      grid: { cols: { desktop: 3, tablet: 2, phone: 1 } }, modulePreset: 'gridrow', columns: [{ modules: [{ type: 'text', html: '<p>x</p>' }] }],
    }],
  }],
};
const out = compile(spec); const B = blocks(out); const plain = compile(strip(spec)); const P = blocks(plain);
const of = (name, i = 0) => B.filter((b) => b.name === name)[i];
check('section: a stack is kept in order', JSON.stringify(of('divi/section').attrs.modulePreset) === '["defsection","sectionblack"]');
check('row: a single id becomes an array', JSON.stringify(of('divi/row').attrs.modulePreset) === '["rowwide"]');
check('grid row', B.some((b) => b.name === 'divi/row' && JSON.stringify(b.attrs.modulePreset) === '["gridrow"]'));
check('nested row inside a column', B.some((b) => b.name === 'divi/row' && JSON.stringify(b.attrs.modulePreset) === '["innerrow"]'));
check('column', JSON.stringify(of('divi/column').attrs.modulePreset) === '["cardcol"]');
check('a column without a preset gets none', B.filter((b) => b.name === 'divi/column').some((b) => b.attrs.modulePreset === undefined));
check('text, button, blurb, divider, accordion, image', ['divi/text', 'divi/button', 'divi/blurb', 'divi/divider', 'divi/accordion', 'divi/image'].every((n) => Array.isArray(of(n).attrs.modulePreset)), B.map((b) => b.name + ':' + JSON.stringify(b.attrs.modulePreset)).join(' '));
check('the text without a preset gets none', of('divi/text', 1).attrs.modulePreset === undefined);
check('an accordion\'s ITEMS do not inherit the preset', B.filter((b) => b.name === 'divi/accordion-item').every((b) => b.attrs.modulePreset === undefined) && B.some((b) => b.name === 'divi/accordion-item'));
check('same number of blocks, same order, as without presets', B.length === P.length && B.every((b, i) => b.name === P[i].name));
check('NOTHING but modulePreset differs from the spec without presets', B.every((b, i) => { const a = { ...b.attrs }; delete a.modulePreset; return JSON.stringify(a) === JSON.stringify(P[i].attrs); }));
check('text outside block comments is identical', out.replace(/<!-- wp:[\s\S]*?-->/g, '#') === plain.replace(/<!-- wp:[\s\S]*?-->/g, '#'));
check('block comments stay comment-safe (no raw <, >, &, --, \\" inside the JSON)', B.every((b) => !/[<>&]|--|\\"/.test(b.json)) && B.some((b) => /\\u003c/.test(b.json)));
check('content with quotes and dashes survives', of('divi/text').attrs.content.innerContent.desktop.value.includes('Title & "quotes" -- dashes'));
check('a spec without any preset compiles byte for byte as it did before', compile(strip(spec)) === plain && !/modulePreset/.test(plain));
check('an empty preset is ignored', !/modulePreset/.test(compile({ sections: [{ modulePreset: '', rows: [{ columns: [{ modules: [{ type: 'text', html: '<p>x</p>', modulePreset: [] }] }] }] }] })));
let threw = false; try { compile({ sections: [{ rows: [{ columns: [{ modules: [{ type: 'text', html: '<p>x</p>', modulePreset: 'bad id"' }] }] }] }] }); } catch (e) { threw = /not a preset id/.test(e.message); }
check('an id that cannot be a preset id is refused', threw);
console.log(failed ? failed + ' FAILED' : 'all passed'); process.exit(failed ? 1 : 0);
