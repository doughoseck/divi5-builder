// site-updates-test.js: the pure parts of site-updates.js (the plan, the page checks).
//   node scripts/site-updates-test.js
// Set SITE_UPDATES_FILE to test another copy (used for mutation testing).
const path = require('path');
const { planUpdates, compareHealth, pageHealth } = require(process.env.SITE_UPDATES_FILE || path.join(__dirname, 'site-updates.js'));
let n = 0, failed = 0;
const check = (name, ok, detail) => { n++; console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (!ok && detail !== undefined ? '\n          ' + JSON.stringify(detail) : '')); if (!ok) failed++; };
const P = (item, name, version, new_version, extra = {}) => ({ item, name, version, new_version, kind: 'patch', active: true, blocked: '', ...extra });
const status = () => ({
  wordpress: { version: '6.8.1', new_version: '6.9', kind: 'major', offered: ['6.8.3', '6.9'] }, translations: 2,
  plugins: [P('a/a.php', 'Alpha', '1.0', '1.0.1'), P('b/b.php', 'Beta', '1.9', '2.0', { kind: 'major', active: false }), P('c/c.php', 'Gamma', '3.0', null), P('d/d.php', 'Delta Pro', '2.0', '2.1', { blocked: 'no download offered' })],
  themes: [P('Divi', 'Divi', '5.13.1', '5.14.0', { kind: 'minor' }), P('child', 'Child', '1.0', null)],
});
const names = t => t.map(u => u.type + ':' + u.name + ':' + u.to);

console.log('--- the plan');
let r = planUpdates(status(), { plugins: 'all', themes: 'all', core: true, translations: true });
check('--all: plugins, then themes, then WordPress, then translations', JSON.stringify(names(r.todo)) === JSON.stringify(['plugin:Alpha:1.0.1', 'plugin:Beta:2.0', 'theme:Divi:5.14.0', 'core:WordPress:6.8.3', 'translations:translations (2):']), names(r.todo));
check('an inactive plugin is updated too', r.todo.some(u => u.item === 'b/b.php' && u.active === false));
check('a blocked plugin is left out, with its reason', r.skipped.some(([nm, why]) => nm === 'Delta Pro' && /no download/.test(why)) && !r.todo.some(u => u.item === 'd/d.php'));
check('an up-to-date plugin is neither planned nor reported under --all', !r.todo.some(u => u.item === 'c/c.php') && !r.skipped.some(([nm]) => nm === 'Gamma'));
check('WordPress without --major: the maintenance release of its own branch', r.todo.find(u => u.type === 'core').item === '6.8.3' && r.todo.find(u => u.type === 'core').kind === 'minor');
check('... and the new release is listed as left out', r.skipped.some(([nm, why]) => nm === 'WordPress 6.9' && /--major/.test(why)));
r = planUpdates(status(), { core: true, major: true });
check('WordPress with --major: the newest release', r.todo.length === 1 && r.todo[0].item === '6.9' && r.todo[0].kind === 'major' && !r.skipped.length, r);
const s2 = status(); s2.wordpress = { version: '6.8.3', new_version: '6.9', kind: 'major', offered: ['6.9'] };
r = planUpdates(s2, { core: true });
check('WordPress with only a new release on offer and no --major: nothing planned, said why', r.todo.length === 0 && r.skipped.length === 1 && /--major/.test(r.skipped[0][1]), r);
const s2b = status(); s2b.wordpress.offered = ['6.8.2', '6.8.3', '6.9'];
check('several maintenance releases on offer: the newest of the branch', planUpdates(s2b, { core: true }).todo[0].item === '6.8.3');
const s3 = status(); s3.wordpress = { version: '6.9', new_version: null, kind: null, offered: [] };
check('WordPress up to date: nothing, silently', planUpdates(s3, { core: true }).todo.length === 0 && planUpdates(s3, { core: true }).skipped.length === 0);
r = planUpdates(status(), { plugins: ['a/a.php', 'c/c.php', 'zzz/zzz.php'] });
check('named plugins: only those', names(r.todo).join() === 'plugin:Alpha:1.0.1');
check('... a named one that is up to date, and one that does not exist, are reported', r.skipped.some(([nm, why]) => nm === 'Gamma' && /up to date/.test(why)) && r.skipped.some(([nm, why]) => nm === 'zzz/zzz.php' && /no installed plugin/.test(why)), r.skipped);
check('... themes, WordPress and translations are not touched', !r.todo.some(u => u.type !== 'plugin'));
r = planUpdates(status(), { plugins: 'all', themes: 'all', core: true, translations: true, skip: ['b/b.php', 'Divi', 'core', 'translations'] });
check('--skip takes out a plugin, a theme, WordPress and translations', names(r.todo).join() === 'plugin:Alpha:1.0.1' && r.skipped.filter(([, why]) => /on request/.test(why)).length === 3, r);
check('nothing asked for: nothing planned', planUpdates(status(), {}).todo.length === 0);
const s4 = status(); s4.translations = 0;
check('no translation updates: not in the plan', !planUpdates(s4, { translations: true }).todo.length);

console.log('--- page checks');
const ok = (u, bytes = 50000) => ({ url: u, status: 200, bytes, closed: true, fatal: '', title: 'T' });
check('a healthy page is read as healthy', (() => { const h = pageHealth('/x', 200, '<html><head><title> Home </title></head><body>hi</body></html>'); return h.status === 200 && h.closed && h.fatal === '' && h.title === 'Home'; })());
check('WordPress\'s critical error page is recognised', pageHealth('/x', 500, '<p>There has been a critical error on this website.</p>').fatal !== '');
check('a PHP fatal printed into the page is recognised', pageHealth('/x', 200, '<html><b>Fatal error</b>: Uncaught Error</html>').fatal !== '');
check('the maintenance page is recognised', pageHealth('/x', 503, 'Briefly unavailable for scheduled maintenance. Check back in a minute.').fatal !== '');
check('the words "fatal error" in normal text are not', pageHealth('/x', 200, '<html><p>How to fix a fatal error in PHP</p></html>').fatal === '');
check('same before and after: no problems', compareHealth([ok('/a'), ok('/b')], [ok('/a'), ok('/b', 49000)]).length === 0);
check('a page that now answers 500', /answered 500/.test(compareHealth([ok('/a')], [{ ...ok('/a'), status: 500 }])[0]));
check('a page that no longer answers at all', /answered nothing/.test(compareHealth([ok('/a')], [{ ...ok('/a'), status: 0, bytes: 0, closed: false }])[0]));
check('a page that shows a fatal error with status 200', /shows/.test(compareHealth([ok('/a')], [{ ...ok('/a'), fatal: 'Fatal error</b>' }])[0]));
check('a page that is cut off', /cut off/.test(compareHealth([ok('/a')], [{ ...ok('/a'), closed: false }])[0]));
check('a page that lost more than half its content', /shrank/.test(compareHealth([ok('/a', 50000)], [ok('/a', 20000)])[0]));
check('... but a small change in size is fine', compareHealth([ok('/a', 50000)], [ok('/a', 30000)]).length === 0);
check('a page that was already broken before is not blamed on the update', compareHealth([{ ...ok('/a'), status: 404 }], [{ ...ok('/a'), status: 404 }]).length === 0 && compareHealth([{ ...ok('/a'), fatal: 'x' }], [{ ...ok('/a'), status: 500 }]).length === 0);
check('each bad page is reported once', compareHealth([ok('/a'), ok('/b')], [{ ...ok('/a'), status: 500, fatal: 'x', closed: false }, ok('/b')]).length === 1);

console.log('\n' + (failed ? `${failed} of ${n} FAILED` : `all ${n} checks passed`));
process.exit(failed ? 1 : 0);
