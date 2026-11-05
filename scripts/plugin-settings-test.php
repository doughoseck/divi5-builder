<?php
/**
 * Settings page and admin notices (#37).
 *
 *   php scripts/plugin-settings-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * d5b_settings_save() ends with d5b_settings_exit() (the wp_safe_redirect + exit pair). The test defines its
 * own d5b_settings_exit() before loading settings.php, so the real one (guarded by function_exists) is not used.
 *
 * The promises under test: only a request with a valid nonce AND the right capability changes a switch, only
 * the parts whose box changed are written, a part without a switch or an unknown key never reaches the switch
 * writer, the risk notes are printed as d5b_risk_note() gives them, and nothing stored is printed unescaped.
 *
 * Constants are permanent in a PHP process: WPSU_VERSION is defined near the end, for the red-notice scenario.
 *
 * 2.5.0 (#61, Task 4): the "Updates:" line at the top of the Settings tab (d5b_updater_status_line, read from the kept
 * d5b_update_json, never a request) and the Check now handler (d5b_updater_check_now). The must-use copy needs another
 * D5B_FILE (a constant), so the suite runs itself again with --mu in a child process and counts the child's checks.
 * Loaded with T_PLUGIN_STANDINS: the site transients, wp_update_plugins (T_update_checks) and wp_clean_update_cache (T_traps).
 */
$MODE = $argv[1] ?? 'main';
define( 'D5B_VERSION', '2.0.0' );
define( 'T_PLUGIN_STANDINS', true );
require __DIR__ . '/plugin-test-stubs.php';
$GLOBALS['T_exits'] = 0;
function d5b_settings_exit() { $GLOBALS['T_exits']++; }
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/gate.php';
require $dir . '/inc/takeover.php';
require $dir . '/inc/abilities/categories.php'; // as the loader does: the page then shows the AI abilities categories it ships with
require $dir . '/inc/settings.php';
require $dir . '/inc/updater.php';
foreach ( array( 'page-building', 'media-audit', 'media-clean-up', 'updates' ) as $f ) { require $dir . '/parts/' . $f . '.php'; }

$mu = sys_get_temp_dir() . '/d5b-set-mu-' . getmypid();
if ( ! is_dir( $mu ) ) { mkdir( $mu ); }
define( 'WPMU_PLUGIN_DIR', $mu );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-set-plugins-' . getmypid() );
if ( '--mu' === $MODE ) {
	define( 'D5B_FILE', WPMU_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' ); // a must-use copy
} else {
	define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' ); // installed as a normal plugin
}

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function fresh() { t_reset(); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $_POST = array(); $_GET = array(); $GLOBALS['T_exits'] = 0; }
function post( array $on ) { $_POST = array( 'action' => 'd5b_save_switches', '_wpnonce' => 'x', 'd5b_on' => $on ); }
// A post without d5b_switch_rev is the form of a page loaded just now: it carries the current revision (the stale-page
// refusal has its own checks in plugin-abilities-switches-test.php).
function run_save() { if ( ! array_key_exists( 'd5b_switch_rev', $_POST ) ) { $_POST['d5b_switch_rev'] = d5b_abilities_switch_rev(); } try { d5b_settings_save(); return null; } catch ( Throwable $e ) { return $e; } }
function html( $fn ) { ob_start(); $fn(); return ob_get_clean(); }
function writes_of( $name ) { return count( array_filter( $GLOBALS['T_writes'], function ( $w ) use ( $name ) { return $w[1] === $name; } ) ); }
function stored() { return is_multisite() ? get_site_option( 'd5b_switches', null ) : get_option( 'd5b_switches', null ); }
// The update.json the build writes (a fake sha256: a hex digest, not a key), and a kept answer in d5b_update_json.
function upd_json( string $version = '2.5.0' ) {
	return array( 'slug' => 'divi5-builder', 'version' => $version, 'tag' => 'v2026.11.05', 'download_url' => 'https://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip',
		'sha256' => str_repeat( '0123456789abcdef', 4 ), 'requires' => '6.0', 'requires_php' => '7.4', 'tested' => '6.9' );
}
function upd_keep( array $entry ) { $GLOBALS['T_site_transients']['d5b_update_json'] = $entry; }
const CHECK_HREF = 'href="https://x.example/wp-admin/admin-post.php?action=d5b_update_check&amp;_wpnonce=nonce-for-d5b_update_check"';
function run_check() { try { d5b_updater_check_now(); return null; } catch ( Throwable $e ) { return $e; } }

// ---------------------------------------------------------------- the must-use run (child process)
if ( '--mu' === $MODE ) {
	fresh(); t_set_caps( array( 'manage_options' ) );
	upd_keep( array( 'json' => upd_json(), 'checked' => time() - 60, 'ok' => true, 'error' => '', 'good_at' => time() - 60 ) );
	$out = html( 'd5b_settings_render' );
	check( 'mu: the page says it is a must-use copy, updated by replacing the folder', false !== strpos( $out, '<p>Updates: this is a must-use copy; WordPress does not update must-use plugins. Update it by replacing the folder.</p>' ), $out );
	check( 'mu: no "from GitHub Releases", no newest version, no Check now link', false === strpos( $out, 'from GitHub Releases' ) && false === strpos( $out, 'newest' ) && false === strpos( $out, 'Check now' ) && false === strpos( $out, 'd5b_update_check' ), $out );
	check( 'mu: nothing fetched', array() === $GLOBALS['T_http_calls'] );
	echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
	exit( $failed ? 1 : 0 );
}

// ---- save
echo "save refuses without a valid nonce\n";
fresh(); t_set_caps( array( 'manage_options' ) ); $GLOBALS['T_referer_ok'] = false;
post( array( 'updates' => '1' ) );
$e = run_save();
check( 'the nonce was checked, for action d5b_switches', in_array( 'd5b_switches', $GLOBALS['T_referer_calls'], true ), $GLOBALS['T_referer_calls'] );
check( 'bad nonce: the option is unchanged and nothing was written', null === stored() && array() === $GLOBALS['T_writes'] && null === $e, $GLOBALS['T_writes'] );
check( 'bad nonce: no redirect saying updated', array() === $GLOBALS['T_redirects'], $GLOBALS['T_redirects'] );

echo "save refuses an editor\n";
fresh(); t_set_caps( array( 'edit_posts', 'activate_plugins' ) );
post( array( 'updates' => '1', 'media_audit' => '1' ) );
$e = run_save();
check( 'editor: the option is unchanged and nothing was written', null === stored() && array() === $GLOBALS['T_writes'] && null === $e, $GLOBALS['T_writes'] );
check( 'editor: refused through wp_die, no updated redirect', 1 === count( $GLOBALS['T_dies'] ) && array() === $GLOBALS['T_redirects'], array( $GLOBALS['T_dies'], $GLOBALS['T_redirects'] ) );

echo "save as admin flips only what changed, and logs who\n";
fresh(); t_set_caps( array( 'manage_options' ) ); $GLOBALS['T_user_login'] = 'old-admin';
d5b_switch_set( 'media_audit', true, 'old-admin' );
$before = d5b_switches();
$GLOBALS['T_writes'] = array(); $GLOBALS['T_user_login'] = 'doug';
post( array( 'media_audit' => '1', 'updates' => '1' ) ); // audit stays on, updates turns on, clean-up stays off
$e = run_save();
$s = d5b_switches();
check( 'no exception', null === $e, null === $e ? '' : $e->getMessage() );
check( 'updates is now on, by doug, with an ISO time', true === $s['updates']['on'] && 'doug' === $s['updates']['changed_by'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $s['updates']['changed_at'] ), $s['updates'] );
check( 'media audit was not touched: same time, same person', $before['media_audit'] === $s['media_audit'], array( $before['media_audit'], $s['media_audit'] ) );
check( 'media clean-up was not touched (still off, no log)', false === $s['media_clean_up']['on'] && '' === $s['media_clean_up']['changed_by'], $s['media_clean_up'] );
check( 'exactly one write: only the one changed part', 1 === count( $GLOBALS['T_writes'] ), $GLOBALS['T_writes'] );
check( 'redirected back to the page with updated=1, and exited', array( 'https://x.example/wp-admin/options-general.php?page=divi5-builder&updated=1' ) === $GLOBALS['T_redirects'] && 1 === $GLOBALS['T_exits'], array( $GLOBALS['T_redirects'], $GLOBALS['T_exits'] ) );
$GLOBALS['T_writes'] = array();
post( array( 'updates' => '1' ) ); // media audit box unticked
run_save();
$s = d5b_switches();
check( 'an unticked box switches the part off and logs it', false === $s['media_audit']['on'] && 'doug' === $s['media_audit']['changed_by'] && true === $s['updates']['on'] && 1 === count( $GLOBALS['T_writes'] ), array( $s, $GLOBALS['T_writes'] ) );
$GLOBALS['T_writes'] = array();
post( array( 'updates' => '1' ) ); // nothing changed
run_save();
check( 'a save that changes nothing writes nothing', array() === $GLOBALS['T_writes'], $GLOBALS['T_writes'] );
fresh(); t_set_caps( array( 'manage_options' ) );
$_POST = array( 'action' => 'd5b_save_switches', '_wpnonce' => 'x' ); // no d5b_on at all: every box unticked
d5b_switch_set( 'updates', true, 'a' ); $GLOBALS['T_writes'] = array();
run_save();
check( 'a form with no boxes ticked switches everything off', false === d5b_switches()['updates']['on'] );

echo "a part without a switch, or an unknown key, never reaches the switch writer\n";
fresh(); t_set_caps( array( 'manage_options' ) );
post( array( 'page_building' => '1', 'nonsense' => '1', '../x' => '1', 'updates' => '1' ) );
$e = run_save();
check( 'no exception from page_building or an unknown key', null === $e, null === $e ? '' : $e->getMessage() );
check( 'only updates was written; no key but the four exists', 1 === count( $GLOBALS['T_writes'] ) && array( 'media_audit', 'media_clean_up', 'updates' ) === array_keys( d5b_switches() ) && true === d5b_switches()['updates']['on'], array( $GLOBALS['T_writes'], d5b_switches() ) );
fresh(); t_set_caps( array( 'manage_options' ) );
post( array( 'page_building' => '1', 'nonsense' => '1' ) );
$e = run_save();
check( 'with only bad keys, nothing is written and nothing throws', null === $e && array() === $GLOBALS['T_writes'], array( $e ? $e->getMessage() : '', $GLOBALS['T_writes'] ) );
fresh(); t_set_caps( array( 'manage_options' ) );
$_POST = array( 'action' => 'd5b_save_switches', '_wpnonce' => 'x', 'd5b_on' => 'not-an-array' );
$e = run_save();
check( 'a d5b_on that is not an array is read as nothing ticked, no error', null === $e );

// ---- the menu
echo "menu\n";
fresh();
d5b_settings_menu();
check( 'single site: add_options_page, manage_options, slug divi5-builder, d5b_settings_render', array( array( 'add_options_page', 'Divi 5 Builder', 'Divi 5 Builder', 'manage_options', 'divi5-builder', 'd5b_settings_render' ) ) === $GLOBALS['T_menus'], $GLOBALS['T_menus'] );
fresh(); t_set_multisite( true );
d5b_settings_menu();
check( 'multisite, on a subsite: no page at all', array() === $GLOBALS['T_menus'], $GLOBALS['T_menus'] );
$GLOBALS['T_network_admin'] = true;
d5b_settings_menu();
check( 'multisite, Network Admin: submenu of settings.php with manage_network_options', array( array( 'add_submenu_page', 'settings.php', 'Divi 5 Builder', 'Divi 5 Builder', 'manage_network_options', 'divi5-builder', 'd5b_settings_render' ) ) === $GLOBALS['T_menus'], $GLOBALS['T_menus'] );

echo "multisite save is network-level\n";
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_options' ) ); // a site administrator, not a network one
post( array( 'updates' => '1' ) );
run_save();
check( 'site admin on multisite: refused, nothing written', array() === $GLOBALS['T_writes'] && 1 === count( $GLOBALS['T_dies'] ), array( $GLOBALS['T_writes'], $GLOBALS['T_dies'] ) );
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_network_options' ) );
post( array( 'updates' => '1' ) );
run_save();
check( 'network admin: written as a site option, never a per-site option', array( array( 'update_site_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'] || array( array( 'add_site_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'] || ( 1 === count( $GLOBALS['T_writes'] ) && 'd5b_switches' === $GLOBALS['T_writes'][0][1] && false !== strpos( $GLOBALS['T_writes'][0][0], 'site_option' ) ), $GLOBALS['T_writes'] );
check( 'network admin: redirected to the Network Admin page', array( 'https://x.example/wp-admin/network/settings.php?page=divi5-builder&updated=1' ) === $GLOBALS['T_redirects'], $GLOBALS['T_redirects'] );
echo "render needs the capability too\n";
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_options' ) );
check( 'site admin on multisite sees no form', '' === trim( html( 'd5b_settings_render' ) ) || false === strpos( html( 'd5b_settings_render' ), '<form' ) );

// ---- render
echo "render\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$out = html( 'd5b_settings_render' );
check( 'shows the version', false !== strpos( $out, '2.0.0' ), $out );
check( 'Divi 5 not detected: says routes that need Divi will answer with an error', false !== strpos( $out, 'Divi 5 not detected: routes that need Divi will answer with an error.' ) && false === strpos( $out, 'Divi 5 detected' ) && false === strpos( $out, 'idle' ), $out );
foreach ( array( 'media_audit', 'media_clean_up', 'updates' ) as $p ) {
	check( "single site: $p note is printed as d5b_risk_note gives it", false !== strpos( $out, esc_html( d5b_risk_note( $p ) ) ) && '' !== d5b_risk_note( $p ), $p );
	check( "$p has one checkbox", 1 === substr_count( $out, 'name="d5b_on[' . $p . ']"' ), $p );
}
check( 'page_building has no checkbox and the form names exactly three', false === strpos( $out, 'd5b_on[page_building]' ) && 3 === substr_count( $out, 'name="d5b_on[' ), $out );
check( 'all three start unticked', 0 === preg_match( '/name="d5b_on\[[a-z_]+\]"[^>]*checked/', $out ) && 3 === preg_match_all( '/name="d5b_on\[[a-z_]+\]"/', $out ), $out );
check( 'the AI abilities master starts unticked', 1 === preg_match( '/name="d5b_abilities_master"(?![^>]*checked)/', $out ), $out );
// The spec (Defaults): the categories are already ticked for when the master is switched on; the master keeps them off until then.
check( 'the shipped page shows every category, already ticked (the spec default), and nothing else is ticked', 23 === preg_match_all( '/name="d5b_abilities_cat\[[a-z]+\]"[^>]*checked/', $out ) && 23 === substr_count( $out, 'checked="checked"' ), substr_count( $out, 'checked="checked"' ) );
check( 'no multisite sentence on a single site', false === strpos( $out, 'every site in it at once' ) && false === strpos( $out, 'sites that share these files' ) );
check( 'the form posts to admin-post.php with the nonce and the action', false !== strpos( $out, 'action="https://x.example/wp-admin/admin-post.php"' ) && false !== strpos( $out, 'value="nonce-for-d5b_switches"' ) && false !== strpos( $out, 'name="action" value="d5b_save_switches"' ), $out );
check( 'no "Last switched" line when nothing was ever switched', false === strpos( $out, 'Last switched' ) );
d5b_switch_set( 'updates', true, 'doug' );
$out = html( 'd5b_settings_render' );
$at  = d5b_switches()['updates']['changed_at'];
check( 'updates ticked, and Last switched on <time> by doug', 1 === preg_match_all( '/name="d5b_on\[[a-z_]+\]"[^>]*checked/', $out ) && 1 === preg_match( '/name="d5b_on\[updates\]"[^>]*checked/', $out ) &&false !== strpos( $out, 'Last switched on ' . $at . ' by doug' ) && 1 === substr_count( $out, 'Last switched' ), $out );
d5b_switch_set( 'updates', false, 'ann' );
$out = html( 'd5b_settings_render' );
check( 'one entry per switch: its latest change only', 1 === substr_count( $out, 'Last switched' ) && false !== strpos( $out, 'Last switched off ' ) && false !== strpos( $out, ' by ann' ) && false === strpos( $out, ' by doug' ), $out );
update_option( 'd5b_switches', array( 'updates' => array( 'on' => true, 'changed_at' => '2026-01-01T00:00:00Z', 'changed_by' => '<b>x</b>"\'<script>alert(1)</script>' ) ) );
$out = html( 'd5b_settings_render' );
check( 'a stored login is printed escaped', false !== strpos( $out, '&lt;b&gt;x&lt;/b&gt;' ) && false === strpos( $out, '<b>x</b>' ) && false === strpos( $out, '<script>' ), $out );
$_GET = array( 'updated' => '1' );
check( 'after a save the page says so', false !== strpos( html( 'd5b_settings_render' ), 'Settings saved' ) );
$_GET = array();
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_network_options' ) );
$out = html( 'd5b_settings_render' );
check( 'multisite: the network sentences are in the notes', false !== strpos( $out, esc_html( d5b_risk_note( 'updates' ) ) ) && false !== strpos( $out, 'every site in it at once' ) && false !== strpos( $out, 'sites that share these files' ), $out );
check( 'multisite: the form posts to the network edit action', false !== strpos( $out, 'action="https://x.example/wp-admin/network/edit.php?action=d5b_save_switches"' ), $out );
define( 'ET_BUILDER_5_DIR', '/x' );
fresh(); t_set_caps( array( 'manage_options' ) );
$out = html( 'd5b_settings_render' );
check( 'Divi 5 detected shows when it is', false !== strpos( $out, 'Divi 5 detected' ) && false === strpos( $out, 'not detected' ), $out );

// ---- the stand-aside record is refreshed in wp-admin only on sites that still carry an old copy
echo "no old copy: the notice builds no REST server; the settings page always refreshes\n";
fresh(); t_set_caps( array( 'activate_plugins', 'manage_options' ) );
html( 'd5b_admin_notice' );
check( 'no legacy constant, empty record: the notice makes no rest_get_server() call, no gate run', 0 === $GLOBALS['T_server_calls'] && ! isset( $GLOBALS['d5b_gate_ran'] ) && array() === $GLOBALS['T_routes'], array( $GLOBALS['T_server_calls'], array_keys( $GLOBALS['T_routes'] ) ) );
html( 'd5b_settings_render' );
check( 'the settings page refreshes once even so (one server build)', 1 === $GLOBALS['T_server_calls'], $GLOBALS['T_server_calls'] );

// An old copy with no constant of its own (a code snippet) is live: only its routes show it. No REST request yet.
echo "snippet copy live, no REST request yet: the settings page shows the red line\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$GLOBALS['T_server_routes'] = array( '/wp-media-quarantine/v1/batches' => array() );
check( 'premise: no media clean-up constant, nothing recorded', ! defined( 'WPMQ_VERSION' ) && null === get_option( 'd5b_legacy_seen', null ) );
$out = html( 'd5b_settings_render' );
check( 'the settings page shows Media clean-up is LIVE, in red', false !== strpos( $out, 'Media clean-up is LIVE on this site through the old copy, even though its switch here is off.' ) && false !== strpos( $out, 'color:#b32d2e' ), $out );
check( '... and the record now names it', array( 'media_clean_up' ) === get_option( 'd5b_legacy_seen' ), get_option( 'd5b_legacy_seen', null ) );

// The owner deletes the old copy. The record still names it; the next page is an ordinary wp-admin page.
echo "old copy deleted: the red line clears on the next admin page, with no REST request\n";
fresh(); t_set_caps( array( 'activate_plugins' ) );
update_option( 'd5b_legacy_seen', array( 'media_clean_up' ) ); // left from when the copy was serving
check( 'premise: without a refresh the stale record would still give the red line', 1 === count( array_filter( d5b_takeover_lines(), function ( $l ) { return $l['red']; } ) ) );
$out = html( 'd5b_admin_notice' );
check( 'the notice has no red line and no line at all', '' === $out, $out );
check( 'the record is cleared, through one server build', array() === get_option( 'd5b_legacy_seen' ) && 1 === $GLOBALS['T_server_calls'], array( get_option( 'd5b_legacy_seen', null ), $GLOBALS['T_server_calls'] ) );

// ---- multisite, plugin activated on one subsite only: the switches live in Network Admin, which never loads it
echo "multisite, activated on a subsite only: super admins are told where the switches are\n";
$unreach = "Divi 5 Builder's switches are in Network Admin > Settings. Network-activate the plugin (or activate it on the main site) to reach them.";
function subsite( array $caps ) { fresh(); t_set_multisite( true ); t_set_caps( $caps ); $GLOBALS['T_blog_id'] = 5; $GLOBALS['T_options']['active_plugins'] = array( 'divi5-builder/divi5-builder.php' ); }
subsite( array( 'manage_network_options', 'manage_network_plugins' ) );
$out = html( 'd5b_admin_notice' );
check( 'a super admin on that subsite sees the line, once, as a warning', 1 === substr_count( $out, esc_html( $unreach ) ) && false !== strpos( $out, 'notice-warning' ), $out );
subsite( array( 'activate_plugins', 'manage_options' ) );
check( 'a subsite administrator (not a super admin) does not', false === strpos( html( 'd5b_admin_notice' ), esc_html( $unreach ) ) );
subsite( array( 'manage_network_options', 'manage_network_plugins' ) );
$GLOBALS['T_site_options']['active_sitewide_plugins'] = array( 'divi5-builder/divi5-builder.php' => 1 );
check( 'network-activated: no line', false === strpos( html( 'd5b_admin_notice' ), esc_html( $unreach ) ) );
subsite( array( 'manage_network_options', 'manage_network_plugins' ) );
$GLOBALS['T_blogs'][1] = array( 'active_plugins' => array( 'divi5-builder/divi5-builder.php' ) );
check( 'also active on the main site: no line', false === strpos( html( 'd5b_admin_notice' ), esc_html( $unreach ) ) );
subsite( array( 'manage_network_options', 'manage_network_plugins' ) );
$GLOBALS['T_blog_id'] = 1;
check( 'on the main site itself: no line', false === strpos( html( 'd5b_admin_notice' ), esc_html( $unreach ) ) );
subsite( array( 'manage_network_options', 'manage_network_plugins' ) );
$GLOBALS['T_network_admin'] = true;
check( 'in Network Admin: no line', false === strpos( html( 'd5b_admin_notice' ), esc_html( $unreach ) ) );
subsite( array( 'manage_network_options', 'manage_network_plugins' ) );
check( 'as a must-use plugin (loaded everywhere): no line', ! d5b_switches_unreachable( WPMU_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' ) && d5b_switches_unreachable() );
fresh(); t_set_caps( array( 'manage_network_options', 'manage_options', 'activate_plugins' ) );
check( 'single site: no line', false === strpos( html( 'd5b_admin_notice' ), esc_html( $unreach ) ) && ! d5b_switches_unreachable() );

// ---- the network settings page lists the subsites where an old copy is serving
echo "network settings page: subsites with an old copy serving\n";
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_network_options' ) ); $GLOBALS['T_network_admin'] = true;
$GLOBALS['T_blogs'] = array(
	1 => array( 'home' => 'https://net.example' ),
	2 => array( 'home' => 'https://two.net.example', 'd5b_legacy_seen' => array( 'updates' ) ),
	3 => array( 'home' => 'https://three.net.example', 'd5b_legacy_seen' => array() ),
	4 => array( 'home' => 'https://four.net.example<b>', 'd5b_legacy_seen' => array( 'media_audit', 'page_building' ) ),
);
d5b_switch_set( 'media_audit', true, 'net' ); // on: no red line for it
$out = html( 'd5b_settings_render' );
check( 'site 2: the old copy is named, and Updates is LIVE in red (switch off)', false !== strpos( $out, 'On https://two.net.example (site 2), an old copy is serving Updates. Remove it there to finish the move.' ) && false !== strpos( $out, 'color:#b32d2e;font-weight:600">Updates is LIVE on https://two.net.example (site 2) through the old copy, even though its switch is off.' ), $out );
check( 'site 4: both parts named, no red line (one is on, one has no switch), home escaped', false !== strpos( $out, 'On https://four.net.example&lt;b&gt; (site 4), an old copy is serving Media audit.' ) && false !== strpos( $out, '(site 4), an old copy is serving Page building.' ) && false === strpos( $out, 'Media audit is LIVE' ) && false === strpos( $out, 'Page building is LIVE' ) && false === strpos( $out, '<b>' ), $out );
check( 'site 3 (empty record) and the main site are not listed', false === strpos( $out, 'site 3)' ) && false === strpos( $out, 'site 1)' ), $out );
check( 'sites read in one get_sites call, ids only, capped at 500', array( array( 'fields' => 'ids', 'number' => 500 ) ) === $GLOBALS['T_get_sites'], $GLOBALS['T_get_sites'] );
$GLOBALS['T_blogs'] = array(); for ( $i = 1; $i <= 600; $i++ ) { $GLOBALS['T_blogs'][ $i ] = array( 'd5b_legacy_seen' => array( 'updates' ) ); }
check( '600 sites with an old copy: at most 500 are read', 499 === substr_count( html( 'd5b_settings_render' ), 'Updates is LIVE on site ' ) );
fresh(); t_set_caps( array( 'manage_options' ) );
$GLOBALS['T_blogs'] = array( 2 => array( 'd5b_legacy_seen' => array( 'updates' ) ) );
check( 'single site: no network list, get_sites never called', false === strpos( html( 'd5b_settings_render' ), 'site 2' ) && array() === $GLOBALS['T_get_sites'] );

// ---- admin notice
echo "admin notice\n";
fresh();
define( 'WPSU_VERSION', '1.0.0' );
// The old updates copy is really serving (its constant AND its routes), so the gate stands aside and the line is red.
$GLOBALS['T_server_routes'] = array( '/divi5-builder/v1/version' => array(), '/wp-site-updates/v1/info' => array() );
d5b_gate();
check( 'premise: the takeover has a red and a plain line', 1 === count( array_filter( d5b_takeover_lines(), function ( $l ) { return $l['red']; } ) ) && count( d5b_takeover_lines() ) >= 2, d5b_takeover_lines() );
$red   = array_values( array_filter( d5b_takeover_lines(), function ( $l ) { return $l['red']; } ) )[0]['text'];
$plain = array_values( array_filter( d5b_takeover_lines(), function ( $l ) { return ! $l['red']; } ) )[0]['text'];
check( 'a user without activate_plugins sees nothing', '' === html( 'd5b_admin_notice' ) );
t_set_caps( array( 'activate_plugins' ) );
$out = html( 'd5b_admin_notice' );
check( 'the red line is in a notice-error box', false !== strpos( $out, '<div class="notice notice-error"><p>' . esc_html( $red ) . '</p></div>' ), $out );
check( 'a plain line is in a notice-warning box, not an error box', false !== strpos( $out, '<div class="notice notice-warning"><p>' . esc_html( $plain ) . '</p></div>' ) && false === strpos( $out, 'notice-error"><p>' . esc_html( $plain ) ), $out );
t_set_caps( array( 'manage_options' ) );
check( 'the settings page lists the same takeover lines', false !== strpos( html( 'd5b_settings_render' ), esc_html( $plain ) ), html( 'd5b_settings_render' ) );
t_set_caps( array( 'activate_plugins' ) );
t_set_multisite( true );
check( 'on multisite activate_plugins alone is not enough', '' === html( 'd5b_admin_notice' ) );
t_set_caps( array( 'manage_network_plugins' ) );
check( 'on multisite manage_network_plugins sees it', false !== strpos( html( 'd5b_admin_notice' ), 'notice-error' ) );
t_set_multisite( false ); t_set_caps( array( 'activate_plugins' ) );
$GLOBALS['T_server_routes'] = array(); $GLOBALS['T_options'] = array(); $GLOBALS['d5b_stood_aside'] = array();
check( 'when the route copy is gone the plain line is gone from the notice', false === strpos( html( 'd5b_admin_notice' ), esc_html( $plain ) ), html( 'd5b_admin_notice' ) );

// A person re-activates the old updates plugin in wp-admin. The next page is a wp-admin page, not a REST request:
// a fresh request, a server not built yet, and a record that still says nothing is stood aside.
echo "old plugin re-activated in wp-admin: the red line shows on the very next admin page\n";
fresh(); t_set_caps( array( 'activate_plugins', 'manage_options' ) );
update_option( 'active_plugins', array( 'wp-site-updates/wp-site-updates.php' ) );
$GLOBALS['T_server_routes'] = array( '/wp-site-updates/v1/info' => array() ); // its routes, as rest_api_init would register them
check( 'premise: no REST request yet, nothing recorded', null === get_option( 'd5b_legacy_seen', null ) && ! isset( $GLOBALS['d5b_gate_ran'] ) );
$out = html( 'd5b_admin_notice' );
check( 'the notice shows the red LIVE line at once', false !== strpos( $out, '<div class="notice notice-error"><p>Updates is LIVE on this site through the old copy, even though its switch here is off.</p></div>' ), $out );
check( 'and names the re-activated plugin', false !== strpos( $out, 'The old plugin wp-site-updates/wp-site-updates.php is active again and is in charge of Updates.' ), $out );
check( 'the record is now written, through one REST server build', array( 'updates' ) === get_option( 'd5b_legacy_seen' ) && 1 === $GLOBALS['T_server_calls'], array( get_option( 'd5b_legacy_seen', null ), $GLOBALS['T_server_calls'] ) );
check( 'the settings page shows the red line too', false !== strpos( html( 'd5b_settings_render' ), 'Updates is LIVE on this site through the old copy' ) );
// The settings page on its own (no notice before it on that request) refreshes as well.
fresh(); t_set_caps( array( 'manage_options' ) );
update_option( 'active_plugins', array( 'wp-site-updates/wp-site-updates.php' ) );
$GLOBALS['T_server_routes'] = array( '/wp-site-updates/v1/info' => array() );
check( 'settings page alone: the red line shows at once', false !== strpos( html( 'd5b_settings_render' ), 'Updates is LIVE on this site through the old copy' ) && array( 'updates' ) === get_option( 'd5b_legacy_seen' ) );

echo "the 2.4.0 categories on the page\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$out = html( 'd5b_settings_render' );
$ok = true; foreach ( array( 'users', 'plugins', 'themes', 'core' ) as $s ) { if ( '' === d5b_risky_note( $s ) || false === strpos( $out, 'name="d5b_risky[' . $s . ']"' ) || false === strpos( $out, esc_html( d5b_risky_note( $s ) ) ) ) { $ok = false; } }
check( 'users, plugins, themes and core each show a risky box with its note', $ok );
check( 'the email category shows (label Email), without a risky box, unticked on a fresh site', false !== strpos( $out, 'name="d5b_abilities_cat[email]"' ) && false !== strpos( $out, '> Email</label>' ) && false === strpos( $out, 'name="d5b_risky[email]"' ) && 0 === preg_match( '/name="d5b_abilities_cat\[email\]"[^>]*checked/', $out ), $out );
check( 'the WordPress core note warns there is no rollback', false !== strpos( d5b_risky_note( 'core' ), 'There is no rollback for a core update; keep a backup.' ) );

echo "old copy inert (constant, no routes): plain line only\n";
fresh(); t_set_caps( array( 'activate_plugins' ) );
update_option( 'd5b_legacy_seen', array( 'updates' ) ); // a stale record from when it was serving
$out = html( 'd5b_admin_notice' );
check( 'the refresh clears the stale record; plain "installed but inactive", no red line', false !== strpos( $out, '<div class="notice notice-warning"><p>An old copy of Updates is installed but inactive. Delete it to finish the move.</p></div>' ) && false === strpos( $out, 'notice-error' ) && array() === get_option( 'd5b_legacy_seen' ), $out );

// ---- 2.5.0: where updates come from, and Check now
echo "the Updates line: not checked yet\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$out = html( 'd5b_settings_render' );
check( 'no kept answer: "Installed 2.0.0. Not checked yet." with a Check now link', false !== strpos( $out, '<p>Updates: from GitHub Releases. Installed 2.0.0. Not checked yet. <a ' . CHECK_HREF . '>Check now</a></p>' ), $out );
check( 'the line sits above the switches form', false !== strpos( $out, 'Updates: from GitHub Releases' ) && strpos( $out, 'Updates: from GitHub Releases' ) < strpos( $out, '<form' ), $out );
check( 'rendering the page fetches nothing (the line reads the kept answer only)', array() === $GLOBALS['T_http_calls'] && false === get_site_transient( 'd5b_update_json' ), $GLOBALS['T_http_calls'] );
check( 'the Check now link carries the nonce for d5b_update_check, once', 1 === substr_count( $out, CHECK_HREF ) && 1 === substr_count( $out, 'Check now' ), $out );
$_GET = array( 'tab' => 'audit' );
check( 'not on the Audit log tab', false === strpos( html( 'd5b_settings_render' ), 'Updates: ' ) );
$_GET = array();

echo "the Updates line: a good answer kept\n";
fresh(); t_set_caps( array( 'manage_options' ) );
upd_keep( array( 'json' => upd_json(), 'checked' => time() - 3 * 3600, 'ok' => true, 'error' => '', 'good_at' => time() - 3 * 3600 ) );
$out = html( 'd5b_settings_render' );
check( 'installed, newest and "checked 3 hours ago"', false !== strpos( $out, '<p>Updates: from GitHub Releases. Installed 2.0.0, newest 2.5.0 (checked 3 hours ago). <a ' . CHECK_HREF . '>Check now</a></p>' ), $out );
check( 'still no request', array() === $GLOBALS['T_http_calls'] );
$old = upd_json(); $old['version'] = '2.<b>5'; // a kept json that no longer validates is never printed
upd_keep( array( 'json' => $old, 'checked' => time() - 60, 'ok' => true, 'error' => '', 'good_at' => time() - 60 ) );
$out = html( 'd5b_settings_render' );
check( 'a kept json that does not validate: no version shown from it, said as no good answer', false === strpos( $out, '2.<b>5' ) && false === strpos( $out, '2.&lt;b&gt;5' ) && false !== strpos( $out, 'no good answer yet' ), $out );

echo "the Updates line: the last request failed\n";
fresh(); t_set_caps( array( 'manage_options' ) );
upd_keep( array( 'json' => upd_json(), 'checked' => time() - 120, 'ok' => false, 'error' => 'could not read the update file (HTTP 404)', 'good_at' => time() - 2 * 86400 ) );
$out = html( 'd5b_settings_render' );
check( 'the error and the age of the last good answer', false !== strpos( $out, '<p>Updates: from GitHub Releases. Installed 2.0.0. Could not read the update file (HTTP 404); last good answer 2 days ago. <a ' . CHECK_HREF . '>Check now</a></p>' ), $out );
upd_keep( array( 'json' => null, 'checked' => time() - 120, 'ok' => false, 'error' => 'the update file is not valid JSON' ) );
$out = html( 'd5b_settings_render' );
check( 'no good answer ever: says so, with the error', false !== strpos( $out, 'Installed 2.0.0. Could not read the update file (the update file is not valid JSON); no good answer yet.' ), $out );
upd_keep( array( 'json' => null, 'checked' => time() - 120, 'ok' => false, 'error' => 'could not read the update file: <script>x</script>' ) );
$out = html( 'd5b_settings_render' );
check( 'the error is printed escaped', false !== strpos( $out, 'Could not read the update file (&lt;script&gt;x&lt;/script&gt;)' ) && false === strpos( $out, '<script>' ), $out );
// A failed request with a kept json but no good_at (an entry written before good_at existed): the kept answer is still named.
upd_keep( array( 'json' => upd_json(), 'checked' => time() - 120, 'ok' => false, 'error' => 'could not read the update file (HTTP 503)' ) );
$out = html( 'd5b_settings_render' );
check( 'failed, kept json, no good_at: "the last good answer is kept."', false !== strpos( $out, '<p>Updates: from GitHub Releases. Installed 2.0.0. Could not read the update file (HTTP 503); the last good answer is kept. <a ' . CHECK_HREF . '>Check now</a></p>' ), $out );
check( 'failed, kept json, no good_at: no version and no age shown', false === strpos( $out, 'newest' ) && false === strpos( $out, ' ago' ), $out );

echo "the Updates line: a very long error is capped\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$GLOBALS['T_http']['https://raw.githubusercontent.com/doughoseck/divi5-builder/main/plugin/update.json'] = new WP_Error( 'http_request_failed', str_repeat( 'x', 1024 * 1024 ) );
d5b_updater_fetch( true );
$k = get_site_transient( 'd5b_update_json' );
check( 'a 1 MB transport error is stored as its first 200 characters', is_array( $k ) && is_string( $k['error'] ?? null ) && 200 === strlen( $k['error'] ) && 0 === strpos( $k['error'], 'could not read the update file: xxx' ), is_array( $k ) ? strlen( (string) ( $k['error'] ?? '' ) ) : $k );
$GLOBALS['T_http_calls'] = array();
// Measured without the Check now link's markup (its href is the admin URL plus a nonce, about 120 characters on its own).
function line_len( string $line ): int { return strlen( str_replace( '<a ' . CHECK_HREF . '>Check now</a>', '', $line ) ); }
$line = d5b_updater_status_line();
check( '... and the rendered line is under 400 characters', line_len( $line ) < 400 && strlen( strip_tags( $line ) ) < 400 && false !== strpos( $line, 'Could not read the update file (xxx' ), array( line_len( $line ), strlen( $line ) ) );
// An entry holding a longer string than the fetch would write (an older plugin, a foreign write) is capped at display too.
upd_keep( array( 'json' => null, 'checked' => time() - 60, 'ok' => false, 'error' => str_repeat( 'y', 1024 * 1024 ) ) );
$line = d5b_updater_status_line();
check( 'a 1 MB stored error renders under 400 characters', line_len( $line ) < 400 && strlen( strip_tags( $line ) ) < 400 && false !== strpos( $line, '(' . str_repeat( 'y', 200 ) . ')' ) && false === strpos( $line, str_repeat( 'y', 201 ) ), strlen( $line ) );
upd_keep( array( 'json' => null, 'checked' => time() - 60, 'ok' => false, 'error' => str_repeat( "\u{00e9}", 300 ) ) );
$line = d5b_updater_status_line();
check( 'the display cap counts characters, not bytes (no broken UTF-8)', 1 === preg_match( '//u', $line ) && false !== strpos( $line, '(' . str_repeat( "\u{00e9}", 200 ) . ')' ), $line );

echo "the Updates line on a network\n";
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_network_options' ) ); $GLOBALS['T_network_admin'] = true;
upd_keep( array( 'json' => upd_json(), 'checked' => time() - 3 * 3600, 'ok' => true, 'error' => '', 'good_at' => time() - 3 * 3600 ) );
check( 'the Network Admin page shows it too', false !== strpos( html( 'd5b_settings_render' ), 'Installed 2.0.0, newest 2.5.0 (checked 3 hours ago).' ) );

echo "the Updates line: a must-use copy (child run)\n";
$p = proc_open( array( PHP_BINARY, '-d', 'max_execution_time=60', __FILE__, '--mu' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
$cout = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $code = proc_close( $p );
preg_match_all( '/^  (PASS|FAIL)  (.+)$/m', $cout, $mm, PREG_SET_ORDER );
foreach ( $mm as $x ) { check( '--mu: ' . $x[2], 'PASS' === $x[1] ); }
check( '--mu: the child ran its checks and exited 0', 0 === $code && count( $mm ) >= 3, $cout );

echo "after Check now the page says so\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$_GET = array( 'd5b_checked' => '1' );
check( '?d5b_checked=1: "Update check done."', 1 === substr_count( html( 'd5b_settings_render' ), '<div class="notice notice-success"><p>Update check done.</p></div>' ) );
$_GET = array();
check( 'without it: no such notice', false === strpos( html( 'd5b_settings_render' ), 'Update check done.' ) );

echo "Check now: refused without a nonce or the capability\n";
$kept = array( 'json' => upd_json(), 'checked' => time() - 60, 'ok' => true, 'error' => '', 'good_at' => time() - 60 );
fresh(); t_set_caps( array( 'manage_options', 'update_plugins' ) ); $GLOBALS['T_referer_ok'] = false; upd_keep( $kept );
$e = run_check();
check( 'the nonce was checked, for action d5b_update_check', array( 'd5b_update_check' ) === $GLOBALS['T_referer_calls'], $GLOBALS['T_referer_calls'] );
check( 'bad nonce: the kept answer stays, no check ran, no redirect', null === $e && $kept === get_site_transient( 'd5b_update_json' ) && array() === $GLOBALS['T_update_checks'] && array() === $GLOBALS['T_traps'] && array() === $GLOBALS['T_redirects'], array( $GLOBALS['T_update_checks'], $GLOBALS['T_traps'], $GLOBALS['T_redirects'] ) );
foreach ( array( 'an editor' => array( 'edit_posts' ), 'manage_options without update_plugins' => array( 'manage_options' ), 'update_plugins without manage_options' => array( 'update_plugins' ) ) as $who => $caps ) {
	fresh(); t_set_caps( $caps ); upd_keep( $kept );
	$e = run_check();
	check( "$who: refused through wp_die, nothing changed, no redirect", null === $e && 1 === count( $GLOBALS['T_dies'] ) && $kept === get_site_transient( 'd5b_update_json' ) && array() === $GLOBALS['T_update_checks'] && array() === $GLOBALS['T_traps'] && array() === $GLOBALS['T_redirects'], array( $GLOBALS['T_dies'], $GLOBALS['T_update_checks'], $GLOBALS['T_redirects'] ) );
}
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_options', 'update_plugins' ) ); upd_keep( $kept );
run_check();
check( 'multisite: a site administrator (no manage_network_options) is refused', 1 === count( $GLOBALS['T_dies'] ) && array() === $GLOBALS['T_update_checks'] && $kept === get_site_transient( 'd5b_update_json' ) );

echo "Check now: with the nonce and the capability\n";
fresh(); t_set_caps( array( 'manage_options', 'update_plugins' ) ); upd_keep( $kept );
$GLOBALS['T_site_transients']['update_plugins'] = (object) array( 'last_checked' => time() );
$e = run_check();
check( 'no exception, no wp_die', null === $e && array() === $GLOBALS['T_dies'], array( $e ? $e->getMessage() : '', $GLOBALS['T_dies'] ) );
check( 'd5b_update_json is deleted (the next filter run asks GitHub)', false === get_site_transient( 'd5b_update_json' ) && in_array( 'd5b_update_json', $GLOBALS['T_site_transient_deletes'], true ), $GLOBALS['T_site_transient_deletes'] );
check( 'wp_clean_update_cache ran once, so core\'s update_plugins is gone and wp_update_plugins does not stop at its 12 hour timeout', array( 'wp_clean_update_cache' ) === $GLOBALS['T_traps'] && false === get_site_transient( 'update_plugins' ), $GLOBALS['T_traps'] );
check( 'wp_update_plugins ran once (and nothing else of the update checks)', array( 'wp_update_plugins' ) === $GLOBALS['T_update_checks'], $GLOBALS['T_update_checks'] );
check( 'redirected back to the Settings page with d5b_checked=1, and exited', array( 'https://x.example/wp-admin/options-general.php?page=divi5-builder&d5b_checked=1' ) === $GLOBALS['T_redirects'] && 1 === $GLOBALS['T_exits'], array( $GLOBALS['T_redirects'], $GLOBALS['T_exits'] ) );
check( 'the handler itself makes no request', array() === $GLOBALS['T_http_calls'] );
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_network_options', 'update_plugins' ) ); upd_keep( $kept );
run_check();
check( 'network admin: the same, redirected to the Network Admin page', array( 'https://x.example/wp-admin/network/settings.php?page=divi5-builder&d5b_checked=1' ) === $GLOBALS['T_redirects'] && array( 'wp_update_plugins' ) === $GLOBALS['T_update_checks'] && false === get_site_transient( 'd5b_update_json' ), $GLOBALS['T_redirects'] );

echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
foreach ( glob( $mu . '/*' ) ?: array() as $f ) { @unlink( $f ); }
@rmdir( $mu );
exit( $failed ? 1 : 0 );
