#!/usr/bin/env node
/**
 * switch-advice-test.js: the sentences the scripts print when a switch is off (issue #37, task 6).
 *   node scripts/switch-advice-test.js
 *   ADVICE_FILE=/path/to/switch-advice.js node scripts/switch-advice-test.js    # mutation testing
 */
const path = require('path');
const fs = require('fs');
const { adviceFor } = require(process.env.ADVICE_FILE || './switch-advice.js');

let failed = 0, n = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '\n          ' + String(detail).slice(0, 400))); if (!ok) failed++; };
const status = on => ({ version: '2.0.0', divi5: true, switches: { media_audit: false, media_clean_up: false, updates: false, [on]: true }, stood_aside: {} });
const off = () => ({ version: '2.0.0', divi5: true, switches: { media_audit: false, media_clean_up: false, updates: false }, stood_aside: {} });

for (const [part, label] of [['media_audit', 'Media audit'], ['media_clean_up', 'Media clean-up'], ['updates', 'Updates']]) {
  const a = adviceFor(part, off());
  check(`${part} off -> the switch sentence with label "${label}"`, a === `Switch ${label} on in Settings > Divi 5 Builder (an administrator must do it in wp-admin).`, a);
  check(`${part} off -> no mention of uploading a file or mu-plugins`, !/mu-plugins|assets\/|upload assets/i.test(a), a);
  const i = adviceFor(part, null);
  check(`${part} with no plugin (null) -> install sentence`, i === `Install divi5-builder.zip from the latest release (Plugins > Add New > Upload Plugin), then switch ${label} on in Settings > Divi 5 Builder.`, i);
}
check('page_building with no plugin -> install zip', adviceFor('page_building', null) === 'Install divi5-builder.zip from the latest release.', adviceFor('page_building', null));
check('a switch that is on is not told to switch it on', !/^Switch /.test(adviceFor('updates', status('updates'))), adviceFor('updates', status('updates')));
check('a status with the switch off is NOT given the install advice', !/Install divi5-builder\.zip/.test(adviceFor('updates', off())), adviceFor('updates', off()));
check('an unknown part throws', (() => { try { adviceFor('nope', null); return false; } catch (e) { return true; } })());

console.log('--- no script writes the switch');
for (const f of ['switch-advice.js', 'media-audit.js', 'media-quarantine.js', 'site-updates.js']) {
  const src = fs.readFileSync(path.join(__dirname, f), 'utf8');
  check(`${f} never touches the settings/switch option`, !/divi5_builder_(settings|switches)|update_option|options\/|wp\/v2\/settings/.test(src.replace(/^\s*(\/\/|\*|\/\*).*$/gm, '')), f);
}

console.log(failed ? `\nFAILED: ${failed} of ${n}` : `\nALL ${n} CHECKS PASSED`);
process.exit(failed ? 1 : 0);
