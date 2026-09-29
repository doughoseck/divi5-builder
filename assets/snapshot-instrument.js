// COMPUTED-STYLE SNAPSHOT: proves that a bulk change (colours -> globals, presets) changed nothing a visitor sees.
// Playbook: references/divi5-globalize-site.md, section 8.
// HOW TO USE: open any page of the site in a browser tab you can run JavaScript in (same origin, not a chrome:// page).
//   1. window.__urls = ["https://example.com/", "https://example.com/about/", ...]   every url to compare
//   2. paste this whole file
//   3. window.__run("before")                  fire and forget; poll window.__jobDone / window.__jobN
//   4. window.__run("diff")                    a CONTROL first (nothing changed): must report 0
//   5. make the change, no-op CSS save, warm every url twice, then window.__run("diff") and read window.__summary()
//   one page only: window.__run("diff", ["/about/"]). Another width: window.__W = 390, then a new BEFORE.
// The BEFORE snapshot lives in this origin's localStorage (keys bfsnap:before:<url>).
// Known noise it ignores: countdown timer digits, a Theme Builder header section's scroll-state background,
// and colour NOTATION (color(srgb 0 0 0 / 0.33) == rgba(0,0,0,0.33)).
// Blind spots: hover, pseudo-elements, background images, every width but window.__W. Text is recorded for leaf
// elements only, so a heading whose text sits in a child element has text "".
if (!Array.isArray(window.__urls) || !window.__urls.length) throw new Error("set window.__urls first");
window.__W = window.__W || 1400; // set window.__W = 390 (and take a new BEFORE) to check a phone width
window.__sig = async (url) => {
  const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;left:-3000px;top:0;width:' + window.__W + 'px;height:900px;visibility:hidden;border:0'; document.body.appendChild(f);
  await new Promise(res => { f.onload = res; f.src = url + (url.includes('?') ? '&' : '?') + 'snap=' + Date.now(); setTimeout(res, 30000); });
  await new Promise(r => setTimeout(r, 3500));
  const d = f.contentDocument, w = f.contentWindow;
  const st = d.createElement('style'); st.textContent = '*{transition:none !important;animation:none !important}'; d.head.appendChild(st);
  await new Promise(r => setTimeout(r, 200));
  const ORD = /^et_pb_[a-z_]+_\d+(_tb_\w+)?$/; const oc = e => String(e.className && e.className.baseVal === undefined ? e.className : '').split(' ').find(x => ORD.test(x)) || '';
  const owner = e => { let n = e; while (n && n !== d.body) { const c = oc(n); if (c) return c; n = n.parentElement; } return 'none'; };
  const els = [...d.querySelectorAll('.et_pb_section, .et_pb_row, .et_pb_column, .et_pb_module, .et_pb_button, h1,h2,h3,h4,h5,h6, p, li, .et_pb_toggle_title, .et_pb_social_icon a, .et_pb_menu a')].filter(e => !e.closest('#wpadminbar'));
  const sp = s => s.replace(/\s+/g, ''); const map = {}; const counter = {};
  for (const e of els) { const own = oc(e); let key; if (own) key = own; else { const o = owner(e); const base = o + '>' + e.tagName; counter[base] = (counter[base] || 0) + 1; key = base + '#' + counter[base]; }
    if (map[key] !== undefined) { counter[key] = (counter[key] || 1) + 1; key = key + '~' + counter[key]; }
    const c = w.getComputedStyle(e);
    map[key] = [sp(c.color), sp(c.backgroundColor), c.fontSize, c.fontWeight, c.textTransform, c.lineHeight, sp(c.borderTopColor) + '/' + sp(c.borderLeftColor) + '/' + sp(c.borderBottomColor), c.borderLeftWidth + '/' + c.borderBottomWidth, (e.childElementCount === 0 ? e.textContent.trim().slice(0, 18) : ''), c.paddingTop + '/' + c.paddingRight + '/' + c.paddingBottom + '/' + c.paddingLeft, c.marginTop + '/' + c.marginBottom + '/' + c.marginLeft, c.textAlign, c.maxWidth + '/' + c.width, c.display].join('|'); }
  f.remove(); return map;
};
window.__fullDiff = async (u) => { const norm = s => s.replace(/color\(srgb000\/([0-9.]+)\)/g, 'rgba(0,0,0,$1)'); const names = ['color', 'bg', 'size', 'weight', 'transform', 'lh', 'borderColors', 'borderW', 'text', 'padding', 'margin', 'align', 'maxw/width', 'display']; const before = JSON.parse(localStorage.getItem('bfsnap:before:' + u) || '{}'); const after = await window.__sig(u); let n = 0, notation = 0, noise = 0; const real = [];
  for (const k of new Set([...Object.keys(before), ...Object.keys(after)])) { if (before[k] === after[k]) continue; n++; if (before[k] === undefined || after[k] === undefined) { real.push(k + (before[k] === undefined ? ' ADDED' : ' REMOVED')); continue; } const b = before[k].split('|'), a = after[k].split('|'); const ch = names.map((nm, i) => b[i] !== a[i] ? [nm, b[i], a[i]] : null).filter(Boolean); if (ch.every(c => norm(c[1]) === norm(c[2]))) { notation++; continue; } if (/countdown_timer/.test(k) || (/^et_pb_section_\d_tb_header$/.test(k) && ch.every(c => c[0] === 'bg'))) { noise++; continue; } real.push(k + ' "' + (a[8] || '') + '" ' + ch.filter(c => norm(c[1]) !== norm(c[2])).map(c => c[0] + ': ' + c[1] + ' -> ' + c[2]).join('; ')); }
  return { url: u.replace(location.origin, ''), keys: Object.keys(after).length, diffs: n, notation, noise, real: real.length, first: real.slice(0, 6) }; };
window.__run = (mode, only) => { const list = only ? window.__urls.filter(u => only.some(o => u.endsWith(o))) : window.__urls; if (mode === 'before') { Object.keys(localStorage).filter(k => k.startsWith('bfsnap:')).forEach(k => localStorage.removeItem(k)); window.__jobDone = false; window.__jobN = 0; (async () => { for (const u of list) { const sig = await window.__sig(u); localStorage.setItem('bfsnap:before:' + u, JSON.stringify(sig)); window.__jobN++; } window.__jobDone = true; })(); return 'before started'; }
  window.__dDone = false; window.__dRes = []; (async () => { for (const u of list) { try { window.__dRes.push(await window.__fullDiff(u)); } catch (e) { window.__dRes.push({ url: u, error: String(e) }); } } window.__dDone = true; })(); return 'diff started'; };
// compact result: never dump window.__dRes whole, it floods the context
window.__summary = () => ({ done: window.__dDone, pages: (window.__dRes || []).length, keys: (window.__dRes || []).reduce((s, r) => s + (r.keys || 0), 0), errors: (window.__dRes || []).filter(r => r.error).length, totalReal: (window.__dRes || []).reduce((s, r) => s + (r.real || 0), 0), nonZero: (window.__dRes || []).filter(r => r.real).map(r => ({ u: r.url.slice(0, 30), real: r.real, first: r.first.slice(0, 3).map(x => x.slice(0, 110)) })) });
'snapshot instrument loaded: ' + window.__urls.length + ' urls';
