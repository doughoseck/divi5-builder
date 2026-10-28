#!/usr/bin/env node
/*
 * ds-live-test.js: prove the design-system WRITES on a real site (divi5-builder plugin >= 2.0.1, as a plugin or
 * must-use), and leave no trace.
 * (One trace on Divi 5.15+: Divi never drops a variable, it archives it, so the test variables stay in the store
 * with status "archived", which is exactly what deleting them in the Variable Manager leaves behind.)
 *
 *   node ds-live-test.js <site> colors | variables | nested | presets | all
 *
 * "nested" (issue #45) needs Divi 5.18 or newer for nested variables to render; the plugin's checks run on any Divi 5.
 *
 * Each part creates a test item, updates it, checks Divi's own store after every step, then puts the store back from
 * the backup the plugin took before the test's FIRST write and proves the store is exactly what it was.
 * Run it on a staging site, and not while someone is editing presets, colours or variables in the builder: a restore
 * puts the whole store back, so an edit made during the test would be lost.
 * Saving to these stores clears Divi's generated CSS (as it does in the builder); pages rebuild it on their next visit.
 */
const path = require('path'); const fs = require('fs'); const os = require('os'); const { execFileSync } = require('child_process');
const [site, what] = process.argv.slice(2);
if (!site || !['colors', 'variables', 'nested', 'presets', 'all'].includes(what)) { console.error('usage: node ds-live-test.js <site> colors|variables|nested|presets|all'); process.exit(1); }
const WP = path.join(__dirname, 'wp.js');
const run = a => { try { return { ok: true, out: execFileSync('node', [WP, site, ...a], { encoding: 'utf8', maxBuffer: 64e6, stdio: ['ignore', 'pipe', 'pipe'] }) }; } catch (e) { return { ok: false, out: String(e.stderr || e.message) }; } };
const J = a => { const r = run(a); if (!r.ok) return { __error: r.out.replace(/\s+/g, ' ').slice(0, 220) }; try { return JSON.parse(r.out); } catch (e) { return { __error: 'not json: ' + r.out.slice(0, 120) }; } };
const ds = () => J(['design-system', '--json', '--full']);
// An empty PHP array comes back as JSON [] whether it was a list or a map, so an empty store reads as [] and the same
// store with the test item taken out as {}: both count as empty.
const sort = v => Array.isArray(v) ? (v.length ? v.map(sort) : {}) : (v && typeof v === 'object' ? Object.keys(v).sort().reduce((o, k) => (o[k] = sort(v[k]), o), {}) : v);
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
    const find = (v, id) => { for (const items of Object.values(v || {})) if (items && items[id]) return items[id]; return null; };
    const live = Object.values(before || {}).flatMap(items => Object.entries(items || {})).filter(([, x]) => /^ZZ Test .*\(delete me\)$/.test(x.label || '') && x.status !== 'archived');
    if (!check('no ACTIVE test variable left over from an earlier run', !live.length, 'archive them first: ' + live.map(([id]) => `wp.js ${site} ds-variable-archive --id ${id}`).join(' ; '))) return;
    const dry = J(['ds-variable-set', '--type', 'numbers', '--label', LABEL, '--value', '20px', '--dry-run']);
    check('dry run answers and writes nothing', dry.ok && dry.dryRun === true && (same(ds().variables, before) || (empty(ds().variables) && empty(before))), dry.__error);
    const c = J(['ds-variable-set', '--type', 'numbers', '--label', LABEL, '--value', '20px']);
    if (!check('create: accepted by Divi (the user needs the "Variables Manager" permission)', c.ok && c.action === 'created' && /^gvid-[a-z0-9]{10}$/.test(c.id || ''), c.__error || JSON.stringify(c).slice(0, 200))) return; writes++;
    let now = ds().variables; const it = ((now || {}).numbers || {})[c.id];
    check('create: the variable is in Divi\'s store', it && it.value === '20px' && it.label === LABEL && it.id === c.id, JSON.stringify(it));
    check('create: same fields as a variable made in the builder (variableType, order as text, UTC time)', it && it.variableType === 'numbers' && typeof it.order === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(it.lastUpdated || '') && Math.abs(Date.parse(it.lastUpdated) - Date.now()) < 120000, JSON.stringify(it));
    const u = J(['ds-variable-set', '--type', 'numbers', '--label', LABEL, '--value', '24px']); if (u.ok) writes++; now = ds().variables;
    check('same label again updates the same variable', u.ok && u.action === 'updated' && u.id === c.id && now.numbers[c.id].value === '24px', u.__error);
    const s = J(['ds-variable-set', '--type', 'strings', '--label', 'ZZ Test Text (delete me)', '--value', 'Hello & <b>bye</b>']); if (s.ok) writes++; now = ds().variables;
    check('a second type is added, the first is kept, the text is stored character for character', s.ok && now.numbers && now.numbers[c.id] && now.strings && now.strings[s.id] && now.strings[s.id].value === 'Hello & <b>bye</b>', s.__error || JSON.stringify((now.strings || {})[s.id]));
    const badT = run(['ds-variable-set', '--type', 'colors', '--label', 'ZZ', '--value', '#fff']); check('type "colors" is refused (colours have their own store)', !badT.ok && /bad_type/.test(badT.out));
    const ad = J(['ds-variable-archive', '--id', s.id, '--dry-run']); now = ds().variables;
    check('archive dry run answers and writes nothing', ad.ok && ad.dryRun === true && ['archived', 'removed'].includes(ad.action) && find(now, s.id) && find(now, s.id).status !== 'archived', ad.__error || JSON.stringify(ad));
    const a = J(['ds-variable-archive', '--id', s.id]); if (a.ok) writes++; now = ds().variables;
    check('archive: Divi 5.15+ keeps it with status "archived", older Divi removes it; the other variable is untouched', a.ok && (a.action === 'archived' ? find(now, s.id) && find(now, s.id).status === 'archived' : a.action === 'removed' && !find(now, s.id)) && now.numbers[c.id].value === '24px', a.__error || JSON.stringify(find(now, s.id)));
    const again = J(['ds-variable-set', '--type', 'strings', '--label', 'ZZ Test Text (delete me)', '--value', 'x', '--dry-run']);
    check('the label of a deleted variable is free again (a new one would be created)', again.ok && again.action === 'created' && again.id !== s.id, again.__error || JSON.stringify(again).slice(0, 200));
    const r = undo('variables', writes); now = ds().variables;
    check('restore of the backup taken before the test', r.ok, r.__error);
    // Divi 5.15+ refuses a store that drops a variable, so the restore archives what the test made (as the builder
    // deletes); on older Divi it is dropped. Either way, nothing of the test may stay ACTIVE and nothing else may change.
    const testIds = [c.id, s.id];
    const strip = v => { const o = {}; for (const [t, items] of Object.entries(v || {})) { const k = {}; for (const [id, x] of Object.entries(items || {})) if (!testIds.includes(id)) k[id] = x; if (Object.keys(k).length) o[t] = k; } return o; };
    const left = testIds.filter(id => find(now, id));
    if (left.length) check('Divi 5.15+: the test variables are kept, ARCHIVED (deleted the way the builder does it)', left.every(id => find(now, id).status === 'archived') && (r.archived || []).includes(c.id), JSON.stringify(left.map(id => [id, find(now, id).status])) + ' archived by restore: ' + JSON.stringify(r.archived));
    check('END STATE: every variable that was there before is exactly as it was, no test variable is active', same(strip(now), strip(before)), JSON.stringify(strip(now)).slice(0, 200));
  },
  // Nested variables (Divi 5.18, plugin >= 2.0.1, issue #45): a variable made of others is accepted and stored as the
  // builder stores it; a missing, self, cyclic or other-type reference is refused; a variable in use cannot be archived.
  nested() {
    const before = ds().variables; let writes = 0;
    const find = (v, id) => { for (const items of Object.values(v || {})) if (items && items[id]) return items[id]; return null; };
    const live = Object.values(before || {}).flatMap(items => Object.entries(items || {})).filter(([, x]) => /^ZZ Test .*\(delete me\)$/.test(x.label || '') && x.status !== 'archived');
    if (!check('no ACTIVE test variable left over from an earlier run', !live.length, 'archive them first: ' + live.map(([id]) => `wp.js ${site} ds-variable-archive --id ${id}`).join(' ; '))) return;
    const tok = id => '$variable({"type":"content","value":{"name":"' + id + '","settings":{}}})$';
    const make = (type, label, value) => { const r = J(['ds-variable-set', '--type', type, '--label', label + ' (delete me)', '--value', value]); if (r.ok) writes++; return r; };
    const a = make('numbers', 'ZZ Test Nest Min', '10px'), b = make('numbers', 'ZZ Test Nest Max', '20px'), y = make('strings', 'ZZ Test Nest Year', '2026');
    if (!check('three plain test variables created', a.ok && b.ok && y.ok, a.__error || b.__error || y.__error)) return undo('variables', writes);
    const fl = make('numbers', 'ZZ Test Nest Fluid', `clamp( {{gvid-${a.id.slice(5)}}}, 4vw, {{gvid-${b.id.slice(5)}}})`);
    let now = ds().variables;
    check('a nested number variable is accepted and stored exactly as the builder stores one', fl.ok && find(now, fl.id) && find(now, fl.id).value === `clamp( ${tok(a.id)}, 4vw, ${tok(b.id)})`, fl.__error || JSON.stringify(find(now, fl.id)));
    const cp = make('strings', 'ZZ Test Nest Copy', `© {{gvid-${y.id.slice(5)}}} Example`);
    now = ds().variables;
    check('a nested string variable is accepted, character for character', cp.ok && find(now, cp.id) && find(now, cp.id).value === `© ${tok(y.id)} Example`, cp.__error || JSON.stringify(find(now, cp.id)));
    const snap = ds().variables;
    const refused = (name, args, problem) => { const r = run(['ds-variable-set', ...args]); check(name + ': refused (400 bad_references, ' + problem + '), nothing written', !r.ok && /bad_references/.test(r.out) && new RegExp(': ' + problem + ':').test(r.out) && same(ds().variables, snap), r.out.slice(0, 200)); };
    refused('a reference to a variable that does not exist', ['--type', 'numbers', '--label', 'ZZ Test Nest Bad (delete me)', '--value', '{{gvid-zzmissing01}} * 2'], 'missing');
    refused('a reference to itself', ['--type', 'numbers', '--id', fl.id, '--label', 'ZZ Test Nest Fluid (delete me)', '--value', `{{${fl.id}}} * 2`], 'self');
    refused('a cycle (Min uses Fluid, which uses Min)', ['--type', 'numbers', '--id', a.id, '--label', 'ZZ Test Nest Min (delete me)', '--value', `{{${fl.id}}} / 2`], 'cycle');
    refused('a number variable using a string variable', ['--type', 'numbers', '--label', 'ZZ Test Nest Bad (delete me)', '--value', `{{${y.id}}}`], 'type');
    const ar = run(['ds-variable-archive', '--id', a.id]);
    check('archive of a variable another active variable uses: refused (409), the user listed by id and label, nothing written', !ar.ok && /409 variable_in_use/.test(ar.out) && ar.out.includes(fl.id) && ar.out.includes('ZZ Test Nest Fluid (delete me)') && same(ds().variables, snap), ar.out.slice(0, 300));
    const a1 = J(['ds-variable-archive', '--id', fl.id]); if (a1.ok) writes++;
    const a2 = J(['ds-variable-archive', '--id', a.id]); if (a2.ok) writes++;
    check('once its user is archived first, the variable can be archived', a1.ok && a2.ok && ['archived', 'removed'].includes(a2.action), a1.__error || a2.__error);
    const r = undo('variables', writes); now = ds().variables;
    check('restore of the backup taken before the test', r.ok, r.__error);
    const testIds = [a.id, b.id, y.id, fl.id, cp.id].filter(Boolean);
    const strip = v => { const o = {}; for (const [t, items] of Object.entries(v || {})) { const k = {}; for (const [id, x] of Object.entries(items || {})) if (!testIds.includes(id)) k[id] = x; if (Object.keys(k).length) o[t] = k; } return o; };
    check('END STATE: every variable that was there before is exactly as it was, no test variable is active', same(strip(now), strip(before)) && testIds.every(id => !find(now, id) || find(now, id).status === 'archived'), JSON.stringify(strip(now)).slice(0, 200));
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
// 2.0.1: the nested-variable checks and the archive guard (issue #45) are what the "nested" part proves.
const atLeast = (want) => { for (let i = 0; i < want.length; i++) { const a = ver[i] || 0; if (a !== want[i]) return a > want[i]; } return true; };
if (v.__error || !atLeast([2, 0, 1])) { console.error('STOP: this test needs the divi5-builder plugin >= 2.0.1 on the site (found ' + (v.version || 'none') + ')'); process.exit(1); }
for (const t of what === 'all' ? ['colors', 'variables', 'nested', 'presets'] : [what]) { console.log('--- ' + t + ' ---'); try { tests[t](); } catch (e) { check(t + ' ran to the end', false, e.message); } }
console.log(failed ? '\n' + failed + ' FAILED. Check the stores with `wp.js <site> design-system`; `wp.js <site> ds-backups` lists what can be restored.' : '\nall passed, nothing left behind');
process.exit(failed ? 1 : 0);
