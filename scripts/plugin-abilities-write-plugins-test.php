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
 *
 * Task 3 (plugins-update, plugins-rollback, plugins-delete-preview / plugins-delete) runs on a real plugins folder and a
 * real uploads store under the system temp folder (T_plugins_disk: get_plugins() reads the files as core does, cached until
 * wp_clean_plugins_cache). The promises: the copy is taken BEFORE the upgrader runs and holds the old files byte for byte; a
 * copy that cannot be made refuses the update (and removes its row) unless without_copy; rollback_on_failure puts the old
 * files back at once; a rollback restores byte-identical files and keeps the files it replaces; a newer kept copy is not
 * skipped without force_older; a delete runs the plugin's uninstall routine unless keep_data, and a code taken for one
 * keep_data value is refused for the other.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.0' );
define( 'T_PLUGIN_STANDINS', true );
// Made only by the update, rollback and delete sections (disk_site()); before them the table is get_plugins().
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-wplug-' . getmypid() . '/plugins' );
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/read-history', 'inc/abilities/write-common', 'inc/abilities/confirm', 'inc/abilities/maint-common', 'inc/abilities/write-plugins', 'parts/updates' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'update_plugins', 'install_plugins', 'activate_plugins' );
const HELLO = 'hello-dolly/hello.php';
const AKISMET = 'akismet/akismet.php';
const SELF_FILE = 'divi5-builder/divi5-builder.php';

$GLOBALS['d5b_maint_seams'] = array(
	'now'   => function () { return $GLOBALS['T_clock']; },
	'sleep' => function ( $s ) { $GLOBALS['T_sleeps'][] = $s; $GLOBALS['T_clock'] += $s; },
	// The renames of the swap: recorded (from, to, maintenance mode on?, copies made so far); a source matching
	// T_rename_refuse_re is refused, as a host can refuse a rename.
	'rename' => function ( $from, $to ) {
		$GLOBALS['T_mrenames'][] = array( basename( $from ), basename( $to ), ! empty( $GLOBALS['T_in_maintenance'] ), count( $GLOBALS['T_copy_calls'] ?? array() ) );
		// T_rename_hook: called first (another request acting between the renames); a non-null answer is the rename's.
		if ( is_callable( $GLOBALS['T_rename_hook'] ?? null ) ) { $h = $GLOBALS['T_rename_hook']; $x = $h( $from, $to ); if ( null !== $x ) { return $x; } }
		if ( '' !== ( $GLOBALS['T_rename_refuse_re'] ?? '' ) && 1 === preg_match( $GLOBALS['T_rename_refuse_re'], str_replace( '\\', '/', $from ) ) ) { return false; }
		return @rename( $from, $to );
	},
);

// The home page: fine, unless a plugin in T_breaks is active (or one in T_needs is not): then core's critical error page.
function page( $title = 'Home', $size = 50000 ) { $h = '<html><head><title>' . $title . '</title></head><body>'; return $h . str_repeat( 'x', max( 0, $size - strlen( $h ) - 14 ) ) . '</body></html>'; }
function serve_site() {
	$GLOBALS['T_http_fn'] = function ( $url, $args ) {
		if ( 'https://x.example/' !== preg_replace( '/[?&]d5b=[0-9]+$/', '', $url ) ) { return null; }
		foreach ( $GLOBALS['T_breaks'] as $p ) { if ( is_plugin_active( $p ) ) { return array( 'code' => 500, 'body' => 'There has been a critical error on this website.' ); } }
		foreach ( $GLOBALS['T_needs'] as $p ) { if ( ! is_plugin_active( $p ) ) { return array( 'code' => 500, 'body' => 'There has been a critical error on this website.' ); } }
		// T_breaks_at: plugin => the installed version that breaks the site (read from the plugins folder, as WordPress would load it).
		foreach ( $GLOBALS['T_breaks_at'] ?? array() as $p => $v ) { $all = get_plugins(); if ( isset( $all[ $p ] ) && $v === $all[ $p ]['Version'] ) { return array( 'code' => 500, 'body' => 'There has been a critical error on this website.' ); } }
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
	$GLOBALS['T_breaks'] = array(); $GLOBALS['T_needs'] = array(); $GLOBALS['T_breaks_at'] = array();
	$GLOBALS['T_mrenames'] = array(); $GLOBALS['T_rename_refuse_re'] = '';
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
check( 'plugins-status: a read in plugins (no risky key, no annotations of its own, so readonly true at registration)', 'plugins' === ( $st['category'] ?? '' ) && ! array_key_exists( 'risky', (array) $st ) && ! array_key_exists( 'annotations', (array) $st ), $st );
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
// 2.4.1: the lookup lists only the update, rollback and delete rows of the kind (one query, newest 100) and reads only those.
function raw_hrow( $ability, $type, $action, $before = array(), $after = array() ) {
	global $wpdb;
	$wpdb->insert( d5b_history_table(), array( 'created_at' => '2026-11-01 10:00:00', 'user_id' => 3, 'ability' => $ability, 'audit_id' => 0, 'object_type' => $type, 'object_id' => 0, 'action' => $action, 'revision_id' => null, 'summary' => 's', 'before' => json_encode( $before ), 'after' => json_encode( $after ) ) );
	return (int) $wpdb->insert_id;
}
function kept_lookup_case( int $unrelated ) {
	global $wpdb;
	site();
	$kept = array();
	foreach ( array( 'update', 'rollback', 'delete' ) as $i => $act ) { $kept[] = raw_hrow( 'divi5-builder/plugins-' . $act, 'plugin', $act, array( 'plugin' => AKISMET, 'version' => '4.' . $i ), array( 'kept' => array( 'path' => '/x/k' . $i, 'bytes' => 1, 'files' => 1 ) ) ); }
	$abil = array( array( 'divi5-builder/plugins-activate', 'plugin', 'activate' ), array( 'divi5-builder/plugins-deactivate', 'plugin', 'deactivate' ), array( 'divi5-builder/plugins-install', 'plugin', 'install' ), array( 'divi5-builder/posts-update', 'post', 'update' ) );
	for ( $i = 0; $i < $unrelated; $i++ ) { $a = $abil[ $i % 4 ]; raw_hrow( $a[0], $a[1], $a[2], array( 'plugin' => HELLO ), array( 'x' => $i ) ); }
	$wpdb->queries = array();
	$out = d5b_maint_kept_copies( 'plugin' );
	$lists = array_values( preg_grep( '/^SELECT id, created_at/', $wpdb->queries ) ); $gets = array_values( preg_grep( '/^SELECT \* FROM \S+ WHERE id = \d+$/', $wpdb->queries ) );
	return array( $out, $lists, $gets, array_reverse( $kept ) );
}
list( $out, $lists, $gets, $kept ) = kept_lookup_case( 400 );
check( 'kept_copies: 400 unrelated rows and 3 kept rows -> 1 list query + 3 gets', 1 === count( $lists ) && 3 === count( $gets ) && $kept === array_column( $out, 'history_id' ), array( count( $lists ), count( $gets ), array_column( $out, 'history_id' ), $kept ) );
check( '... the list asks for the three abilities of the kind (an IN list, prepared), newest 100', 1 === preg_match( "/ability IN \\('divi5-builder\\/plugins-update', 'divi5-builder\\/plugins-rollback', 'divi5-builder\\/plugins-delete'\\)/", $lists[0] ?? '' ) && 1 === preg_match( '/ LIMIT 100 OFFSET 0$/', $lists[0] ?? '' ) && array() === $GLOBALS['wpdb']->unprepared, $lists );
list( $out, $lists, $gets, $kept ) = kept_lookup_case( 700 );
check( '... 700 newer unrelated rows do not push the 3 kept rows out of the lookup', $kept === array_column( $out, 'history_id' ) && 3 === count( $gets ), array_column( $out, 'history_id' ) );
check( 'history list: an empty abilities filter matches nothing (no query)', array() === d5b_history_list( array( 'abilities' => array() ) ) );
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
// 2.4.1: a plugin header holding a vendor key (split literals: no whole key-shaped string in the source).
site();
$ghk = 'gh' . 'p_' . str_repeat( 'aB3', 12 ); $awk = 'AK' . 'IA' . str_repeat( 'Q7', 8 ); $glk = 'gl' . 'pat-' . str_repeat( 'aB_-', 5 );
$GLOBALS['T_plugin_table'][ HELLO ]['Version'] = '1.7.2+' . $ghk;
$GLOBALS['T_plugin_table'][ AKISMET ]['Name'] = 'Akismet id' . $awk . ' ' . $glk;
$GLOBALS['T_site_transients']['update_plugins'] = offers( array( AKISMET => '5.1', HELLO => '1.7.3' ) );
$r = call( 'plugins-status' ); $j = json_encode( $r );
check( 'status: a GitHub token in a Version header and an AWS id and GitLab token in a Name header are masked (glued too)', is_array( $r ) && false === strpos( $j, $ghk ) && false === strpos( $j, $awk ) && false === strpos( $j, $glk ) && false !== strpos( $j, '1.7.2+[redacted]' ), $r );

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
$GLOBALS['T_clock'] = 1700000099.0; // 2.4.1: the 5-minute window of 1700000000 is 1699999800 to 1700000100
$r = call( 'updates-check' );
check( 'updates-check clears once and is rate-limited: a second check within 5 minutes is too_soon, nothing cleared, no row', 'too_soon' === code( $r ) && 1 === traps( 'wp_clean_update_cache' ) && 3 === count( $GLOBALS['T_update_checks'] ) && 1 === count( hrows() ), array( code( $r ), $GLOBALS['T_update_checks'] ) );
check( '... too_soon answers 429', 429 === d5b_ability_http_status( 'too_soon' ) );
$GLOBALS['T_clock'] = 1700000100.0;
check( 'in the next 5-minute window it runs again', is_array( call( 'updates-check' ) ) && 2 === traps( 'wp_clean_update_cache' ) && 2 === count( hrows() ) );
// 2.4.3: vendors that only hook their updater in wp-admin (Elegant Themes on admin_init) are hooked before the refresh,
// otherwise wp_update_themes() from REST rewrites the list WITHOUT Divi and hides a pending Divi update.
if ( ! function_exists( 'et_core_enable_automatic_updates' ) ) { function et_core_enable_automatic_updates( $url, $version ) { $GLOBALS['T_update_checks'][] = 'et:' . $version . ':' . $url; $GLOBALS['et_core_updates'] = (object) array( 'v' => $version ); } }
if ( ! function_exists( 'get_template_directory_uri' ) ) { function get_template_directory_uri() { return 'https://example.test/wp-content/themes/Divi'; } }
if ( ! defined( 'ET_CORE_VERSION' ) ) { define( 'ET_CORE_VERSION', '4.27.4' ); }
unset( $GLOBALS['et_core_updates'] ); $GLOBALS['T_update_checks'] = array(); $GLOBALS['T_clock'] = 1700000400.0;
call( 'updates-check' );
check( 'updates-check hooks the Elegant Themes updater once, BEFORE the three checks, with the parent theme URI and ET_CORE_VERSION', array( 'et:4.27.4:https://example.test/wp-content/themes/Divi', 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' ) === $GLOBALS['T_update_checks'], $GLOBALS['T_update_checks'] );
$GLOBALS['T_update_checks'] = array(); $GLOBALS['T_clock'] = 1700000700.0;
call( 'updates-check' );
check( 'an updater already hooked ($GLOBALS[et_core_updates] set, as Divi does on admin_init) is not hooked twice', array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' ) === $GLOBALS['T_update_checks'], $GLOBALS['T_update_checks'] );
unset( $GLOBALS['et_core_updates'] ); $GLOBALS['T_update_checks'] = array();
call( 'plugins-status' );
check( 'a status read hooks nothing and asks nothing (it only reads the stored list)', array() === $GLOBALS['T_update_checks'], $GLOBALS['T_update_checks'] );
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
$pg = edata( $r )['pages'] ?? null;
check( '... the error carries human_check, reverted false, never the plugin\'s output', d5b_human_check() === ( edata( $r )['human_check'] ?? null ) && false === edata( $r )['reverted'] && false === strpos( json_encode( array( $r->get_error_message(), edata( $r ) ) ), 'Fatal output' ), edata( $r ) );
check( '2.4.1 ... and the before round in pages.before (after empty, problems empty, unavailable false)', is_array( $pg ) && 1 === count( $pg['before'] ) && 200 === $pg['before'][0]['status'] && array() === $pg['after'] && array() === $pg['problems'] && false === $pg['unavailable'] && array() === $pg['skipped'], $pg );
check( '... the row keeps that before round too', 1 === count( hlast()['after']['pages']['before'] ?? array() ), hlast()['after'] );
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

// ======== Task 3: update, rollback, delete (a real plugins folder and a real store)
$T_BASE = dirname( WP_PLUGIN_DIR );
function t_rm( $d ) {
	if ( is_link( $d ) || is_file( $d ) ) { @unlink( $d ); return; }
	if ( ! file_exists( $d ) ) { return; }
	// A Windows junction: neither a link nor a folder to PHP, removed with rmdir (its target is not touched).
	if ( ! is_dir( $d ) ) { @rmdir( $d ); return; }
	foreach ( array_diff( (array) scandir( $d ), array( '.', '..' ) ) as $f ) {
		$p = $d . '/' . $f;
		if ( is_link( $p ) || ( file_exists( $p ) && ! is_dir( $p ) && ! is_file( $p ) ) || realpath( $p ) !== realpath( $d ) . DIRECTORY_SEPARATOR . $f ) { @unlink( $p ) || @rmdir( $p ); continue; }
		t_rm( $p );
	}
	@rmdir( $d );
}
// A directory link: a symbolic link where PHP may make one, else a Windows junction (no privilege needed). false when neither.
function t_link_dir( $target, $link ) {
	if ( @symlink( $target, $link ) ) { return true; }
	if ( '\\' !== DIRECTORY_SEPARATOR ) { return false; }
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" 2>&1', $o, $rc );
	clearstatcache();
	return 0 === $rc && file_exists( $link );
}
register_shutdown_function( function () use ( $T_BASE ) { t_rm( $T_BASE ); } );
function t_put( $path, $body ) { if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0777, true ); } file_put_contents( $path, $body ); }
// A plugin on disk: its main file with the headers, and the extra files (path in its folder => content).
function plug_write( $file, $name, $version, array $extra = array() ) {
	$p = WP_PLUGIN_DIR . '/' . $file;
	t_put( $p, "<?php\n/*\nPlugin Name: " . $name . "\nVersion: " . $version . "\n*/\n// the code of " . $version . "\n" );
	foreach ( $extra as $rel => $body ) { t_put( dirname( $p ) . '/' . $rel, $body ); }
	wp_clean_plugins_cache( false );
}
// Every file under $path (a folder, or one file) => sha1 of its bytes, by relative path.
function tree( $path ) {
	if ( is_file( $path ) ) { return array( '' => sha1_file( $path ) ); }
	$out = array();
	if ( ! is_dir( $path ) ) { return $out; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) { $out[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( $path ) + 1 ) ) ] = sha1_file( $f->getPathname() ); }
	ksort( $out );
	return $out;
}
const ADMIN_ALL = array( 'read', 'manage_options', 'update_plugins', 'install_plugins', 'activate_plugins', 'delete_plugins' );
const ACME = 'acme-pro-folder/acme.php'; // a plugin whose folder is not its slug
const SOLO = 'solo.php';                 // a single-file plugin
function disk_site( array $caps = ADMIN_ALL, bool $multi = false, bool $risky = true ) {
	global $T_BASE;
	site( $caps, $multi, $risky );
	t_rm( $T_BASE ); mkdir( WP_PLUGIN_DIR, 0777, true ); mkdir( $T_BASE . '/uploads', 0777, true );
	$GLOBALS['T_upload_basedir'] = $T_BASE . '/uploads';
	$GLOBALS['T_plugins_disk'] = true; $GLOBALS['T_plugins_cache'] = null;
	plug_write( AKISMET, 'Akismet', '5.0', array( 'readme.txt' => 'akismet readme 5.0', 'inc/class.php' => '<?php // akismet class 5.0' ) );
	plug_write( HELLO, 'Hello Dolly', '1.7.2', array( 'lyrics.txt' => 'hello lyrics' ) );
	plug_write( SELF_FILE, 'Divi 5 Builder', '2.4.0' );
	plug_write( ACME, 'Acme Pro', '3.0', array( 'assets/acme.css' => 'body{}' ) );
	plug_write( SOLO, 'Solo', '1.0' );
	$GLOBALS['T_fresh']['update_plugins'] = offers( array( AKISMET => '5.1', ACME => '3.1', SOLO => '1.1' ) );
}
function version_of( $f ) { $all = get_plugins(); return $all[ $f ]['Version'] ?? null; }
function row( $id ) { return d5b_history_get( (int) $id ); }
function kept_path( $id ) { return (string) ( row( $id )['after']['kept']['path'] ?? '' ); }
// A kept copy as if it had been kept the way it is now: its file list and fingerprint made again from the files (a copy
// that was MADE without the main file, not changed afterwards).
function reseal( $id ) { $a = row( $id )['after']; $a['kept']['manifest_sha1'] = d5b_maint_manifest_keep( $a['kept']['path'] ); d5b_history_set_after( (int) $id, $a ); }

echo "update, rollback, delete: registration\n";
site();
$defs = d5b_ability_defs();
$up = $defs['divi5-builder/plugins-update'] ?? null; $rb = $defs['divi5-builder/plugins-rollback'] ?? null;
$dp = $defs['divi5-builder/plugins-delete-preview'] ?? null; $dl = $defs['divi5-builder/plugins-delete'] ?? null;
check( 'the four exist, risky, in plugins', null !== $up && null !== $rb && null !== $dp && null !== $dl && array( true, true, true, true ) === array( $up['risky'] ?? null, $rb['risky'] ?? null, $dp['risky'] ?? null, $dl['risky'] ?? null ) && 'plugins' === $up['category'] && 'plugins' === $rb['category'] && 'plugins' === $dp['category'] && 'plugins' === $dl['category'] );
check( 'update: plugin required; rollback_on_failure and without_copy optional', array( 'plugin' ) === ( $up['input_schema']['required'] ?? null ) && array( 'plugin', 'rollback_on_failure', 'without_copy' ) === array_keys( $up['input_schema']['properties'] ?? array() ), $up['input_schema'] ?? null );
check( 'rollback: plugin and history_id required; force_older optional', array( 'plugin', 'history_id' ) === ( $rb['input_schema']['required'] ?? null ) && array( 'plugin', 'history_id', 'force_older' ) === array_keys( $rb['input_schema']['properties'] ?? array() ) );
check( 'delete-preview: readonly; delete: destructive with confirmation', true === ( $dp['annotations']['readonly'] ?? null ) && true === ( $dl['annotations']['destructive'] ?? null ) && array( 'plugin', 'keep_data', 'without_copy', 'confirmation' ) === array_keys( $dl['input_schema']['properties'] ?? array() ) && array( 'plugin', 'keep_data', 'without_copy' ) === array_keys( $dp['input_schema']['properties'] ?? array() ) );
check( 'the new codes have their own HTTP status (not 500)', 500 !== d5b_ability_http_status( 'active' ) && 500 !== d5b_ability_http_status( 'newer_copy_exists' ) && 500 !== d5b_ability_http_status( 'copy_missing' ) && 500 !== d5b_ability_http_status( 'update_failed' ) && 500 !== d5b_ability_http_status( 'delete_failed' ) && 500 !== d5b_ability_http_status( 'restore_mismatch' ) );

echo "plugins-update\n";
disk_site();
$old = tree( WP_PLUGIN_DIR . '/akismet' );
$r = call( 'plugins-update', array( 'plugin' => AKISMET ) );
check( 'update: updated 5.0 -> 5.1, the version read from the folder', is_array( $r ) && true === $r['updated'] && '5.0' === $r['from'] && '5.1' === $r['to'] && '5.1' === $r['version'] && '5.1' === version_of( AKISMET ), $r );
$hid = (int) ( $r['history_id'] ?? 0 );
check( 'update: copy before upgrader (trap order): copy_dir ran before bulk_upgrade, and the kept copy holds the OLD files byte for byte', null !== $GLOBALS['T_copies_at_upgrade'] && $GLOBALS['T_copies_at_upgrade'] >= 1 && $old === tree( kept_path( $hid ) ) && false !== strpos( kept_path( $hid ), '/d5b-history/' . $hid . '-' ), array( $GLOBALS['T_copies_at_upgrade'], kept_path( $hid ) ) );
$h = row( $hid );
check( '... one row, written first: plugin, update, before { plugin, version }, after { version, kept, pages, rolled_back }', 1 === count( hrows() ) && 'plugin' === $h['object_type'] && 'update' === $h['action'] && 'divi5-builder/plugins-update' === $h['ability'] && array( 'plugin' => AKISMET, 'version' => '5.0' ) === $h['before'] && '5.1' === $h['after']['version'] && is_array( $h['after']['kept'] ) && isset( $h['after']['pages']['after'] ) && false === $h['after']['rolled_back'], $h );
check( '... the result: kept without its path, pages, human_check, no problems, an active plugin stays active', array( 'bytes', 'files' ) === array_keys( (array) $r['kept'] ) && 3 === $r['kept']['files'] && false === strpos( json_encode( $r ), 'd5b-history' ) && d5b_human_check() === $r['human_check'] && array() === $r['problems'] && in_array( AKISMET, active(), true ), $r );
$st = call( 'plugins-status' );
check( '... plugins-status lists the kept copy (akismet 5.0)', array( 'plugin' => AKISMET, 'version' => '5.0', 'history_id' => $hid ) === array_intersect_key( (array) ( $st['kept_copies'][0] ?? array() ), array_flip( array( 'plugin', 'version', 'history_id' ) ) ), $st['kept_copies'] ?? null );

disk_site(); $GLOBALS['T_copy_fail'] = true;
$r = call( 'plugins-update', array( 'plugin' => AKISMET ) );
check( 'update: copy error refuses and removes the row (copy_failed, no upgrade, still 5.0, no row, no store folder left)', 'copy_failed' === code( $r ) && 0 === count( $GLOBALS['T_upgrade_calls'] ) && '5.0' === version_of( AKISMET ) && array() === hrows() && array() === array_values( array_diff( (array) @scandir( $T_BASE . '/uploads/d5b-history' ), array( '.', '..', '.htaccess', 'index.html' ) ) ), array( code( $r ), hrows() ) );
disk_site(); $GLOBALS['T_copy_fail'] = true;
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'without_copy' => true ) );
$h = hlast();
check( 'update: without_copy proceeds with kept null (updated, the row says kept null, the result says it cannot be put back)', is_array( $r ) && true === $r['updated'] && null === $r['kept'] && '5.1' === version_of( AKISMET ) && array_key_exists( 'kept', $h['after'] ) && null === $h['after']['kept'] && 'copy_failed' === ( $r['copy_error'] ?? null ) && 1 === preg_match( '/cannot put/i', implode( ' ', (array) ( $r['warnings'] ?? array() ) ) ), array( $r, $h ) );

disk_site(); $GLOBALS['T_breaks_at'] = array( AKISMET => '5.1' );
$old = tree( WP_PLUGIN_DIR . '/akismet' );
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'rollback_on_failure' => true ) );
check( 'update: rollback_on_failure restores at once (pages worse -> the old files back byte for byte, rolled_back true, the problem named)', is_array( $r ) && true === $r['rolled_back'] && '5.0' === version_of( AKISMET ) && '5.0' === $r['version'] && $old === tree( WP_PLUGIN_DIR . '/akismet' ) && 1 === count( $r['problems'] ) && in_array( AKISMET, active(), true ), $r );
$uh = row( $r['history_id'] ?? 0 ); $rh = row( $r['rollback_history_id'] ?? 0 );
check( '... the update row says rolled_back; a rollback row keeps the 5.1 files it replaced', true === ( $uh['after']['rolled_back'] ?? null ) && 'rollback' === ( $rh['action'] ?? null ) && '5.1' === $rh['before']['version'] && is_array( $rh['after']['kept'] ) && false !== strpos( (string) @file_get_contents( $rh['after']['kept']['path'] . '/akismet.php' ), 'Version: 5.1' ), array( $uh, $rh ) );
check( '... no folder left beside the plugin (no .d5b-new- / .d5b-old-)', array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ) && 5 === count( array_diff( scandir( WP_PLUGIN_DIR ), array( '.', '..' ) ) ), scandir( WP_PLUGIN_DIR ) );
check( '... one more page round after the restore: pages_after_restore in the result and the row, the site fine again', 1 === count( $r['pages_after_restore']['after'] ?? array() ) && 200 === $r['pages_after_restore']['after'][0]['status'] && array() === $r['pages_after_restore']['problems'] && $r['pages_after_restore'] === ( $uh['after']['pages_after_restore'] ?? null ), array( $r['pages_after_restore'] ?? null, $uh['after'] ?? null ) );
disk_site(); $GLOBALS['T_upgrade_result'] = array( AKISMET => 'wipe' );
$old = tree( WP_PLUGIN_DIR . '/akismet' );
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'rollback_on_failure' => true ) );
check( 'update: rollback_on_failure after a FAILED update (the upgrader removed the folder, then failed): update_failed, the old files back byte for byte, rolled_back', 'update_failed' === code( $r ) && true === ( edata( $r )['rolled_back'] ?? null ) && $old === tree( WP_PLUGIN_DIR . '/akismet' ) && '5.0' === version_of( AKISMET ) && isset( edata( $r )['pages_after_restore']['after'] ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), array( code( $r ), edata( $r ) ) );
$rbr = row( edata( $r )['rollback_history_id'] ?? 0 );
check( '... the rollback row of that restore keeps nothing (there was no folder to replace)', is_array( $rbr ) && array_key_exists( 'kept', $rbr['after'] ) && null === $rbr['after']['kept'], $rbr );
disk_site(); $GLOBALS['T_breaks_at'] = array( AKISMET => '5.1' );
$r = call( 'plugins-update', array( 'plugin' => AKISMET ) );
check( 'update: default leaves the new version (pages worse, problems reported, rolled_back false, still 5.1)', is_array( $r ) && false === $r['rolled_back'] && '5.1' === version_of( AKISMET ) && 1 === count( $r['problems'] ) && 1 === count( hrows() ), $r );

disk_site(); $GLOBALS['T_upgrade_result'] = array( AKISMET => new WP_Error( 'download_failed', 'Download failed.' ) );
$r = call( 'plugins-update', array( 'plugin' => AKISMET ) );
check( 'update: the upgrader fails -> update_failed with the reason; the row says failed and keeps the copy', 'update_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'Download failed' ) && is_array( hlast()['after']['kept'] ) && '' !== (string) ( hlast()['after']['failed'] ?? '' ) && d5b_human_check() === ( edata( $r )['human_check'] ?? null ), array( code( $r ), hlast() ) );
disk_site();
$r = call( 'plugins-update', array( 'plugin' => HELLO ) );
check( 'update: nothing offered -> already_up_to_date, no row, no copy, no upgrade', is_array( $r ) && true === ( $r['already_up_to_date'] ?? null ) && false === $r['updated'] && array() === hrows() && array() === $GLOBALS['T_copy_calls'] && array() === $GLOBALS['T_upgrade_calls'], $r );
$r = call( 'plugins-update', array( 'plugin' => SELF_FILE ) );
check( 'update: self refused (no row, no copy, no upgrade)', 'self' === code( $r ) && array() === hrows() && array() === $GLOBALS['T_copy_calls'] && array() === $GLOBALS['T_upgrade_calls'], code( $r ) );
check( 'update: not installed -> not_found', 'not_found' === code( call( 'plugins-update', array( 'plugin' => 'nope/nope.php' ) ) ) && array() === hrows() );
disk_site( ADMIN_ALL, false, false );
check( 'update: the Plugins risky switch off: refused', 'FORBIDDEN' === call( 'plugins-update', array( 'plugin' => AKISMET ) ) && array() === $GLOBALS['T_upgrade_calls'] );
disk_site( array( 'read', 'manage_options', 'activate_plugins', 'delete_plugins' ) );
check( 'update: without update_plugins: refused', 'FORBIDDEN' === call( 'plugins-update', array( 'plugin' => AKISMET ) ) && array() === $GLOBALS['T_upgrade_calls'] );
disk_site( ADMIN_ALL, true ); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'update: multisite, not a network administrator: refused', 'FORBIDDEN' === call( 'plugins-update', array( 'plugin' => AKISMET ) ) && array() === $GLOBALS['T_upgrade_calls'] );
disk_site(); $GLOBALS['T_file_mods_off'] = true;
check( 'update: file changes switched off: not_allowed, no row', 'not_allowed' === code( call( 'plugins-update', array( 'plugin' => AKISMET ) ) ) && array() === hrows() && array() === $GLOBALS['T_upgrade_calls'] );

echo "plugins-rollback\n";
disk_site();
$v50 = tree( WP_PLUGIN_DIR . '/akismet' );
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' );
$rows_before = count( hrows() );
$r = call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $u1 ) );
check( 'rollback: wrong plugin -> not_found, nothing changed, no row', 'not_found' === code( $r ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && $rows_before === count( hrows() ), code( $r ) );
check( 'rollback: a row that is not an update/rollback/delete -> not_found', 'not_found' === code( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => d5b_wc_history_row( 'divi5-builder/plugins-activate', 'plugin', 0, 'activate', array( 'plugin' => AKISMET ), array( 'kept' => array( 'path' => kept_path( $u1 ) ) ), 'x' ) ) ) ) );
check( 'rollback: self refused', 'self' === code( call( 'plugins-rollback', array( 'plugin' => SELF_FILE, 'history_id' => $u1 ) ) ) );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'rollback: restores byte-identical files (5.0 back), the result names the new row', is_array( $r ) && $v50 === tree( WP_PLUGIN_DIR . '/akismet' ) && '5.0' === version_of( AKISMET ) && '5.0' === $r['version'] && '5.1' === $r['replaced_version'] && $u1 === $r['restored_from'], $r );
$rh = row( $r['history_id'] ?? 0 );
check( 'rollback: replaced folder kept in the new row (rollback row, before 5.1, after.kept = the 5.1 files byte for byte)', 'rollback' === ( $rh['action'] ?? null ) && 'divi5-builder/plugins-rollback' === $rh['ability'] && array( 'plugin' => AKISMET, 'version' => '5.1', 'from_history_id' => $u1 ) === $rh['before'] && $v51 === tree( $rh['after']['kept']['path'] ) && false !== strpos( $rh['after']['kept']['path'], '/d5b-history/' . $rh['id'] . '-' ) && isset( $rh['after']['pages']['after'] ), $rh );
check( '... an active plugin stays active; pages and human_check; no <dir>.d5b-<time> left in the plugins folder', in_array( AKISMET, active(), true ) && true === $r['active'] && isset( $r['pages']['before'], $r['pages']['after'] ) && d5b_human_check() === $r['human_check'] && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), $r );
check( '... the copy it restored from is still there (rolling back twice works)', $v50 === tree( kept_path( $u1 ) ) );
check( 'rollback: an older copy than the newest kept for that plugin -> newer_copy_exists', 'newer_copy_exists' === code( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) ) ) && $v50 === tree( WP_PLUGIN_DIR . '/akismet' ) );

disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$GLOBALS['T_fresh']['update_plugins'] = offers( array( AKISMET => '5.2' ) );
$u2 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v52 = tree( WP_PLUGIN_DIR . '/akismet' ); $nrows = count( hrows() );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'rollback: newer copy without force_older refused (newer_copy_exists names the newer row, nothing changed)', 'newer_copy_exists' === code( $r ) && $u2 === ( edata( $r )['newer_history_id'] ?? null ) && $v52 === tree( WP_PLUGIN_DIR . '/akismet' ) && $nrows === count( hrows() ), array( code( $r ), edata( $r ) ) );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1, 'force_older' => true ) );
check( '... with force_older the older copy is restored (5.0)', is_array( $r ) && '5.0' === version_of( AKISMET ), $r );

disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
t_rm( kept_path( $u1 ) );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'rollback: missing copy -> copy_missing, nothing changed, no row', 'copy_missing' === code( $r ) && '5.1' === version_of( AKISMET ) && 1 === count( hrows() ), code( $r ) );
disk_site(); $GLOBALS['T_copy_fail'] = true;
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET, 'without_copy' => true ) )['history_id'];
check( 'rollback: an update row that kept no copy -> not_found', 'not_found' === code( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) ) ) );
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$tampered = d5b_wc_history_row( 'divi5-builder/plugins-update', 'plugin', 0, 'update', array( 'plugin' => AKISMET, 'version' => '1' ), array( 'version' => '5.1', 'kept' => array( 'path' => WP_PLUGIN_DIR . '/hello-dolly', 'bytes' => 1, 'files' => 1 ) ), 'x' );
check( 'rollback: a row whose kept path is not in the store -> copy_missing (never copies from anywhere else)', 'copy_missing' === code( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $tampered, 'force_older' => true ) ) ) && '5.1' === version_of( AKISMET ) );

disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
plug_write( AKISMET, 'Akismet', '5.3' ); // updated since, outside the abilities
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'rollback: installed newer than the row -> warnings (5.3 installed, the row left 5.1), still restored', is_array( $r ) && '5.0' === version_of( AKISMET ) && 1 === count( preg_grep( '/5\.3/', (array) ( $r['warnings'] ?? array() ) ) ), $r );

disk_site();
$acme0 = tree( WP_PLUGIN_DIR . '/acme-pro-folder' );
$ua = (int) call( 'plugins-update', array( 'plugin' => ACME ) )['history_id'];
check( 'rollback of a plugin whose folder differs from its slug: the copy is of acme-pro-folder', '3.1' === version_of( ACME ) && 1 === preg_match( '#/plugin/acme-pro-folder$#', kept_path( $ua ) ) && $acme0 === tree( kept_path( $ua ) ), kept_path( $ua ) );
$r = call( 'plugins-rollback', array( 'plugin' => ACME, 'history_id' => $ua ) );
check( '... restored into acme-pro-folder, byte for byte, and the plugin file resolves', is_array( $r ) && $acme0 === tree( WP_PLUGIN_DIR . '/acme-pro-folder' ) && '3.0' === version_of( ACME ) && ! is_dir( WP_PLUGIN_DIR . '/acme' ), $r );
disk_site();
$solo0 = tree( WP_PLUGIN_DIR . '/solo.php' );
$us = (int) call( 'plugins-update', array( 'plugin' => SOLO ) )['history_id'];
$r = call( 'plugins-rollback', array( 'plugin' => SOLO, 'history_id' => $us ) );
check( 'rollback of a single-file plugin: the file back byte for byte, the replaced file kept', is_array( $r ) && $solo0 === tree( WP_PLUGIN_DIR . '/solo.php' ) && '1.0' === version_of( SOLO ) && is_file( (string) row( $r['history_id'] )['after']['kept']['path'] ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), $r );
disk_site();
$ua = (int) call( 'plugins-update', array( 'plugin' => ACME ) )['history_id'];
// the kept copy no longer holds acme.php (a plugin that renamed its main file): the swap is undone
rename( kept_path( $ua ) . '/acme.php', kept_path( $ua ) . '/acme-main.php' );
$now = tree( WP_PLUGIN_DIR . '/acme-pro-folder' );
$r = call( 'plugins-rollback', array( 'plugin' => ACME, 'history_id' => $ua ) );
check( 'M1 a kept copy changed after it was made (a file renamed) -> copy_tampered (409), nothing touched, no row', 'copy_tampered' === code( $r ) && 1 === ( edata( $r )['added'] ?? null ) && 1 === ( edata( $r )['removed'] ?? null ) && $now === tree( WP_PLUGIN_DIR . '/acme-pro-folder' ) && 1 === count( hrows() ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), array( code( $r ), edata( $r ) ) );
// The same copy as if it had been KEPT that way (its fingerprint made from it): the plugin file does not resolve after the swap.
reseal( $ua );
$r = call( 'plugins-rollback', array( 'plugin' => ACME, 'history_id' => $ua ) );
check( 'rollback: the plugin file does not resolve after the swap -> restore_mismatch, the swap undone (3.1 files back)', 'restore_mismatch' === code( $r ) && $now === tree( WP_PLUGIN_DIR . '/acme-pro-folder' ) && '3.1' === version_of( ACME ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : $r ) );
// M8: the two rows of the undone rollback are flagged and left out of kept_copies and the newer-copy check.
$mm = array_values( array_filter( hrows(), function ( $h ) { return 'rollback' === $h['action']; } ) );
$flags = array_map( function ( $h ) { return ! empty( row( $h['id'] )['after']['restore_mismatch'] ); }, $mm );
check( 'M8 restore_mismatch: both rollback rows (the swap and its undo) are flagged after.restore_mismatch', 2 === count( $mm ) && array( true, true ) === $flags, $flags );
$kc = call( 'plugins-status' )['kept_copies'] ?? null;
check( 'M8 ... kept_copies lists the update row only (the flagged rows are not offered)', is_array( $kc ) && 1 === count( $kc ) && $ua === (int) $kc[0]['history_id'], $kc );
$r = call( 'plugins-rollback', array( 'plugin' => ACME, 'history_id' => $ua ) );
check( 'M8 ... a retry of that row is not refused newer_copy_exists (it is judged on its own copy again: restore_mismatch)', 'restore_mismatch' === code( $r ) && '3.1' === version_of( ACME ), array( code( $r ), edata( $r ) ) );
disk_site();
$ua = (int) call( 'plugins-update', array( 'plugin' => ACME ) )['history_id'];
rename( kept_path( $ua ) . '/acme.php', kept_path( $ua ) . '/acme-main.php' ); reseal( $ua );
$calls = 0;
// the undo's own swap refused (the 4th rename: its staging copy into place): nothing is flagged, as the first rollback row
// holds the only copy of the 3.1 files
$GLOBALS['T_rename_hook'] = function ( $from, $to ) use ( &$calls ) { $calls++; return 4 === $calls ? false : null; };
$r = call( 'plugins-rollback', array( 'plugin' => ACME, 'history_id' => $ua ) );
$GLOBALS['T_rename_hook'] = null;
$mm = array_values( array_filter( hrows(), function ( $h ) { return 'rollback' === $h['action'] && ! empty( row( $h['id'] )['after']['restore_mismatch'] ); } ) );
check( 'M8 when the undo itself fails, nothing is flagged: the first rollback row stays offered (it holds the files that were live)', 'restore_mismatch' === code( $r ) && array() === $mm, array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : null ) );

echo "plugins-delete\n";
disk_site();
$r = call( 'plugins-delete-preview', array( 'plugin' => AKISMET ) );
check( 'delete: active refused (preview and delete), nothing removed', 'active' === code( $r ) && 'active' === code( call( 'plugins-delete', array( 'plugin' => AKISMET, 'confirmation' => 'ABCDEFGHJKLM' ) ) ) && is_dir( WP_PLUGIN_DIR . '/akismet' ) && array() === hrows(), code( $r ) );
check( 'delete: self refused', 'self' === code( call( 'plugins-delete-preview', array( 'plugin' => SELF_FILE ) ) ) && 'self' === code( call( 'plugins-delete', array( 'plugin' => SELF_FILE, 'confirmation' => 'X' ) ) ) );
t_put( WP_PLUGIN_DIR . '/hello-dolly/uninstall.php', "<?php\nupdate_option( 'hello_uninstalled', 1 );\ndelete_option( 'hello_data' );\n" );
update_option( 'hello_data', 'keep me?' );
$hello0 = tree( WP_PLUGIN_DIR . '/hello-dolly' );
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) );
check( 'delete: preview says has_uninstall (uninstall.php), that the data goes for good, and gives a code; nothing removed', is_array( $p ) && true === $p['has_uninstall'] && false === $p['keep_data'] && '1.7.2' === $p['version'] && 1 === preg_match( '/for good/', (string) $p['note'] ) && 12 === strlen( (string) $p['confirmation'] ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === hrows(), $p );
check( '... no uninstall.php and not registered: has_uninstall false', false === ( call( 'plugins-delete-preview', array( 'plugin' => ACME ) )['has_uninstall'] ?? null ) );
update_option( 'uninstall_plugins', array( ACME => 'acme_uninstall_cb' ) );
check( '... registered in uninstall_plugins: has_uninstall true', true === ( call( 'plugins-delete-preview', array( 'plugin' => ACME ) )['has_uninstall'] ?? null ) );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) );
check( 'delete: delete runs uninstall_plugin (through delete_plugins), the folder is gone, WordPress no longer lists it', is_array( $r ) && true === $r['deleted'] && true === $r['uninstall_ran'] && array( HELLO ) === $GLOBALS['T_uninstalls'] && 1 === get_option( 'hello_uninstalled' ) && false === get_option( 'hello_data' ) && array( array( HELLO ) ) === $GLOBALS['T_delete_calls'] && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ) && null === version_of( HELLO ), $r );
$h = hlast();
check( '... the row: delete, before { plugin, version, keep_data, has_uninstall }, after.kept = the folder byte for byte', 'delete' === $h['action'] && 'divi5-builder/plugins-delete' === $h['ability'] && HELLO === $h['before']['plugin'] && '1.7.2' === $h['before']['version'] && false === $h['before']['keep_data'] && true === $h['before']['has_uninstall'] && $hello0 === tree( $h['after']['kept']['path'] ) && true === $h['after']['deleted'], $h );
check( '... the copy was taken before delete_plugins ran (it holds uninstall.php)', isset( tree( $h['after']['kept']['path'] )['uninstall.php'] ) );
$r2 = call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $h['id'] ) );
check( '... and plugins-rollback puts a deleted plugin back (files byte for byte, not active)', is_array( $r2 ) && $hello0 === tree( WP_PLUGIN_DIR . '/hello-dolly' ) && '1.7.2' === version_of( HELLO ) && false === $r2['active'], $r2 );

disk_site();
t_put( WP_PLUGIN_DIR . '/hello-dolly/uninstall.php', "<?php\nupdate_option( 'hello_uninstalled', 1 );\ndelete_option( 'hello_data' );\n" );
update_option( 'hello_data', 'keep me?' );
$GLOBALS['T_site_transients']['update_plugins'] = offers( array( HELLO => '1.8', AKISMET => '5.1' ) );
$hello0 = tree( WP_PLUGIN_DIR . '/hello-dolly' );
get_plugins(); // fill the plugins cache, as a request that listed the plugins before would have
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'keep_data' => true ) );
check( 'delete keep_data: the preview says the data stays', is_array( $p ) && true === $p['keep_data'] && true === $p['has_uninstall'] && 1 === preg_match( '/not run|stays/i', (string) $p['note'] ), $p );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'keep_data' => true, 'confirmation' => $p['confirmation'] ) );
check( 'delete: keep_data skips uninstall and removes the folder (no delete_plugins, no uninstall, folder gone, cache cleaned)', is_array( $r ) && true === $r['deleted'] && false === $r['uninstall_ran'] && array() === $GLOBALS['T_uninstalls'] && array() === $GLOBALS['T_delete_calls'] && false === get_option( 'hello_uninstalled' ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ) && null === version_of( HELLO ), $r );
check( '... the copy is kept byte for byte', $hello0 === tree( hlast()['after']['kept']['path'] ) && true === hlast()['before']['keep_data'] );
check( '... the plugin\'s data stays (hello_data untouched)', 'keep me?' === get_option( 'hello_data' ) );
$hooks = array_values( array_filter( array_map( function ( $a ) { return $a[0]; }, $GLOBALS['T_do_actions'] ?? array() ), function ( $h ) { return in_array( $h, array( 'delete_plugin', 'deleted_plugin' ), true ); } ) );
$ut = $GLOBALS['T_site_transients']['update_plugins'] ?? null;
check( '... as core: delete_plugin and deleted_plugin fire, the plugin leaves the stored update list (the others stay)', array( 'delete_plugin', 'deleted_plugin' ) === $hooks && is_object( $ut ) && ! isset( $ut->response[ HELLO ] ) && isset( $ut->response[ AKISMET ] ), array( $hooks, $ut ) );

disk_site();
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'keep_data' => true, 'confirmation' => $p['confirmation'] ) );
check( 'delete: code taken with keep_data=false refused for keep_data=true (confirmation_invalid stale), nothing removed, no row', 'confirmation_invalid' === code( $r ) && 'stale' === ( edata( $r )['reason'] ?? null ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === hrows() && array() === $GLOBALS['T_delete_calls'], array( code( $r ), edata( $r ) ) );
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'keep_data' => true ) );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) );
check( '... and the other way round (keep_data=true code for a delete that would run uninstall)', 'confirmation_invalid' === code( $r ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === $GLOBALS['T_uninstalls'] );
check( 'delete: no code -> confirmation_invalid', 'confirmation_invalid' === code( call( 'plugins-delete', array( 'plugin' => HELLO ) ) ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) );
disk_site(); $p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) ); $GLOBALS['T_copy_fail'] = true;
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) );
check( 'delete: the copy cannot be made -> copy_failed, nothing removed, no row', 'copy_failed' === code( $r ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === hrows() && array() === $GLOBALS['T_delete_calls'], code( $r ) );
disk_site( array( 'read', 'manage_options', 'update_plugins', 'activate_plugins' ) );
check( 'delete: without delete_plugins: refused', 'FORBIDDEN' === call( 'plugins-delete-preview', array( 'plugin' => HELLO ) ) && 'FORBIDDEN' === call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => 'X' ) ) );
disk_site(); $p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) );
d5b_abilities_set_category( 'plugins', true, false, 'tester' );
check( 'delete: the Plugins risky switch off: preview and delete refused, nothing removed', 'FORBIDDEN' === call( 'plugins-delete-preview', array( 'plugin' => HELLO ) ) && 'FORBIDDEN' === call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === hrows() );
disk_site( ADMIN_ALL, true ); $p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) ); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'delete: multisite, not a network administrator: preview and delete refused, nothing removed', is_array( $p ) && 'FORBIDDEN' === call( 'plugins-delete-preview', array( 'plugin' => HELLO ) ) && 'FORBIDDEN' === call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === hrows() );

echo "delete: without_copy and a site that refuses file changes\n";
disk_site();
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'without_copy' => true ) );
check( 'preview: without_copy echoed and named in the note', is_array( $p ) && true === $p['without_copy'] && 1 === preg_match( '/cannot put it back/', $p['note'] ), $p );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) );
check( 'a code taken with without_copy=true is refused for without_copy=false (stale), nothing removed', 'confirmation_invalid' === code( $r ) && 'stale' === ( edata( $r )['reason'] ?? null ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ), code( $r ) );
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'without_copy' => true ) ); $GLOBALS['T_copy_fail'] = true;
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'without_copy' => true, 'confirmation' => $p['confirmation'] ) );
check( 'without_copy: the copy fails, the plugin is deleted anyway; kept null in the result and the row; the result says it cannot be put back', is_array( $r ) && true === $r['deleted'] && null === $r['kept'] && 'copy_failed' === ( $r['copy_error'] ?? null ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ) && array_key_exists( 'kept', hlast()['after'] ) && null === hlast()['after']['kept'] && 1 === preg_match( '/cannot put/', implode( ' ', (array) ( $r['warnings'] ?? array() ) ) . ' ' . $r['note'] ), array( $r, hlast() ) );
disk_site(); $GLOBALS['T_copy_fail'] = false;
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'without_copy' => true ) );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'without_copy' => true, 'confirmation' => $p['confirmation'] ) );
check( 'without_copy: the copy is still tried, and kept when it works', is_array( $r ) && is_array( $r['kept'] ) && is_array( hlast()['after']['kept'] ) && ! isset( $r['copy_error'] ), $r );
disk_site(); $GLOBALS['T_file_mods_off'] = true;
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) );
check( 'a site that refuses file changes: the preview is refused (not_allowed) and issues no code', 'not_allowed' === code( $p ) && array() === array_filter( array_keys( $GLOBALS['T_transients'] ?? array() ), function ( $k ) { return 0 === strpos( $k, 'd5b_confirm_' ); } ), code( $p ) );
$GLOBALS['T_file_mods_off'] = false; $p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) ); $GLOBALS['T_file_mods_off'] = true;
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) );
check( '... the delete is refused BEFORE the code is used (not_allowed; the same code works once file changes are allowed again)', 'not_allowed' === code( $r ) && is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) && array() === hrows(), code( $r ) );
$GLOBALS['T_file_mods_off'] = false;
check( '... (the code was not consumed)', is_array( call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ) ) ) && ! is_dir( WP_PLUGIN_DIR . '/hello-dolly' ) );

echo "rollback: the swap\n";
disk_site();
$v50 = tree( WP_PLUGIN_DIR . '/akismet' );
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$GLOBALS['T_mrenames'] = array(); $GLOBALS['T_maintenance'] = array(); $GLOBALS['T_opcache'] = array(); $GLOBALS['T_copy_calls'] = array();
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$mr = $GLOBALS['T_mrenames'];
check( 'the staging copy is made first (.d5b-new-akismet-<6 hex>), then two renames, both in maintenance mode: live -> .d5b-old-akismet-<6 hex>, staging -> akismet', is_array( $r ) && count( $mr ) >= 2 && 1 === preg_match( '/^\.d5b-old-akismet-[0-9a-f]{6}$/', $mr[0][1] ) && 'akismet' === $mr[0][0] && 1 === preg_match( '/^\.d5b-new-akismet-[0-9a-f]{6}$/', $mr[1][0] ) && 'akismet' === $mr[1][1] && true === $mr[0][2] && true === $mr[1][2] && $mr[0][3] >= 1 && 1 === preg_match( '#/\.d5b-new-akismet-[0-9a-f]{6}$#', (string) ( $GLOBALS['T_copy_calls'][0][2] ?? '' ) ), array( $mr, $GLOBALS['T_copy_calls'] ) );
check( '... maintenance mode on then off once; the opcode cache of the folder invalidated', array( true, false ) === $GLOBALS['T_maintenance'] && false === $GLOBALS['T_in_maintenance'] && ! file_exists( ABSPATH . '.maintenance' ) && in_array( WP_PLUGIN_DIR . '/akismet', $GLOBALS['T_opcache'], true ), array( $GLOBALS['T_maintenance'], $GLOBALS['T_opcache'] ) );
// 2.4.1: the site was already in maintenance mode (another update running): the swap never removes that .maintenance file.
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
@mkdir( ABSPATH, 0777, true ); file_put_contents( ABSPATH . '.maintenance', '<?php $upgrading = 1700000000; ?>' );
$GLOBALS['T_maintenance'] = array(); $GLOBALS['T_mrenames'] = array();
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'a .maintenance file that was there before the swap is left as it was (not rewritten, not removed); the swap still ran', is_array( $r ) && '<?php $upgrading = 1700000000; ?>' === @file_get_contents( ABSPATH . '.maintenance' ) && ! in_array( false, $GLOBALS['T_maintenance'], true ) && 2 <= count( $GLOBALS['T_mrenames'] ), array( $GLOBALS['T_maintenance'], is_wp_error( $r ) ? $r->get_error_message() : 'ok' ) );
@unlink( ABSPATH . '.maintenance' );
d5b_maint_filesystem();
check( 'd5b_maint_maintenance: on writes .maintenance, off removes the one it wrote', true === d5b_maint_maintenance( true ) && file_exists( ABSPATH . '.maintenance' ) && true === d5b_maint_maintenance( false ) && ! file_exists( ABSPATH . '.maintenance' ) );
file_put_contents( ABSPATH . '.maintenance', 'theirs' );
check( '... a file that was there first: on leaves it, off leaves it', true === d5b_maint_maintenance( true ) && 'theirs' === @file_get_contents( ABSPATH . '.maintenance' ) && true === d5b_maint_maintenance( false ) && 'theirs' === @file_get_contents( ABSPATH . '.maintenance' ) );
@unlink( ABSPATH . '.maintenance' );
check( '... an off without an on of this request removes nothing', ( function () { file_put_contents( ABSPATH . '.maintenance', 'x' ); unset( $GLOBALS['d5b_maint_mm_created'] ); d5b_maint_maintenance( false ); $ok = file_exists( ABSPATH . '.maintenance' ); @unlink( ABSPATH . '.maintenance' ); return $ok; } )() );

// Fix round 1 S1: a staging or aside folder of that name is already there (a request that died): refused, nothing touched.
foreach ( array( 'new', 'old' ) as $which ) {
	disk_site();
	$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
	$v51 = tree( WP_PLUGIN_DIR . '/akismet' ); $nrows = count( hrows() );
	$GLOBALS['d5b_maint_seams']['hex'] = function ( $n ) { return 'abc123'; };
	$pre = WP_PLUGIN_DIR . '/.d5b-' . $which . '-akismet-abc123';
	@mkdir( $pre, 0777, true ); file_put_contents( $pre . '/left.txt', 'a leftover of a request that died' );
	$GLOBALS['T_mrenames'] = array(); $GLOBALS['T_maintenance'] = array(); $GLOBALS['T_copy_calls'] = array();
	$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
	unset( $GLOBALS['d5b_maint_seams']['hex'] );
	check( "S1 a .d5b-$which- folder of the chosen name already there: copy_failed \"nothing was changed\" before any copy, rename or maintenance mode; that folder and its file untouched, live 5.1, no new row", 'copy_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'nothing was changed' ) && 'a leftover of a request that died' === @file_get_contents( $pre . '/left.txt' ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && $nrows === count( hrows() ) && array() === $GLOBALS['T_mrenames'] && array() === $GLOBALS['T_maintenance'] && array() === $GLOBALS['T_copy_calls'], array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : $r, $GLOBALS['T_mrenames'] ) );
	@unlink( $pre . '/left.txt' ); @rmdir( $pre );
}

disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' ); $nrows = count( hrows() ); $GLOBALS['T_maintenance'] = array();
$GLOBALS['T_copy_fail_to_re'] = '#/\.d5b-new-#';
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'swap: the copy-in fails -> copy_failed "nothing was changed": the live folder untouched (5.1 byte for byte), no dot folder left, no new row, no maintenance mode', 'copy_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'nothing was changed' ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ) && $nrows === count( hrows() ) && array() === $GLOBALS['T_maintenance'], array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : $r ) );
$GLOBALS['T_fs_delete_fail_re'] = '#/\.d5b-new-#'; unset( $GLOBALS['T_copy_re_done'] );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$left = array_values( preg_grep( '/^\.d5b-new-akismet-/', scandir( WP_PLUGIN_DIR ) ) );
check( 'swap: the copy-in fails AND the part-made copy cannot be removed -> the message names the leftover, never "nothing was changed"; the live folder untouched; WordPress does not list the leftover', 'copy_failed' === code( $r ) && 1 === count( $left ) && false !== strpos( $r->get_error_message(), $left[0] ) && false === strpos( $r->get_error_message(), 'nothing was changed' ) && false === strpos( $r->get_error_message(), 'as it was' ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && 5 === count( get_plugins() ), array( is_wp_error( $r ) ? $r->get_error_message() : $r, $left ) );

disk_site();
$v50 = tree( WP_PLUGIN_DIR . '/akismet' );
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' );
$GLOBALS['T_rename_refuse_re'] = '#/\.d5b-old-#'; $GLOBALS['T_fs_delete_fail_re'] = '#/\.d5b-old-akismet-[0-9a-f]{6}/changelog\.txt$#';
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$left = array_values( preg_grep( '/^\.d5b-old-akismet-/', scandir( WP_PLUGIN_DIR ) ) );
check( 'swap: the move into the store is refused (rename) and the leftover cannot be removed whole: the store holds a whole copy (kept), the warning names the leftover, live is 5.0', is_array( $r ) && $v50 === tree( WP_PLUGIN_DIR . '/akismet' ) && is_array( row( $r['history_id'] )['after']['kept'] ) && $v51 === tree( row( $r['history_id'] )['after']['kept']['path'] ) && 1 === count( $left ) && 1 === count( preg_grep( '/' . preg_quote( $left[0], '/' ) . '.*by hand/', (array) ( $r['warnings'] ?? array() ) ) ) && 5 === count( get_plugins() ), array( $r, $left ) );
disk_site();
$v50 = tree( WP_PLUGIN_DIR . '/akismet' );
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' );
$GLOBALS['T_rename_refuse_re'] = '#/\.d5b-old-#'; $GLOBALS['T_copy_fail_to_re'] = '#/d5b-history/#';
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$left = array_values( preg_grep( '/^\.d5b-old-akismet-/', scandir( WP_PLUGIN_DIR ) ) );
$store_dirs = array_values( preg_grep( '/^' . (int) ( $r['history_id'] ?? 0 ) . '-/', (array) @scandir( $T_BASE . '/uploads/d5b-history' ) ) );
check( 'swap: the move into the store fails whole (rename refused, copy fails): kept null, the replaced files stay WHOLE in the leftover, which the warning names as the only copy; live is 5.0', is_array( $r ) && null === $r['kept'] && null === row( $r['history_id'] )['after']['kept'] && 1 === count( $left ) && $v51 === tree( WP_PLUGIN_DIR . '/' . $left[0] ) && array() === $store_dirs && 1 === count( preg_grep( '/' . preg_quote( $left[0], '/' ) . '.*only copy/', (array) ( $r['warnings'] ?? array() ) ) ) && $v50 === tree( WP_PLUGIN_DIR . '/akismet' ), array( $r, $left, $store_dirs ) );
$fr = row( $r['history_id'] ?? 0 );
check( 'F1b ... the completed rollback row still names the dot folder holding the only copy (aside), staging null, kept null', is_array( $fr ) && $left[0] === ( $fr['after']['aside'] ?? null ) && array_key_exists( 'staging', $fr['after'] ) && null === $fr['after']['staging'] && null === $fr['after']['kept'] && '5.0' === ( $fr['after']['version'] ?? null ), $fr['after'] ?? null );

function t_big( $path, $bytes ) { if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0777, true ); } $h = fopen( $path, 'w' ); ftruncate( $h, $bytes ); fclose( $h ); clearstatcache(); }
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' ); $nrows = count( hrows() );
$filler = $T_BASE . '/uploads/d5b-history/999999-' . str_repeat( 'a', 32 ) . '/filler.bin';
t_big( $filler, 2147483648 - d5b_history_store_bytes() - 10 );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'restore: the store would pass 2 GB with the replaced files -> store_full, live untouched, no row', 'store_full' === code( $r ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && $nrows === count( hrows() ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), code( $r ) );
@unlink( $filler ); clearstatcache();
t_big( WP_PLUGIN_DIR . '/akismet/huge.bin', 536870913 );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'restore: the files being replaced are over 512 MB -> copy_too_big, live untouched, no row', 'copy_too_big' === code( $r ) && file_exists( WP_PLUGIN_DIR . '/akismet/huge.bin' ) && '5.1' === version_of( AKISMET ) && $nrows === count( hrows() ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), code( $r ) );
@unlink( WP_PLUGIN_DIR . '/akismet/huge.bin' ); clearstatcache();
check( 'restore: a folder name that is not one -> invalid_input', 'invalid_input' === code( d5b_maint_restore( 'plugin', '../x', $u1 ) ) && 'invalid_input' === code( d5b_maint_restore( 'plugin', '.d5b-old-akismet-1', $u1 ) ) && 'invalid_input' === code( d5b_maint_restore( 'kernel', 'akismet', $u1 ) ) );

echo "rollback: a deleted plugin\n";
disk_site();
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'keep_data' => true ) );
$d = call( 'plugins-delete', array( 'plugin' => HELLO, 'keep_data' => true, 'confirmation' => $p['confirmation'] ) );
$nrows = count( hrows() );
$GLOBALS['T_copy_fail_to_re'] = '#/\.d5b-new-#'; $GLOBALS['T_fs_delete_fail_re'] = '#/\.d5b-new-#';
$r = call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $d['history_id'] ) );
$left = array_values( preg_grep( '/^\.d5b-new-hello-dolly-/', scandir( WP_PLUGIN_DIR ) ) );
check( 'restore of a deleted plugin, the copy-in fails and the half copy cannot be removed: the message names it and never says "as it was" or "nothing was changed"; still not installed, not listed', 'copy_failed' === code( $r ) && 1 === count( $left ) && false !== strpos( $r->get_error_message(), $left[0] ) && false === strpos( $r->get_error_message(), 'as it was' ) && false === strpos( $r->get_error_message(), 'nothing was changed' ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ) && null === version_of( HELLO ) && $nrows === count( hrows() ), array( is_wp_error( $r ) ? $r->get_error_message() : $r, $left ) );
t_rm( WP_PLUGIN_DIR . '/' . $left[0] ); $GLOBALS['T_fs_delete_fail_re'] = ''; unset( $GLOBALS['T_copy_re_done'] );
$r = call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $d['history_id'] ) );
check( '... when the half copy is removed: "nothing was changed", no leftover, still not installed', 'copy_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'nothing was changed' ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ), is_wp_error( $r ) ? $r->get_error_message() : $r );
$GLOBALS['T_copy_fail_to_re'] = '';
t_set_caps( array( 'read', 'manage_options', 'update_plugins', 'activate_plugins', 'delete_plugins' ) );
$r = call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $d['history_id'] ) );
check( 'restoring a deleted plugin is an install: without install_plugins it is refused (forbidden), nothing put back', 'forbidden' === code( $r ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ), code( $r ) );
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
check( '... while an update row restore needs update_plugins only', is_array( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) ) ) && '5.0' === version_of( AKISMET ) );
t_set_caps( ADMIN_ALL );
check( '... with install_plugins it is put back', is_array( call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $d['history_id'] ) ) ) && '1.7.2' === version_of( HELLO ) );

echo "a linked plugin: without_copy never lifts the refusal\n";
const LINKP = 'linkp/linkp.php';
function linked_site() {
	global $T_BASE;
	disk_site();
	t_put( $T_BASE . '/outside-linkp/linkp.php', "<?php\n/*\nPlugin Name: Linkp\nVersion: 1.0\n*/\n" );
	t_put( $T_BASE . '/outside-linkp/data.txt', 'what the link points to' );
	$ok = t_link_dir( $T_BASE . '/outside-linkp', WP_PLUGIN_DIR . '/linkp' );
	wp_clean_plugins_cache( false );
	$GLOBALS['T_fresh']['update_plugins'] = offers( array( AKISMET => '5.1', LINKP => '1.1' ) );
	return $ok;
}
$ok = linked_site(); $out0 = tree( $T_BASE . '/outside-linkp' );
$r = call( 'plugins-update', array( 'plugin' => LINKP, 'without_copy' => true ) );
check( 'update a plugin whose folder is a link, without_copy: linked (409); no upgrade, no row, what it points to untouched', $ok && '1.0' === version_of( LINKP ) && 'linked' === code( $r ) && array() === $GLOBALS['T_upgrade_calls'] && array() === hrows() && $out0 === tree( $T_BASE . '/outside-linkp' ) && 409 === d5b_ability_http_status( 'linked' ), array( $ok, code( $r ) ) );
$p = call( 'plugins-delete-preview', array( 'plugin' => LINKP, 'without_copy' => true ) );
$r = call( 'plugins-delete', array( 'plugin' => LINKP, 'without_copy' => true, 'confirmation' => $p['confirmation'] ?? '' ) );
check( 'delete it, without_copy: linked; delete_plugins never called, no row, the link and what it points to untouched', 'linked' === code( $r ) && array() === $GLOBALS['T_delete_calls'] && array() === hrows() && file_exists( WP_PLUGIN_DIR . '/linkp/linkp.php' ) && $out0 === tree( $T_BASE . '/outside-linkp' ), array( code( $p ), code( $r ) ) );
$p = call( 'plugins-delete-preview', array( 'plugin' => LINKP, 'keep_data' => true, 'without_copy' => true ) );
$r = call( 'plugins-delete', array( 'plugin' => LINKP, 'keep_data' => true, 'without_copy' => true, 'confirmation' => $p['confirmation'] ?? '' ) );
check( '... with keep_data too: linked, nothing removed', 'linked' === code( $r ) && $out0 === tree( $T_BASE . '/outside-linkp' ) && array() === hrows(), code( $r ) );

// The three codes without_copy does lift.
disk_site();
t_big( WP_PLUGIN_DIR . '/akismet/huge.bin', 536870913 );
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'without_copy' => true ) );
check( 'without_copy still lifts copy_too_big (update proceeds, kept null, copy_error)', is_array( $r ) && true === $r['updated'] && null === $r['kept'] && 'copy_too_big' === ( $r['copy_error'] ?? null ), $r );
t_rm( WP_PLUGIN_DIR . '/akismet/huge.bin' );
disk_site();
$filler = $T_BASE . '/uploads/d5b-history/999999-' . str_repeat( 'a', 32 ) . '/filler.bin';
t_big( $filler, 2147483648 - 10 );
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'without_copy' => true ) );
check( '... and store_full on an update', is_array( $r ) && true === $r['updated'] && 'store_full' === ( $r['copy_error'] ?? null ), $r );
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO, 'without_copy' => true ) );
$r = call( 'plugins-delete', array( 'plugin' => HELLO, 'without_copy' => true, 'confirmation' => $p['confirmation'] ) );
check( '... and store_full on a delete', is_array( $r ) && true === $r['deleted'] && 'store_full' === ( $r['copy_error'] ?? null ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ), $r );
@unlink( $filler ); clearstatcache();

echo "rollback_on_failure with a full store\n";
// How big the store is after one update of akismet, and how big the 5.1 folder is (measured on a fresh site).
disk_site();
call( 'plugins-update', array( 'plugin' => AKISMET ) );
$after_one = d5b_history_store_bytes(); $s51 = d5b_maint_tree_size( WP_PLUGIN_DIR . '/akismet' )['bytes'];
disk_site(); $GLOBALS['T_breaks_at'] = array( AKISMET => '5.1' );
$v50 = tree( WP_PLUGIN_DIR . '/akismet' );
$filler = $T_BASE . '/uploads/d5b-history/999999-' . str_repeat( 'a', 32 ) . '/filler.bin';
t_big( $filler, 2147483648 - $after_one - $s51 + 1 ); // room for the 5.0 copy, not for the 5.1 files after it
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'rollback_on_failure' => true ) );
$rh = row( $r['rollback_history_id'] ?? 0 );
check( 'the store has room for the copy of 5.0 but not for the broken 5.1: the site is restored anyway (5.0 byte for byte), the rollback row keeps null, a warning says the new version was not kept', is_array( $r ) && is_array( $r['kept'] ) && true === $r['rolled_back'] && $v50 === tree( WP_PLUGIN_DIR . '/akismet' ) && is_array( $rh ) && array_key_exists( 'kept', $rh['after'] ) && null === $rh['after']['kept'] && 1 === count( preg_grep( '/not kept.*store is full/', (array) ( $r['warnings'] ?? array() ) ) ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ), array( $r['warnings'] ?? null, $rh['after'] ?? null ) );
// By hand, the same full store refuses: break 5.0 into 5.1 again by an update without the automatic restore.
$GLOBALS['T_breaks_at'] = array();
$nrows = count( hrows() );
$u2 = call( 'plugins-update', array( 'plugin' => AKISMET, 'without_copy' => true ) );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => (int) $r['history_id'], 'force_older' => true ) );
check( '... a rollback asked by hand is still refused for the full store (store_full), live untouched', is_array( $u2 ) && 'store_full' === code( $r ) && '5.1' === version_of( AKISMET ), array( code( $r ), $u2['copy_error'] ?? null ) );
@unlink( $filler ); clearstatcache();
$ok = linked_site();
$u = d5b_wc_history_row( 'divi5-builder/plugins-update', 'plugin', 0, 'update', array( 'plugin' => LINKP, 'version' => '1.0' ), array( 'running' => true ), 'x' );
$kp = $T_BASE . '/uploads/d5b-history/' . $u . '-' . str_repeat( 'b', 32 ) . '/plugin/linkp';
t_put( $kp . '/linkp.php', "<?php\n/*\nPlugin Name: Linkp\nVersion: 0.9\n*/\n" );
d5b_history_set_after( $u, array( 'version' => '1.0', 'kept' => array( 'path' => $kp, 'bytes' => 1, 'files' => 1 ) ) );
check( '... an automatic restore still refuses a live folder that is a link (linked), whatever keep_optional says', $ok && 'linked' === code( d5b_maint_restore( 'plugin', 'linkp', $u, array( 'keep_optional' => true ) ) ) && $out0 === tree( $T_BASE . '/outside-linkp' ) );

echo "rollback: the renames of the swap\n";
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' ); $nrows = count( hrows() );
$GLOBALS['T_rename_refuse_re'] = '#/plugins/akismet$#'; $GLOBALS['T_maintenance'] = array();
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'the first rename (live -> .d5b-old) refused: copy_failed, live untouched (5.1 byte for byte), staging removed, row deleted, maintenance off', 'copy_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'nothing was changed' ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ) && $nrows === count( hrows() ) && array( true, false ) === $GLOBALS['T_maintenance'], array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : $r ) );
$GLOBALS['T_rename_refuse_re'] = '#/\.d5b-new-#'; $GLOBALS['T_maintenance'] = array();
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'the second rename (staging -> live) refused, the put-back works: copy_failed, live back as it was (5.1 byte for byte), no dot folder, row deleted', 'copy_failed' === code( $r ) && false !== strpos( $r->get_error_message(), 'nothing was changed' ) && $v51 === tree( WP_PLUGIN_DIR . '/akismet' ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ) && $nrows === count( hrows() ) && array( true, false ) === $GLOBALS['T_maintenance'], array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : $r ) );
$GLOBALS['T_rename_refuse_re'] = '#/\.d5b-(new|old)-#';
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$left = array_values( preg_grep( '/^\.d5b-old-akismet-/', scandir( WP_PLUGIN_DIR ) ) );
check( 'the second rename AND the put-back refused: the live folder is missing, its files whole in .d5b-old-*, the message names it (no "nothing was changed"), staging removed', 'copy_failed' === code( $r ) && ! file_exists( WP_PLUGIN_DIR . '/akismet' ) && 1 === count( $left ) && $v51 === tree( WP_PLUGIN_DIR . '/' . $left[0] ) && false !== strpos( $r->get_error_message(), $left[0] ) && false === strpos( $r->get_error_message(), 'nothing was changed' ) && array() === preg_grep( '/^\.d5b-new-/', scandir( WP_PLUGIN_DIR ) ), array( is_wp_error( $r ) ? $r->get_error_message() : $r, $left ) );
$sr = row( edata( $r )['history_id'] ?? 0 );
check( 'F1a ... its rollback row is kept and completed as failed: running false, a plain failed reason, aside = the dot folder, staging null; the error names the row', is_array( $sr ) && 'rollback' === $sr['action'] && false === ( $sr['after']['running'] ?? null ) && '' !== (string) ( $sr['after']['failed'] ?? '' ) && $left[0] === ( $sr['after']['aside'] ?? null ) && array_key_exists( 'staging', $sr['after'] ) && null === $sr['after']['staging'] && false !== strpos( $r->get_error_message(), 'History row ' . $sr['id'] ), array( edata( $r ), $sr ) );
$GLOBALS['T_rename_refuse_re'] = '';

echo "fix wave M1: a kept copy changed by the plugin's own uninstall routine\n";
disk_site();
update_option( 'active_plugins', array( SELF_FILE ) );
// The uninstall routine writes a file into the kept copy (anything that can write in uploads can): never put in place.
t_put( WP_PLUGIN_DIR . '/hello-dolly/uninstall.php', '<?php foreach ( glob( "' . $T_BASE . '/uploads/d5b-history/*/plugin/hello-dolly", GLOB_ONLYDIR ) as $d ) { file_put_contents( $d . "/planted.php", "<?php // planted" ); }' );
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) );
$d = call( 'plugins-delete', array( 'plugin' => HELLO, 'confirmation' => $p['confirmation'] ?? '' ) );
check( 'M1 the delete answer says the files come back from the kept copy only if it is unchanged', is_array( $d ) && false !== strpos( (string) $d['note'], 'from the kept copy if it is unchanged' ) && is_file( kept_path( $d['history_id'] ) . '/planted.php' ), $d );
$nrows = count( hrows() );
$r = call( 'plugins-rollback', array( 'plugin' => HELLO, 'history_id' => $d['history_id'] ) );
check( 'M1 ... the rollback refuses copy_tampered (1 added): the planted file never reaches wp-content/plugins, no row', 'copy_tampered' === code( $r ) && 1 === ( edata( $r )['added'] ?? null ) && ! file_exists( WP_PLUGIN_DIR . '/hello-dolly' ) && $nrows === count( hrows() ), array( code( $r ), edata( $r ) ) );
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
file_put_contents( kept_path( $u1 ) . '/readme.txt', 'akismet readme 5.0 CHANGED' );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
check( 'M1 a file of the kept copy changed -> copy_tampered (1 changed), 5.1 stays', 'copy_tampered' === code( $r ) && 1 === ( edata( $r )['changed'] ?? null ) && '5.1' === version_of( AKISMET ), array( code( $r ), edata( $r ) ) );
file_put_contents( kept_path( $u1 ) . '/readme.txt', 'akismet readme 5.0' );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$rk = row( $r['history_id'] ?? 0 )['after']['kept'] ?? null;
check( 'M1 put back as it was: restores; the rollback row\'s own copy of the 5.1 files carries its fingerprint too', is_array( $r ) && '5.0' === version_of( AKISMET ) && is_array( $rk ) && 1 === preg_match( '/^[0-9a-f]{40}$/', (string) ( $rk['manifest_sha1'] ?? '' ) ) && true === d5b_maint_manifest_check( $rk['path'], $rk ), array( $r, $rk ) );
$g = d5b_history_public_side( row( $u1 )['after'] );
check( 'M1 the fingerprint is not in what the history reads show (kept: bytes, files)', array( 'bytes', 'files' ) === array_keys( (array) $g['kept'] ) );

echo "fix wave M2: one update or rollback of an item at a time\n";
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$v51 = tree( WP_PLUGIN_DIR . '/akismet' );
$inner = null; $mid = null; $calls = 0;
$GLOBALS['T_rename_hook'] = function ( $from, $to ) use ( &$inner, &$mid, &$calls, $u1 ) {
	if ( 1 !== ++$calls ) { return null; }
	$mid = hlast(); // the rollback row, read between the renames
	// Another request (none of this request's memory) asks for the same rollback, and for an update, meanwhile.
	$saved = $GLOBALS['d5b_maint_locks'] ?? array(); $GLOBALS['d5b_maint_locks'] = array();
	$n0 = count( hrows() ); $dots0 = preg_grep( '/^\.d5b-/', scandir( WP_PLUGIN_DIR ) );
	$inner = array( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1, 'force_older' => true ) ), call( 'plugins-update', array( 'plugin' => AKISMET ) ), $n0 === count( hrows() ) && $dots0 === preg_grep( '/^\.d5b-/', scandir( WP_PLUGIN_DIR ) ) );
	$GLOBALS['d5b_maint_locks'] = $saved;
	return null;
};
$GLOBALS['T_fresh']['update_plugins'] = offers( array( AKISMET => '5.2' ) );
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$GLOBALS['T_rename_hook'] = null;
check( 'M2 a second rollback and an update of the same plugin while the first rollback is between its renames: busy (409), nothing touched, no row', is_array( $inner ) && 'busy' === code( $inner[0] ) && 'busy' === code( $inner[1] ) && true === $inner[2] && 409 === d5b_ability_http_status( 'busy' ), array( code( $inner[0] ?? null ), code( $inner[1] ?? null ), $inner[2] ?? null ) );
check( 'M2 ... the first rollback finished normally (5.0 back, no dot folder), and the lock is gone', is_array( $r ) && '5.0' === version_of( AKISMET ) && array() === preg_grep( '/\.d5b-/', scandir( WP_PLUGIN_DIR ) ) && '' === d5b_maint_lock_fresh( d5b_maint_item_lock( 'plugin', 'akismet' ) ), $r );
check( 'M2 the rollback row written before the renames names both folders (staging, aside), names only', is_array( $mid ) && 'rollback' === $mid['action'] && true === ( $mid['after']['running'] ?? null ) && 1 === preg_match( '/^\.d5b-new-akismet-[0-9a-f]{6}$/', (string) ( $mid['after']['staging'] ?? '' ) ) && 1 === preg_match( '/^\.d5b-old-akismet-[0-9a-f]{6}$/', (string) ( $mid['after']['aside'] ?? '' ) ), $mid['after'] ?? null );
check( 'M2 another plugin is not held up by that lock', d5b_maint_item_lock( 'plugin', 'akismet' ) !== d5b_maint_item_lock( 'plugin', 'acme-pro-folder' ) );
// The item lock holds 600 s (the engine's own time limit): read while the rollback swaps.
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$held = null;
$GLOBALS['T_rename_hook'] = function ( $from, $to ) use ( &$held ) { if ( null === $held ) { $held = d5b_maint_lock_fresh( d5b_maint_item_lock( 'plugin', 'akismet' ) ); } return null; };
call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$GLOBALS['T_rename_hook'] = null;
check( 'M2 the item lock runs 600 s: its value expires at now + 600', 600 === d5b_maint_item_lock_ttl() && is_string( $held ) && (int) substr( (string) strrchr( $held, '|' ), 1 ) === (int) floor( $GLOBALS['T_clock'] ) + 600, $held );
// The lock held by a request that died: past its time it is taken over.
disk_site();
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$GLOBALS['T_options'][ d5b_maint_item_lock( 'plugin', 'akismet' ) ] = str_repeat( 'e', 16 ) . '|' . ( (int) $GLOBALS['T_clock'] + 30 ); // another request's row
check( 'M2 a lock another request holds: plugins-rollback busy, nothing changed', 'busy' === code( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) ) ) && '5.1' === version_of( AKISMET ) );
$GLOBALS['T_clock'] += 31;
check( 'M2 ... once it has run out, it is taken over and the rollback runs', is_array( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) ) ) && '5.0' === version_of( AKISMET ) );
// The automatic restore of rollback_on_failure runs under the update's own lock (a nested take), and releases it.
disk_site(); $GLOBALS['T_breaks_at'] = array( AKISMET => '5.1' );
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'rollback_on_failure' => true ) );
check( 'M2 rollback_on_failure: the restore inside the update is not refused by the update\'s own lock; the lock is released after', is_array( $r ) && true === $r['rolled_back'] && '5.0' === version_of( AKISMET ) && '' === d5b_maint_lock_fresh( d5b_maint_item_lock( 'plugin', 'akismet' ) ) && array() === ( $GLOBALS['d5b_maint_locks'] ?? array() ), $r );

echo "final review F2 (2.4.1): on a network the item lock is one row of the MAIN site's options table\n";
disk_site( ADMIN_ALL, true ); switch_to_blog( 2 ); // the call comes in on site 2 of the network
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$ln = d5b_maint_item_lock( 'plugin', 'akismet' ); $where = null;
$GLOBALS['T_rename_hook'] = function ( $from, $to ) use ( &$where, $ln ) { if ( null === $where ) { $where = array( 'main' => is_string( $GLOBALS['T_options'][ $ln ] ?? null ), 'site' => isset( $GLOBALS['T_blogs'][2][ $ln ] ), 'fresh' => '' !== d5b_maint_lock_fresh( $ln, true ) ); } return null; };
$r = call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u1 ) );
$GLOBALS['T_rename_hook'] = null;
check( 'F2 network: the rollback on site 2 holds the lock as a row of the main site\'s table (wp_options), not site 2\'s; released after', is_array( $r ) && array( 'main' => true, 'site' => false, 'fresh' => true ) === $where && ! isset( $GLOBALS['T_options'][ $ln ] ), array( $where, code( $r ) ) );
$u2 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
// Another site of the network holds it: the main site's row, written by another request.
$GLOBALS['T_options'][ $ln ] = str_repeat( 'd', 16 ) . '|' . ( (int) $GLOBALS['T_clock'] + 60 );
check( 'F2 ... a lock another site of the network holds: busy, nothing changed', 'busy' === code( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u2 ) ) ) && '5.1' === version_of( AKISMET ) );
unset( $GLOBALS['T_options'][ $ln ] );
$GLOBALS['T_blogs'][2][ $ln ] = str_repeat( 'd', 16 ) . '|' . ( (int) $GLOBALS['T_clock'] + 60 );
check( 'F2 ... a per-site row of that name does not hold the network up', is_array( call( 'plugins-rollback', array( 'plugin' => AKISMET, 'history_id' => $u2 ) ) ) && '5.0' === version_of( AKISMET ) );
unset( $GLOBALS['T_blogs'][2][ $ln ] ); restore_current_blog();

echo "final review F4: two plugins in one folder\n";
disk_site();
plug_write( 'akismet/akismet-extra.php', 'Akismet Extra', '1.0' );
$p = call( 'plugins-delete-preview', array( 'plugin' => HELLO ) );
check( 'F4 preview of a plugin alone in its folder: siblings empty', is_array( $p ) && array() === $p['siblings'], $p );
update_option( 'active_plugins', array( SELF_FILE ) ); // akismet inactive; its sibling active next
update_option( 'active_plugins', array( SELF_FILE, 'akismet/akismet-extra.php' ) );
$p = call( 'plugins-delete-preview', array( 'plugin' => AKISMET ) );
$d = call( 'plugins-delete', array( 'plugin' => AKISMET, 'confirmation' => 'ABCDEFGHJKLM' ) );
check( 'F4 an active sibling in the same folder refuses preview and delete (active, naming it); nothing removed', 'active' === code( $p ) && 'akismet/akismet-extra.php' === ( edata( $p )['sibling'] ?? null ) && 'active' === code( $d ) && is_file( WP_PLUGIN_DIR . '/akismet/akismet-extra.php' ) && is_file( WP_PLUGIN_DIR . '/akismet/akismet.php' ) && array() === hrows(), array( code( $p ), code( $d ) ) );
update_option( 'active_plugins', array( SELF_FILE ) );
$p = call( 'plugins-delete-preview', array( 'plugin' => AKISMET ) );
check( 'F4 inactive sibling: the preview lists it in siblings and the note says it goes too', is_array( $p ) && array( 'akismet/akismet-extra.php' ) === $p['siblings'] && false !== strpos( (string) $p['note'], 'akismet/akismet-extra.php' ), $p );
$d = call( 'plugins-delete', array( 'plugin' => AKISMET, 'confirmation' => $p['confirmation'] ?? '' ) );
check( 'F4 ... and the delete then removes the folder with both files (as previewed)', is_array( $d ) && true === $d['deleted'] && ! file_exists( WP_PLUGIN_DIR . '/akismet' ), $d );

echo "fix wave M6: the installed folder must be the slug asked for\n";
disk_site();
$GLOBALS['T_plugins_api']['contact-form-7'] = (object) array( 'slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'version' => '5.9', 'download_link' => 'https://downloads.wordpress.org/plugin/some-other-plugin.1.0.zip' );
$GLOBALS['T_install_adds'] = array( 'some-other-plugin/some-other-plugin.php' => array( 'Name' => 'Some Other', 'Version' => '1.0' ) );
$r = call( 'plugins-install', array( 'slug' => 'contact-form-7' ) );
check( 'M6 plugins_api (a filter) gives another plugin\'s zip for the slug: bad_source, what was installed is removed again', 'bad_source' === code( $r ) && false !== strpos( $r->get_error_message(), '"some-other-plugin"' ) && false !== strpos( $r->get_error_message(), 'removed again' ) && ! file_exists( WP_PLUGIN_DIR . '/some-other-plugin' ) && ! isset( get_plugins()['some-other-plugin/some-other-plugin.php'] ), array( code( $r ), is_wp_error( $r ) ? $r->get_error_message() : $r ) );
$h = hlast();
check( 'M6 ... the install row says failed, what was installed instead, and that it was removed', 'install' === $h['action'] && true === ( $h['after']['failed'] ?? null ) && 'some-other-plugin' === ( $h['after']['installed_instead'] ?? null ) && true === ( $h['after']['removed'] ?? null ), $h['after'] );
$GLOBALS['T_plugins_api']['contact-form-7']->download_link = 'https://downloads.wordpress.org/plugin/contact-form-7.5.9.zip';
$GLOBALS['T_install_adds'] = array( 'contact-form-7/wp-contact-form-7.php' => array( 'Name' => 'Contact Form 7', 'Version' => '5.9' ) );
$r = call( 'plugins-install', array( 'slug' => 'contact-form-7' ) );
check( 'M6 ... the right zip installs as before', is_array( $r ) && 'contact-form-7/wp-contact-form-7.php' === $r['plugin'] && '5.9' === $r['version'] && is_file( WP_PLUGIN_DIR . '/contact-form-7/wp-contact-form-7.php' ), $r );

echo "fix wave M7: pages not re-checked in time are warned about\n";
disk_site();
foreach ( array( 601, 602, 603 ) as $pid ) { t_add_post( array( 'ID' => $pid, 'post_type' => 'page' ) ); }
// Once akismet 5.1 is installed every page answers in 25 s: the after round (60 s) cannot fetch them all.
$GLOBALS['T_http_fn'] = function ( $url, $args ) { if ( '5.1' === ( get_plugins()[ AKISMET ]['Version'] ?? '' ) ) { $GLOBALS['T_clock'] += 25; } return array( 'code' => 200, 'body' => page() ); };
$r = call( 'plugins-update', array( 'plugin' => AKISMET, 'rollback_on_failure' => true ) );
$w = (array) ( $r['warnings'] ?? array() );
check( 'M7 an update whose after round runs out of time: pages.skipped lists the pages, a warning names them, another says rollback_on_failure could not judge them', is_array( $r ) && 1 <= count( $r['pages']['skipped'] ?? array() ) && 1 === count( preg_grep( '/^\d+ page\(s\) were not re-checked in time: https:\/\/x\.example\//', $w ) ) && 1 === count( preg_grep( '/rollback_on_failure could not judge/', $w ) ) && false === $r['rolled_back'], is_array( $r ) ? array( $r['pages']['skipped'] ?? null, $w ) : code( $r ) );
serve_site();

echo "fix wave M10: a link to this plugin's own folder\n";
disk_site();
$linked = t_link_dir( WP_PLUGIN_DIR . '/divi5-builder', WP_PLUGIN_DIR . '/d5b-alias' );
wp_clean_plugins_cache( false );
$listed = isset( get_plugins()['d5b-alias/divi5-builder.php'] );
$p = call( 'plugins-delete-preview', array( 'plugin' => 'd5b-alias/divi5-builder.php' ) );
check( 'M10 plugins-delete-preview of an alias folder of this plugin: self, no code issued', $linked && $listed && 'self' === code( $p ), array( $linked, $listed, code( $p ) ) );
$a = call( 'plugins-activate', array( 'plugin' => 'd5b-alias/divi5-builder.php' ) );
check( 'M10 plugins-activate of it: self, not activated', 'self' === code( $a ) && ! in_array( 'd5b-alias/divi5-builder.php', active(), true ), code( $a ) );
check( 'M10 plugins-update and plugins-deactivate of it: self', 'self' === code( call( 'plugins-update', array( 'plugin' => 'd5b-alias/divi5-builder.php' ) ) ) && 'self' === code( call( 'plugins-deactivate', array( 'plugin' => 'd5b-alias/divi5-builder.php' ) ) ) );
t_rm( WP_PLUGIN_DIR . '/d5b-alias' );

echo "history reads: a kept copy shows as its size\n";
disk_site(); d5b_abilities_set_category( 'history', true, false, 'tester' );
$u1 = (int) call( 'plugins-update', array( 'plugin' => AKISMET ) )['history_id'];
$g = call( 'history-change-get', array( 'id' => $u1 ) );
check( 'history-change-get: kept is { bytes, files } only; neither the store path nor the <id>-<hex> folder name', is_array( $g ) && array( 'bytes', 'files' ) === array_keys( (array) ( $g['after']['kept'] ?? array() ) ) && 3 === $g['after']['kept']['files'] && false === strpos( json_encode( $g ), 'd5b-history' ) && false === strpos( json_encode( $g ), $u1 . '-' ), $g );
$l = call( 'history-changes-list', array() );
check( 'history-changes-list: no path either', is_array( $l ) && false === strpos( json_encode( $l ), 'd5b-history' ) );
$GLOBALS['T_plugins_disk'] = false;

echo "no secrets\n";
site(); cf7();
$all = json_encode( array( call( 'plugins-install', array( 'slug' => 'contact-form-7' ) ), call( 'plugins-activate', array( 'plugin' => 'contact-form-7/wp-contact-form-7.php' ) ), hrows() ) );
check( 'no password, key or token in the results or the rows', 1 !== preg_match( '/password|api_key|token|secret/i', $all ), $all );

echo "\n" . ( $failed ? "$failed of $n FAILED\n" : "all $n passed\n" );
exit( $failed ? 1 : 0 );
