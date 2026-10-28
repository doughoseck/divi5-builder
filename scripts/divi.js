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
 * name. Column widths come from the row layout (fractional flex by default; see COLUMNS/LAYOUT below).
 *
 * VARIABLES IN ANY VALUE (Divi 5.18): "{{gvid-xxxx}}" becomes that design variable's token and "{{dc:post_author}}"
 * a dynamic-content token, inside any string: "padding":{"top":"{{gvid-x}}"}, "margin":{"top":"{{gvid-x}} * -1"}
 * (math is + - * / only, never "×"), "html":"<p>By {{dc:post_author}} · {{gvid-y}}</p>". See expandRefs below.
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
 *   {"type":"link","text":"Tickets","url":"https://x","icon":"&#xf054;","border":{"bottom":{"width":"1px","color":"gcid-…","style":"solid"}}}   // the WHOLE module is one <a>
 *   {"type":"swiper-posts","postType":"post","count":6,"slidesPerView":{"desktop":3,"tablet":2,"phone":1}}   // needs the Divi Plugins Swiper add-on
 *   {"type":"raw","block":"divi/xxx","attrs":{...},"inner":"..."}          // escape hatch
 *
 * PRESETS (the recommended way to style): any section, row, column or module may carry
 *   "modulePreset":"<id>"  or  ["<default preset id>","<id>"]  (ids from `wp.js <site> design-system`).
 * A module on a preset carries content and placement only. See SKILL.md, "Design system first".
 *
 * VISIBILITY TOGGLES (native, no JS): any module may carry "toggleId":"<id>"
 * (becomes a toggle target) and "hidden":true (starts hidden). A button may carry
 * "toggles":["idA",…] (+ "trigger":"<id>") to flip every listed target on click.
 * Give both control buttons the same full id list (incl. their own ids). Used for
 * monthly/annual pricing, show-more, etc.
 *
 * COLUMNS/LAYOUT: rows take layout "1" | "1-1" | "1-1-1" | "1-1-1-1" (equal, fractional flex: per-breakpoint
 * flexType, the default) or an unequal one: "2-1" "1-2" "3-1" "1-3" "3-2" "2-3" "2-1-1" "1-1-2" "1-2-1" "1-3-1"
 * "3-1-1-1" "1-1-1-3" (Divi's own offset-columns structures; the column count must match). An unknown layout
 * is an error. mode:"flexwrap" = legacy 100% columns that wrap at wrapAt. grid:{…} on a row = CSS Grid. Columns
 * also take bgImage/bgPosition (image, cover), background (gcid|hex), rowGap, and CARD props: padding, radius,
 * border{color,width}, shadow, hover{borderColor, shadow{blur,spread}}.
 *
 * ANY section, row, column or module also takes "cssId" / "cssClass" (CSS ID & Classes; anchors such as
 * #contact) and "hideOn": ["phone"|"tablet"|"desktop"] (hidden on just those devices).
 */
const fs = require('fs');
const path = require('path');

let BV = '5.9.0'; // overwritten from spec.builderVersion

// ---- comment-safe JSON, in the site's STORED form ----
// A save re-serialises every block: \" -> \u0022, \\ -> \u005c, -- -> \u002d\u002d, < > & -> \u003c \u003e \u0026,
// apostrophes and non-ASCII left raw. Emitting that form means what we send is byte-for-byte what gets stored
// (measured 2026-09-21 on Divi 5.9 and 5.13; the Theme Builder write guard depends on it). Order matters:
// backslash pairs are parked first so the quote pass cannot split them.
// An empty object as an attribute value is never stored as sent: WordPress's save turns PHP-empty `{}` into `[]`
// ("module":{} comes back as "module":[]), so the page would not round-trip byte for byte, and the Theme Builder
// write guard would reject it (issue #21, measured on Divi 5.15.1). An empty group carries no setting, so it is
// dropped, bottom-up (a group that only held empty groups goes too). The block's own top level is kept as given.
// Empty ARRAYS are left alone: they are stored as `[]` and survive the round trip.
function dropEmpty(v) {
  if (Array.isArray(v)) return v.map(dropEmpty);
  if (!v || typeof v !== 'object') return v;
  const out = {};
  for (const k of Object.keys(v)) {
    const x = dropEmpty(v[k]);
    if (x && typeof x === 'object' && !Array.isArray(x) && !Object.keys(x).length) continue;
    out[k] = x;
  }
  return out;
}
// ---- Variable references inside any value (issue #45, Divi 5.18) ----
// In ANY string value of a spec (a number field, a text body, a URL...):
//   {{gvid-xxxx}}        -> the design variable's token, the form the Visual Builder stores (live 5.18.0 page):
//                           $variable({"type":"content","value":{"name":"gvid-xxxx","settings":{}}})$
//   {{dc:post_author}}   -> a dynamic-content token, the same shape with the dynamic-content name (dc() below)
// So "padding":{"top":"{{gvid-space}}"}, "margin":{"top":"{{gvid-space}} * -1"} (Divi wraps + - * / in calc() at render;
// "×" is NOT an operator) and "html":"<p>By {{dc:post_author}} · {{gvid-copyright}}</p>" all work. Colours keep their
// own form: a "gcid-…" name in a colour field. Expanded once, in attrJSON, so every block (raw ones too) gets it.
const VAR_PLACEHOLDER = /\{\{\s*(gvid-[^}\s]*|dc:[^}\s]*)\s*\}\}/g;
function expandRefs(v) {
  if (typeof v === 'string') {
    if (v.indexOf('{{') < 0) return v;
    const out = v.replace(VAR_PLACEHOLDER, (all, ref) => {
      if (ref.startsWith('gvid-')) {
        if (!/^gvid-[a-z0-9_-]+$/i.test(ref)) throw new Error(`"${all}" is not a variable id (take gvid-… ids from wp.js <site> design-system)`);
        return dc(ref);
      }
      const name = ref.slice(3);
      if (!/^[a-z][a-z0-9_]*$/.test(name)) throw new Error(`"${all}" is not a dynamic-content name (lowercase letters, digits and _ , e.g. {{dc:post_author}})`);
      return dc(name);
    });
    // Anything still shaped like a placeholder ("{{gvid-a b}}", "{{ dc: x }}") would reach the page as literal text.
    const left = /\{\{\s*(?:gvid-|dc\s*:)[^}]*\}\}/.exec(out);
    if (left) throw new Error(`"${left[0]}" is not a variable id or dynamic-content name (use {{gvid-xxxx}} or {{dc:name}}, no spaces)`);
    return out;
  }
  if (Array.isArray(v)) return v.map(expandRefs);
  if (!v || typeof v !== 'object') return v;
  const out = {};
  for (const k of Object.keys(v)) out[k] = expandRefs(v[k]);
  return out;
}
function attrJSON(obj) {
  return JSON.stringify(expandRefs(dropEmpty(obj)))
    .replace(/\\\\/g, '\u0000BS\u0000')
    .replace(/\\"/g, '\\u0022')
    .replace(/\u0000BS\u0000/g, '\\u005c')
    .replace(/</g, '\\u003c').replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026').replace(/--/g, '\\u002d\\u002d');
}
const PLACEHOLDER_CLOSE = '<!-- /wp:divi/placeholder -->';
function open(name, attrs) { return `<!-- wp:${name}${attrs ? ' ' + attrJSON(attrs) : ''} -->`; }
function close(name) { return `<!-- /wp:${name} -->`; }
function selfClose(name, attrs) { return `<!-- wp:${name}${attrs ? ' ' + attrJSON(attrs) : ''} /-->`; }

// DESIGN SYSTEM FIRST: any section, row, column or module may carry
//   "modulePreset": "<id>"  or  ["<default preset id>", "<id>"]   (a stack: later ids win)
// The ids come from `wp.js <site> design-system`. A module on a preset should carry content and placement only:
// a design setting given next to a preset overrides it, and a setting from the same option group as one of the
// preset's breaks both (see references/divi5-globalize-site.md, rule 3).
function withPreset(spec, lines) {
  if (!spec || spec.modulePreset === undefined || spec.modulePreset === null) return lines;
  const ids = [].concat(spec.modulePreset).map(String).filter((x) => x !== '');
  if (!ids.length) return lines;
  for (const id of ids) if (!/^[A-Za-z0-9_-]+$/.test(id)) throw new Error(`modulePreset: "${id}" is not a preset id (take ids from wp.js <site> design-system)`);
  const m = /^<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)(?: (\{[\s\S]*\}))? (\/)?-->$/.exec(lines[0] || '');
  if (!m) throw new Error('modulePreset: cannot find the opening block of ' + (spec.type || 'this element'));
  const attrs = m[2] ? JSON.parse(m[2]) : {};
  attrs.modulePreset = ids;
  return [`<!-- wp:${m[1]} ${attrJSON(attrs)} ${m[3] ? '/' : ''}-->`].concat(lines.slice(1));
}

// Issue #33: two things any section, row, column or module may carry (read from builder-saved pages and Divi 5.15.1):
//   "cssId": "contact", "cssClass": "dark wide"  -> module.advanced.htmlAttributes (Divi's "CSS ID & Classes"; anchors)
//   "hideOn": ["phone"|"tablet"|"desktop"]       -> module.decoration.disabledOn on Divi's disabled-on breakpoints:
//        phone -> "phone", tablet -> "tabletOnly", desktop -> "desktopAbove". NOT "desktop": that is Divi's base
//        device, so a rule there applies at every width and would hide the element everywhere.
const HIDE_BP = { phone: 'phone', tablet: 'tabletOnly', desktop: 'desktopAbove' };
function withCommon(spec, lines) {
  if (!spec || (spec.cssId == null && spec.cssClass == null && spec.hideOn == null)) return lines;
  const html = {};
  if (spec.cssId != null && spec.cssId !== '') {
    if (!/^[A-Za-z][\w-]*$/.test(String(spec.cssId))) throw new Error(`cssId "${spec.cssId}" is not a valid HTML id (a letter, then letters, digits, - or _)`);
    html.id = String(spec.cssId);
  }
  if (spec.cssClass != null && spec.cssClass !== '') {
    if (!/^[\w -]+$/.test(String(spec.cssClass))) throw new Error(`cssClass "${spec.cssClass}" has characters a class name cannot hold`);
    html.class = String(spec.cssClass).trim().replace(/\s+/g, ' ');
  }
  const hide = {};
  for (const d of [].concat(spec.hideOn || [])) {
    if (!HIDE_BP[d]) throw new Error(`hideOn: "${d}" is not a device (use "phone", "tablet" or "desktop")`);
    hide[HIDE_BP[d]] = { value: 'on' };
  }
  if (!Object.keys(html).length && !Object.keys(hide).length) return lines;
  const m = /^<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)(?: (\{[\s\S]*\}))? (\/)?-->$/.exec(lines[0] || '');
  if (!m) throw new Error('cssId/cssClass/hideOn: cannot find the opening block of ' + (spec.type || 'this element'));
  const attrs = m[2] ? JSON.parse(m[2]) : {};
  attrs.module = attrs.module || {};
  if (Object.keys(html).length) {
    attrs.module.advanced = attrs.module.advanced || {};
    const cur = (attrs.module.advanced.htmlAttributes && attrs.module.advanced.htmlAttributes.desktop && attrs.module.advanced.htmlAttributes.desktop.value) || {};
    attrs.module.advanced.htmlAttributes = { desktop: { value: Object.assign({}, cur, html) } };
  }
  if (Object.keys(hide).length) {
    attrs.module.decoration = attrs.module.decoration || {};
    attrs.module.decoration.disabledOn = Object.assign({}, attrs.module.decoration.disabledOn || {}, hide);
  }
  return [`<!-- wp:${m[1]} ${attrJSON(attrs)} ${m[3] ? '/' : ''}-->`].concat(lines.slice(1));
}

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
// else a literal. Prefer gcid names: take them from `wp.js <site> design-system`
// (`wp.js global-colors` only lists the gcids pages already use).
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
// ---- Interactions: the full set, read from Divi 5.13.1's own lists (field-library + InteractionsScriptData.php) ----
const IX_TRIGGERS = ['click', 'mouseEnter', 'mouseExit', 'viewportEnter', 'viewportExit', 'load', 'breakpointEnter', 'breakpointExit'];
const IX_EFFECTS = ['toggleVisibility', 'addVisibility', 'removeVisibility', 'togglePreset', 'addPreset', 'removePreset',
  'toggleAttribute', 'addAttribute', 'removeAttribute', 'toggleCookie', 'addCookie', 'removeCookie', 'scrollToElement', 'mirrorMouseMovement'];
const IX_MOVES = ['translate', 'scale', 'opacity', 'tilt', 'rotate'];
// Any module may carry:  "interactions":[{ "trigger":"click", "effect":"toggleVisibility", "target":"<toggleId>" , ...}]
//   target          the toggleId / interactionTarget of the element that receives the effect (may be in ANOTHER canvas:
//                   that is what makes Divi append a popup canvas to the page). "targets":[...] repeats the effect.
//   attributeName/attributeValue   for *Attribute effects (e.g. name "class", value "is-stuck")
//   cookieName/cookieValue         for *Cookie effects          presetId, replaceExistingPreset   for *Preset effects
//   breakpoint                     for breakpointEnter/Exit ("desktop" | "tablet" | "phone" ...)
//   timeDelay ("300ms"), sensitivity (0-100) + mouseMovementType for mirrorMouseMovement
// "triggerId" names the trigger (letters and digits only); one is derived from the content when omitted.
function interactionsDecoration(m) {
  const list = [];
  for (const ix of m.interactions) {
    if (!IX_TRIGGERS.includes(ix.trigger || 'click')) throw new Error(`interaction trigger "${ix.trigger}" is not one of: ${IX_TRIGGERS.join(', ')}`);
    if (!IX_EFFECTS.includes(ix.effect)) throw new Error(`interaction effect "${ix.effect}" is not one of: ${IX_EFFECTS.join(', ')}`);
    if (ix.mouseMovementType && !IX_MOVES.includes(ix.mouseMovementType)) throw new Error(`mouseMovementType "${ix.mouseMovementType}" is not one of: ${IX_MOVES.join(', ')}`);
    const targets = ix.targets || (ix.target ? [ix.target] : []); if (!targets.length) throw new Error(`interaction "${ix.effect}" needs a target (the toggleId of the element it acts on)`);
    for (const t of targets) list.push(Object.assign({}, ix, { target: t }));
  }
  let h = 0; for (const ch of JSON.stringify(m.interactions)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  const trig = m.triggerId || ('ix' + h.toString(36)); if (!/^[a-zA-Z0-9]+$/.test(trig)) throw new Error(`triggerId "${trig}" may only hold letters and digits (Divi reads it back with that pattern)`);
  return { interactionTrigger: trig, interactions: { desktop: { value: { interactions: list.map((ix, k) => ({
    id: trig + 'i' + k, enableInteraction: 'on', trigger: ix.trigger || 'click', effect: ix.effect,
    target: { targetClass: 'et-interaction-target-' + ix.target, label: ix.label || 'Target', moduleId: '', targetType: 'module' },
    attributeName: ix.attributeName || '', attributeValue: ix.attributeValue || '', cookieName: ix.cookieName || '', cookieValue: ix.cookieValue || '',
    presetId: ix.presetId || '', replaceExistingPreset: !!ix.replaceExistingPreset, timeDelay: ix.timeDelay || '0ms',
    sensitivity: ix.sensitivity == null ? 50 : ix.sensitivity, mouseMovementType: ix.mouseMovementType || 'translate',
    breakpointName: ix.breakpoint || '', triggerClass: 'et-interaction-trigger-' + trig })) } } } };
}
// Generic click-interaction: one trigger, one effect, one or more targets.
// effect: any of IX_EFFECTS above ("toggleVisibility", "addVisibility" = show, "removeVisibility" = hide, "scrollToElement" …)
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
// Issue #16: a module on a preset (modulePreset, or a group preset via `preset`) writes ONLY the style settings its
// spec asks for, so the preset stays in control. Without a preset the compiler's usual defaults apply, unchanged.
// styleDefault(m, value, fallback): the spec's value if given, else the fallback only when there is no preset.
function onPreset(m) { return [].concat(m.modulePreset == null ? [] : m.modulePreset).some((x) => String(x) !== '') || !!m.preset; }
function styleDefault(m, value, fallback) { return value ? value : (onPreset(m) ? undefined : fallback); }

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
  if (!m.toggleId && !m.hidden && !m.interactions) return module;
  module = module || {};
  module.decoration = module.decoration || {};
  if (m.interactions) Object.assign(module.decoration, interactionsDecoration(m));
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
// NOTE: on Divi 5.9 text modules could not be interaction TRIGGERS (Divi rendered none), only toggle
// TARGETS (toggleId/hidden). Since Divi 5.13 any module can be a trigger ("interactions" on the spec,
// see above); on a site older than 5.13 use a button for triggers.
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
  const align = styleDefault(m, m.align, 'left');
  const module = align ? { advanced: { alignment: { desktop: { value: align } } } } : {};
  const dec = {};
  if (m.padding) dec.spacing = { desktop: { value: { padding: Object.assign({ syncVertical: 'on', syncHorizontal: 'on' }, m.padding) } } };
  if (m.interactions) Object.assign(dec, interactionsDecoration(m));
  if (m.popup) Object.assign(dec, popupInteractionDecoration(m.popup));
  if (m.toggles) Object.assign(dec, togglesDecoration(m.trigger || ('tg' + m.toggles[0]), m.toggles));
  if (m.scrollTo) Object.assign(dec, effectInteraction('s' + m.scrollTo, 'scrollToElement', m.scrollTo));
  if (m.togglePreset) Object.assign(dec, effectInteraction('p' + m.togglePreset.target, 'togglePreset', m.togglePreset.target, { presetId: m.togglePreset.presetId, replaceExistingPreset: true }));
  if (m.toggleId) dec.interactionTarget = m.toggleId; // this button is also a toggle target
  if (m.hidden) Object.assign(dec, hiddenDecoration());
  if (Object.keys(dec).length) module.decoration = dec;
  const bdec = {};
  const fv = fontValue({ color: m.color, size: m.size, weight: styleDefault(m, m.weight, '700'), family: m.family, caps: m.caps });
  if (Object.keys(fv).length) bdec.font = { font: { desktop: { value: fv } } };
  const bg = colorVal(m.bg); if (bg) bdec.background = { desktop: { value: { color: bg } } };
  if (m.radius) bdec.border = { desktop: { value: { radius: { topLeft: m.radius, topRight: m.radius, bottomLeft: m.radius, bottomRight: m.radius, sync: 'on' } } } };
  if (m.icon !== undefined || !onPreset(m)) bdec.button = { desktop: { value: { icon: { enable: m.icon ? 'on' : 'off' } } } };
  const inner = { text: m.text || 'Learn more', linkUrl: m.url || '#' };
  if (m.newTab) inner.linkTarget = 'on';
  const button = { innerContent: { desktop: { value: inner } } };
  if (Object.keys(bdec).length) button.decoration = bdec;
  const attrs = Object.keys(module).length ? { module, button, builderVersion: BV } : { button, builderVersion: BV };
  return [open('divi/button', attrs), close('divi/button')];
}
// Link: the WHOLE module renders as a real <a href>, unlike icon-list-item (which has
// module.advanced.link declared but its render_callback never wraps in an anchor — verified
// against IconListItemModule.php, 5.13). Use this, not icon-list, for a list of items that
// must each be independently clickable (a "body menu" rebuilt from a nav menu, etc).
// icon accepts a unicode string ("&#xf054;") or {unicode,type,weight} — type defaults to "fa"
// (Divi's own bundled FontAwesome webfont; confirmed via IconLibrary/IconFont/Utils.php that
// type:"fa" -> font-family "FontAwesome", and full_icons_list.json carries chevron-right etc).
// `border` is Divi's raw border.styles shape, e.g. {bottom:{width:'1px',color:'#fff',style:'solid'}},
// passed straight through so a caller can put a divider on just one side, or top+bottom on item 1.
function mLink(m) {
  const module = {};
  const dec = {};
  if (m.padding) dec.spacing = { desktop: { value: { padding: Object.assign({ syncVertical: 'on', syncHorizontal: 'on' }, m.padding) } } };
  if (m.border) dec.border = { desktop: { value: { styles: m.border } } };
  if (m.gap) dec.layout = { desktop: { value: { columnGap: m.gap, rowGap: m.gap } } };
  if (m.width) dec.sizing = { desktop: { value: { width: m.width } } };
  if (m.interactions) Object.assign(dec, interactionsDecoration(m));
  if (m.toggleId) dec.interactionTarget = m.toggleId;
  if (m.hidden) Object.assign(dec, hiddenDecoration());
  if (Object.keys(dec).length) module.decoration = dec;
  const cdec = {};
  const fv = fontValue(m);
  if (Object.keys(fv).length) cdec.font = { font: { desktop: { value: fv } } };
  const inner = { text: m.text || '', linkUrl: m.url || '#' };
  if (m.newTab) inner.linkTarget = 'on';
  const attrs = { content: { innerContent: { desktop: { value: inner } } }, builderVersion: BV };
  if (Object.keys(cdec).length) attrs.content.decoration = cdec;
  if (m.icon) {
    const icon = typeof m.icon === 'string' ? { unicode: m.icon } : m.icon;
    const iattrs = { innerContent: { desktop: { value: { unicode: icon.unicode || '&#xf00c;', type: icon.type || 'fa', weight: icon.weight || '900', target: 'off' } } } };
    const iadv = {};
    if (m.iconColor) iadv.color = { desktop: { value: colorVal(m.iconColor) } };
    if (m.iconSize) iadv.size = { desktop: { value: m.iconSize } };
    if (Object.keys(iadv).length) iattrs.advanced = iadv;
    attrs.icon = iattrs;
  }
  if (Object.keys(module).length) attrs.module = module;
  return [open('divi/link', attrs), close('divi/link')];
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
  // unicode/type/weight pick WHICH glyph (content), so they are always written; size and colour are style.
  const adv = {};
  const size = styleDefault(m, m.size, '40px'); if (size) adv.size = { desktop: { value: size } };
  const color = styleDefault(m, m.color, '#ffffff'); if (color) adv.color = { desktop: { value: color } };
  const icon = { innerContent: { desktop: { value: { unicode: m.unicode || '&#x51;', type: m.iconType || 'divi', weight: m.weight || '400' } } } };
  if (Object.keys(adv).length) icon.advanced = adv;
  const attrs = { icon, builderVersion: BV };
  if (m.absolute) attrs.module = { decoration: { position: { desktop: { value: { mode: 'absolute', origin: { absolute: m.origin || 'top right' }, offset: { horizontal: m.offsetX || '-50px' } } } } } };
  return [selfClose('divi/icon', attrs)];
}
function mImage(m) {
  const iv = { src: m.src || '', id: m.id || '', alt: m.alt || '' };
  if (m.url) { iv.linkUrl = m.url; iv.linkTarget = m.newTab === false ? 'off' : 'on'; } // link the image (opens new tab by default)
  const attrs = { image: { innerContent: { desktop: { value: iv } } }, builderVersion: BV };
  // Optional framing (rounded corners / drop shadow / border / max width). Issue #33: on divi/image the MODULE
  // element has no border, boxShadow or sizing groups (it does not clip the photo, so corners stayed square).
  // Divi's own Divi 4 converter and builder-saved pages put border + boxShadow on the IMAGE element
  // (image.decoration.*), max-width in module.advanced.sizing and the alignment in module.advanced.align.
  const f = m.frame;
  if (f) {
    const idec = {};
    if (f.radius) idec.border = { desktop: { value: { radius: { topLeft: f.radius, topRight: f.radius, bottomLeft: f.radius, bottomRight: f.radius, sync: 'on' } } } };
    if (f.border) { idec.border = idec.border || { desktop: { value: {} } }; idec.border.desktop.value.styles = { all: { width: f.border.width || '1px', color: f.border.color || '#e6eae6', style: 'solid' } }; }
    if (f.shadow) { const sh = f.shadow === true ? { h: '0px', v: '18px', blur: '40px', spread: '0px', color: 'rgba(16,40,28,0.18)' } : f.shadow; idec.boxShadow = { desktop: { value: { horizontal: sh.h || '0px', vertical: sh.v || '18px', blur: sh.blur || '40px', spread: sh.spread || '0px', position: 'outer', color: sh.color || 'rgba(0,0,0,0.2)', style: 'preset1' } } }; }
    if (Object.keys(idec).length) attrs.image.decoration = idec;
    if (f.maxWidth) attrs.module = { advanced: { sizing: { desktop: { value: { maxWidth: f.maxWidth } } }, align: { desktop: { value: m.align || 'center' } } } };
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
  const imageIcon = { innerContent: { desktop: { value: iconInner } } };
  const placement = styleDefault(m, m.iconPlacement, 'top');
  if (placement) imageIcon.advanced = { placement: { desktop: { value: placement } } };
  if (m.iconSize) imageIcon.decoration = { sizing: { desktop: { value: { iconFontSize: m.iconSize } } } };
  const title = { innerContent: { desktop: { value: { text: m.title || '' } } } };
  if (m.titleLevel) title.decoration = { font: { font: { desktop: { value: { headingLevel: m.titleLevel } } } } };
  const attrs = {
    imageIcon,
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
  const attrs = (m.showLine !== undefined || !onPreset(m))
    ? { divider: { advanced: { line: { desktop: { value: { show: m.showLine ? 'on' : 'off' } } } } }, builderVersion: BV }
    : { builderVersion: BV };
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
// Native pricing tables. tiers:[{title,subtitle,price,currency,per,url,buttonText,featured,features:[{text,excluded}|"str"]}],
// plus an optional module-level "currency" for every tier that states none.
// Pricing currency: the SPEC should state it ("currency" on a tier, or on the pricing module for every tier),
// taken from the site (its WooCommerce currency, the client's country), not from where the builder sits.
// Fallback: this computer's region (Intl locale; DIVI_LOCALE overrides it), always with a warning on stderr,
// because the site's customers may be elsewhere. A region missing from the table falls back to Divi's "$".
const REGION_CURRENCY = { ZA: 'ZAR', NA: 'NAD', BW: 'BWP', LS: 'LSL', SZ: 'SZL', ZW: 'USD', MZ: 'MZN', ZM: 'ZMW', KE: 'KES', NG: 'NGN', GH: 'GHS',
  EG: 'EGP', MA: 'MAD', US: 'USD', CA: 'CAD', MX: 'MXN', BR: 'BRL', AR: 'ARS', CL: 'CLP', CO: 'COP', GB: 'GBP', IE: 'EUR', DE: 'EUR',
  FR: 'EUR', NL: 'EUR', BE: 'EUR', LU: 'EUR', ES: 'EUR', PT: 'EUR', IT: 'EUR', AT: 'EUR', FI: 'EUR', GR: 'EUR', CY: 'EUR', MT: 'EUR',
  SK: 'EUR', SI: 'EUR', EE: 'EUR', LV: 'EUR', LT: 'EUR', HR: 'EUR', CH: 'CHF', SE: 'SEK', NO: 'NOK', DK: 'DKK', PL: 'PLN', CZ: 'CZK',
  HU: 'HUF', RO: 'RON', TR: 'TRY', IL: 'ILS', AE: 'AED', SA: 'SAR', IN: 'INR', PK: 'PKR', CN: 'CNY', HK: 'HKD', JP: 'JPY', KR: 'KRW',
  SG: 'SGD', MY: 'MYR', TH: 'THB', ID: 'IDR', PH: 'PHP', VN: 'VND', AU: 'AUD', NZ: 'NZD' };
let localCurrencyMemo = null;
function localCurrency() {
  if (localCurrencyMemo) return localCurrencyMemo;
  let sym = null, why;
  try {
    const loc = process.env.DIVI_LOCALE || Intl.DateTimeFormat().resolvedOptions().locale;
    const region = new Intl.Locale(loc).maximize().region || '';
    const code = REGION_CURRENCY[region];
    if (code) { sym = new Intl.NumberFormat(loc, { style: 'currency', currency: code, currencyDisplay: 'narrowSymbol' }).formatToParts(1).find((p) => p.type === 'currency').value; why = `this computer's region, ${loc} -> ${code}`; }
    else why = `region "${region || '?'}" of ${loc} is not in the table, so Divi's default`;
  } catch (e) { why = 'no usable locale, so Divi\'s default'; }
  return (localCurrencyMemo = { sym, why });
}
function pricingCurrency(m, t, i) {
  const given = t.currency || m.currency;
  if (given) return given;
  const lc = localCurrency(); const use = lc.sym || '$';
  process.stderr.write(`WARNING: pricing table ${i + 1}${t.title ? ` ("${t.title}")` : ''} has no "currency": used "${use}" (${lc.why}). ` +
    'State "currency" in the spec, from the site (its shop currency or the client\'s country): its customers may not be where you are.\n');
  return use;
}
function mPricingTables(m) {
  const lines = [open('divi/pricing-tables', { builderVersion: BV })];
  (m.tiers || []).forEach((t, i) => {
    const feats = (t.features || []).map((f) => (typeof f === 'string' ? '+ ' + f : (f.excluded ? '- ' : '+ ') + f.text)).join('\n');
    const a = {
      currencyFrequency: { innerContent: { desktop: { value: { currency: pricingCurrency(m, t, i), per: t.per || 'month' } } } },
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

// Divi Plugins "Swiper" addon (block prefix dp-dss, title "Swiper Post Slider" in the
// builder). Loops ANY post type (custom_query/current_post_type/multiple_cpt) into a
// Swiper carousel — verified against the site's own /wp/v2/block-types schema (dp-dss
// isn't in the bundled Divi zip, so it can't come from catalog.js; read its attrs from a
// live site with the plugin active: GET /wp-json/wp/v2/block-types/dp-dss/posts-slider).
// Same flat innerContent.desktop.value shape as FilterGrid, PLUS a few fields (slidesPerView,
// spaceBetween) that ship with real per-breakpoint defaults (3/2/1) — pass an object
// {desktop,tablet,phone} for those when you need every breakpoint pinned to the same value,
// not just desktop (leaving tablet/phone untouched keeps THEIR OWN defaults, not your override).
function mSwiperPostsSlider(m) {
  const resp = (v) => (v == null ? undefined : (typeof v === 'object' && !Array.isArray(v)
    ? { desktop: { value: String(v.desktop) }, ...(v.tablet != null && { tablet: { value: String(v.tablet) } }), ...(v.phone != null && { phone: { value: String(v.phone) } }) }
    : { desktop: { value: String(v) } }));
  const map = {
    current_post_type: m.currentPostType, multiple_cpt: m.postType, post_number: m.count != null ? String(m.count) : undefined,
    order: m.order, orderby: m.orderBy, include_categories: m.categories,
    showImage: m.showImage, showTitle: m.showTitle, titleLink: m.titleLink,
    show_author: m.showAuthor, show_date: m.showDate, show_terms: m.showTerms, show_post_meta: m.showPostMeta,
    showContent: m.showContent, truncate_content: m.truncateContent != null ? String(m.truncateContent) : undefined,
    imageClickAction: m.imageClickAction, thumbnailSize: m.thumbnailSize,
    figureRatio: m.figureRatio, ratioWidth: m.ratioWidth, ratioHeight: m.ratioHeight, objectFit: m.objectFit,
    direction: m.direction, mode: m.mode, showNavigation: m.showNavigation, pagination: m.pagination,
    autoplay: m.autoplay, delay: m.delay != null ? String(m.delay) : undefined, pauseOnMouseEnter: m.pauseOnMouseEnter,
    arrowColor: m.arrowColor, arrowBGColor: m.arrowBGColor, arrowSize: m.arrowSize, arrowPlacement: m.arrowPlacement,
    bulletsColor: m.bulletsColor, bulletsAColor: m.bulletsAColor, bulletsSize: m.bulletsSize,
  };
  Object.assign(map, m.settings || {}); // raw passthrough for any other option
  const attrs = { builderVersion: BV };
  for (const [k, v] of Object.entries(map)) { if (v == null || v === '') continue; attrs[k] = { innerContent: resp(v) }; }
  for (const [k, v] of Object.entries({ slidesPerView: m.slidesPerView, spaceBetween: m.spaceBetween })) {
    if (v == null) continue; attrs[k] = { innerContent: resp(v) };
  }
  // title/content are real named elements (per the block's own schema) styled the normal
  // Divi way, not flat innerContent -- same shape as any other module's text decoration.
  if (m.titleFont) { const fv = fontValue(m.titleFont); if (Object.keys(fv).length) attrs.title = { decoration: { font: { font: { desktop: { value: fv } } } } }; }
  if (m.htmlClass) attrs.module = { advanced: { htmlAttributes: { desktop: { value: { class: m.htmlClass } } } } };
  return [open('dp-dss/posts-slider', attrs), close('dp-dss/posts-slider')];
}

function buildModule(m) { return withPreset(m, withCommon(m, buildModuleBare(m))); }
function buildModuleBare(m) {
  switch (m.type) {
    case 'heading': return mHeading(m);
    case 'text': return mText(m);
    case 'button': return mButton(m);
    case 'link': return mLink(m);
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
    case 'swiper-posts': return mSwiperPostsSlider(m);
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
// Unequal layouts (issue #33): Divi 5.15.1's own "offset-columns" templates, read from the builder's
// constant-library.js, per column per breakpoint (a breakpoint left out inherits the larger one), plus where the
// row wraps. "2-1" = two thirds + one third, "1-2-1" = quarter + half + quarter, and so on.
const OFFSET = {
  '3-2': { name: 'offset-columns_1', wrap: ['phone'], cols: [{ desktop: '3_5', phone: '24_24' }, { desktop: '2_5', phone: '24_24' }] },
  '2-3': { name: 'offset-columns_2', wrap: ['phone'], cols: [{ desktop: '2_5', phone: '24_24' }, { desktop: '3_5', phone: '24_24' }] },
  '2-1-1': { name: 'offset-columns_3', wrap: ['tablet'], cols: [{ desktop: '12_24', tablet: '24_24' }, { desktop: '6_24', tablet: '12_24', phone: '24_24' }, { desktop: '6_24', tablet: '12_24', phone: '24_24' }] },
  '1-1-2': { name: 'offset-columns_4', wrap: ['tablet'], cols: [{ desktop: '6_24', tablet: '12_24', phone: '24_24' }, { desktop: '6_24', tablet: '12_24', phone: '24_24' }, { desktop: '12_24', tablet: '24_24' }] },
  '2-1': { name: 'offset-columns_5', wrap: ['phone'], cols: [{ desktop: '16_24', phone: '24_24' }, { desktop: '8_24', phone: '24_24' }] },
  '1-2': { name: 'offset-columns_6', wrap: ['phone'], cols: [{ desktop: '8_24', phone: '24_24' }, { desktop: '16_24', phone: '24_24' }] },
  '3-1-1-1': { name: 'offset-columns_7', wrap: ['tablet'], cols: [{ desktop: '12_24', tablet: '24_24' }, { desktop: '4_24', tablet: '8_24', phone: '12_24' }, { desktop: '4_24', tablet: '8_24', phone: '12_24' }, { desktop: '4_24', tablet: '8_24', phone: '12_24' }] },
  '1-1-1-3': { name: 'offset-columns_8', wrap: ['tablet'], cols: [{ desktop: '4_24', tablet: '8_24', phone: '12_24' }, { desktop: '4_24', tablet: '8_24', phone: '12_24' }, { desktop: '4_24', tablet: '8_24', phone: '12_24' }, { desktop: '12_24', tablet: '24_24' }] },
  '3-1': { name: 'offset-columns_9', wrap: ['phone'], cols: [{ desktop: '18_24', phone: '24_24' }, { desktop: '6_24', phone: '24_24' }] },
  '1-3': { name: 'offset-columns_10', wrap: ['phone'], cols: [{ desktop: '6_24', phone: '24_24' }, { desktop: '18_24', phone: '24_24' }] },
  '1-2-1': { name: 'offset-columns_11', wrap: ['phone'], cols: [{ desktop: '6_24', phone: '24_24' }, { desktop: '12_24', phone: '24_24' }, { desktop: '6_24', phone: '24_24' }] },
  '1-3-1': { name: 'offset-columns_12', wrap: ['phone'], cols: [{ desktop: '1_5', phone: '24_24' }, { desktop: '3_5', phone: '24_24' }, { desktop: '1_5', phone: '24_24' }] },
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
  return withPreset(col, withCommon(col, lines));
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
  if (row.grid) return withPreset(row, withCommon(row, buildGridRow(row)));
  const key = row.layout || '1';
  const O = OFFSET[key];
  // An unknown layout used to become ONE column, silently. Refuse it instead, and list what exists.
  if (!O && !LAYOUT[key]) throw new Error(`layout "${key}" is not known. Equal: ${Object.keys(LAYOUT).join(', ')}. Unequal: ${Object.keys(OFFSET).join(', ')}. For card grids put "grid" on the row.`);
  const ncols = (row.columns || []).length;
  if (O && ncols !== O.cols.length) throw new Error(`layout "${key}" has ${O.cols.length} columns, the row has ${ncols}`);
  const n = O ? O.cols.length : LAYOUT[key];
  const module = { advanced: { flexColumnStructure: { desktop: { value: O ? O.name : 'equal-columns_' + n } } } };
  let sizing = { desktop: '24_24' };
  if (O) {
    const layout = { desktop: { value: { flexWrap: 'nowrap' } } };
    (row.wrap || O.wrap).forEach((bp) => { layout[bp] = { value: { flexWrap: 'wrap' } }; });
    module.decoration = { layout };
  } else if (n > 1) {
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
  (row.columns || []).forEach((col, i) => lines.push(...buildColumn(col, O ? O.cols[i] : sizing)));
  lines.push(close('divi/row'));
  return withPreset(row, withCommon(row, lines));
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
  return withPreset(sec, withCommon(sec, lines));
}

// Unknown keys (issue #17): a WARNING on stderr per key, or an error with opts.strict. opts.quiet silences the
// warnings; compile.warnings keeps those of the last call either way.
function reportSpec(warnings, opts) {
  if (warnings.length && opts.strict) throw new Error('spec check failed:\n  ' + warnings.join('\n  '));
  if (!opts.quiet) for (const w of warnings) process.stderr.write('WARNING: ' + w + '\n');
}
function compile(spec, opts = {}) {
  compile.warnings = checkSpec(spec, 'page'); reportSpec(compile.warnings, opts);
  BV = spec.builderVersion || BV;
  const lines = ['<!-- wp:divi/placeholder -->'];
  for (const sec of (spec.sections || [])) lines.push(...buildSection(sec));
  // Stored pages close the wrapper directly after the last block, no newline (9 of 9 on 5.9, 38 of 38 on 5.13).
  return lines.join('\n') + PLACEHOLDER_CLOSE;
}

// Compile a popup canvas: a full-screen, hidden-by-default overlay section whose
// interactionTarget matches a trigger elsewhere. Content is created as an
// et_pb_canvas post, then attached to a page via `wp.js link-canvas`.
// popup = { targetId, overlayColor, width, modules:[...], builderVersion }
function compilePopupCanvas(popup, opts = {}) {
  compilePopupCanvas.warnings = checkSpec(popup, 'popup'); reportSpec(compilePopupCanvas.warnings, opts);
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
  lines.push(close('divi/column'), close('divi/row'), close('divi/section'));
  return lines.join('\n') + PLACEHOLDER_CLOSE;
}

// ---- Spec check (issue #17): unknown keys are reported, not silently ignored ----
// The known keys are read from THIS file's own source, so a new option is known the moment a builder reads it:
// for each builder, every `<param>.<key>` it reads, following helpers that receive the same object (fontValue(m),
// withCommon(sec, …)). Keys starting with "_" or "$" are free for notes ("_comment").
let KNOWN = null;
function knownKeys() {
  if (KNOWN) return KNOWN;
  const src = fs.readFileSync(__filename, 'utf8');
  const fns = {};
  for (const h of src.matchAll(/^function (\w+)\(([^)]*)\)\s*\{/gm)) {
    let i = h.index + h[0].length - 1, depth = 0, j = i;
    for (; j < src.length; j++) { if (src[j] === '{') depth++; else if (src[j] === '}' && !--depth) break; }
    fns[h[1]] = { param: (h[2].split(',')[0] || '').trim(), body: src.slice(i, j + 1) };
  }
  const keysOf = (name, seen = new Set()) => {
    const f = fns[name]; const out = new Set(); if (!f || !f.param || seen.has(name)) return out; seen.add(name);
    const p = f.param.replace(/[^\w$]/g, '');
    for (const k of f.body.matchAll(new RegExp('\\b' + p + '\\.(\\w+)', 'g'))) out.add(k[1]);
    for (const c of f.body.matchAll(new RegExp('\\b(\\w+)\\(\\s*' + p + '\\s*[,)]', 'g'))) if (c[1] !== name) keysOf(c[1], seen).forEach((k) => out.add(k));
    return out;
  };
  const shared = new Set(['type', ...keysOf('withPreset'), ...keysOf('withCommon')]);
  const sw = (fns.buildModuleBare || {}).body || '';
  const module = {};
  for (const c of sw.matchAll(/case '([\w-]+)': return (\w+)\(m\)/g)) module[c[1]] = new Set([...shared, ...keysOf(c[2])]);
  KNOWN = {
    page: keysOf('compile'), section: keysOf('buildSection'), column: keysOf('buildColumn'),
    row: new Set([...shared, ...keysOf('buildRow'), ...keysOf('buildGridRow')]), popup: keysOf('compilePopupCanvas'), module,
  };
  return KNOWN;
}
function editDistance(a, b) {
  const d = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
  return d[a.length][b.length];
}
// checkSpec(spec, kind = 'page'|'popup') -> [ "unknown key ..." ] (empty when every key is one a builder reads)
function checkSpec(spec, kind = 'page') {
  const K = knownKeys(); const out = [];
  const suggest = (k, allowed) => {
    let best = null, bd = 3;
    for (const a of allowed) { const dd = a.toLowerCase() === k.toLowerCase() ? 0 : editDistance(k.toLowerCase(), a.toLowerCase()); if (dd < bd) { bd = dd; best = a; } }
    return best ? ` (did you mean "${best}"?)` : '';
  };
  const visit = (o, kd, where) => {
    if (!o || typeof o !== 'object' || Array.isArray(o)) return;
    const allowed = kd === 'module' ? K.module[o.type] : K[kd];
    if (!allowed) return; // an unknown module type is refused by buildModule itself
    const label = kd === 'module' ? `${o.type} module` : kd;
    for (const k of Object.keys(o)) if (!/^[_$]/.test(k) && !allowed.has(k)) out.push(`unknown key "${k}" on the ${label} at ${where}${suggest(k, allowed)}`);
    const kids = (arr, kk, name) => (Array.isArray(arr) ? arr : []).forEach((x, i) => visit(x, kk(x), `${where}.${name}[${i}]`));
    if (kd === 'page') kids(o.sections, () => 'section', 'sections');
    if (kd === 'section') kids(o.rows, () => 'row', 'rows');
    if (kd === 'row') kids(o.columns, () => 'column', 'columns');
    if (kd === 'column' || kd === 'popup' || kd === 'module') kids(o.modules, (x) => (x && x.type === 'row' ? 'row' : 'module'), 'modules');
  };
  visit(spec, kind, kind === 'page' ? 'spec' : 'popup');
  // Variable math (issue #45): a value with a variable in it and a "×", "÷" or "x" used as an operator.
  const values = (o, where) => {
    if (typeof o === 'string') { const w = variableMathWarning(o); if (w) out.push(`${where} = "${o}": ${w}`); return; }
    if (Array.isArray(o)) { o.forEach((x, i) => values(x, `${where}[${i}]`)); return; }
    if (o && typeof o === 'object') for (const k of Object.keys(o)) if (!/^[_$]/.test(k)) values(o[k], `${where}.${k}`);
  };
  values(spec, kind === 'page' ? 'spec' : 'popup');
  return out;
}

// Divi 5.18 wraps a value holding a variable in calc() when it has + - * / between operands
// (StyleLibrary/Utils/Utils.php normalize_variable_math). "×" or "÷" pass through unchanged: calc(var(--gvid-x) × -1)
// is invalid CSS and the browser drops the whole declaration (measured live: an image margin). Text with a variable in
// it (mixed text, "Copyright © {{gvid-year}}") is not math and is left alone: only a value that is, apart from its
// variables, numbers, units, CSS functions and operators is checked. Returns the warning, or null.
const CSS_WORDS = new Set(['px', 'em', 'rem', 'vw', 'vh', 'vmin', 'vmax', 'svh', 'lvh', 'dvh', 'svw', 'lvw', 'dvw', 'ch', 'ex', 'lh', 'rlh', 'cqw', 'cqh', 'cqi', 'cqb', 'cqmin', 'cqmax',
  'pt', 'pc', 'cm', 'mm', 'q', 'in', 'deg', 'rad', 'grad', 'turn', 's', 'ms', 'fr', 'calc', 'clamp', 'min', 'max', 'var', 'x']);
function variableMathWarning(value) {
  if (typeof value !== 'string') return null;
  const hasVar = /\$variable\(\{"type":"content","value":\{"name":"gvid-|var\(--gvid-|\{\{\s*gvid-/.test(value);
  if (!hasVar) return null;
  const rest = value
    .replace(/\$variable\(\{"type":"content","value":\{"name":"gvid-[^"]*","settings":\{[^}]*\}\}\}\)\$/g, ' ')
    .replace(/var\(--gvid-[\w-]*\)/g, ' ').replace(/\{\{\s*gvid-[^}]*\}\}/g, ' ');
  if (!/^[\s\d.%+\-*/×÷(),a-z]*$/i.test(rest)) return null;                       // text, not a number expression
  if ((rest.match(/[a-z]+/gi) || []).some((w) => !CSS_WORDS.has(w.toLowerCase()))) return null;
  const op = /×/.test(rest) ? '×' : /÷/.test(rest) ? '÷' : /(^|[\s\d)])x(?=[\s\d(+-]|$)/i.test(rest) ? 'x' : null;
  if (!op) return null;
  return `\`${op}\` is not a CSS operator; Divi only treats + - * / as math. Use \`${op === '÷' ? '/' : '*'}\`.`;
}

// ---- Checks after compiling (issue #17) ----
// The global colours, variables and presets the compiled markup REFERENCES, read from the output itself.
function referencedIds(out) {
  const t = out.replace(/\\u0022/g, '"');
  const ids = { colors: new Set(t.match(/gcid-[a-z0-9-]+/g) || []), variables: new Set(t.match(/gvid-[a-z0-9-]+/g) || []), presets: new Set() };
  for (const m of t.matchAll(/"(?:modulePreset|presetId)":\[([^\]]*)\]/g)) for (const id of m[1].split(',')) { const s = id.replace(/"/g, '').trim(); if (s && s !== 'default') ids.presets.add(s); }
  return ids;
}
const BUILTIN_COLORS = ['gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color'];
// --site <name>: every referenced colour, variable and preset must exist on that site (a missing one renders nothing).
function checkAgainstSite(out, site) {
  const { execFileSync } = require('child_process');
  const ds = JSON.parse(execFileSync(process.execPath, [path.join(__dirname, 'wp.js'), site, 'design-system', '--json', '--full'], { encoding: 'utf8', maxBuffer: 64e6 }));
  const ids = referencedIds(out); const problems = [];
  const colors = ds.colors || {};
  for (const c of ids.colors) {
    if (BUILTIN_COLORS.includes(c)) continue;
    if (!colors[c]) problems.push(`global colour ${c} does not exist on ${site} (take ids from: wp.js ${site} design-system)`);
    else if (colors[c].status && colors[c].status !== 'active') problems.push(`global colour ${c} ("${colors[c].label}") is ${colors[c].status} on ${site}`);
  }
  // Variables: by id, wherever they are referenced ({{gvid-…}} placeholders are tokens by now, and a token inside a
  // calculation or a mixed text still names the id). An archived one is deleted in the builder: report it too.
  const vars = new Map((Array.isArray(ds.variables) ? ds.variables : Object.values(ds.variables || {}).flatMap((x) => (Array.isArray(x) ? x : Object.values(x || {})))).filter((v) => v && v.id).map((v) => [v.id, v]));
  for (const v of ids.variables) {
    if (!vars.has(v)) problems.push(`design variable ${v} does not exist on ${site}`);
    else if (vars.get(v).status && vars.get(v).status !== 'active') problems.push(`design variable ${v} ("${vars.get(v).label}") is ${vars.get(v).status} on ${site}`);
  }
  const presets = new Set([...((ds.presets || {}).module || []), ...((ds.presets || {}).group || [])].map((p) => p.id));
  for (const p of ids.presets) if (!presets.has(p)) problems.push(`preset ${p} does not exist on ${site} (take ids from: wp.js ${site} design-system)`);
  return problems;
}

// ---- CLI ----
//   node divi.js compile|compile-canvas <spec.json> [--out F] [--strict] [--site <name>] [--no-lint]
//   --strict       an unknown spec key, or a catalogue lint error, fails the run (exit 1)
//   --site <name>  check every global colour, variable and preset the page uses exists on that site (exit 1 if not)
//   --no-lint      skip the catalogue lint that runs after compiling when catalog/catalog.json exists
if (require.main === module) {
  const [cmd, specPath, ...rest] = process.argv.slice(2);
  const flagVal = (n) => { const i = rest.indexOf('--' + n); return i >= 0 && rest[i + 1] && !rest[i + 1].startsWith('--') ? rest[i + 1] : null; };
  const strict = rest.includes('--strict'); const site = flagVal('site'); const outFile = flagVal('out');
  if (!['compile', 'compile-canvas'].includes(cmd) || !specPath) { process.stderr.write('usage: node divi.js compile|compile-canvas <spec.json> [--out F] [--strict] [--site <name>] [--no-lint]\n'); process.exit(1); }
  const spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));
  let out;
  try { out = cmd === 'compile' ? compile(spec, { strict }) : compilePopupCanvas(spec, { strict }); }
  catch (e) { process.stderr.write('ERROR: ' + e.message + '\n'); process.exit(1); }
  if (outFile) { fs.writeFileSync(outFile, out); process.stderr.write(`wrote ${out.length} chars to ${outFile}\n`); } else process.stdout.write(out);
  let fail = false;
  if (site) {
    const problems = checkAgainstSite(out, site);
    for (const p of problems) process.stderr.write('ERROR: ' + p + '\n');
    if (problems.length) fail = true; else process.stderr.write(`site check: every colour, variable and preset exists on ${site}\n`);
  }
  const catalogFile = path.join(__dirname, '..', 'catalog', 'catalog.json');
  if (!rest.includes('--no-lint') && fs.existsSync(catalogFile)) {
    const { spawnSync } = require('child_process');
    const f = outFile || path.join(require('os').tmpdir(), `d5b-compiled-${process.pid}.html`); if (!outFile) fs.writeFileSync(f, out);
    const r = spawnSync(process.execPath, [path.join(__dirname, 'catalog.js'), 'lint', f], { encoding: 'utf8' });
    if (!outFile) try { fs.unlinkSync(f); } catch (e) {}
    const lines = String(r.stdout || '').split(/\r?\n/).filter((l) => /^\s+(ERROR|warn)\s/.test(l) || /error\(s\)|no errors/.test(l));
    process.stderr.write('lint: ' + (lines.length ? lines.map((l) => l.trim()).join('\n  ') : 'no output') + '\n');
    if (r.status !== 0) { if (strict) fail = true; else process.stderr.write('lint found errors (use --strict to fail on them)\n'); }
  }
  if (fail) process.exit(1);
}

// buildModule: compile ONE module spec (see buildModule's switch for `type`s) to its block-markup
// lines, for splicing into an existing page's content — e.g. replacing a single stale block
// (a leftover shortcode) without regenerating the section/row/column around it and losing
// responsive values a spec can't express (buildSection only takes one padding, not per-breakpoint).
module.exports = { compile, compilePopupCanvas, dc, buildModule, checkSpec, knownKeys, expandRefs, variableMathWarning, referencedIds };
