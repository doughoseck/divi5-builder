// Image slot audit: run INSIDE a page in a browser (paste into the console or a browser tool) at a desktop and a
// phone viewport. Lists every visible picture (img and CSS background): where it sits, the box it fills, the file
// pixel size. Returns {url, vw, n, items:[[slot, kind, boxW, boxH, fileW, fileH, file]]}. Used for a graphics size guide.
async function measure() {
  await new Promise(r => setTimeout(r, 2500)); window.scrollTo(0, document.body.scrollHeight); await new Promise(r => setTimeout(r, 1500)); window.scrollTo(0, 0); await new Promise(r => setTimeout(r, 800));
  const out = [], seen = new Set();
  const frac = el => { const c = el.closest('.et_pb_column'); if (!c) return ''; const m = c.className.match(/et_flex_column_(\d+)_24/); if (m) return 'col ' + Math.round(Number(m[1]) / 24 * 100) + '%'; const n = c.parentElement ? c.parentElement.querySelectorAll(':scope > .et_pb_column').length : 1; return 'col 1/' + n; };
  const slot = el => {
    if (el.closest('.et-l--header')) return 'site header'; if (el.closest('.et-l--footer')) return 'footer';
    if (el.closest('.et_pb_fullwidth_header')) return 'hero (fullwidth header)';
    if (el.classList.contains('et_pb_section') || el.closest('.et_pb_section') === el) return 'section background';
    if (el.closest('.dp-dfg-item')) return 'FilterGrid card'; if (el.closest('.et_pb_blog .et_pb_post, .et_pb_blog article')) return 'blog list card';
    if (el.closest('.et_pb_post_content')) return 'post body (single post)'; if (el.closest('.et_pb_post_title, .et_pb_title_featured_container')) return 'post featured image';
    if (el.closest('.et_pb_slider')) return 'slider'; if (el.closest('.dp-ddg-item, [class*="dp-ddg-"]')) return 'gallery item';
    if (el.closest('.et_pb_timeline')) return 'timeline card'; if (el.closest('.dp-dss, [class*="dp-dss"]')) return 'testimonial slider';
    if (el.classList.contains('et_pb_row')) return 'row background'; if (el.classList.contains('et_pb_column')) return 'column background ' + frac(el);
    return 'image in ' + (frac(el) || 'full row'); };
  const push = (el, kind, src, nw, nh) => { const b = el.getBoundingClientRect(); if (b.width < 40 || b.height < 40) return; const s = slot(el); const file = src.split('/').pop().split('?')[0].slice(0, 44);
    const k = s + '|' + Math.round(b.width / 20) + '|' + Math.round(b.height / 20); if (seen.has(k)) return; seen.add(k); out.push([s, kind, Math.round(b.width), Math.round(b.height), nw, nh, file]); };
  for (const im of document.querySelectorAll('img')) { const src = im.currentSrc || im.src; if (!src || /^data:/.test(src)) continue; const st = getComputedStyle(im); if (st.display === 'none' || st.visibility === 'hidden') continue; push(im, 'img', src, im.naturalWidth, im.naturalHeight); }
  for (const el of document.querySelectorAll('*')) { const bg = getComputedStyle(el).backgroundImage; if (!bg || !/url\(/.test(bg)) continue; const m = bg.match(/url\("?([^")]+)"?\)/); if (!m || /\.svg|^data:/.test(m[1])) continue; if (getComputedStyle(el).display === 'none') continue; let nw = 0, nh = 0; try { const i = new Image(); i.src = m[1]; if (i.complete) { nw = i.naturalWidth; nh = i.naturalHeight; } } catch (e) {} push(el, 'bg', m[1], nw, nh); }
  return { url: location.pathname, vw: innerWidth, n: out.length, items: out };
}
