#!/usr/bin/env node
// Before a dev -> live move: what was created or edited on the LIVE site since the dev copy was taken, and whether dev
// has it (matched by slug). Public REST only, no credentials; custom post types are included when they are public.
//   node content-delta.js <live-url> <dev-url> <since-ISO> [type ...]        (default types: posts pages media)
const [LIVE, DEV, SINCE, ...types] = process.argv.slice(2).map(s => s.replace(/\/$/, ''));
if (!LIVE || !DEV || !SINCE) { console.error('usage: node content-delta.js <live-url> <dev-url> <since-ISO> [type ...]'); process.exit(1); }
const UA = { 'User-Agent': 'Mozilla/5.0 (content delta)' };
const getAll = async (base, route, q) => { const out = []; for (let page = 1; page < 20; page++) { const r = await fetch(`${base}/wp-json/wp/v2/${route}?${q}&per_page=100&page=${page}`, { headers: UA }); if (!r.ok) { if (page === 1) return { error: r.status }; break; } const j = await r.json(); if (!Array.isArray(j) || !j.length) break; out.push(...j); if (j.length < 100) break; } return out; };
(async () => {
  for (const route of types.length ? types : ['posts', 'pages', 'media']) {
    const live = await getAll(LIVE, route, `modified_after=${SINCE}&_fields=id,modified,date,slug,title,link`);
    if (live.error) { console.log(`\n== ${route}: live answered ${live.error} (not public over REST?)`); continue; }
    console.log(`\n== ${route}: ${live.length} item(s) created or modified on live since ${SINCE}`);
    for (const it of live) {
      const dev = await fetch(`${DEV}/wp-json/wp/v2/${route}?slug=${encodeURIComponent(it.slug)}&_fields=id,modified&nocache=delta`, { headers: UA }).then(r => r.ok ? r.json() : []).catch(() => []);
      const onDev = Array.isArray(dev) && dev.length ? `dev ${dev[0].id} modified ${dev[0].modified.slice(0, 16)}` : 'NOT ON DEV';
      console.log(`  ${it.date >= SINCE ? 'NEW ' : 'edit'} ${it.id} live-modified ${it.modified.slice(0, 16)} /${it.slug}/ "${(it.title && (it.title.rendered || it.title) || '').slice(0, 55)}" | ${onDev}`);
    }
  }
  const c = await fetch(`${LIVE}/wp-json/wp/v2/comments?after=${SINCE}&per_page=100&_fields=id`, { headers: UA }).then(r => r.json()).catch(() => null);
  console.log(`\n== comments on live since then: ${Array.isArray(c) ? c.length : 'unknown'}`);
  console.log('\nNot visible over public REST: menus, widgets, options, plugin settings, form entries, drafts. Ask the owner.');
})();
