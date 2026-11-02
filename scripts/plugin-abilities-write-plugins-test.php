<?php
/**
 * Plugin abilities (#32, release 2.4.0, Task 2): plugins-status, updates-check, plugins-install, plugins-activate and
 * plugins-deactivate.
 *
 *   php -d max_execution_time=60 scripts/plugin-abilities-write-plugins-test.php
 *
 * Loads the real inc/ files and parts/updates.php (the update engine: wpsu_status) with WordPress stand-ins
 * (plugin-test-stubs.php with T_PLUGIN_STANDINS: a fake plugin table, the active lists as core keeps them, activate_plugin /
 * deactivate_plugins as core changes them, plugins_api, Plugin_Upgrader::install, the update transients and checks, all
 * recording their order in T_traps). The page checks run for real (maint-common.php) against scripted page answers
 * (T_http_fn); the clock and the retry wait are the maint seams. Set D5B_PLUGIN_DIR to test another copy of
 * plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: the status read never clears or refreshes anything; updates-check clears once and is limited to
 * one per 5 minutes; install takes only a downloads.wordpress.org link as plugins_api returned it, refused BEFORE the
 * upgrader runs, never over an installed folder; an activation that makes the pages worse is undone; a fatal activation is
 * activation_failed with no after round; this plugin never acts on itself; a network activation needs a network admin.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.0' );
define( 'T_PLUGIN_STANDINS', true );
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-wplug-' . getmypid() . '/plugins' ); // never made: the table is get_plugins()
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/write-common', 'inc/abilities/maint-common', 'inc/abilities/write-plugins', 'parts/updates' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'update_plugins', 'install_plugins', 'activate_plugins' );
const HELLO = 'hello-dolly/hello.php';
const AKISMET = 'akismet/akismet.php';
const SELF_FILE = 'divi5-builder/divi5-builder.php';

$GLOBALS['d5b_maint_seams'] = array(
	'now'   => function () { return $GLOBALS['T_clock']; },
	'sleep' => function ( $s ) { $GLOBALS['T_sleeps'][] = $s; $GLOBALS['T_clock'] += $s; },
);

// The home page: fine, unless a plugin in T_breaks is active (or one in T_needs is not): then core's critical error page.
function page( $title = 'Home', $size = 50000 ) { $h = '<html><head><title>' . $title . '</title></head><body>'; return $h . str_repeat( 'x', max( 0, $size - strlen( $h ) - 14 ) ) . '</body></html>'; }
function serve_site() {
	$GLOBALS['T_http_fn'] = function ( $url, $args ) {
		if ( 'https://x.example/' !== preg_replace( '/[?&]d5b=[0-9]+$/', '', $url ) ) { return null; }
		foreach ( $GLOBALS['T_breaks'] as $p ) { if ( is_plugin_active( $p ) ) { return array( 'code' => 500, 'body' => 'There has been a critical error on this website.' ); } }
		foreach ( $GLOBALS['T_needs'] as $p ) { if ( ! is_plugin_active( $p ) ) { return array( 'code' => 500, 'body' => 'There has been a critical error on this website.' ); } }
		return array( 'code' => 200, 'body' => page() );
	};
}
function site( array $caps = ADMIN, bool $multi = false, bool $risky = true ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( $multi );
	$GLOBALS['T_user_id'] = 3; $GLOBALS['T_user_login'] = 'admin'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	d5b_abilities_set_category( 'plugins', true, $risky, 'tester' );
	d5b_abilities_set_category( 'core', true, false, 'tester' );
	$GLOBALS['T_clock'] = 1700000000.0; $GLOBALS['T_sleeps'] = array();
	$GLOBALS['T_plugin_table'] = array(
		AKISMET   => array( 'Name' => 'Akismet', 'Version' => '5.0' ),
		HELLO     => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ),
		SELF_FILE => array( 'Name' => 'Divi 5 Builder', 'Version' => '2.4.0' ),
	);
	update_option( 'active_plugins', array( AKISMET, SELF_FILE ) );
	$GLOBALS['T_breaks'] = array(); $GLOBALS['T_needs'] = array();
	serve_site();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function edata( $r ) { return is_wp_error( $r ) ? $r->get_error_data() : null; }
function hrows() { global $wpdb; return array_values( $wpdb->tables[ d5b_history_table() ] ?? array() ); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function active() { return (array) get_option( 'active_plugins', array() ); }
function offers( array $o ) { $t = new stdClass(); $t->last_checked = 1700000000; $t->response = array(); foreach ( $o as $f => $v ) { $t->response[ $f ] = (object) array( 'new_version' => $v, 'package' => 'https://downloads.wordpress.org/plugin/x.' . $v . '.zip' ); } return $t; }
function traps( $name ) { return count( array_filter( $GLOBALS['T_traps'], function ( $t ) use ( $name ) { return $t === $name; } ) ); }

// ---- registration
echo "registration\n";
site();
$defs = d5b_ability_defs();
$st = $defs['divi5-builder/plugins-status'] ?? null; $uc = $defs['divi5-builder/updates-check'] ?? null;
$in = $defs['divi5-builder/plugins-install'] ?? null; $ac = $defs['divi5-builder/plugins-activate'] ?? null; $de = $defs['divi5-builder/plugins-deactivate'] ?? null;
check( 'all five exist', null !== $st && null !== $uc && null !== $in && null !== $ac && null !== $de );
check( 'plugins-status: a read in plugins (no risky key, read-only annotations as every read)', 'plugins' === ( $st['category'] ?? '' ) && ! array_key_exists( 'risky', (array) $st ) && false !== ( $st['annotations']['readonly'] ?? true ), $st );
check( 'updates-check: a normal write in core (no risky), readonly false', 'core' === ( $uc['category'] ?? '' ) && ! array_key_exists( 'risky', (array) $uc ) && false === ( $uc['annotations']['readonly'] ?? null ), $uc );
check( 'install, activate, deactivate: risky in plugins, readonly false', array( true, true, true ) === array( $in['risky'] ?? null, $ac['risky'] ?? null, $de['risky'] ?? null ) && 'plugins' === $in['category'] && 'plugins' === $ac['category'] && 'plugins' === $de['category'] && false === $ac['annotations']['readonly'] );
check( 'install: slug required, nothing else', array( 'slug' ) === $in['input_schema']['required'] && array( 'slug' ) === array_keys( $in['input_schema']['properties'] ) );
check( 'activate / deactivate: plugin required, network optional', array( 'plugin' ) === $ac['input_schema']['required'] && array( 'plugin', 'network' ) === array_keys( $ac['input_schema']['properties'] ) && array( 'plugin', 'network' ) === array_keys( $de['input_schema']['properties'] ) );
check( 'the new codes have their own HTTP status (not 500)', 500 !== d5b_ability_http_status( 'self' ) && 500 !== d5b_ability_http_status( 'exists' ) && 500 !== d5b_ability_http_status( 'bad_source' ) && 500 !== d5b_ability_http_status( 'too_soon' ) && 500 !== d5b_ability_http_status( 'activation_failed' ) && 500 !== d5b_ability_http_status( 'install_failed' ) );

// ---- plugins-status
echo "plugins-status\n";
site();
$GLOBALS['T_site_transients']['update_plugins'] = offers( array( AKISMET => '5.1' ) );
$r = call( 'plugins-status' );
$ak = null; foreach ( (array) ( $r['plugins'] ?? array() ) as $row ) { if ( AKISMET === $row['item'] ) { $ak = $row; } }
check( 'status: the stored update list (akismet 5.0 -> 5.1, active), kept_copies empty', is_array( $r ) && null !== $ak && '5.1' === $ak['new_version'] && true === $ak['active'] && array() === $r['kept_copies'], $r );
check( 'status never clears a transient (trap on delete_site_transient) and never asks wordpress.org', array() === $GLOBALS['T_site_transient_deletes'] && array() === $GLOBALS['T_update_checks'] && 0 === traps( 'wp_clean_update_cache' ), array( $GLOBALS['T_site_transient_deletes'], $GLOBALS['T_update_checks'] ) );
check( 'status writes no history row', array() === hrows() );
$k1 = d5b_wc_history_row( 'divi5-builder/plugins-update', 'plugin', 0, 'update', array( 'plugin' => AKISMET, 'version' => '4.9' ), array( 'version' => '5.0', 'kept' => array( 'path' => '/x/akismet', 'bytes' => 10, 'files' => 1 ) ), 'Updated' );
$k2 = d5b_wc_history_row( 'divi5-builder/plugins-update', 'plugin', 0, 'update', array( 'plugin' => HELLO, 'version' => '1.7.1' ), array( 'version' => '1.7.2', 'kept' => null ), 'Updated without copy' );
$k3 = d5b_wc_history_row( 'divi5-builder/themes-update', 'theme', 0, 'update', array( 'stylesheet' => 'Divi', 'version' => '4' ), array( 'version' => '5', 'kept' => array( 'path' => '/x/Divi', 'bytes' => 1, 'files' => 1 ) ), 'Theme' );
$r = call( 'plugins-status' );
$row1 = d5b_history_get( $k1 );
check( 'kept_copies: only plugin rows with kept not null, as { plugin, version, history_id, kept_at }', array( array( 'plugin' => AKISMET, 'version' => '4.9', 'history_id' => $k1, 'kept_at' => d5b_wc_iso( (string) $row1['created_at'] ) ) ) === $r['kept_copies'] && '' !== $r['kept_copies'][0]['kept_at'], $r['kept_copies'] );
check( 'kept_copies never shows the path of the copy', false === strpos( json_encode( $r ), '/x/akismet' ) );
// A premium plugin's offer: the package url carries a licence key (the literal split so no whole key-shaped string is in the source).
function keyed_offers( $file, $ver ) {
	$t = offers( array( $file => $ver ) );
	$t->response[ $file ]->package = 'https://vendor.example/download.zip?li' . 'cense_key=' . 'ZQ7x' . 'K9pL2mN4';
	return $t;
}
function no_key( $r ) { $j = json_encode( $r ); return false === strpos( $j, 'ZQ7x' . 'K9pL2mN4' ) && false === strpos( $j, 'cense_key' ) && false === strpos( $j, 'vendor.example' ); }
site();
$GLOBALS['T_site_transients']['update_plugins'] = keyed_offers( AKISMET, '5.1' );
$r = call( 'plugins-status' );
check( 'status: a stored package url with a licence key never reaches the result (the offer is still shown)', is_array( $r ) && no_key( $r ) && '5.1' === $r['plugins'][0]['new_version'], $r );
site( array( 'read', 'manage_options' ) );
check( 'without update_plugins: refused', 'FORBIDDEN' === call( 'plugins-status' ) );
site();
check( 'unknown input refused', 'invalid_input' === code( call( 'plugins-status', array( 'refresh' => true ) ) ) );

// ---- updates-check
echo "updates-check\n";
site();
check( 'the Updates switch is off', false === d5b_switch_is_on( 'updates' ) );
$GLOBALS['T_site_transients']['update_plugins'] = offers( array() );
$GLOBALS['T_fresh']['update_plugins'] = offers( array( AKISMET => '5.2' ) );
$r = call( 'updates-check' );
$ak = null; foreach ( (array) ( $r['plugins'] ?? array() ) as $row ) { if ( AKISMET === $row['item'] ) { $ak = $row; } }
check( 'updates-check returns the fresh status (akismet 5.2 now offered)', is_array( $r ) && null !== $ak && '5.2' === $ak['new_version'], $r );
check( 'updates-check clears once: wp_clean_update_cache once, each update transient deleted once, then the three checks', 1 === traps( 'wp_clean_update_cache' ) && array( 'update_plugins', 'update_themes', 'update_core' ) === $GLOBALS['T_site_transient_deletes'] && array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' ) === $GLOBALS['T_update_checks'], array( $GLOBALS['T_site_transient_deletes'], $GLOBALS['T_update_checks'] ) );
$h = hlast();
check( 'one history row: object_type updates, action check, after = the counts', 1 === count( hrows() ) && 'updates' === $h['object_type'] && 'check' === $h['action'] && 'divi5-builder/updates-check' === $h['ability'] && array( 'plugins' => 1, 'themes' => 0, 'core' => 0, 'translations' => 0 ) === $h['after'], $h );
$GLOBALS['T_clock'] += 299;
$r = call( 'updates-check' );
check( 'updates-check clears once and is rate-limited: a second check within 5 minutes is too_soon, nothing cleared, no row', 'too_soon' === code( $r ) && 1 === traps( 'wp_clean_update_cache' ) && 3 === count( $GLOBALS['T_update_checks'] ) && 1 === count( hrows() ), array( code( $r ), $GLOBALS['T_update_checks'] ) );
check( '... too_soon answers 429', 429 === d5b_ability_http_status( 'too_soon' ) );
$GLOBALS['T_clock'] += 2;
check( 'after 5 minutes it runs again', is_array( call( 'updates-check' ) ) && 2 === traps( 'wp_clean_update_cache' ) && 2 === count( hrows() ) );
site(); $GLOBALS['T_fresh']['update_plugins'] = keyed_offers( AKISMET, '5.3' );
$r = call( 'updates-check' );
check( 'updates-check: a fresh package url with a licence key reaches neither the result nor the row', is_array( $r ) && no_key( $r ) && no_key( hrows() ) && '5.3' === $r['plugins'][0]['new_version'], $r );
site( array( 'read', 'manage_options' ) );
check( 'without update_plugins: refused, nothing cleared', 'FORBIDDEN' === call( 'updates-check' ) && 0 === traps( 'wp_clean_update_cache' ) );
site(); d5b_abilities_set_category( 'core', false, false, 'tester' );
check( 'the core category off: refused', 'FORBIDDEN' === call( 'updates-check' ) && 0 === traps( 'wp_clean_update_cache' ) );

// ---- plugins-install
echo "plugins-install\n";
function cf7( $link = 'https://downloads.wordpress.org/plugin/contact-form-7.5.9.zip' ) {
	$GLOBALS['T_plugins_api']['contact-form-7'] = (object) array( 'slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'version' => '5.9', 'download_link' => $link );
	$GLOBALS['T_install_adds'] = array( 'contact-form-7/wp-contact-form-7.php' => array( 'Name' => 'Contact Form 7', 'Version' => '5.9' ) );
}
site(); cf7();
$r = call( 'plugins-install', array( 'slug' => 'contact-form-7' ) );
check( 'install: installed, the plugin file and version, not active', is_array( $r ) && true === $r['installed'] && 'contact-form-7/wp-contact-form-7.php' === $r['plugin'] && '5.9' === $r['version'] && false === $r['active'] && ! in_array( 'contact-form-7/wp-contact-form-7.php', active(), true ), $r );
check( '... plugins_api asked for plugin_information of that slug, THEN install() with the link exactly as returned', array( 'plugins_api', 'install' ) === $GLOBALS['T_traps'] && 'plugin_information' === $GLOBALS['T_plugins_api_calls'][0][0] && 'contact-form-7' === ( (object) $GLOBALS['T_plugins_api_calls'][0][1] )->slug && array( 'https://downloads.wordpress.org/plugin/contact-form-7.5.9.zip' ) === $GLOBALS['T_install_calls'], $GLOBALS['T_traps'] );
$h = hlast();
check( '... one history row: object_type plugin, action install, after { plugin, version }', 1 === count( hrows() ) && 'plugin' === $h['object_type'] && 'install' === $h['action'] && array( 'plugin' => 'contact-form-7/wp-contact-form-7.php', 'version' => '5.9' ) === $h['after'], $h );
check( '... the download link is in neither the result nor the row', false === strpos( json_encode( $r ), 'downloads.wordpress.org' ) && false === strpos( json_encode( $h ), 'downloads.wordpress.org' ) );
foreach ( array(
	'http (not https)'          => 'http://downloads.wordpress.org/plugin/contact-form-7.5.9.zip',
	'another host'              => 'https://evil.example/contact-form-7.zip',
	'a look-alike host'         => 'https://downloads.wordpress.org.evil.example/contact-form-7.zip',
	'wordpress.org only in the query' => 'https://evil.example/?u=https://downloads.wordpress.org/plugin/x.zip',
	'a user in the url'         => 'https://downloads.wordpress.org@evil.example/x.zip',
	'upper-case host'           => 'https://DOWNLOADS.wordpress.org/plugin/x.zip',
	'no link'                   => null,
	'a link as a list'          => array( 'https://downloads.wordpress.org/plugin/x.zip' ),
) as $what => $link ) {
	site(); cf7( $link );
	$r = call( 'plugins-install', array( 'slug' => 'contact-form-7' ) );
	check( 'install: non-wordpress.org link refused before install() (trap order): ' . $what, 'bad_source' === code( $r ) && array( 'plugins_api' ) === $GLOBALS['T_traps'] && array() === hrows() && ! isset( $GLOBALS['T_plugin_table']['contact-form-7/wp-contact-form-7.php'] ), array( code( $r ), $GLOBALS['T_traps'] ) );
}
site(); cf7();
$r = call( 'plugins-install', array( 'slug' => 'akismet' ) );
check( 'install: a slug whose folder is installed is refused (exists), wordpress.org not even asked', 'exists' === code( $r ) && array() === $GLOBALS['T_traps'] && array() === hrows(), array( code( $r ), $GLOBALS['T_traps'] ) );
site();
check( 'install: this plugin\'s own slug is installed, so exists', 'exists' === code( call( 'plugins-install', array( 'slug' => 'divi5-builder' ) ) ) && array() === $GLOBALS['T_traps'] );
site();
$r = call( 'plugins-install', array( 'slug' => 'no-such-plugin-x' ) );
check( 'install: wordpress.org does not know the slug: not_found, nothing installed', 'not_found' === code( $r ) && array( 'plugins_api' ) === $GLOBALS['T_traps'] && array() === hrows(), array( code( $r ), $GLOBALS['T_traps'] ) );
site(); $GLOBALS['T_plugins_api']['busy'] = new WP_Error( 'plugins_api_failed', 'An unexpected error occurred. Something may be wrong with WordPress.org.' );
check( 'install: wordpress.org unreachable: unavailable', 'unavailable' === code( call( 'plugins-install', array( 'slug' => 'busy' ) ) ) && array( 'plugins_api' ) === $GLOBALS['T_traps'] );
foreach ( array( '../akismet', 'Contact-Form-7', 'a/b', '', 'x y', str_repeat( 'a', 101 ) ) as $bad ) {
	site(); cf7();
	$r = call( 'plugins-install', array( 'slug' => $bad ) );
	check( 'install: slug ' . json_encode( substr( $bad, 0, 20 ) ) . ' refused as invalid_input, nothing asked', 'invalid_input' === code( $r ) && array() === $GLOBALS['T_traps'], array( code( $r ), $GLOBALS['T_traps'] ) );
}
site(); cf7(); $GLOBALS['T_install_result'] = false;
$r = call( 'plugins-install', array( 'slug' => 'contact-form-7' ) );
check( 'install: the upgrader fails (false): install_failed; the row says failed', 'install_failed' === code( $r ) && true === ( hlast()['after']['failed'] ?? null ), array( code( $r ), hlast() ) );
site(); cf7(); $GLOBALS['T_install_result'] = new WP_Error( 'folder_exists', 'Destination folder already exists.' );
$r = call( 'plugins-install', array( 'slug' => 'contact-form-7' ) );
check( 'install: the upgrader answers WP_Error: install_failed with its message', 'install_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'Destination folder already exists' ), is_wp_error( $r ) ? $r->get_error_message() : $r );
site(); cf7(); $GLOBALS['T_file_mods_off'] = true;
check( 'install: file changes switched off on the site (DISALLOW_FILE_MODS): not_allowed, nothing asked', 'not_allowed' === code( call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ) ) && array() === $GLOBALS['T_traps'] );
site( ADMIN, false, false ); cf7();
check( 'install: the Plugins risky switch off: refused', 'FORBIDDEN' === call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ) && array() === $GLOBALS['T_traps'] );
site( array( 'read', 'manage_options', 'activate_plugins' ) ); cf7();
check( 'install: without install_plugins: refused', 'FORBIDDEN' === call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ) && array() === $GLOBALS['T_traps'] );
site( ADMIN, true ); cf7(); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'install: multisite, not a network administrator: refused', 'FORBIDDEN' === call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ) && array() === $GLOBALS['T_traps'] );
site( ADMIN, true ); cf7();
check( 'install: multisite, a network administrator: installed', is_array( call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ) ) && 1 === traps( 'install' ) );

// ---- plugins-activate
echo "plugins-activate\n";
site();
$before_list = active();
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'activate: pages fine -> stays active, reverted false, no problems', is_array( $r ) && true === $r['active'] && false === $r['reverted'] && array() === $r['problems'] && in_array( HELLO, active(), true ), $r );
check( '... activate_plugin( plugin, \'\', false, false )', array( array( HELLO, '', false, false ) ) === $GLOBALS['T_activate_calls'], $GLOBALS['T_activate_calls'] );
check( '... the result carries pages (both rounds) and human_check', isset( $r['pages']['before'], $r['pages']['after'] ) && 1 === count( $r['pages']['before'] ) && 1 === count( $r['pages']['after'] ) && d5b_human_check() === $r['human_check'], $r );
$h = hlast();
check( '... one row: plugin, activate; before = the active list, after = the active list + pages + reverted', 1 === count( hrows() ) && 'plugin' === $h['object_type'] && 'activate' === $h['action'] && $before_list === $h['before']['active'] && HELLO === $h['before']['plugin'] && active() === $h['after']['active'] && false === $h['after']['reverted'] && isset( $h['after']['pages']['before'], $h['after']['pages']['after'] ), $h );
check( '... no deactivation', array() === $GLOBALS['T_deactivate_calls'] );

site(); $GLOBALS['T_breaks'] = array( HELLO );
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'activate: pages worse -> deactivated, reverted true, the problem named', is_array( $r ) && true === $r['reverted'] && false === $r['active'] && ! in_array( HELLO, active(), true ) && 1 === count( $r['problems'] ) && false !== strpos( $r['problems'][0], 'answered 500' ), $r );
check( '... deactivate_plugins( plugin, false, false ) once: not silent', array( array( HELLO, false, false ) ) === $GLOBALS['T_deactivate_calls'], $GLOBALS['T_deactivate_calls'] );
$h = hlast();
check( '... both rounds in the row, reverted true, the after list without the plugin', true === $h['after']['reverted'] && 1 === count( $h['after']['pages']['before'] ) && 1 === count( $h['after']['pages']['after'] ) && 1 === count( $h['after']['pages']['problems'] ) && ! in_array( HELLO, $h['after']['active'], true ), $h );
check( '... the result still carries human_check', d5b_human_check() === $r['human_check'] );

site(); $GLOBALS['T_activate_fail'] = array( HELLO => 'fatal' ); $level = ob_get_level();
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'activate: fatal plugin -> activation_failed, not in the active list, no page round after', 'activation_failed' === code( $r ) && ! in_array( HELLO, active(), true ) && 1 === count( $GLOBALS['T_http_calls'] ), array( code( $r ), active(), count( $GLOBALS['T_http_calls'] ) ) );
check( '... the output buffer core left open is closed again', $level === ob_get_level(), array( $level, ob_get_level() ) );
check( '... the error carries human_check, pages null, reverted false, never the plugin\'s output', d5b_human_check() === ( edata( $r )['human_check'] ?? null ) && array_key_exists( 'pages', (array) edata( $r ) ) && null === edata( $r )['pages'] && false === edata( $r )['reverted'] && false === strpos( json_encode( array( $r->get_error_message(), edata( $r ) ) ), 'Fatal output' ), edata( $r ) );
check( '... the row: failed, the active list unchanged', 'activate' === hlast()['action'] && ! in_array( HELLO, hlast()['after']['active'], true ) && '' !== (string) ( hlast()['after']['failed'] ?? '' ), hlast() );

site(); $GLOBALS['T_activate_fail'] = array( HELLO => 'output' );
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'activate: unexpected output (core had already activated it) -> activation_failed, switched off again, no page round after', 'activation_failed' === code( $r ) && ! in_array( HELLO, active(), true ) && true === edata( $r )['reverted'] && array( array( HELLO, false, false ) ) === $GLOBALS['T_deactivate_calls'] && 1 === count( $GLOBALS['T_http_calls'] ), array( code( $r ), active(), edata( $r ) ) );
check( '... the printed text is not in the answer', false === strpos( json_encode( array( $r->get_error_message(), edata( $r ) ) ), 'something printed' ) );
site(); $GLOBALS['T_activate_fail'] = array( HELLO => 'requirements' );
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'activate: requirements not met -> activation_failed, nothing switched on or off', 'activation_failed' === code( $r ) && ! in_array( HELLO, active(), true ) && array() === $GLOBALS['T_deactivate_calls'] && false !== strpos( $r->get_error_message(), 'PHP version' ), is_wp_error( $r ) ? $r->get_error_message() : $r );

echo "activation and deactivation routines (not silent)\n";
// (a) The plugin's own activation routine runs, before the list is saved; activated_plugin after it.
site(); $seen = array();
$GLOBALS['T_plugin_hooks'][ 'activate_' . HELLO ][] = function () use ( &$seen ) { $seen[] = array( 'activate', is_plugin_active( HELLO ) ); update_option( 'hello_table_made', 1 ); };
$GLOBALS['T_plugin_hooks']['activated_plugin'][] = function ( $p ) use ( &$seen ) { $seen[] = array( 'activated', is_plugin_active( $p ) ); };
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( '(a) the activation routine ran (activate_<plugin> before the list was saved, activated_plugin after): not silent', is_array( $r ) && true === $r['active'] && array( array( 'activate', false ), array( 'activated', true ) ) === $seen && 1 === get_option( 'hello_table_made' ) && array( 'activate_plugin', 'activate_' . HELLO, 'activated_plugin' ) === $GLOBALS['T_hook_log'], array( $seen, $GLOBALS['T_hook_log'] ) );
// (b) A routine that prints: core answers unexpected_output with the plugin already active -> switched off again.
site(); $GLOBALS['T_plugin_hooks'][ 'activate_' . HELLO ][] = function () { echo 'Table created!'; };
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( '(b) an activation routine that echoes -> unexpected_output -> activation_failed, reverted true, inactive, no after round', 'activation_failed' === code( $r ) && 'unexpected_output' === edata( $r )['reason'] && true === edata( $r )['reverted'] && ! in_array( HELLO, active(), true ) && 1 === count( $GLOBALS['T_http_calls'] ) && false === strpos( json_encode( array( $r->get_error_message(), edata( $r ) ) ), 'Table created' ), array( code( $r ), edata( $r ), active() ) );
check( '... the revert ran the deactivation routine (not silent)', array( array( HELLO, false, false ) ) === $GLOBALS['T_deactivate_calls'] && in_array( 'deactivate_' . HELLO, $GLOBALS['T_hook_log'], true ), array( $GLOBALS['T_deactivate_calls'], $GLOBALS['T_hook_log'] ) );
// (c) A routine that throws before the list is saved: not active, no after round, the buffer closed.
site(); $GLOBALS['T_plugin_hooks'][ 'activate_' . HELLO ][] = function () { echo 'half'; throw new RuntimeException( 'cannot create table' ); }; $level = ob_get_level();
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( '(c) an activation routine that throws -> activation_failed, not active, nothing to revert, no after round', 'activation_failed' === code( $r ) && false === edata( $r )['reverted'] && ! in_array( HELLO, active(), true ) && array() === $GLOBALS['T_deactivate_calls'] && 1 === count( $GLOBALS['T_http_calls'] ) && $level === ob_get_level(), array( code( $r ), edata( $r ), count( $GLOBALS['T_http_calls'] ) ) );
check( '... a RuntimeException from the plugin is not mistaken for the internal stop; its message is not passed on', is_wp_error( $r ) && false === strpos( $r->get_error_message(), 'cannot create table' ) );
// The revert after bad page checks runs the plugin's deactivation routine, which cleans up.
site(); $GLOBALS['T_breaks'] = array( HELLO );
$GLOBALS['T_plugin_hooks'][ 'activate_' . HELLO ][] = function () { update_option( 'hello_table_made', 1 ); };
$GLOBALS['T_plugin_hooks'][ 'deactivate_' . HELLO ][] = function () { delete_option( 'hello_table_made' ); };
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'revert after worse pages: the deactivation routine fires and cleans up what the activation made', is_array( $r ) && true === $r['reverted'] && false === $r['active'] && false === get_option( 'hello_table_made' ) && in_array( 'deactivate_' . HELLO, $GLOBALS['T_hook_log'], true ) && ! isset( $r['warnings'] ), $r );
// A deactivation routine that throws during the revert: caught, the plugin still ends inactive (switched off silently after).
site(); $GLOBALS['T_breaks'] = array( HELLO ); $level = ob_get_level();
$GLOBALS['T_plugin_hooks'][ 'deactivate_' . HELLO ][] = function () { echo 'bye'; throw new Error( 'boom in deactivation' ); };
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'revert with a deactivation routine that throws: caught, the plugin ends inactive, reverted true, a warning', is_array( $r ) && true === $r['reverted'] && false === $r['active'] && ! in_array( HELLO, active(), true ) && array( array( HELLO, false, false ), array( HELLO, true, false ) ) === $GLOBALS['T_deactivate_calls'] && ! empty( $r['warnings'] ) && $level === ob_get_level(), array( $r, $GLOBALS['T_deactivate_calls'] ) );
site(); $GLOBALS['T_plugin_hooks'][ 'activate_' . HELLO ][] = function () { echo 'x'; };
$GLOBALS['T_plugin_hooks'][ 'deactivate_' . HELLO ][] = function () { throw new Error( 'boom' ); };
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'unexpected_output revert with a deactivation routine that throws: still ends inactive', 'activation_failed' === code( $r ) && true === edata( $r )['reverted'] && false === edata( $r )['active'] && ! in_array( HELLO, active(), true ), array( code( $r ), edata( $r ) ) );

site(); $GLOBALS['T_http_fn'] = function () { return null; };
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'activate: the site cannot fetch its own pages -> stays active, pages unavailable, human_check, a warning', is_array( $r ) && true === $r['active'] && false === $r['reverted'] && true === $r['pages']['unavailable'] && d5b_human_check() === $r['human_check'] && ! empty( $r['warnings'] ), $r );

site();
foreach ( array( 'plugins-activate', 'plugins-deactivate' ) as $ab ) {
	$r = call( $ab, array( 'plugin' => SELF_FILE ) );
	check( 'self refused for ' . $ab . ' (nothing called, no row)', 'self' === code( $r ) && array() === $GLOBALS['T_activate_calls'] && array() === $GLOBALS['T_deactivate_calls'] && array() === hrows() && in_array( SELF_FILE, active(), true ), code( $r ) );
	check( '... also written another way (leading slash, other case)', 'self' === code( call( $ab, array( 'plugin' => '/Divi5-Builder/divi5-builder.php' ) ) ) );
}
check( 'activate: a plugin that is not installed: not_found', 'not_found' === code( call( 'plugins-activate', array( 'plugin' => 'nope/nope.php' ) ) ) && array() === $GLOBALS['T_activate_calls'] );
foreach ( array( '../../wp-config.php', 'akismet/../../x.php', 'akismet/akismet.txt', "akismet/akismet.php\0", 'a/b/c.php' ) as $bad ) {
	check( 'activate: plugin ' . json_encode( $bad ) . ' is invalid_input', 'invalid_input' === code( call( 'plugins-activate', array( 'plugin' => $bad ) ) ) && array() === $GLOBALS['T_activate_calls'] );
}
$r = call( 'plugins-activate', array( 'plugin' => AKISMET ) );
check( 'activate: already active: already_active, nothing called, no row', is_array( $r ) && true === $r['already_active'] && true === $r['active'] && array() === $GLOBALS['T_activate_calls'] && array() === hrows(), $r );
check( 'activate: network true on a single site: invalid_input', 'invalid_input' === code( call( 'plugins-activate', array( 'plugin' => HELLO, 'network' => true ) ) ) && array() === $GLOBALS['T_activate_calls'] );
site( ADMIN, false, false );
check( 'activate: the Plugins risky switch off: refused', 'FORBIDDEN' === call( 'plugins-activate', array( 'plugin' => HELLO ) ) && array() === $GLOBALS['T_activate_calls'] );
site( array( 'read', 'manage_options', 'install_plugins' ) );
check( 'activate: without activate_plugins: refused', 'FORBIDDEN' === call( 'plugins-activate', array( 'plugin' => HELLO ) ) && array() === $GLOBALS['T_activate_calls'] );

echo "multisite\n";
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin';
$r = call( 'plugins-activate', array( 'plugin' => HELLO, 'network' => true ) );
check( 'multisite: network activate needs is_super_admin (a site admin is refused, nothing called)', 'forbidden' === code( $r ) && array() === $GLOBALS['T_activate_calls'] && array() === (array) get_site_option( 'active_sitewide_plugins', array() ), code( $r ) );
$r = call( 'plugins-activate', array( 'plugin' => HELLO ) );
check( 'multisite: a site admin activates for this site', is_array( $r ) && true === $r['active'] && in_array( HELLO, active(), true ) && array( array( HELLO, '', false, false ) ) === $GLOBALS['T_activate_calls'], $r );
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin'; $GLOBALS['T_plugin_table'][ HELLO ]['Network'] = true;
check( 'multisite: a Network: true plugin without network (core would network-activate it): not_allowed', 'not_allowed' === code( call( 'plugins-activate', array( 'plugin' => HELLO ) ) ) && array() === $GLOBALS['T_activate_calls'] );
site( ADMIN, true );
$r = call( 'plugins-activate', array( 'plugin' => HELLO, 'network' => true ) );
check( 'multisite: a network administrator activates network-wide', is_array( $r ) && true === $r['active'] && true === $r['network'] && isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ HELLO ] ) && array( array( HELLO, '', true, false ) ) === $GLOBALS['T_activate_calls'], $r );
check( '... the row records the network list', array() === hlast()['before']['active'] && array( HELLO ) === hlast()['after']['active'] && true === hlast()['before']['network'], hlast() );
$GLOBALS['T_user_login'] = 'siteadmin';
check( 'multisite: deactivating a network-active plugin for one site: not_allowed', 'not_allowed' === code( call( 'plugins-deactivate', array( 'plugin' => HELLO ) ) ) && array() === $GLOBALS['T_deactivate_calls'] );
check( 'multisite: network deactivate needs is_super_admin', 'forbidden' === code( call( 'plugins-deactivate', array( 'plugin' => HELLO, 'network' => true ) ) ) && array() === $GLOBALS['T_deactivate_calls'] );

// ---- plugins-deactivate
echo "plugins-deactivate\n";
site();
$before_list = active();
$r = call( 'plugins-deactivate', array( 'plugin' => AKISMET ) );
check( 'deactivate: inactive now, pages and human_check, reverted false', is_array( $r ) && false === $r['active'] && false === $r['reverted'] && ! in_array( AKISMET, active(), true ) && isset( $r['pages']['before'], $r['pages']['after'] ) && d5b_human_check() === $r['human_check'], $r );
check( '... deactivate_plugins( plugin, false, false ): the plugin\'s own deactivation routine runs, as on the Plugins screen', array( array( AKISMET, false, false ) ) === $GLOBALS['T_deactivate_calls'], $GLOBALS['T_deactivate_calls'] );
$h = hlast();
check( '... one row: deactivate, before/after active lists, pages, reverted false', 'deactivate' === $h['action'] && $before_list === $h['before']['active'] && active() === $h['after']['active'] && false === $h['after']['reverted'] && isset( $h['after']['pages']['after'] ), $h );
site(); $GLOBALS['T_needs'] = array( AKISMET );
$r = call( 'plugins-deactivate', array( 'plugin' => AKISMET ) );
check( 'deactivate: pages worse -> the problem is reported, never reverted (stays inactive, no activate call)', is_array( $r ) && 1 === count( $r['problems'] ) && false === $r['reverted'] && false === $r['active'] && array() === $GLOBALS['T_activate_calls'], $r );
site(); $GLOBALS['T_plugin_hooks'][ 'deactivate_' . AKISMET ][] = function () { echo 'x'; throw new Error( 'boom' ); }; $level = ob_get_level();
$r = call( 'plugins-deactivate', array( 'plugin' => AKISMET ) );
check( 'deactivate: a deactivation routine that throws is caught; the plugin still ends inactive, with a warning', is_array( $r ) && false === $r['active'] && ! in_array( AKISMET, active(), true ) && ! empty( $r['warnings'] ) && $level === ob_get_level(), $r );
site();
$r = call( 'plugins-deactivate', array( 'plugin' => HELLO ) );
check( 'deactivate: not active: already_inactive, nothing called, no row', is_array( $r ) && true === $r['already_inactive'] && array() === $GLOBALS['T_deactivate_calls'] && array() === hrows(), $r );
site( ADMIN, false, false );
check( 'deactivate: the Plugins risky switch off: refused', 'FORBIDDEN' === call( 'plugins-deactivate', array( 'plugin' => AKISMET ) ) );

echo "no secrets\n";
site(); cf7();
$all = json_encode( array( call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ), call( 'plugins-activate', array( 'plugin' => 'contact-form-7/wp-contact-form-7.php' ) ), hrows() ) );
check( 'no password, key or token in the results or the rows', 1 !== preg_match( '/password|api_key|token|secret/i', $all ), $all );

echo "\n" . ( $failed ? "$failed of $n FAILED\n" : "all $n passed\n" );
exit( $failed ? 1 : 0 );
