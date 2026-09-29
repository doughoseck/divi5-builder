#!/usr/bin/env node
/*
 * ds-live-test.js — prove the design-system WRITES on a real site (mu-plugin >= 1.8.1), and leave no trace.
 *
 *   node ds-live-test.js <site> colors | variables | presets | all
 *
 * Each part creates a test item, updates it, checks Divi's own store after every step, then puts the store back from
 * the backup the plugin took before the test's FIRST write and proves the store is exactly what it was.
 * Run it on a staging site, and not while someone is editing presets, colours or variables in the builder: a restore
 * puts the whole store back, so an edit made during the test would be lost.
 * Saving to these stores clears Divi's generated CSS (as it does in the builder); pages rebuild it on their next visit.
 */
const path = require('path'); const fs = require('fs'); const os = require('os'); const { execFileSync } = require('child_process');
const [site, what] = process.argv.slice(2);
if (!site || !['colors', 'variables', 'presets', 'all'].includes(what)) { console.error('usage: node ds-live-test.js <site> colors|variables|presets|all'); process.exit(1); }
const WP = path.join(__dirname, 'wp.js');
const run = a => { try { return { ok: true, out: execFileSync('node', [WP, site, ...a], { encoding: 'utf8', maxBuffer: 64e6, stdio: ['ignore', 'pipe', 'pipe'] }) }; } catch (e) { return { ok: false, out: String(e.stderr || e.message) }; } };
const J = a => { const r = run(a); if (!r.ok) return { __error: r.out.replace(/\s+/g, ' ').slice(0, 220) }; try { return JSON.parse(r.out); } catch (e) { return { __error: 'not json: ' + r.out.slice(0, 120) }; } };
const ds = () => J(['design-system', '--json', '--full']);
const sort = v => Array.isArray(v) ? v.map(sort) : (v && typeof v === 'object' ? Object.keys(v).sort().reduce((o, k) => (o[k] = sort(v[k]), o), {}) : v);
const same = (a, b) => JSON.stringify(sort(a)) === JSON.stringify(sort(b));
const empty = v => !v || (typeof v === 'object' && Object.values(v).every(x => !x || (typeof x === 'object' && !Object.keys(x).length)));
let failed = 0; const check = (name, ok, detail) => { console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail ? '\n          ' + String(detail).slice(0, 300) : '')); if (!ok) failed++; return ok; };
// the backup taken before the test's first write is `writes - 1` places from the newest
const undo = (store, writes) => J(['ds-restore', '--store', store, '--index', String(writes - 1)]);

const tests = {
  colors() {
    const before = ds().colors || {}; const LABEL = 'ZZ Test Colour (delete me)'; let writes = 0;
    const dry = J(['ds-color-set', '--label', LABEL, '--color', '#123456', '--dry-run']);
    check('dry run answers and writes nothing', dry.ok && dry.dryRun === true && same(ds().colors || {}, before), dry.__error);
    const c = J(['ds-color-set', '--label', LABEL, '--color', '#123456']); if (!check('create: accepted', c.ok && c.action === 'created' && /^gcid-[a-z0-9]{10}$/.test(c.id || ''), c.__error || JSON.stringify(c).slice(0, 200))) return; writes++;
    let now = ds().colors; const others = o => { const x = { ...o }; delete x[c.id]; return x; };
    check('create: the colour is in Divi\'s store', now[c.id] && now[c.id].color === '#123456' && now[c.id].label === LABEL, JSON.stringify(now[c.id]));
    check('create: every existing colour is untouched', same(others(now), before));
    const ref = Object.values(before)[0]; if (ref) check('create: same fields as the colours already on the site', same(Object.keys(now[c.id]).sort(), Object.keys(ref).sort()), Object.keys(now[c.id] || {}).join(',') + ' vs ' + Object.keys(ref).join(','));
    const u = J(['ds-color-set', '--label', LABEL, '--color', 'rgba(18,52,86,0.5)']); if (u.ok) writes++; now = ds().colors;
    check('same label again updates the same colour', u.ok && u.action === 'updated' && u.id === c.id && Object.keys(now).length === Object.keys(before).length + 1 && now[c.id].color === 'rgba(18,52,86,0.5)', u.__error || JSON.stringify(now[c.id]));
    const bad = run(['ds-color-set', '--label', 'ZZ Bad', '--color', 'not-a-colour']); check('a value that is not a colour is refused', !bad.ok && /bad_color/.test(bad.out), bad.out.slice(0, 120));
    const r0 = J(['ds-restore', '--store', 'colors', '--index', '0']); if (r0.ok) writes++; now = ds().colors;
    check('restore 0 = the store before the last write', r0.ok && now[c.id] && now[c.id].color === '#123456', r0.__error || JSON.stringify(now[c.id]));
    const r = undo('colors', writes); now = ds().colors || {};
    check('restore of the backup taken before the test: the test colour is gone', r.ok && !now[c.id], r.__error);
    check('END STATE: the colour store is exactly what it was', same(now, before), Object.keys(now).join(','));
  },
  variables() {
    const before = ds().variables; const LABEL = 'ZZ Test Gap (delete me)'; let writes = 0;
    const dry = J(['ds-variable-set', '--type', 'numbers', '--label', LABEL, '--value', '20px', '--dry-run']);
    check('dry run answers and writes nothing', dry.ok && dry.dryRun === true && (same(ds().variables, before) || (empty(ds().variables) && empty(before))), dry.__error);
    const c = J(['ds-variable-set', '--type', 'numbers', '--label', LABEL, '--value', '20px']);
    if (!check('create: accepted by Divi (the user needs the "Variables Manager" permission)', c.ok && c.action === 'created' && /^gvid-[a-z0-9]{10}$/.test(c.id || ''), c.__error || JSON.stringify(c).slice(0, 200))) return; writes++;
    let now = ds().variables; const it = ((now || {}).numbers || {})[c.id];
    check('create: the variable is in Divi\'s store', it && it.value === '20px' && it.label === LABEL && it.id === c.id, JSON.stringify(it));
    const u = J(['ds-variable-set', '--type', 'numbers', '--label', LABEL, '--value', '24px']); if (u.ok) writes++; now = ds().variables;
    check('same label again updates the same variable', u.ok && u.action === 'updated' && u.id === c.id && now.numbers[c.id].value === '24px', u.__error);
    const s = J(['ds-variable-set', '--type', 'strings', '--label', 'ZZ Test Text (delete me)', '--value', 'Hello & <b>bye</b>']); if (s.ok) writes++; now = ds().variables;
    check('a second type is added, the first is kept, the text is stored character for character', s.ok && now.numbers && now.numbers[c.id] && now.strings && now.strings[s.id] && now.strings[s.id].value === 'Hello & <b>bye</b>', s.__error || JSON.stringify((now.strings || {})[s.id]));
    const badT = run(['ds-variable-set', '--type', 'colors', '--label', 'ZZ', '--value', '#fff']); check('type "colors" is refused (colours have their own store)', !badT.ok && /bad_type/.test(badT.out));
    const r = undo('variables', writes); now = ds().variables;
    check('restore of the backup taken before the test', r.ok, r.__error);
    check('END STATE: the variable store is exactly what it was', same(now, before) || (empty(now) && empty(before)), JSON.stringify(now).slice(0, 200));
  },
  presets() {
    const before = ds().presets; const NAME = 'ZZ Test Preset (delete me)'; let writes = 0; const f = path.join(os.tmpdir(), 'd5b-live-test-preset.json');
    const spec = v => fs.writeFileSync(f, JSON.stringify({ moduleName: 'divi/text', name: NAME, attrs: { module: { decoration: { spacing: { desktop: { value: { padding: { top: v } } } } } }, content: { innerContent: { desktop: { value: '<p>must never be stored</p>' } } } } }));
    const st0 = run(['ds-selftest']); check('selftest before the test', st0.ok && /different: 0/.test(st0.out), st0.out.slice(0, 120));
    spec('1px'); const dry = J(['ds-preset-set', '--file', f, '--dry-run']);
    check('dry run answers and writes nothing', dry.ok && dry.dryRun === true && same(ds().presets, before), dry.__error);
    const c = J(['ds-preset-set', '--file', f]); if (!check('create: accepted', c.ok && c.action === 'created', c.__error || JSON.stringify(c).slice(0, 200))) return; writes++;
    let now = ds().presets; const mine = p => p.module.find(x => x.id === c.id);
    check('create: the preset is in Divi\'s store, with its style part', mine(now) && mine(now).name === NAME && JSON.stringify(mine(now).styleAttrs || {}).includes('1px'), JSON.stringify(mine(now)).slice(0, 200));
    check('create: content was kept out of it', !JSON.stringify(mine(now)).includes('must never be stored') && (c.strippedContent || []).length > 0);
    check('create: every existing preset is untouched', before.module.every(p => same(p, now.module.find(x => x.id === p.id))) && same(now.group, before.group));
    check('create: it did not become the default', !mine(now).isDefault);
    spec('2px'); const u = J(['ds-preset-set', '--file', f]); if (u.ok) writes++; now = ds().presets;
    check('same name again updates the same preset', u.ok && u.action === 'updated' && u.id === c.id && JSON.stringify(mine(now).attrs).includes('2px') && !JSON.stringify(mine(now)).includes('1px'), u.__error);
    const st1 = run(['ds-selftest']); check('selftest with the test preset in the store', st1.ok && /different: 0/.test(st1.out), st1.out.slice(0, 120));
    const r = undo('presets', writes); now = ds().presets;
    check('restore of the backup taken before the test: the test preset is gone', r.ok && !mine(now), r.__error);
    check('END STATE: the preset store is exactly what it was', same(now, before));
    try { fs.unlinkSync(f); } catch (e) { /* */ }
  },
};
const v = J(['plugin-version']); const ver = String(v.version || '0').split('.').map(Number);
if (v.__error || ver[0] < 1 || (ver[0] === 1 && (ver[1] < 8 || (ver[1] === 8 && (ver[2] || 0) < 1)))) { console.error('STOP: this test needs mu-plugin >= 1.8.1 on the site (found ' + (v.version || 'none') + ')'); process.exit(1); }
for (const t of what === 'all' ? ['colors', 'variables', 'presets'] : [what]) { console.log('--- ' + t + ' ---'); try { tests[t](); } catch (e) { check(t + ' ran to the end', false, e.message); } }
console.log(failed ? '\n' + failed + ' FAILED. Check the stores with `wp.js <site> design-system`; `wp.js <site> ds-backups` lists what can be restored.' : '\nall passed, nothing left behind');
process.exit(failed ? 1 : 0);
