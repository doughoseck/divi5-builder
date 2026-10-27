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
 */
define( 'D5B_VERSION', '2.0.0' );
require __DIR__ . '/plugin-test-stubs.php';
$GLOBALS['T_exits'] = 0;
function d5b_settings_exit() { $GLOBALS['T_exits']++; }
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/gate.php';
require $dir . '/inc/takeover.php';
require $dir . '/inc/settings.php';
foreach ( array( 'page-building', 'media-audit', 'media-clean-up', 'updates' ) as $f ) { require $dir . '/parts/' . $f . '.php'; }

$mu = sys_get_temp_dir() . '/d5b-set-mu-' . getmypid();
if ( ! is_dir( $mu ) ) { mkdir( $mu ); }
define( 'WPMU_PLUGIN_DIR', $mu );
define( 'D5B_FILE', $mu . '/divi5-builder.php' );

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function fresh() { t_reset(); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $_POST = array(); $_GET = array(); $GLOBALS['T_exits'] = 0; }
function post( array $on ) { $_POST = array( 'action' => 'd5b_save_switches', '_wpnonce' => 'x', 'd5b_on' => $on ); }
function run_save() { try { d5b_settings_save(); return null; } catch ( Throwable $e ) { return $e; } }
function html( $fn ) { ob_start(); $fn(); return ob_get_clean(); }
function writes_of( $name ) { return count( array_filter( $GLOBALS['T_writes'], function ( $w ) use ( $name ) { return $w[1] === $name; } ) ); }
function stored() { return is_multisite() ? get_site_option( 'd5b_switches', null ) : get_option( 'd5b_switches', null ); }

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
check( 'Divi 5 not detected: says page building is idle', false !== strpos( $out, 'Divi 5 not detected: page building is idle' ) && false === strpos( $out, 'Divi 5 detected' ), $out );
foreach ( array( 'media_audit', 'media_clean_up', 'updates' ) as $p ) {
	check( "single site: $p note is printed as d5b_risk_note gives it", false !== strpos( $out, esc_html( d5b_risk_note( $p ) ) ) && '' !== d5b_risk_note( $p ), $p );
	check( "$p has one checkbox", 1 === substr_count( $out, 'name="d5b_on[' . $p . ']"' ), $p );
}
check( 'page_building has no checkbox and the form names exactly three', false === strpos( $out, 'd5b_on[page_building]' ) && 3 === substr_count( $out, 'type="checkbox"' ), $out );
check( 'all three start unticked', false === strpos( $out, 'checked="checked"' ), $out );
check( 'no multisite sentence on a single site', false === strpos( $out, 'every site in it at once' ) && false === strpos( $out, 'sites that share these files' ) );
check( 'the form posts to admin-post.php with the nonce and the action', false !== strpos( $out, 'action="https://x.example/wp-admin/admin-post.php"' ) && false !== strpos( $out, 'value="nonce-for-d5b_switches"' ) && false !== strpos( $out, 'name="action" value="d5b_save_switches"' ), $out );
check( 'no "Last switched" line when nothing was ever switched', false === strpos( $out, 'Last switched' ) );
d5b_switch_set( 'updates', true, 'doug' );
$out = html( 'd5b_settings_render' );
$at  = d5b_switches()['updates']['changed_at'];
check( 'updates ticked, and Last switched on <time> by doug', 1 === substr_count( $out, 'checked="checked"' ) && false !== strpos( $out, 'Last switched on ' . $at . ' by doug' ) && 1 === substr_count( $out, 'Last switched' ), $out );
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
echo "no old copy: the notice and the page build no REST server\n";
fresh(); t_set_caps( array( 'activate_plugins', 'manage_options' ) );
html( 'd5b_admin_notice' ); html( 'd5b_settings_render' );
check( 'no legacy constant: no rest_get_server() call, no gate run', 0 === $GLOBALS['T_server_calls'] && ! isset( $GLOBALS['d5b_gate_ran'] ) && array() === $GLOBALS['T_routes'], array( $GLOBALS['T_server_calls'], array_keys( $GLOBALS['T_routes'] ) ) );

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

echo "old copy inert (constant, no routes): plain line only\n";
fresh(); t_set_caps( array( 'activate_plugins' ) );
update_option( 'd5b_legacy_seen', array( 'updates' ) ); // a stale record from when it was serving
$out = html( 'd5b_admin_notice' );
check( 'the refresh clears the stale record; plain "installed but inactive", no red line', false !== strpos( $out, '<div class="notice notice-warning"><p>An old copy of Updates is installed but inactive. Delete it to finish the move.</p></div>' ) && false === strpos( $out, 'notice-error' ) && array() === get_option( 'd5b_legacy_seen' ), $out );

echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
foreach ( glob( $mu . '/*' ) ?: array() as $f ) { @unlink( $f ); }
@rmdir( $mu );
exit( $failed ? 1 : 0 );
