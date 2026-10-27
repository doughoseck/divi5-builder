#!/usr/bin/env node
/**
 * plugin-live-check.js <site>: read-only check of the divi5-builder plugin on a live site (issue #37).
 *
 *   node scripts/plugin-live-check.js <site>
 *
 * Only ever reads, through wp.js (which holds the credentials; this script never sees or prints them):
 *   1. GET /wp-json/                       the REST namespaces present
 *   2. GET divi5-builder/v1/status         version, switches, which parts stood aside for an old copy
 *   3. GET /wp/v2/settings                 the d5b_switches option must NOT be exposed there (needs an administrator)
 * Checks: a switched-off part's namespace must be absent (unless that part stood aside: an old copy provides it,
 * reported as a note); d5b_switches must not appear in settings.
 *
 * Exit 0 = every check passed, 1 = a check failed (or the plugin is absent), 2 = something could not be checked.
 */
const path = require('path');
const { execFileSync } = require('child_process');

const PARTS = { media_audit: 'wp-media-audit/v1', media_clean_up: 'wp-media-quarantine/v1', updates: 'wp-site-updates/v1' };

// GET through wp.js rest-get. Returns { status, json } (status 200 on success, the HTTP code on an error, 0 when unknown).
function get(site, args, wpjs) {
  try {
    const out = execFileSync(process.execPath, [wpjs, site, 'rest-get'].concat(args), { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    let json = null; try { json = JSON.parse(out); } catch (e) { /* not JSON */ }
    return { status: 200, json };
  } catch (e) {
    const m = /HTTP (\d{3})/.exec(String(e.stderr || ''));
    return { status: m ? Number(m[1]) : 0, json: null, err: String(e.stderr || e.message).split('\n')[0] };
  }
}

function check(site, wpjs) {
  const lines = []; let failed = false, unchecked = false;
  const say = s => lines.push(s);

  const idx = get(site, ['--index'], wpjs);
  let ns = null;
  if (idx.json && Array.isArray(idx.json.namespaces)) { ns = idx.json.namespaces; say('Namespaces present: ' + ns.join(', ')); }
  else { unchecked = true; say(`UNCHECKED: could not read the REST index (${idx.err || 'HTTP ' + idx.status})`); }

  const st = get(site, ['divi5-builder/v1/status'], wpjs);
  let status = null;
  if (st.status === 404) { failed = true; say('FAIL: the divi5-builder plugin is not installed or not active on this site (status route answered 404).'); }
  else if (st.json && typeof st.json === 'object') { status = st.json; say('Status route: ' + JSON.stringify(status)); }
  else { unchecked = true; say(`UNCHECKED: could not read the status route (${st.err || 'HTTP ' + st.status})`); }

  if (status && ns) {
    const sw = status.switches || {}, aside = status.stood_aside || {};
    for (const part of Object.keys(PARTS)) {
      const present = ns.includes(PARTS[part]);
      if (!sw[part] && present && aside[part]) say(`NOTE: ${part} switch is off but ${PARTS[part]} is present: this part stood aside (${aside[part]}) for an old copy of it running on the site. Expected.`);
      else if (!sw[part] && present) { failed = true; say(`FAIL: ${part} switch is off but ${PARTS[part]} is present.`); }
      else if (sw[part] && !present) say(`WARNING: ${part} switch is on but ${PARTS[part]} is not present.`);
      else say(`ok: ${part} ${sw[part] ? 'on, ' + PARTS[part] + ' present' : 'off, ' + PARTS[part] + ' absent'}`);
    }
  }

  const se = get(site, ['wp/v2/settings'], wpjs);
  if (se.status === 401 || se.status === 403) { unchecked = true; say('UNCHECKED: cannot read settings (needs an administrator); could not confirm d5b_switches is hidden from /wp/v2/settings.'); }
  else if (se.json && typeof se.json === 'object') {
    if (Object.prototype.hasOwnProperty.call(se.json, 'd5b_switches')) { failed = true; say('FAIL: d5b_switches appears in /wp/v2/settings (the switches must not be exposed to REST).'); }
    else say('ok: d5b_switches is not in /wp/v2/settings.');
  } else { unchecked = true; say(`UNCHECKED: could not read settings (${se.err || 'HTTP ' + se.status})`); }

  const code = failed ? 1 : unchecked ? 2 : 0;
  say(code === 0 ? 'RESULT: all checks passed.' : code === 1 ? 'RESULT: a check FAILED.' : 'RESULT: some checks could not be made (UNCHECKED above).');
  return { code, lines };
}

if (require.main === module) {
  const site = process.argv[2];
  if (!site || site.startsWith('-')) { console.error('usage: node plugin-live-check.js <site>'); process.exit(2); }
  const r = check(site, path.join(__dirname, 'wp.js'));
  console.log(r.lines.join('\n'));
  process.exit(r.code);
}
module.exports = { check, PARTS };
