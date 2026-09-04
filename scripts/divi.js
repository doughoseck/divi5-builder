#!/usr/bin/env node
/*
 * divi.js — compile a compact page spec into Divi 5 native block markup.
 *
 *   node divi.js compile <spec.json>            # prints block markup to stdout
 *   node divi.js compile <spec.json> --out F     # writes to file F
 *
 * You author a small JSON spec; this emits valid, builder-editable Divi 5
 * markup (section > row > column > modules) with the correct content keys,
 * builderVersion, leading placeholder, and comment-safe escaping. Prefer this
 * over hand-writing blocks. See references/divi5-format.md for the schema this
 * is built from, and the header of SKILL.md for the spec shape.
 *
 * SPEC SHAPE (all styling fields optional):
 * {
 *   "builderVersion": "5.9.0",
 *   "sections": [{
 *     "background": {"color":"#0F4D2A"}            // literal hex
 *                 | {"colorVar":"gcid-primary-color"} // global color
 *                 | {"image":"https://...","gradientOverlay":"0deg"},
 *     "padding": {"top":"80px","bottom":"80px"},
 *     "rows": [{
 *       "layout": "1" | "1-1" | "1-1-1" | "1-1-1-1",   // default "1"
 *       "columns": [{ "modules": [ ...module specs... ] }]
 *     }]
 *   }]
 * }
 *
 * STYLING RULE — NO INLINE CSS. Content is semantic HTML only (no style="").
 * Typography/colour come from module SETTINGS + GLOBAL variables: pass a gcid
 * name for any colour (rendered as a global-colour token) and a global font family
 * name. Columns are always 100%; control layout with row flexWrap (see below).
 *
 * MODULE SPECS (all styling via settings — colours accept "gcid-*" names):
 *   {"type":"heading","level":"h1","text":"Hi","family":"Outfit","size":"44px","weight":"800","color":"gcid-heading-color","align":"center"}
 *   {"type":"text","html":"<p>Body</p>","size":"18px","color":"gcid-body-color","align":"left","textColor":"light"}
 *   {"type":"button","text":"Sign up","url":"https://x","bg":"gcid-secondary-color","color":"gcid-heading-color","radius":"10px","padding":{"top":"12px","bottom":"12px","left":"22px","right":"22px"},"popup":"tourpop"}
 *   {"type":"image","src":"https://x.png","alt":"...","id":"223341","frame":{"radius":"12px","shadow":true,"border":{"color":"gcid-..."}}}
 *   {"type":"blurb","title":"Attendance","text":"...","titleLevel":"h4","icon":{"unicode":"&#xf00c;","type":"fa"},"chip":{"bg":"gcid-primary-color"}}
 *   {"type":"iconlist","items":["Forms","Sparring"],"icon":{"unicode":"&#xf00c;"}}
 *   {"type":"accordion","openFirst":true,"items":[{"title":"Q?","text":"A."}]}   // native FAQ (not code)
 *   {"type":"divider","height":"300px","showLine":false}   // invisible spacer / column-height holder
 *   {"type":"video","src":"https://x.mp4","frame":{"radius":"14px","shadow":true}}
 *   {"type":"icon","unicode":"&#x51;","iconType":"divi","absolute":true}   // e.g. popup close
 *   {"type":"code","html":"<div>…custom HTML/CSS…</div>"}   // fallback — keep rare
 *   {"type":"raw","block":"divi/xxx","attrs":{...},"inner":"..."}          // escape hatch
 *
 * VISIBILITY TOGGLES (native, no JS): any module may carry "toggleId":"<id>"
 * (becomes a toggle target) and "hidden":true (starts hidden). A button may carry
 * "toggles":["idA",…] (+ "trigger":"<id>") to flip every listed target on click.
 * Give both control buttons the same full id list (incl. their own ids). Used for
 * monthly/annual pricing, show-more, etc.
 *
 * COLUMNS/LAYOUT: rows take layout "1" | "1-1" | "1-1-1" | "1-1-1-1" + optional
 * wrapAt ("phone" default | "tablet"). Every column is 100% (flexType 24_24); the
 * row uses flex and wraps (stacks) at wrapAt — nowrap above it. Columns also take
 * bgImage/bgPosition (image, cover), background (gcid|hex), rowGap, and CARD props:
 * padding, radius, border{color,width}, hover{borderColor, shadow{blur,spread}}.
 */
const fs = require('fs');

let BV = '5.9.0'; // overwritten from spec.builderVersion

// ---- comment-safe JSON (mirror WP serialize_block escaping) ----
function attrJSON(obj) {
  return JSON.stringify(obj)
    .replace(/</g, '\\u003c').replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026').replace(/'/g, '\\u0027');
}
function open(name, attrs) { return `<!-- wp:${name}${attrs ? ' ' + attrJSON(attrs) : ''} -->`; }
function close(name) { return `<!-- /wp:${name} -->`; }
function selfClose(name, attrs) { return `<!-- wp:${name}${attrs ? ' ' + attrJSON(attrs) : ''} /-->`; }

// color -> either literal or global $variable()$ token
function colorToken(spec) {
  if (!spec) return null;
  if (typeof spec === 'string') return spec;               // already a value
  if (spec.colorVar) return `$variable({"type":"color","value":{"name":"${spec.colorVar}","settings":${spec.opacity != null ? JSON.stringify({ opacity: spec.opacity }) : '{}'}}})$`;
  if (spec.color) return spec.color;
  return null;
}

// ---- module builders ----
//
// HARD RULE: NEVER emit inline CSS (a `style="..."` attribute) in module content.
// Inline CSS defeats the Divi builder — it's un-editable in the UI and the hardest
// thing to find/change later. Style ONLY through Divi module settings (the
// decoration.* objects below) and, for colours/fonts, prefer the site's GLOBAL
// variables/presets so one edit re-themes the whole site. Content values hold
// clean SEMANTIC html (<h1>, <h2>, <p>, <ul>…) with NO style attributes.

// Resolve a colour to a Divi global-variable token when a gcid name is given,
// else a literal. Prefer gcid names — discover them with `wp.js global-colors`.
function colorVal(c) {
  if (c == null) return undefined;
  if (typeof c === 'string') return c.startsWith('gcid-') ? colorToken({ colorVar: c }) : c;
  if (c.colorVar) return colorToken(c);
  if (c.color) return c.color;
  return undefined;
}
// Build a Divi font `.value` object from typography props (all optional).
function fontValue(m) {
  const v = {};
  if (m.family) v.family = m.family;         // a global font family name, e.g. "Outfit"
  if (m.size) v.size = m.size;
  if (m.weight) v.weight = String(m.weight);
  if (m.color != null) { const c = colorVal(m.color); if (c) v.color = c; }
  if (m.lh) v.lineHeight = m.lh;
  if (m.ls) v.letterSpacing = m.ls;
  if (m.caps) v.capitalization = m.caps;     // "uppercase" | "capitalize" | ...
  return v;
}
// module.advanced.text.text value: text alignment + light/dark colour scheme.
function textAdvanced(m) {
  const v = {};
  if (m.align) v.orientation = m.align;
  if (m.textColor) v.color = m.textColor;    // "light" (on dark bg) | "dark"
  return Object.keys(v).length ? v : null;
}

// Divi 5 "Interaction" that toggles a popup canvas's visibility on click.
// targetId must match the popup canvas section's interactionTarget id.
function popupInteractionDecoration(targetId) {
  const trig = 't' + targetId;
  return {
    interactionTrigger: trig,
    interactions: { desktop: { value: { interactions: [{
      id: trig + 'i', enableInteraction: 'on', trigger: 'click', effect: 'toggleVisibility',
      target: { targetClass: 'et-interaction-target-' + targetId, label: 'Popup', moduleId: '', targetType: 'module' },
      replaceExistingPreset: false, sensitivity: 50, mouseMovementType: 'translate',
      cookieName: '', cookieValue: '', triggerClass: 'et-interaction-trigger-' + trig, presetId: '', timeDelay: '0ms',
    }] } } },
  };
}

// Hidden-by-default (Visibility off on all breakpoints) — the "state B" of a toggle.
function hiddenDecoration() { return { disabledOn: { desktop: { value: 'on' }, tablet: { value: 'on' }, phone: { value: 'on' } } }; }
// Generic click-interaction: one trigger, one effect, one or more targets.
// effect: "toggleVisibility" | "scrollToElement" | "togglePreset" | "showElement" | "hideElement" …
function effectInteraction(triggerId, effect, targetIds, extra) {
  const ids = Array.isArray(targetIds) ? targetIds : [targetIds];
  return {
    interactionTrigger: triggerId,
    interactions: { desktop: { value: { interactions: ids.map((tid, k) => Object.assign({
      id: triggerId + 'i' + k, enableInteraction: 'on', trigger: 'click', effect,
      target: { targetClass: 'et-interaction-target-' + tid, label: 'Target', moduleId: '', targetType: 'module' },
      replaceExistingPreset: false, sensitivity: 50, mouseMovementType: 'translate',
      cookieName: '', cookieValue: '', triggerClass: 'et-interaction-trigger-' + triggerId, presetId: '', timeDelay: '0ms',
    }, extra || {})) } } },
  };
}
// Toggle visibility of many targets at once (monthly/annual switch etc.).
function togglesDecoration(triggerId, targetIds) { return effectInteraction(triggerId, 'toggleVisibility', targetIds); }
// A module's groupPreset reference: { slot, id, group } → attach an existing preset by id.
function presetAttr(m, attrs) {
  if (m.preset) attrs.groupPreset = { [m.preset.slot || 'designTitleText']: { presetId: [m.preset.id], groupName: m.preset.group || 'divi/font' } };
  return attrs;
}
// Dynamic-content token (Loop Builder fields etc.). Put in any content value.
//   dc('loop_post_title'), dc('loop_post_featured_image',{thumbnail_size:'large'}) (image src),
//   dc('loop_post_excerpt'), custom field:
//   dc('loop_post_meta_key_manual_custom_field',{select_loop_meta_key:'loop_post_meta_key_<key>'})
function dc(name, settings) { return '$variable(' + JSON.stringify({ type: 'content', value: { name, settings: settings || {} } }) + ')$'; }
// Loop query value for a column/group's module.advanced.loop.
// loop = { id?, postTypes:["belt"], orderBy?, order?, postPerPage? }
function loopValue(loop) {
  return { enable: 'on', loopId: 'loop-' + (loop.id || 'x'), queryType: 'post_types',
    subTypes: (loop.postTypes || []).map((v) => (typeof v === 'string' ? { value: v, label: v } : v)),
    orderBy: loop.orderBy || 'date', order: loop.order || 'ascending', postPerPage: String(loop.postPerPage || '99'),
    includePostWithSpecificTerms: '', excludePostWithSpecificTerms: '', includeSpecificPosts: '', excludeSpecificPosts: '' };
}
// Merge the generic toggle props any module may carry into its module.decoration:
//   toggleId → interactionTarget (makes it a toggle TARGET, class et-interaction-target-<id>)
//   hidden   → disabledOn all breakpoints (starts hidden)
function applyCommon(m, module) {
  if (!m.toggleId && !m.hidden) return module;
  module = module || {};
  module.decoration = module.decoration || {};
  if (m.toggleId) module.decoration.interactionTarget = m.toggleId;
  if (m.hidden) Object.assign(module.decoration, hiddenDecoration());
  return module;
}

// Heading: clean <hN> content, styled via the module's headingFont.<level> SETTINGS.
// Pass a gcid name for `color` and a global font name for `family` where possible.
function mHeading(m) {
  const level = m.level || 'h2';
  const attrs = { content: { innerContent: { desktop: { value: `<${level}>${m.text || ''}</${level}>` } } }, builderVersion: BV };
  const fv = fontValue(m);
  if (Object.keys(fv).length) attrs.content.decoration = { headingFont: { [level]: { font: { desktop: { value: fv } } } } };
  let module = null;
  const adv = textAdvanced(m);
  if (adv) module = { advanced: { text: { text: { desktop: { value: adv } } } } };
  module = applyCommon(m, module);
  if (m.maxWidth) { module = module || {}; module.decoration = Object.assign(module.decoration || {}, { sizing: { desktop: { value: { maxWidth: m.maxWidth, alignment: m.maxWidthAlign || 'center' } } } }); }
  if (module) attrs.module = module;
  return [open('divi/text', presetAttr(m, attrs)), close('divi/text')];
}
// Body text: semantic html (<p>, <ul>…), styled via bodyFont SETTINGS. No inline CSS.
// NOTE: text modules cannot be popup/interaction TRIGGERS (Divi renders none) — but they
// CAN be toggle TARGETS (toggleId/hidden). Use a button for triggers.
function mText(m) {
  const attrs = { content: { innerContent: { desktop: { value: m.html || '' } } }, builderVersion: BV };
  const fv = fontValue(m);
  if (Object.keys(fv).length) attrs.content.decoration = { bodyFont: { body: { font: { desktop: { value: fv } } } } };
  let module = null;
  const adv = textAdvanced(m);
  if (adv) module = { advanced: { text: { text: { desktop: { value: adv } } } } };
  module = applyCommon(m, module);
  if (m.maxWidth) { module = module || {}; module.decoration = Object.assign(module.decoration || {}, { sizing: { desktop: { value: { maxWidth: m.maxWidth, alignment: m.maxWidthAlign || 'center' } } } }); }
  if (module) attrs.module = module;
  return [open('divi/text', presetAttr(m, attrs)), close('divi/text')];
}
// Button: styled via button.decoration SETTINGS (font/background/border) + global
// colours; padding via module spacing. `bg`/`color` accept gcid names.
// Interactions: popup (canvas), toggles[] (visibility switch), scrollTo:"<id>"
// (scrollToElement), togglePreset:{target,presetId}. `url` is skipped if scrollTo set.
function mButton(m) {
  const module = { advanced: { alignment: { desktop: { value: m.align || 'left' } } } };
  const dec = {};
  if (m.padding) dec.spacing = { desktop: { value: { padding: Object.assign({ syncVertical: 'on', syncHorizontal: 'on' }, m.padding) } } };
  if (m.popup) Object.assign(dec, popupInteractionDecoration(m.popup));
  if (m.toggles) Object.assign(dec, togglesDecoration(m.trigger || ('tg' + m.toggles[0]), m.toggles));
  if (m.scrollTo) Object.assign(dec, effectInteraction('s' + m.scrollTo, 'scrollToElement', m.scrollTo));
  if (m.togglePreset) Object.assign(dec, effectInteraction('p' + m.togglePreset.target, 'togglePreset', m.togglePreset.target, { presetId: m.togglePreset.presetId, replaceExistingPreset: true }));
  if (m.toggleId) dec.interactionTarget = m.toggleId; // this button is also a toggle target
  if (m.hidden) Object.assign(dec, hiddenDecoration());
  if (Object.keys(dec).length) module.decoration = dec;
  const bdec = {};
  const fv = fontValue({ color: m.color, size: m.size, weight: m.weight || '700', family: m.family });
  if (Object.keys(fv).length) bdec.font = { font: { desktop: { value: fv } } };
  const bg = colorVal(m.bg); if (bg) bdec.background = { desktop: { value: { color: bg } } };
  if (m.radius) bdec.border = { desktop: { value: { radius: { topLeft: m.radius, topRight: m.radius, bottomLeft: m.radius, bottomRight: m.radius, sync: 'on' } } } };
  bdec.button = { desktop: { value: { icon: { enable: m.icon ? 'on' : 'off' } } } };
  const inner = { text: m.text || 'Learn more', linkUrl: m.url || '#' };
  if (m.newTab) inner.linkTarget = 'on';
  const attrs = { module, button: { innerContent: { desktop: { value: inner } }, decoration: bdec }, builderVersion: BV };
  return [open('divi/button', attrs), close('divi/button')];
}
function mVideo(m) {
  const attrs = { video: { innerContent: { desktop: { value: { src: m.src || '' } } } }, builderVersion: BV };
  const f = m.frame;
  if (f) {
    const dec = {};
    if (f.radius) dec.border = { desktop: { value: { radius: { topLeft: f.radius, topRight: f.radius, bottomLeft: f.radius, bottomRight: f.radius, sync: 'on' } } } };
    if (f.shadow) { const sh = f.shadow === true ? { h: '0px', v: '2px', blur: '18px', spread: '0px', color: 'rgba(0,0,0,0.3)' } : f.shadow; dec.boxShadow = { desktop: { value: { horizontal: sh.h || '0px', vertical: sh.v || '2px', blur: sh.blur || '18px', spread: sh.spread || '0px', position: 'outer', color: sh.color || 'rgba(0,0,0,0.3)', style: 'preset1' } } }; }
    attrs.module = { decoration: dec };
  }
  return [selfClose('divi/video', attrs)];
}
// A Divi font-icon module — used as the popup close button (X = unicode &#x51; in the "divi" icon font).
function mIcon(m) {
  const attrs = {
    icon: { innerContent: { desktop: { value: { unicode: m.unicode || '&#x51;', type: m.iconType || 'divi', weight: m.weight || '400' } } },
      advanced: { size: { desktop: { value: m.size || '40px' } }, color: { desktop: { value: m.color || '#ffffff' } } } },
    builderVersion: BV,
  };
  if (m.absolute) attrs.module = { decoration: { position: { desktop: { value: { mode: 'absolute', origin: { absolute: m.origin || 'top right' }, offset: { horizontal: m.offsetX || '-50px' } } } } } };
  return [selfClose('divi/icon', attrs)];
}
function mImage(m) {
  const iv = { src: m.src || '', id: m.id || '', alt: m.alt || '' };
  if (m.url) { iv.linkUrl = m.url; iv.linkTarget = m.newTab === false ? 'off' : 'on'; } // link the image (opens new tab by default)
  const attrs = { image: { innerContent: { desktop: { value: iv } } }, builderVersion: BV };
  // Optional framing (rounded corners / drop shadow / border / max width).
  const f = m.frame;
  if (f) {
    const dec = {};
    if (f.radius) dec.border = { desktop: { value: { radius: { topLeft: f.radius, topRight: f.radius, bottomLeft: f.radius, bottomRight: f.radius, sync: 'on' } } } };
    if (f.border) { dec.border = dec.border || { desktop: { value: {} } }; dec.border.desktop.value.styles = { all: { width: f.border.width || '1px', color: f.border.color || '#e6eae6', style: 'solid' } }; }
    if (f.shadow) { const sh = f.shadow === true ? { h: '0px', v: '18px', blur: '40px', spread: '0px', color: 'rgba(16,40,28,0.18)' } : f.shadow; dec.boxShadow = { desktop: { value: { horizontal: sh.h || '0px', vertical: sh.v || '18px', blur: sh.blur || '40px', spread: sh.spread || '0px', position: 'outer', color: sh.color || 'rgba(0,0,0,0.2)', style: 'preset1' } } }; }
    if (f.maxWidth) dec.sizing = { desktop: { value: { maxWidth: f.maxWidth, alignment: m.align || 'center' } } };
    attrs.module = { decoration: dec };
  }
  return [open('divi/image', attrs), close('divi/image')];
}
// Blurb: icon/image + title + body. Icon via a Font-Awesome/Divi glyph (m.icon)
// or an image (m.image). Title heading level via m.titleLevel. The small icon
// "chip" background is set through the module's Custom-CSS field (blurbImage) —
// that's a Divi module SETTING, editable in the builder, not inline HTML CSS.
function mBlurb(m) {
  const iconInner = {};
  if (m.icon) { iconInner.useIcon = 'on'; iconInner.icon = { unicode: m.icon.unicode || m.icon, type: m.icon.type || 'fa', weight: m.icon.weight || '900' }; }
  else if (m.image) { iconInner.useIcon = 'off'; iconInner.src = m.image; iconInner.id = m.imageId || ''; iconInner.alt = m.alt || ''; }
  const imageIcon = { innerContent: { desktop: { value: iconInner } }, advanced: { placement: { desktop: { value: m.iconPlacement || 'top' } } } };
  if (m.iconSize) imageIcon.decoration = { sizing: { desktop: { value: { iconFontSize: m.iconSize } } } };
  const title = { innerContent: { desktop: { value: { text: m.title || '' } } } };
  if (m.titleLevel) title.decoration = { font: { font: { desktop: { value: { headingLevel: m.titleLevel } } } } };
  const attrs = {
    imageIcon,
    module: {},
    title,
    content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } },
    builderVersion: BV,
  };
  if (m.chip) attrs.css = { desktop: { value: { blurbImage: `padding:${m.chip.padding || '6px'};border-radius:${m.chip.radius || '8px'};background:${colorVal(m.chip.bg) || 'rgba(15,77,42,.08)'};` } } };
  return [open('divi/blurb', attrs), close('divi/blurb')];
}
// Invisible spacer / height-holder. A common Divi trick: put an off divider in a
// column to give the column height so a COLUMN background image can `cover` the space.
function mDivider(m) {
  const attrs = { divider: { advanced: { line: { desktop: { value: { show: m.showLine ? 'on' : 'off' } } } } }, builderVersion: BV };
  if (m.height) attrs.module = { decoration: { sizing: { desktop: { value: { height: m.height } } } } };
  return [selfClose('divi/divider', attrs)];
}
// Native FAQ accordion. items:[{title, html|text, open}]. Prefer this over a code module.
function mAccordion(m) {
  // `iconColor`/`iconSize` style the closed-toggle icon; `itemRadius` rounds
  // each item. Divi puts the icon on the ACCORDION and the radius on each
  // ITEM — styling the accordion alone leaves square items, which is the
  // difference between matching a site's FAQ and merely resembling it.
  const accAttrs = { builderVersion: BV };
  if (m.iconColor || m.iconSize) {
    const icon = {};
    if (m.iconColor) icon.color = colorVal(m.iconColor);
    if (m.iconSize) { icon.useSize = 'on'; icon.size = m.iconSize; }
    accAttrs.closedToggleIcon = { decoration: { icon: { desktop: { value: icon } } } };
  }
  const lines = [open('divi/accordion', accAttrs)];
  (m.items || []).forEach((it, i) => {
    const a = { title: { innerContent: { desktop: { value: it.title } } }, content: { innerContent: { desktop: { value: it.html || (it.text ? `<p>${it.text}</p>` : '') } } }, builderVersion: BV };
    if (it.open || (i === 0 && m.openFirst)) a.module = { advanced: { open: { desktop: { value: 'on' } } } };
    const r = it.radius || m.itemRadius;
    if (r) {
      a.module = a.module || {};
      a.module.decoration = { border: { desktop: { value: { radius: { topLeft: r, topRight: r, bottomLeft: r, bottomRight: r, sync: 'on' } } } } };
    }
    lines.push(open('divi/accordion-item', a), close('divi/accordion-item'));
  });
  lines.push(close('divi/accordion'));
  return lines;
}
function mIconList(m) {
  // Accept `icon` as either a string ("&#xf00d;") or an object
  // ({unicode,type,weight}) — mBlurb has always taken both, and this taking
  // only the object form meant a string was SILENTLY ignored and every item
  // got the default tick. A list of things that do NOT work, bulleted with
  // ticks, is worse than no icon at all.
  const icon = typeof m.icon === 'string' ? { unicode: m.icon } : (m.icon || { unicode: '&#xf00c;' });
  const lines = [open('divi/icon-list', { builderVersion: BV })];
  for (const item of (m.items || [])) {
    const text = typeof item === 'string' ? item : item.text;
    const attrs = {
      content: { innerContent: { desktop: { value: text } } },
      icon: { innerContent: { desktop: { value: { unicode: icon.unicode || '&#xf00c;', type: icon.type || 'fa', weight: icon.weight || '900', target: 'off' } } } },
      builderVersion: BV,
    };
    lines.push(open('divi/icon-list-item', attrs), close('divi/icon-list-item'));
  }
  lines.push(close('divi/icon-list'));
  return lines;
}
function mCode(m) {
  const attrs = { content: { innerContent: { desktop: { value: m.html || '' } } }, builderVersion: BV };
  return [open('divi/code', attrs), close('divi/code')];
}
function mRaw(m) {
  if (m.inner != null) return [open(m.block, m.attrs || {}), m.inner, close(m.block)];
  return [selfClose(m.block, m.attrs || {})];
}
// ---- native modules verified from live builder (see references/divi5-modules-verified.md) ----
// Native Heading module (distinct from `heading`, which is a text module with <hN>).
function mHeadingModule(m) {
  const title = { innerContent: { desktop: { value: m.text || '' } } };
  const fval = Object.assign({}, fontValue(m)); if (m.level) fval.headingLevel = m.level;
  if (Object.keys(fval).length) title.decoration = { font: { font: { desktop: { value: fval } } } };
  const attrs = { title, builderVersion: BV };
  const module = applyCommon(m, null); if (module) attrs.module = module;
  return [open('divi/heading', presetAttr(m, attrs)), close('divi/heading')];
}
function mCta(m) {
  const attrs = {
    title: { innerContent: { desktop: { value: m.title || '' } } },
    content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } },
    button: { innerContent: { desktop: { value: { text: (m.button && m.button.text) || 'Learn more', linkUrl: (m.button && m.button.url) || m.url || '#' } } } },
    builderVersion: BV,
  };
  const bg = colorVal(m.bg), fc = colorVal(m.color), bdec = {};
  if (bg) bdec.background = { desktop: { value: { color: bg } } };
  if (fc) bdec.font = { font: { desktop: { value: { color: fc } } } };
  if (Object.keys(bdec).length) attrs.button.decoration = bdec;
  const module = applyCommon(m, null); if (module) attrs.module = module;
  return [open('divi/cta', attrs), close('divi/cta')];
}
function mToggle(m) {
  const attrs = { title: { innerContent: { desktop: { value: m.title || '' } } }, content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } }, builderVersion: BV };
  if (m.open) attrs.module = { advanced: { open: { desktop: { value: 'on' } } } };
  return [open('divi/toggle', attrs), close('divi/toggle')];
}
function mTabs(m) {
  const lines = [open('divi/tabs', { builderVersion: BV })];
  (m.items || []).forEach((it) => { lines.push(open('divi/tab', { title: { innerContent: { desktop: { value: it.title || '' } } }, content: { innerContent: { desktop: { value: it.html || (it.text ? `<p>${it.text}</p>` : '') } } }, builderVersion: BV }), close('divi/tab')); });
  lines.push(close('divi/tabs'));
  return lines;
}
function mTestimonial(m) {
  const attrs = {
    author: { innerContent: { desktop: { value: m.author || '' } } },
    jobTitle: { innerContent: { desktop: { value: m.jobTitle || '' } } },
    company: { innerContent: { desktop: { value: { text: m.company || '' } } } },
    content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } },
    builderVersion: BV,
  };
  if (m.portrait) { const p = typeof m.portrait === 'string' ? { src: m.portrait } : m.portrait; attrs.portrait = { innerContent: { desktop: { value: { src: p.src || '', id: p.id || '', alt: p.alt || '' } } } }; }
  return [open('divi/testimonial', attrs), close('divi/testimonial')];
}
// Native pricing tables. tiers:[{title,subtitle,price,currency,per,url,buttonText,featured,features:[{text,excluded}|"str"]}]
function mPricingTables(m) {
  const lines = [open('divi/pricing-tables', { builderVersion: BV })];
  (m.tiers || []).forEach((t) => {
    const feats = (t.features || []).map((f) => (typeof f === 'string' ? '+ ' + f : (f.excluded ? '- ' : '+ ') + f.text)).join('\n');
    const a = {
      currencyFrequency: { innerContent: { desktop: { value: { currency: t.currency || 'R', per: t.per || 'month' } } } },
      subtitle: { innerContent: { desktop: { value: t.subtitle || '' } } },
      title: { innerContent: { desktop: { value: t.title || '' } } },
      price: { innerContent: { desktop: { value: String(t.price != null ? t.price : '') } } },
      button: { innerContent: { desktop: { value: { text: t.buttonText || 'Sign up', linkUrl: t.url || '#' } } } },
      content: { innerContent: { desktop: { value: feats } } },
      builderVersion: BV,
    };
    if (t.featured) a.module = { advanced: { featured: { desktop: { value: 'on' } } } };
    lines.push(open('divi/pricing-table', a), close('divi/pricing-table'));
  });
  lines.push(close('divi/pricing-tables'));
  return lines;
}
function mCounters(m) { // bar counters. items:[{title,percent}]
  const lines = [open('divi/counters', { barProgress: { advanced: { usePercentages: { desktop: { value: 'on' } } } }, builderVersion: BV })];
  (m.items || []).forEach((it) => { lines.push(open('divi/counter', { title: { innerContent: { desktop: { value: it.title || '' } } }, barProgress: { innerContent: { desktop: { value: String(it.percent || 0) } } }, builderVersion: BV }), close('divi/counter')); });
  lines.push(close('divi/counters'));
  return lines;
}
function mCircleCounter(m) { return [open('divi/circle-counter', { title: { innerContent: { desktop: { value: m.title || '' } } }, number: { innerContent: { desktop: { value: String(m.number || 0) } } }, builderVersion: BV }), close('divi/circle-counter')]; }
function mNumberCounter(m) { const number = { innerContent: { desktop: { value: String(m.number || 0) } } }; if (m.percent) number.advanced = { enablePercentSign: { desktop: { value: 'on' } } }; return [open('divi/number-counter', { title: { innerContent: { desktop: { value: m.title || '' } } }, number, builderVersion: BV }), close('divi/number-counter')]; }
function mCountdown(m) { return [open('divi/countdown-timer', { title: { innerContent: { desktop: { value: m.title || '' } } }, content: { advanced: { dateTime: { desktop: { value: m.dateTime || '' } } } }, builderVersion: BV }), close('divi/countdown-timer')]; }
// ---- batch 2 (fullwidth-header/slider/person/map/tooltip/timeline/portfolio/group-carousel) ----
const imgVal = (v, key) => { const o = typeof v === 'string' ? { [key || 'src']: v } : v; const out = {}; out[key || 'src'] = o[key || 'src'] || o.src || o.url || ''; out.id = o.id || ''; if (!key || key === 'src') out.alt = o.alt || ''; return out; };
function mHero(m) { // divi/fullwidth-header. fullHeight -> headerFullscreen
  const attrs = { title: { innerContent: { desktop: { value: m.title || '' } } }, content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } }, builderVersion: BV };
  if (m.image) attrs.image = { innerContent: { desktop: { value: imgVal(m.image) } } };
  if (m.logo) attrs.logo = { innerContent: { desktop: { value: imgVal(m.logo) } } };
  if (m.button1) attrs.buttonOne = { innerContent: { desktop: { value: { text: m.button1.text || '', linkUrl: m.button1.url || '#' } } } };
  if (m.button2) attrs.buttonTwo = { innerContent: { desktop: { value: { text: m.button2.text || '', linkUrl: m.button2.url || '#' } } } };
  if (m.fullHeight) attrs.module = { advanced: { headerFullscreen: { desktop: { value: 'on' } } } };
  // fullwidth-header is a CONTAINER, not a leaf: anything in m.modules nests
  // between its open/close tags and renders BELOW the header's own
  // title/content/buttons, in document order. Verified live on a live site
  // — a linked image (Play badge) and a text line nested inside the hero.
  // To DROP the built-in button, pass button1:{text:'',url:''} rather than
  // omitting it, which is what the visual builder itself writes.
  const lines = [open('divi/fullwidth-header', attrs)];
  for (const mod of (m.modules || [])) lines.push(...buildModule(mod));
  lines.push(close('divi/fullwidth-header'));
  return lines;
}
function mSlider(m) {
  const lines = [open('divi/slider', { builderVersion: BV })];
  (m.slides || []).forEach((s) => {
    const a = { title: { innerContent: { desktop: { value: s.title || '' } } }, content: { innerContent: { desktop: { value: s.html || (s.text ? `<p>${s.text}</p>` : '') } } }, button: { innerContent: { desktop: { value: { text: s.buttonText || '', linkUrl: s.url || '#' } } } }, builderVersion: BV };
    if (s.image) a.image = { innerContent: { desktop: { value: imgVal(s.image) } } };
    if (s.bgImage) a.module = { decoration: { background: { desktop: { value: { image: { url: s.bgImage } } } } } };
    lines.push(open('divi/slide', a), close('divi/slide'));
  });
  lines.push(close('divi/slider'));
  return lines;
}
function mPerson(m) { // divi/team-member — image uses `url` not `src`
  const attrs = { name: { innerContent: { desktop: { value: m.name || '' } } }, position: { innerContent: { desktop: { value: m.position || '' } } }, content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } }, builderVersion: BV };
  if (m.image) attrs.image = { innerContent: { desktop: { value: imgVal(m.image, 'url') } } };
  return [open('divi/team-member', attrs), close('divi/team-member')];
}
function mMap(m) {
  const lines = [open('divi/map', { map: { innerContent: { desktop: { value: { lat: m.lat, lng: m.lng, address: m.address || '', zoom: m.zoom || 12 } } } }, builderVersion: BV })];
  (m.pins || []).forEach((p) => { lines.push(open('divi/map-pin', { pin: { innerContent: { desktop: { value: { lat: p.lat, lng: p.lng, address: p.address || '', zoom: p.zoom || 16 } } } }, title: { innerContent: { desktop: { value: p.title || '' } } }, content: { innerContent: { desktop: { value: p.html || (p.text ? `<p>${p.text}</p>` : '') } } }, builderVersion: BV }), close('divi/map-pin')); });
  lines.push(close('divi/map'));
  return lines;
}
function mTooltip(m) { return [open('divi/tooltip', { content: { innerContent: { desktop: { value: m.html || (m.text ? `<p>${m.text}</p>` : '') } } }, module: { advanced: { tooltip: { desktop: { value: { trigger: m.trigger || 'hover', showArrow: 'on', placement: m.placement || 'inside top center', positionMode: m.positionMode || 'followCursor' } } } } }, builderVersion: BV }), close('divi/tooltip')]; }
function mTimeline(m) {
  const t = {}; if (m.direction) t.direction = m.direction; if (m.position) t.position = m.position; if (m.startFrom) t.startFrom = m.startFrom;
  const parent = { builderVersion: BV }; if (Object.keys(t).length) parent.module = { advanced: { timeline: { desktop: { value: t } } } };
  const lines = [open('divi/timeline', parent)];
  (m.items || []).forEach((it) => { const a = { date: { innerContent: { desktop: { value: it.date || '' } } }, title: { innerContent: { desktop: { value: it.title || '' } } }, content: { innerContent: { desktop: { value: it.html || (it.text ? `<p>${it.text}</p>` : '') } } }, builderVersion: BV }; if (it.icon) a.marker = { innerContent: { desktop: { value: { unicode: it.icon.unicode || it.icon, type: (it.icon.type) || 'divi', weight: (it.icon.weight) || '400' } } } }; lines.push(open('divi/timeline-item', a), close('divi/timeline-item')); });
  lines.push(close('divi/timeline'));
  return lines;
}
function mPostCarousel(m) { return [open('divi/fullwidth-portfolio', { portfolio: { innerContent: { desktop: { value: { type: m.postType || 'project', includedCategories: m.categories || ['all'] } } }, advanced: { layout: { desktop: { value: m.grid ? 'off' : 'on' } } } }, builderVersion: BV }), close('divi/fullwidth-portfolio')]; }
function mGroup(m) { // a divi/group; carries the loop when used in a carousel/loop
  const attrs = { builderVersion: BV };
  if (m.loop) attrs.module = { advanced: { loop: { desktop: { value: loopValue(m.loop) } } } };
  const lines = [open('divi/group', attrs)];
  for (const mod of (m.modules || [])) lines.push(...buildModule(mod));
  lines.push(close('divi/group'));
  return lines;
}
function mGroupCarousel(m) {
  const adv = {};
  if (m.auto) adv.auto = { desktop: { value: 'on' } };
  if (m.slidesToShow) adv.slidesToShow = { desktop: { value: String(m.slidesToShow.desktop || 3) }, tablet: { value: String(m.slidesToShow.tablet || 2) }, phone: { value: String(m.slidesToShow.phone || 1) } };
  const attrs = { builderVersion: BV };
  if (Object.keys(adv).length) attrs.module = { advanced: adv };
  if (m.arrows) attrs.arrows = { advanced: { position: { desktop: { value: m.arrows } } } };
  const lines = [open('divi/group-carousel', attrs)];
  (m.groups || (m.group ? [m.group] : [])).forEach((g) => lines.push(...mGroup(g)));
  lines.push(close('divi/group-carousel'));
  return lines;
}

// Divi Plugins FilterGrid (third-party module `dp-dfg/filtergrid`; requires the
// Divi FilterGrid plugin installed — requires that commercial plugin). Query-driven
// grid of a CPT with built-in content/video popups + skins. Every setting sits at
// <key>.innerContent.desktop.value. Named props cover the verified core; `settings`
// is a raw passthrough for any other option (e.g. video/popup keys — dump a live
// example to learn exact names, see references/divi5-modules-verified.md).
function mFilterGrid(m) {
  const map = {
    custom_query: m.query || 'advanced', multiple_cpt: m.cpt, post_number: m.count != null ? String(m.count) : undefined,
    order: m.order, thumbnail_action: m.thumbnailAction, thumbnail_size: m.thumbnailSize,
    items_layout: m.layout, items_width_flex: m.widthFlex, row_gutter_flex: m.gutterFlex, justify_content: m.justify,
    items_skin: m.skin, show_filters: m.showFilters, show_pagination: m.showPagination, show_post_meta: m.showMeta, use_overlay: m.overlay,
    show_video_preview: m.video ? 'on' : undefined, video_action: m.videoAction, // confirmed video keys
  };
  Object.assign(map, m.settings || {}); // raw passthrough for any other option (e.g. popup_template)
  const attrs = { builderVersion: BV };
  for (const [k, v] of Object.entries(map)) { if (v == null || v === '') continue; attrs[k] = { innerContent: { desktop: { value: String(v) } } }; }
  return [open('dp-dfg/filtergrid', attrs), close('dp-dfg/filtergrid')];
}

function buildModule(m) {
  switch (m.type) {
    case 'heading': return mHeading(m);
    case 'text': return mText(m);
    case 'button': return mButton(m);
    case 'image': return mImage(m);
    case 'blurb': return mBlurb(m);
    case 'iconlist': return mIconList(m);
    case 'code': return mCode(m);
    case 'video': return mVideo(m);
    case 'icon': return mIcon(m);
    case 'divider': return mDivider(m);
    case 'accordion': return mAccordion(m);
    case 'heading-module': return mHeadingModule(m);
    case 'cta': return mCta(m);
    case 'toggle': return mToggle(m);
    case 'tabs': return mTabs(m);
    case 'testimonial': return mTestimonial(m);
    case 'pricing': return mPricingTables(m);
    case 'counters': return mCounters(m);
    case 'circle-counter': return mCircleCounter(m);
    case 'number-counter': return mNumberCounter(m);
    case 'countdown': return mCountdown(m);
    case 'hero': return mHero(m);
    case 'slider': return mSlider(m);
    case 'person': return mPerson(m);
    case 'map': return mMap(m);
    case 'tooltip': return mTooltip(m);
    case 'timeline': return mTimeline(m);
    case 'post-carousel': return mPostCarousel(m);
    case 'group': return mGroup(m);
    case 'group-carousel': return mGroupCarousel(m);
    case 'filtergrid': return mFilterGrid(m);
    case 'row': return buildRow(m); // nested row inside a column (e.g. a rowGap:0 button group)
    case 'raw': return mRaw(m);
    default: throw new Error(`unknown module type: ${m.type}`);
  }
}

// Layout (updated per verified ET practice, 2026-07):
//  - DEFAULT "fractional": columns carry per-breakpoint flexType (1/3 desktop →
//    1/2 tablet → full phone) — ET's documented native method and the author's own builds.
//  - mode:"flexwrap": legacy 100% columns (24_24) + row flexWrap (dynamic-count reflow).
//  - grid:{...} on a row: CSS Grid — BEST for card/pricing/gallery/loop grids.
const LAYOUT = { '1': 1, '1-1': 2, '1-1-1': 3, '1-1-1-1': 4 };
// per-breakpoint flexType + which breakpoints wrap, for fractional rows (24-based)
const FRACTION = {
  '1':       { sizing: { desktop: '24_24' }, wrap: [] },
  '1-1':     { sizing: { desktop: '12_24', phone: '24_24' }, wrap: ['phone'] },
  '1-1-1':   { sizing: { desktop: '8_24', tablet: '12_24', phone: '24_24' }, wrap: ['tablet', 'phone'] },
  '1-1-1-1': { sizing: { desktop: '6_24', tablet: '12_24', phone: '24_24' }, wrap: ['tablet', 'phone'] },
};

// col: { modules:[...], bgImage, bgPosition, background(gcid|hex), rowGap,
//        padding("22px"|{top,...}), radius("14px"), border:{color(gcid|hex),width},
//        hover:{borderColor, shadow} }. sizing = per-bp flexType map from the row.
function buildColumn(col, sizing) {
  sizing = sizing || { desktop: '24_24' };
  const dec = { sizing: {} };
  for (const bp of Object.keys(sizing)) dec.sizing[bp] = { value: { flexType: sizing[bp] } };
  if (col.bgImage) dec.background = { desktop: { value: { image: { url: col.bgImage, position: col.bgPosition || 'center', size: 'cover' } } } };
  else if (col.background) { const cv = colorVal(col.background); if (cv) dec.background = { desktop: { value: { color: cv } } }; }
  if (col.rowGap) dec.layout = { desktop: { value: { rowGap: col.rowGap } } };
  if (col.padding) { const p = typeof col.padding === 'string' ? { top: col.padding, bottom: col.padding, left: col.padding, right: col.padding, syncVertical: 'on', syncHorizontal: 'on' } : col.padding; dec.spacing = { desktop: { value: { padding: p } } }; }
  if (col.radius || col.border) {
    const bv = {};
    if (col.radius) bv.radius = { topLeft: col.radius, topRight: col.radius, bottomLeft: col.radius, bottomRight: col.radius, sync: 'on' };
    if (col.border) bv.styles = { all: { width: col.border.width || '1px', color: colorVal(col.border.color) || '#e6eae6', style: 'solid' } };
    dec.border = { desktop: { value: bv } };
  }
  // ALWAYS-ON card shadow: `shadow:{vertical,blur,spread,color}`.
  // Distinct from `hover.shadow` below, which starts at zero blur and only
  // appears on hover — that cannot express a card that is shadowed AT REST,
  // which is how the reference cards are built (0px 2px 18px rgba(0,0,0,.3)).
  if (col.shadow) {
    const s = col.shadow;
    dec.boxShadow = { desktop: { value: {
      horizontal: s.horizontal || '0px',
      vertical: s.vertical || '2px',
      blur: s.blur || '18px',
      spread: s.spread || '0px',
      position: s.position || 'outer',
      color: s.color || 'rgba(0, 0, 0, 0.3)',
      style: 'preset1',
    } } };
  }
  // Hover states (card lift): border colour change + shadow appears on hover.
  // IMPORTANT: hover lives under the BREAKPOINT (`<bp>.hover`), beside `<bp>.value`
  // — e.g. border.desktop.hover, NOT border.hover. Top-level `hover` renders nothing.
  if (col.hover) {
    if (col.hover.borderColor) { dec.border = dec.border || { desktop: { value: {} } }; dec.border.desktop.hover = { styles: { all: { color: colorVal(col.hover.borderColor) } } }; }
    const hb = col.hover.shadow || {};
    dec.boxShadow = { desktop: { value: { horizontal: '0px', vertical: '0px', blur: '0px', spread: '0px', position: 'outer', color: col.hover.shadowColor || 'rgba(0,0,0,0.3)', style: 'preset1' }, hover: { vertical: hb.vertical || '2px', blur: hb.blur || '18px', spread: hb.spread || '0px' } } };
  }
  const attrs = { module: { decoration: dec }, builderVersion: BV };
  if (col.loop) attrs.module.advanced = { loop: { desktop: { value: loopValue(col.loop) } } }; // Loop Builder: repeat column per queried post
  const lines = [open('divi/column', attrs)];
  for (const m of (col.modules || [])) lines.push(...buildModule(m));
  lines.push(close('divi/column'));
  return lines;
}

// CSS Grid row. row.grid = { cols:{desktop,tablet,phone}, widths?:"equal",
//   spans?:[{target:"first-child"|"3n"|…, span:2}] }. Grid is child-agnostic
// (add/remove/loop items freely). ⚠ a span rule sits in desktop and does NOT reset
// on mobile — a spanned item won't collapse to 1 col on phone. Avoid spans if you
// need clean mobile stacking.
function buildGridRow(row) {
  const n = (row.columns || []).length;
  const g = row.grid; const c = g.cols || {};
  const dv = { display: 'grid', gridColumnWidths: g.widths || 'equal', gridColumnCount: String(c.desktop || 3), gridAutoColumns: String(c.desktop || 3) };
  if (g.spans && g.spans.length) dv.gridOffsetRules = { rules: g.spans.map((s, i) => ({ id: 'rule_' + i, adminLabel: 'span ' + (i + 1), targetOffset: s.target, offsetValues: { columnSpan: String(s.span) } })) };
  const layout = { desktop: { value: dv } };
  if (c.tablet) layout.tablet = { value: { gridColumnCount: String(c.tablet), gridAutoColumns: String(c.tablet) } };
  if (c.phone) layout.phone = { value: { gridColumnCount: String(c.phone), gridAutoColumns: String(c.phone), gridColumnWidths: 'auto' } };
  const module = { advanced: { flexColumnStructure: { desktop: { value: 'css-grid-grids_' + Math.max(0, n - 1) } }, columnStructure: { desktop: { value: Array(Math.max(1, n)).fill('1_' + Math.max(1, n)).join(',') } } }, decoration: { layout } };
  const lines = [open('divi/row', { module, builderVersion: BV })];
  for (const col of (row.columns || [])) lines.push(...buildColumn(col, { desktop: '24_24' }));
  lines.push(close('divi/row'));
  return lines;
}

// row: { layout:"1-1", columns:[...], mode?:"fractional"(default)|"flexwrap",
//        wrap?:["tablet","phone"], grid?:{...} }
function buildRow(row) {
  if (row.grid) return buildGridRow(row);
  const key = row.layout || '1';
  const n = LAYOUT[key] || 1;
  const module = { advanced: { flexColumnStructure: { desktop: { value: 'equal-columns_' + n } } } };
  let sizing = { desktop: '24_24' };
  if (n > 1) {
    if (row.mode === 'flexwrap') {
      module.decoration = { layout: { desktop: { value: { flexWrap: 'nowrap' } }, [(row.wrapAt || 'phone')]: { value: { flexWrap: 'wrap' } } } };
    } else { // fractional (default)
      const F = FRACTION[key] || FRACTION['1'];
      sizing = F.sizing;
      const layout = { desktop: { value: { flexWrap: 'nowrap' } } };
      (row.wrap || F.wrap).forEach((bp) => { layout[bp] = { value: { flexWrap: 'wrap' } }; });
      module.decoration = { layout };
    }
  }
  // Row sizing: `maxWidth` (+ `alignSelf`, default centre) narrows a row — an
  // 800px FAQ column inside a full-width section, for example. `width:"100%"`
  // with `maxWidth:"100%"` is how a hero row is made to span edge to edge.
  // Without this the row inherits the theme's content width and a "fullscreen"
  // hero sits in a letterboxed strip.
  if (row.maxWidth || row.width || row.alignSelf) {
    const v = {};
    if (row.width) v.width = row.width;
    if (row.maxWidth) v.maxWidth = row.maxWidth;
    if (row.maxWidth && !row.width) v.alignSelf = row.alignSelf || 'center';
    else if (row.alignSelf) v.alignSelf = row.alignSelf;
    module.decoration = Object.assign(module.decoration || {}, { sizing: { desktop: { value: v } } });
  }
  const lines = [open('divi/row', { module, builderVersion: BV })];
  for (const col of (row.columns || [])) lines.push(...buildColumn(col, sizing));
  lines.push(close('divi/row'));
  return lines;
}

function buildSection(sec) {
  const decoration = {};
  const bg = sec.background;
  if (bg) {
    const val = {};
    if (bg.image) val.image = { url: bg.image, position: bg.position || 'center', size: bg.size || 'cover' };
    // Full gradient (with colour stops), optionally overlaying the image — like the hero.
    if (bg.gradient) {
      const g = bg.gradient;
      val.gradient = {
        enabled: 'on', type: g.type || 'linear', direction: g.direction || '180deg',
        overlaysImage: bg.image ? 'on' : 'off',
        stops: (g.stops || []).map((s) => ({ position: String(s.position), color: s.color })),
      };
    } else if (bg.image && bg.gradientOverlay) {
      val.gradient = { overlaysImage: 'on', direction: bg.gradientOverlay };
    }
    const ct = colorToken(bg.colorVar ? { colorVar: bg.colorVar, opacity: bg.opacity } : bg.color ? { color: bg.color } : null);
    if (ct) val.color = ct;
    if (Object.keys(val).length) decoration.background = { desktop: { value: val } };
  }
  if (sec.padding) {
    const p = sec.padding;
    decoration.spacing = { desktop: { value: { padding: Object.assign({ syncVertical: 'off', syncHorizontal: 'off' }, p) } } };
  }
  const attrs = { builderVersion: BV };
  if (Object.keys(decoration).length) attrs.module = { decoration };
  const lines = [open('divi/section', attrs)];
  for (const row of (sec.rows || [])) lines.push(...buildRow(row));
  lines.push(close('divi/section'));
  return lines;
}

function compile(spec) {
  BV = spec.builderVersion || BV;
  const lines = ['<!-- wp:divi/placeholder -->']; // matches live-site serialization exactly
  for (const sec of (spec.sections || [])) lines.push(...buildSection(sec));
  return lines.join('\n');
}

// Compile a popup canvas: a full-screen, hidden-by-default overlay section whose
// interactionTarget matches a trigger elsewhere. Content is created as an
// et_pb_canvas post, then attached to a page via `wp.js link-canvas`.
// popup = { targetId, overlayColor, width, modules:[...], builderVersion }
function compilePopupCanvas(popup) {
  BV = popup.builderVersion || BV;
  const target = popup.targetId;
  const sectionAttrs = { builderVersion: BV, module: { decoration: {
    background: { desktop: { value: { color: popup.overlayColor || 'rgba(10,58,31,0.9)' } } },
    interactionTarget: target,
    // clicking the overlay closes it (toggles its own visibility)
    interactionTrigger: 'c' + target,
    interactions: { desktop: { value: { interactions: [{
      id: 'c' + target + 'i', enableInteraction: 'on', trigger: 'click', effect: 'toggleVisibility',
      target: { targetClass: 'et-interaction-target-' + target, label: 'Popup', moduleId: '', targetType: 'module' },
      replaceExistingPreset: false, sensitivity: 50, mouseMovementType: 'translate',
      cookieName: '', cookieValue: '', triggerClass: 'et-interaction-trigger-c' + target, presetId: '', timeDelay: '0ms',
    }] } } },
    disabledOn: { desktop: { value: 'on' }, tablet: { value: 'on' }, phone: { value: 'on' } },
    position: { desktop: { value: { mode: 'fixed', origin: { absolute: 'top center', fixed: 'top center' } } } },
    sizing: { desktop: { value: { minHeight: '100vh', maxHeight: '100vh', height: '100vh' } } },
    zIndex: { desktop: { value: '9999999' } },
    layout: { desktop: { value: { justifyContent: 'center' } } },
  } } };
  const rowAttrs = { builderVersion: BV, module: { advanced: { flexColumnStructure: { desktop: { value: 'equal-columns_1' } } },
    decoration: { sizing: { desktop: { value: { width: popup.width || '70%', alignSelf: 'center' } }, tablet: { value: { width: '80%' } }, phone: { value: { width: '90%' } } } } } };
  const colAttrs = { builderVersion: BV, module: { decoration: { sizing: { desktop: { value: { flexType: '24_24' } } } } } };
  const lines = ['<!-- wp:divi/placeholder -->', open('divi/section', sectionAttrs), open('divi/row', rowAttrs), open('divi/column', colAttrs)];
  for (const m of (popup.modules || [])) lines.push(...buildModule(m));
  lines.push(close('divi/column'), close('divi/row'), close('divi/section'), '<!-- /wp:divi/placeholder -->');
  return lines.join('\n');
}

// ---- CLI ----
if (require.main === module) {
  const [cmd, specPath, ...rest] = process.argv.slice(2);
  const outIdx = rest.indexOf('--out');
  const write = (out) => { if (outIdx >= 0 && rest[outIdx + 1]) { fs.writeFileSync(rest[outIdx + 1], out); process.stderr.write(`wrote ${out.length} chars to ${rest[outIdx + 1]}\n`); } else process.stdout.write(out); };
  if (cmd === 'compile' && specPath) { write(compile(JSON.parse(fs.readFileSync(specPath, 'utf8')))); }
  else if (cmd === 'compile-canvas' && specPath) { write(compilePopupCanvas(JSON.parse(fs.readFileSync(specPath, 'utf8')))); }
  else { process.stderr.write('usage: node divi.js compile|compile-canvas <spec.json> [--out F]\n'); process.exit(1); }
}

module.exports = { compile, compilePopupCanvas, dc };
