#!/usr/bin/env node
// Tracking tags on two sites' homepages (before a go-live: the live site vs the rebuilt copy): GA4 / UA / GTM ids, the
// Meta pixel id, a few known vendors. A tag typed into an old theme's header vanishes on a rebuilt copy; a plugin
// connection may not survive a domain change.   node tracking-compare.js <url-a> <url-b>
const [A, B] = process.argv.slice(2); if (!A || !B) { console.error('usage: node tracking-compare.js <url-a> <url-b>'); process.exit(1); }
const UA = { 'User-Agent': 'Mozilla/5.0 (tracking compare)' };
const find = html => ({
  ga4: [...new Set(html.match(/G-[A-Z0-9]{6,12}/g) || [])], ua: [...new Set(html.match(/UA-\d{4,10}-\d+/g) || [])], gtm: [...new Set(html.match(/GTM-[A-Z0-9]{5,9}/g) || [])],
  // the official Meta plugin 5.x starts the pixel through FacebookSignal.initPixel("id"); older versions and hand-pasted code through fbq('init', 'id')
  metaPixel: [...new Set((html.match(/(?:fbq\(['"]init['"],\s*|initPixel\()['"](\d{6,20})['"]/g) || []).map(s => s.match(/\d{6,20}/)[0]))],
  gtagJs: /googletagmanager\.com\/gtag\/js/.test(html), monsterinsights: /monsterinsights/i.test(html), hotjar: /hotjar/i.test(html), clarity: /clarity\.ms/.test(html),
  tiktok: /analytics\.tiktok\.com/.test(html), linkedin: /snap\.licdn\.com/.test(html), yoastSchema: /yoast-schema-graph/.test(html),
});
(async () => {
  const out = {};
  for (const u of [A, B]) { try { out[u] = find(await (await fetch(u + (u.includes('?') ? '&' : '?') + 'nocache=' + Date.now(), { headers: UA })).text()); console.log(u, JSON.stringify(out[u])); } catch (e) { console.log(u, 'ERR', e.message); } }
  const a = out[A], b = out[B]; if (!a || !b) return;
  const diff = Object.keys(a).filter(k => JSON.stringify(a[k]) !== JSON.stringify(b[k]));
  console.log(diff.length ? 'DIFFERENT: ' + diff.join(', ') : 'identical tracking');
})();
