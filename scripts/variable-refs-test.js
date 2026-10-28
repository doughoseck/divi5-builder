#!/usr/bin/env node
/*
 * variable-refs-test.js: design variables inside values (issue #45, Divi 5.18): the compiler's {{gvid-…}} and
 * {{dc:…}} placeholders, byte for byte against a page the Visual Builder saved on Divi 5.18.0, and the warning for
 * "×" / "÷" / "x" used as math next to a variable.
 *
 *   node scripts/variable-refs-test.js
 *   DIVI_JS=/path/to/divi.js node scripts/variable-refs-test.js   # test another copy (mutation testing)
 *
 * No site needed. The reference page is scripts/fixtures/vb-5.18.0-variables.html: a page built in the Visual Builder
 * on Divi 5.18.0 (a section padding, an image margin with a calculation, a mixed text and a heading size, each holding
 * a variable), stored as WordPress returned it. Only the image's file name, alt and title were made generic.
 */
const fs = require('fs'); const path = require('path');
const D = require(process.env.DIVI_JS || path.join(__dirname, 'divi.js'));
let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 400))); if (!ok) failed++; };
const live = fs.readFileSync(path.join(__dirname, 'fixtures', 'vb-5.18.0-variables.html'), 'utf8');
const lineOf = (text, block) => text.split('\n').find((l) => l.includes('<!-- wp:' + block + ' ')) || '';
// The stored bytes of one attribute value: the comment holds the attrs in the stored form, where every quote inside a
// string is escaped, so a value runs from `"key":"` to the next raw quote.
const stored = (line, key) => { const m = new RegExp('"' + key + '":"([^"]*)"').exec(line); return m ? m[1] : null; };

console.log('--- the compiler writes variables exactly as the Visual Builder stores them (Divi 5.18.0 page) ---');
const spec = { builderVersion: '5.18.0', sections: [{ padding: { top: '{{gvid-xdtsp8ywr8}}' }, rows: [{ columns: [{ modules: [
  { type: 'raw', block: 'divi/image', attrs: { module: { advanced: { spacing: { desktop: { value: { margin: { top: '{{gvid-xdtsp8ywr8}} × -1', syncVertical: 'off', syncHorizontal: 'off' } } } } } }, image: { innerContent: { desktop: { value: { src: 'https://example.com/wp-content/uploads/example-photo.jpg' } } } }, builderVersion: '5.18.0' } },
  { type: 'text', html: '<p>By&nbsp;{{dc:post_author}}.&nbsp;{{gvid-zca7w37pd4}}</p>' },
  { type: 'heading-module', text: 'Your Title Goes Here', size: '{{gvid-lk82fchj2p}}' },
] }] }] }] };
const out = D.compile(spec, { quiet: true });
for (const [what, block, key] of [
  ['section padding (a variable alone in a number field)', 'divi/section', 'top'],
  ['image margin (a variable in a calculation; the live page has the "×" the builder was told to type)', 'divi/image', 'top'],
  ['text body (static text, a dynamic-content token and a variable, with &nbsp; around the chips)', 'divi/text', 'value'],
  ['heading font size (a nested variable: Fluid Heading = clamp( Min, 4vw, Max ))', 'divi/heading', 'size'],
]) {
  const want = stored(lineOf(live, block), key), got = stored(lineOf(out, block), key);
  check(`${what}: identical bytes`, want !== null && want.includes('$variable(') && got === want, { want, got });
}
check('the reference page really holds the tokens (it is not a page without variables)', (live.match(/\$variable\(/g) || []).length === 5);
const textAttrs = JSON.parse(/<!-- wp:divi\/text (\{.*\}) -->/.exec(lineOf(out, 'divi/text'))[1]);
check('decoded, the text body is the mixed text with both tokens', textAttrs.content.innerContent.desktop.value === '<p>By&nbsp;$variable({"type":"content","value":{"name":"post_author","settings":{}}})$.&nbsp;$variable({"type":"content","value":{"name":"gvid-zca7w37pd4","settings":{}}})$</p>', textAttrs.content.innerContent.desktop.value);
check('no placeholder is left in the output', !/\{\{/.test(out));

console.log('--- placeholders: where they work, what is refused ---');
const one = (mod) => D.compile({ sections: [{ rows: [{ columns: [{ modules: [mod] }] }] }] }, { quiet: true });
const raw = (attrs) => one({ type: 'raw', block: 'divi/text', attrs, inner: '' });
const deep = JSON.parse(/<!-- wp:divi\/text (\{.*\}) -->/.exec(raw({ a: [{ b: 'x {{gvid-abc123}} y' }] }))[1]);
check('a placeholder deep in a raw block, in an array, becomes a token', deep.a[0].b === 'x ' + D.dc('gvid-abc123') + ' y', deep);
check('several placeholders in one value, each converted', (one({ type: 'text', html: '<p>{{gvid-a1}} {{gvid-b2}} {{dc:post_title}}</p>' }).match(/\$variable\(/g) || []).length === 3);
check('spaces inside the braces are allowed ({{ gvid-a1 }})', /gvid-a1/.test(one({ type: 'text', html: '<p>{{ gvid-a1 }}</p>' })) && !/\{\{/.test(one({ type: 'text', html: '<p>{{ gvid-a1 }}</p>' })));
check('text without placeholders is untouched (a {{ that is not ours stays)', /\{\{mustache\}\}/.test(one({ type: 'code', html: '<div>{{mustache}}</div>' })));
for (const [bad, why] of [['{{gvid-bad$id}}', 'a character an id cannot hold'], ['{{gvid-a b}}', 'a space in the id'], ['{{dc:Post Author}}', 'a dynamic-content name with a space and capitals'], ['{{dc:}}', 'an empty dynamic-content name']]) {
  let err = null; try { one({ type: 'text', html: `<p>${bad}</p>` }); } catch (e) { err = e.message; }
  check(`refused: ${bad} (${why})`, !!err && err.includes(bad), err);
}
const specWarn = D.checkSpec({ sections: [{ padding: { top: '{{gvid-x1}} * -1' }, rows: [{ columns: [{ modules: [{ type: 'text', html: '<p>By {{dc:post_author}} · {{gvid-y1}}</p>' }] }] }] }] });
check('the spec-key check does not flag {{…}} placeholders', Array.isArray(specWarn) && specWarn.length === 0, specWarn);
const ids = D.referencedIds(live);
check('--site reads the ids from the stored page: inside a calculation, a mixed text and a nested heading size', ['gvid-xdtsp8ywr8', 'gvid-zca7w37pd4', 'gvid-lk82fchj2p'].every((x) => ids.variables.has(x)) && ids.variables.size === 3 && !ids.variables.has('post_author'), [...ids.variables]);
const ids2 = D.referencedIds(out);
check('... and from the compiled page', ['gvid-xdtsp8ywr8', 'gvid-zca7w37pd4', 'gvid-lk82fchj2p'].every((x) => ids2.variables.has(x)) && ids2.variables.size === 3, [...ids2.variables]);

console.log('--- "×" is not math: warned, "*" is not ---');
const MSG = '`×` is not a CSS operator; Divi only treats + - * / as math. Use `*`.';
const tok = (id) => '$variable({"type":"content","value":{"name":"' + id + '","settings":{}}})$';
for (const v of [tok('gvid-xdtsp8ywr8') + ' × -1', '{{gvid-xdtsp8ywr8}} × -1', 'calc(var(--gvid-xdtsp8ywr8) × -1)', '{{gvid-a1}}×2']) check(`warned: ${v.replace(/\$variable.*?\)\$/, 'TOKEN')}`, D.variableMathWarning(v) === MSG, D.variableMathWarning(v));
check('warned: "÷" (and told to use /)', /`÷` is not a CSS operator.*Use `\/`/.test(D.variableMathWarning('{{gvid-a1}} ÷ 2') || ''));
check('warned: an "x" used as multiplication ({{gvid-a1}} x -1)', /`x` is not a CSS operator/.test(D.variableMathWarning('{{gvid-a1}} x -1') || ''), D.variableMathWarning('{{gvid-a1}} x -1'));
for (const v of [tok('gvid-xdtsp8ywr8') + ' * -1', '{{gvid-a1}} * -1', '{{gvid-a1}}*2', 'var(--gvid-a1) / 2', '100% - {{gvid-a1}}', '-{{gvid-a1}}', 'clamp( {{gvid-a1}}, 4vw, {{gvid-a2}})', 'max(2px, {{gvid-a1}} + 1rem)', '{{gvid-a1}}', '0 2px {{gvid-a1}} rgba(0,0,0,.2)'])
  check(`no warning: ${v}`, D.variableMathWarning(v) === null, D.variableMathWarning(v));
for (const v of ['20px × 2', '3 × 4', 'Copyright © {{gvid-a1}} 3 × 4 offer', '<p>{{gvid-a1}} × 2 for 1</p>', 'Size 1920x1080 {{gvid-a1}}'])
  check(`no warning (no variable, or text not math): ${v}`, D.variableMathWarning(v) === null, D.variableMathWarning(v));
const w = D.checkSpec({ sections: [{ padding: { top: '{{gvid-xdtsp8ywr8}} × -1' }, rows: [] }] });
check('compile warns about it, naming where', w.length === 1 && w[0].includes('spec.sections[0].padding.top') && w[0].includes(MSG), w);
check('compile with --strict refuses it', (() => { try { D.compile({ sections: [{ padding: { top: '{{gvid-x1}} × -1' } }] }, { strict: true, quiet: true }); return false; } catch (e) { return /× is not|`×` is not/.test(e.message); } })());
check('a "*" in the same place compiles with no warning', D.checkSpec({ sections: [{ padding: { top: '{{gvid-xdtsp8ywr8}} * -1' }, rows: [] }] }).length === 0);

console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
