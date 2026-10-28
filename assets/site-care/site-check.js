#!/usr/bin/env node
// Page sweep of a WordPress / Divi site, for a go-live or after a big change: every listed path answers 200, Divi
// rendered (no leaked block comments), nothing still points at the old host, optional per-page expectations, plus
// redirect / status rules. Exit code 1 when anything fails.
//   node site-check.js <base-url> --pages <pages.json> [--old-host dev.example.com]
// pages.json: { "pages": ["/", "/about/"], "expect": { "/": [["pattern", 1]] }, "rules": [["/old/", [301, 404], "why"]] }
//   expect: regex source + minimum count in the HTML; rules: path, allowed status codes, description.
const fs = require('fs');
const argv = process.argv.slice(2); const BASE = (argv[0] || '').replace(/\/$/, '');
const arg = n => { const i = argv.indexOf(n); return i > 0 ? argv[i + 1] : ''; };
if (!BASE || !arg('--pages')) { console.error('usage: node site-check.js <base-url> --pages <pages.json> [--old-host host]'); process.exit(1); }
const cfg = JSON.parse(fs.readFileSync(arg('--pages'), 'utf8')); const OLD = arg('--old-host');
const UA = { 'User-Agent': 'Mozilla/5.0 (site check)' };
const get = async (path, redirect) => { const r = await fetch(BASE + path, { headers: UA, redirect }); const text = redirect === 'manual' && r.status >= 300 && r.status < 400 ? '' : await r.text(); return { status: r.status, location: r.headers.get('location') || '', robots: r.headers.get('x-robots-tag') || '', text }; };
(async () => {
  let bad = 0; const t0 = Date.now(); console.log('Checking', BASE);
  for (const p of cfg.pages || []) {
    const r = await get(p, 'follow'); const probs = [];
    if (r.status !== 200) probs.push('HTTP ' + r.status);
    if (OLD && BASE.indexOf(OLD) < 0) { const n = (r.text.match(new RegExp(OLD.replace(/\./g, '\\.'), 'g')) || []).length; if (n) probs.push(n + ' references to ' + OLD); }
    if (/<!-- wp:divi\//.test(r.text)) probs.push('leaked block comments');
    if (!/et_pb_section/.test(r.text)) probs.push('no Divi sections');
    if (/<meta name=.robots.[^>]*noindex/.test(r.text) && !(cfg.noindexOk || []).includes(p)) probs.push('noindex meta');
    for (const [re, min] of ((cfg.expect || {})[p] || [])) { const n = (r.text.match(new RegExp(re, 'g')) || []).length; if (n < min) probs.push(`expected /${re}/ x${min}, found ${n}`); }
    const title = (r.text.match(/<title>([^<]*)<\/title>/) || ['', '?'])[1];
    console.log((probs.length ? 'FAIL ' : 'ok   ') + p.padEnd(42) + String(Math.round(r.text.length / 1024)).padStart(4) + ' KB  ' + title.slice(0, 45) + (probs.length ? '  <-- ' + probs.join('; ') : ''));
    if (probs.length) bad++;
  }
  if ((cfg.rules || []).length) console.log('\nRules');
  for (const [p, codes, what] of cfg.rules || []) {
    const r = await get(p, 'manual'); const ok = codes.includes(r.status);
    console.log((ok ? 'ok   ' : 'FAIL ') + p.padEnd(42) + r.status + (r.location ? ' -> ' + r.location.replace(BASE, '') : '') + (r.robots ? ' [' + r.robots + ']' : '') + '  ' + (what || ''));
    if (!ok) bad++;
  }
  const robots = await get('/robots.txt', 'follow');
  console.log('\nrobots.txt:', robots.status, /Disallow: \/\s*$/m.test(robots.text) ? 'DISALLOWS EVERYTHING' : 'ok', '| sitemap line:', /Sitemap:/.test(robots.text) ? 'yes' : 'no');
  console.log(`\n${bad ? bad + ' problem(s)' : 'all clear'} in ${Math.round((Date.now() - t0) / 1000)} s`);
  process.exit(bad ? 1 : 0);
})();
