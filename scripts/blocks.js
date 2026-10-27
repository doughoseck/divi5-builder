#!/usr/bin/env node
/*
 * blocks.js: edit ONE section, row, column or module of a saved page instead of replacing the whole page (issue #15).
 * Works on a file. Everything outside the block you address stays byte for byte as it was. No site, no credentials.
 *
 *   node wp.js <site> get-page 12 --out page.html            # prints the content hash H
 *   node blocks.js tree page.html                            # every block with its address
 *   node blocks.js replace page.html --at 3 --with new.html --out page2.html
 *   node wp.js <site> update-page 12 --content-file page2.html --expect-hash H   # the usual stale-guarded write
 *
 *   node blocks.js tree <file> [--all] [--json]   # sections, rows, columns, modules; --all adds non-Divi blocks
 *   node blocks.js get <file> --at A [--out F]    # the block's markup, as stored
 *   node blocks.js replace <file> --at A --with F [--from A2] --out O
 *   node blocks.js before|after <file> --at A --with F [--from A2] --out O     # a new sibling
 *   node blocks.js append <file> --at A --with F [--from A2] --out O           # a new LAST child of A
 *   node blocks.js remove <file> --at A --out O
 *   node blocks.js check <file>                   # does it parse: every block closed, in order
 *
 * An address A is one of:
 *   3          section 3 (counting from 1; the divi/placeholder wrapper is not counted)
 *   3.2.1.4    section 3, row 2, column 1, module 4. A nested row is a module of its column: 3.2.1.2.1.1
 *   "label:Featured Work"   the block whose admin label is exactly that (must be the only one)
 *   "#contact"              the block whose CSS ID is that (must be the only one)
 * `tree` prints the address of every block.
 *
 * --with F is block markup: what `divi.js compile` writes, a `get`, or a hand-written block. A compiled page is
 * unwrapped (its placeholder is dropped). What is inserted must be the same kind as what it sits next to: sections
 * next to sections, rows next to rows, columns next to columns, modules next to modules; for `append`, the kind a
 * child of A has. To take one part of a bigger file, --from A2 picks the block at A2 in F (e.g. compile a whole
 * spec, then `--from 1.1.1.1` takes its first module).
 * The result is parsed again before it is written; if it does not parse, nothing is written.
 * Exit 0 = written, 1 = refused (nothing written).
 */
const fs = require('fs'); const crypto = require('crypto');
const md5 = (s) => crypto.createHash('md5').update(String(s), 'utf8').digest('hex');

// ---- parser ----------------------------------------------------------------------------------------------------
// The WordPress block grammar: <!-- wp:ns/name {json} --> ... <!-- /wp:ns/name --> or <!-- wp:ns/name {json} /-->.
// WordPress escapes "--" inside the attributes, so the first "-->" after "<!--" always ends the comment.
// Every block keeps its byte range in the source: start..end covers the opening comment to the end of the closing one.
function parse(src) {
  const root = { name: '#root', children: [], start: 0, end: src.length };
  const stack = [root]; const errors = [];
  let i = 0;
  while ((i = src.indexOf('<!--', i)) !== -1) {
    const close = src.indexOf('-->', i + 4);
    if (close === -1) break;
    const body = src.slice(i + 4, close);
    const m = /^\s+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{[\s\S]*\}\s+)?(\/)?$/.exec(body);
    if (!m) { i = close + 3; continue; }            // an ordinary HTML comment
    const [, closing, rawName, json, self] = m;
    const name = rawName.includes('/') ? rawName : 'core/' + rawName;
    const end = close + 3;
    if (closing) {
      const top = stack[stack.length - 1];
      if (top === root || top.name !== name) { errors.push(`closing ${name} at char ${i} does not match the open ${top === root ? 'nothing' : top.name}`); i = end; continue; }
      top.end = end; top.innerEnd = i; stack.pop();
    } else {
      let attrs = null;
      if (json) { try { attrs = JSON.parse(json); } catch (e) { errors.push(`${name} at char ${i}: its attributes are not valid JSON (${e.message})`); } }
      const b = { name, attrs, start: i, openEnd: end, end: self ? end : -1, selfClosing: !!self, children: [], parent: stack[stack.length - 1] };
      b.parent.children.push(b);
      if (!self) stack.push(b);
    }
    i = end;
  }
  for (const b of stack.slice(1)) errors.push(`${b.name} opened at char ${b.start} is never closed`);
  return { root, errors };
}

// ---- what a block is -------------------------------------------------------------------------------------------
const kindOf = (b) => {
  const n = b.name.replace(/-inner$/, '');
  return n === 'divi/section' ? 'section' : n === 'divi/row' ? 'row' : n === 'divi/column' ? 'column' : n === 'divi/placeholder' ? 'placeholder' : n.startsWith('divi/') ? 'module' : 'other';
};
// The kind a child of this block has. A column holds modules (a nested row counts as one of them); a fullwidth
// section holds modules directly, so a section that already has a module child takes modules.
const childKind = (b) => {
  if (b.name === '#root') return 'section';
  const k = kindOf(b);
  if (k === 'section') return diviChildren(b).some((c) => kindOf(c) === 'module') ? 'module' : 'row';
  return ({ placeholder: 'section', row: 'column', column: 'module' })[k] || 'module';
};
const sameKind = (want, got) => want === got || (want === 'module' && got === 'row');   // a nested row sits where a module does
const deskVal = (o) => o && o.desktop && o.desktop.value;
const labelOf = (b) => { const v = deskVal(b.attrs && b.attrs.module && b.attrs.module.meta && b.attrs.module.meta.adminLabel); return typeof v === 'string' ? v : ''; };
const cssIdOf = (b) => { const v = deskVal(b.attrs && b.attrs.module && b.attrs.module.advanced && b.attrs.module.advanced.htmlAttributes); return (v && typeof v.id === 'string') ? v.id : ''; };

// The Divi children of a block, looking through the placeholder wrapper (and any other non-Divi wrapper).
function diviChildren(b) {
  const out = [];
  for (const c of b.children) { const k = kindOf(c); if (k === 'placeholder' || k === 'other') out.push(...diviChildren(c)); else out.push(c); }
  return out;
}
// Every Divi block with its 1-based address.
function walk(root) {
  const list = [];
  const go = (b, addr) => diviChildren(b).forEach((c, i) => { const a = addr ? `${addr}.${i + 1}` : String(i + 1); c.addr = a; list.push(c); go(c, a); });
  go(root, '');
  return list;
}
function resolve(root, at, what = 'the page') {
  const all = walk(root); const s = String(at).trim();
  let hits;
  if (/^\d+(\.\d+)*$/.test(s)) hits = all.filter((b) => b.addr === s);
  else if (s.startsWith('label:')) hits = all.filter((b) => labelOf(b) === s.slice(6));
  else if (s.startsWith('#')) hits = all.filter((b) => cssIdOf(b) === s.slice(1));
  else throw new Error(`"${at}" is not an address: use 3.2.1, label:<admin label> or #<css id>`);
  if (!hits.length) throw new Error(`nothing at "${at}" in ${what}. Run \`blocks.js tree\` to see the addresses`);
  if (hits.length > 1) throw new Error(`"${at}" matches ${hits.length} blocks in ${what} (at ${hits.map((b) => b.addr).join(', ')}): use one of those addresses`);
  return hits[0];
}

// ---- the fragment to insert ------------------------------------------------------------------------------------
// Its top-level Divi blocks (through a placeholder), or the one block at --from. Returned as the exact source text.
function fragment(text, from) {
  const { root, errors } = parse(text);
  if (errors.length) throw new Error('the --with file does not parse:\n  ' + errors.join('\n  '));
  const blocks = from ? [resolve(root, from, 'the --with file')] : diviChildren(root);
  if (!blocks.length) throw new Error('the --with file has no Divi blocks');
  return { blocks, text: blocks.map((b) => text.slice(b.start, b.end)).join('\n') };
}

// ---- the edit --------------------------------------------------------------------------------------------------
// Returns { out, summary }. The text before and after the changed range is copied, never rebuilt.
function edit(src, op, { at, withText, from } = {}) {
  const { root, errors } = parse(src);
  if (errors.length) throw new Error('the page does not parse, so blocks.js will not edit it:\n  ' + errors.join('\n  '));
  const target = resolve(root, at);
  const kind = kindOf(target);
  let start, end, insert = '';
  let frag = null;
  if (op !== 'remove') {
    if (withText == null) throw new Error(`${op} needs --with <file>`);
    frag = fragment(withText, from);
    const want = op === 'append' ? childKind(target) : kind;
    if (op === 'append' && target.selfClosing) throw new Error(`${target.name} at ${target.addr} has no inside to append to`);
    const bad = frag.blocks.filter((b) => !sameKind(want, kindOf(b)));
    if (bad.length) throw new Error(`a ${kindOf(bad[0])} (${bad[0].name}) cannot go ${op === 'append' ? 'inside' : op === 'replace' ? 'in place of' : op} a ${kind} (${target.name} at ${target.addr}): it needs ${want === 'module' ? 'modules' : want + 's'}. Use --from to pick the right part of the file`);
  }
  const lineStart = src[target.start - 1] === '\n' || target.start === 0;
  if (op === 'replace') { start = target.start; end = target.end; insert = frag.text; }
  else if (op === 'remove') {
    start = target.start; end = target.end;
    if (lineStart && src[end] === '\n') end++;                              // a block on its own line takes its line break with it
  }
  else if (op === 'before') { start = end = target.start; insert = frag.text + (lineStart ? '\n' : ''); }
  else if (op === 'after') { start = end = target.end; insert = (src[end] === '\n' || end === src.length ? '\n' : '') + frag.text; }
  else if (op === 'append') { start = end = target.innerEnd; insert = frag.text + '\n'; if (src[start - 1] !== '\n') insert = '\n' + insert; }
  else throw new Error(`unknown operation "${op}"`);
  const out = src.slice(0, start) + insert + src.slice(end);
  // Proof, not hope: it parses, and everything outside start..end is the original text.
  const again = parse(out);
  if (again.errors.length) throw new Error('the edited page would not parse; nothing written:\n  ' + again.errors.join('\n  '));
  if (out.slice(0, start) !== src.slice(0, start) || out.slice(start + insert.length) !== src.slice(end)) throw new Error('internal: text outside the edit changed');
  const summary = { ok: true, op, at: String(at), target: { address: target.addr, block: target.name, label: labelOf(target) || undefined, cssId: cssIdOf(target) || undefined },
    range: [start, end], removedChars: end - start, insertedChars: insert.length, inserted: frag ? frag.blocks.map((b) => b.name) : [], inputHash: md5(src), outputHash: md5(out) };
  return { out, summary };
}

function treeLines(root, all) {
  const lines = [];
  const show = (b, depth) => {
    for (const c of b.children) {
      const k = kindOf(c);
      if (k === 'placeholder' || (k === 'other' && !all)) { show(c, depth); continue; }
      const lbl = labelOf(c), id = cssIdOf(c);
      lines.push(`${(c.addr || '-').padEnd(12)} ${'  '.repeat(depth)}${c.name.replace(/^divi\//, '')}${lbl ? `  «${lbl}»` : ''}${id ? `  #${id}` : ''}`);
      show(c, depth + 1);
    }
  };
  walk(root); show(root, 0);
  return lines;
}

module.exports = { parse, walk, resolve, edit, kindOf };

if (require.main === module) {
  const [cmd, file, ...rest] = process.argv.slice(2);
  const flag = (k) => { const i = rest.indexOf('--' + k); if (i < 0) return undefined; const v = rest[i + 1]; return (v === undefined || v.startsWith('--')) ? true : v; };
  const die = (msg) => { process.stderr.write(msg + '\n'); process.exit(1); };
  if (!cmd || !file) die('usage: node blocks.js tree|get|replace|before|after|append|remove|check <file> [...]   (see the header of this file)');
  let src; try { src = fs.readFileSync(file, 'utf8'); } catch (e) { die(`cannot read ${file}: ${e.message}`); }
  try {
    if (cmd === 'check') {
      const { root, errors } = parse(src);
      if (errors.length) die('does not parse:\n  ' + errors.join('\n  '));
      console.log(JSON.stringify({ ok: true, blocks: walk(root).length, hash: md5(src) }));
    } else if (cmd === 'tree') {
      const { root, errors } = parse(src);
      if (errors.length) process.stderr.write('WARNING: does not parse cleanly:\n  ' + errors.join('\n  ') + '\n');
      if (flag('json')) console.log(JSON.stringify(walk(root).map((b) => ({ address: b.addr, block: b.name, label: labelOf(b) || undefined, cssId: cssIdOf(b) || undefined, chars: b.end - b.start })), null, 1));
      else console.log(treeLines(root, !!flag('all')).join('\n'));
    } else if (cmd === 'get') {
      const at = flag('at'); if (typeof at !== 'string') die('get needs --at <address>');
      const { root } = parse(src); const b = resolve(root, at);
      const text = src.slice(b.start, b.end);
      if (typeof flag('out') === 'string') { fs.writeFileSync(flag('out'), text, 'utf8'); console.log(JSON.stringify({ ok: true, address: b.addr, block: b.name, saved: flag('out'), chars: text.length })); }
      else process.stdout.write(text + '\n');
    } else if (['replace', 'before', 'after', 'append', 'remove'].includes(cmd)) {
      const at = flag('at'), out = flag('out'), w = flag('with'), from = flag('from');
      if (typeof at !== 'string') die(`${cmd} needs --at <address>`);
      if (typeof out !== 'string') die(`${cmd} needs --out <file> (the input file is never changed)`);
      if (out === file) die('--out must be a different file from the input: keep the original for the hash and for a second look');
      const withText = cmd === 'remove' ? undefined : (typeof w === 'string' ? fs.readFileSync(w, 'utf8') : die(`${cmd} needs --with <file>`));
      const r = edit(src, cmd, { at, withText, from: typeof from === 'string' ? from : undefined });
      fs.writeFileSync(out, r.out, 'utf8');
      console.log(JSON.stringify(Object.assign(r.summary, { saved: out })));
    } else die(`unknown command "${cmd}"`);
  } catch (e) { die('REFUSED: ' + e.message); }
}
