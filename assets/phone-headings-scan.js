// Paste into the browser console (or run through the browser pane's javascript tool) on a page shown at phone width
// (375px). Lists only the headings that look wrong on a phone: larger than 40px, a word wider than its box, or a short
// title (5 words or fewer) wrapping to three lines or more; plus whether the page scrolls sideways.
// Fixes that worked: a tablet/phone size on the heading's PRESET (not on each module); Divi's full-width header keeps
// its text block at 80% width on phones, so `.et_pb_fullwidth_header .et_pb_fullwidth_header_container .header-content
// { width: 100% !important }` under @media (max-width: 767px) in the site CSS.
(() => {
  const out = [];
  for (const h of document.querySelectorAll('#et-boc h1, #et-boc h2, #et-boc h3')) {
    const cs = getComputedStyle(h), r = h.getBoundingClientRect(); if (!r.width || !r.height) continue;
    const lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4, lines = Math.round(r.height / lh), size = parseFloat(cs.fontSize);
    const words = h.textContent.trim().split(/\s+/).length, overflow = h.scrollWidth > h.clientWidth + 2;
    if (size > 40 || overflow || (lines >= 3 && words <= 5)) out.push(h.tagName + ' ' + size + 'px x' + lines + (overflow ? ' OVERFLOWS' : '') + ' "' + h.textContent.trim().slice(0, 40) + '"');
  }
  return { path: location.pathname, viewport: innerWidth, sideways: document.documentElement.scrollWidth > innerWidth ? document.documentElement.scrollWidth : 0, problems: out };
})();
