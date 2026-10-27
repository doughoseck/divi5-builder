<?php
/**
 * The loader, on any site (#37).
 *
 *   php scripts/plugin-anysite-test.php
 *
 * Loads plugin/divi5-builder/divi5-builder.php (the real loader, from a COPY of the folder so it can
 * also be loaded a second time from another path) with WordPress stand-ins (plugin-test-stubs.php),
 * then runs the hooks it registered the way WordPress would.
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 */
set_error_handler( function ( $no, $str ) { $GLOBALS['PHP_WARNINGS'][] = $str; return true; } );
$PHP_WARNINGS = array();
require __DIR__ . '/plugin-test-stubs.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
// rest_api_init passes the WP_REST_Server, as core does.
function fire( $hook ) { foreach ( $GLOBALS['T_actions'][ $hook ] ?? array() as $cb ) { 'rest_api_init' === $hook ? call_user_func( $cb, rest_get_server() ) : call_user_func( $cb ); } }
// The files the loader names, and the .php files really under inc/ and parts/ (both sorted).
function loader_list( $src ) { preg_match_all( "#'((?:inc|parts)/[^']+\\.php)'#", $src, $m ); sort( $m[1] ); return $m[1]; }
function disk_list( $dir ) { $o = array(); foreach ( array( 'inc', 'parts' ) as $d ) { foreach ( scandir( "$dir/$d" ) as $f ) { if ( preg_match( '/\\.php$/', $f ) ) { $o[] = "$d/$f"; } } } sort( $o ); return $o; }
function status() { return call_user_func( $GLOBALS['T_routes']['GET divi5-builder/v1/status']['callback'] ); }
function routes_in( $ns ) { $o = array(); foreach ( $GLOBALS['T_routes'] as $k => $_ ) { if ( 0 === strpos( explode( ' ', $k, 2 )[1], $ns . '/' ) ) { $o[] = $k; } } sort( $o ); return $o; }
function norm( $p ) { return str_replace( chr( 92 ), '/', $p ); }
function copy_tree( $from, $to ) {
	mkdir( $to, 0777, true );
	foreach ( scandir( $from ) as $f ) {
		if ( '.' === $f || '..' === $f ) { continue; }
		is_dir( "$from/$f" ) ? copy_tree( "$from/$f", "$to/$f" ) : copy( "$from/$f", "$to/$f" );
	}
}
function remove_tree( $d ) {
	foreach ( scandir( $d ) as $f ) { if ( '.' !== $f && '..' !== $f ) { is_dir( "$d/$f" ) ? remove_tree( "$d/$f" ) : unlink( "$d/$f" ); } }
	rmdir( $d );
}

$src  = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
$tmp  = sys_get_temp_dir() . '/d5b-anysite-' . bin2hex( random_bytes( 4 ) );
$copyA = "$tmp/mu/divi5-builder"; $copyB = "$tmp/plugins/divi5-builder";
copy_tree( $src, $copyA ); copy_tree( $src, $copyB );
$loaderA = "$copyA/divi5-builder.php"; $loaderB = "$copyB/divi5-builder.php";

// ---- the loader file itself
echo "the loader file\n";
$head = file_get_contents( $loaderA );
check( 'header: plugin name, slug version, update URI, description', false !== strpos( $head, 'Plugin Name: Divi 5 Builder' ) && 1 === preg_match( '/^\s*\*\s*Version:\s*2\.0\.0\s*$/m', $head ) && false !== strpos( $head, 'Update URI: https://github.com/doughoseck/divi5-builder' ) && false !== strpos( $head, 'Description: Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.' ) );
check( 'the header Version: equals D5B_VERSION as defined by the loader', 1 === preg_match( "/define\(\s*'D5B_VERSION',\s*'(\d+\.\d+\.\d+)'\s*\)/", $head, $m ) && 1 === preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $head, $h ) && $m[1] === $h[1], array( $m ?? null, $h ?? null ) );
check( 'the old constants are never defined by the loader', 1 !== preg_match( "/define\(\s*'(D5B_REST_VERSION|WPMA_VERSION|WPMQ_VERSION|WPSU_VERSION)'/", $head ) );
check( 'the loader lists exactly the .php files under inc/ and parts/ (no glob)', loader_list( $head ) === disk_list( $src ) && false === strpos( $head, 'glob(' ), array( loader_list( $head ), disk_list( $src ) ) );
check( 'uninstall.php exists and the loader never loads it (WordPress runs it on delete)', is_file( "$src/uninstall.php" ) && false === strpos( $head, "'uninstall.php'" ) && 1 !== preg_match( '/(require|include)(_once)?[^;]*uninstall/', $head ) );
$top = array(); foreach ( scandir( $src ) as $f ) { if ( preg_match( '/\\.php$/', $f ) ) { $top[] = $f; } }
check( 'the plugin folder holds exactly two top-level .php files: the loader and uninstall.php', array( 'divi5-builder.php', 'uninstall.php' ) === $top, $top );
check( 'header: Requires PHP 7.4 and Requires at least 6.0, the same as readme.txt', 1 === preg_match( '/^\s*\*\s*Requires PHP:\s*7\.4\s*$/m', $head ) && 1 === preg_match( '/^\s*\*\s*Requires at least:\s*6\.0\s*$/m', $head )
	&& 1 === preg_match( '/^Requires PHP:\s*7\.4\s*$/m', file_get_contents( "$src/readme.txt" ) ) && 1 === preg_match( '/^Requires at least:\s*6\.0\s*$/m', file_get_contents( "$src/readme.txt" ) ) );

// ---- no Divi
echo "no Divi: loads, status divi5 false\n";
check( 'no ET_BUILDER_5_DIR in this process', ! defined( 'ET_BUILDER_5_DIR' ) );
require $loaderA;
check( 'D5B_VERSION and D5B_FILE are defined, D5B_FILE is the loader path', defined( 'D5B_VERSION' ) && '2.0.0' === D5B_VERSION && defined( 'D5B_FILE' ) && norm( D5B_FILE ) === norm( $loaderA ), defined( 'D5B_FILE' ) ? D5B_FILE : 'unset' );
check( 'no old constant defined', ! defined( 'D5B_REST_VERSION' ) && ! defined( 'WPMA_VERSION' ) && ! defined( 'WPMQ_VERSION' ) && ! defined( 'WPSU_VERSION' ) );
check( 'loading raised no PHP warning', array() === $PHP_WARNINGS, $PHP_WARNINGS );
check( 'all the plugin functions exist after loading', function_exists( 'd5b_gate' ) && function_exists( 'd5b_settings_menu' ) && function_exists( 'd5b_takeover_on_activation' ) && function_exists( 'd5b_routes_updates' ) && function_exists( 'd5b_init_page_building' ) );
fire( 'init' );
check( 'init registered the page building meta (2 post types x 6 keys)', 12 === count( $GLOBALS['T_post_meta'] ), $GLOBALS['T_post_meta'] );
fire( 'rest_api_init' );
$st = status();
check( 'status: divi5 false, version 2.0.0', false === $st['divi5'] && '2.0.0' === $st['version'], $st );
check( 'status: the three optional parts are off', array( 'media_audit' => false, 'media_clean_up' => false, 'updates' => false ) === $st['switches'], $st['switches'] );
check( 'page building routes exist, the optional ones do not', count( routes_in( 'divi5-builder/v1' ) ) > 10 && array() === routes_in( 'wp-media-audit/v1' ) && array() === routes_in( 'wp-media-quarantine/v1' ) && array() === routes_in( 'wp-site-updates/v1' ) );
check( 'nothing stood aside, no stand-aside saved', array() === $st['stood_aside'] && null === get_option( 'd5b_legacy_seen', null ), array( $st['stood_aside'], get_option( 'd5b_legacy_seen', null ) ) );

// ---- the hooks the loader registers
echo "hooks\n";
$hooks = array_keys( $GLOBALS['T_actions'] ); sort( $hooks );
$want  = array( 'admin_init', 'admin_menu', 'admin_notices', 'admin_post_d5b_save_switches', 'init', 'network_admin_edit_d5b_save_switches', 'network_admin_menu', 'network_admin_notices', 'rest_api_init' );
check( 'exactly the expected hooks, each registered once', $want === $hooks && 1 === count( array_unique( array_map( 'count', $GLOBALS['T_actions'] ) ) ) && 1 === max( array_map( 'count', $GLOBALS['T_actions'] ) ), array( $hooks, array_map( 'count', $GLOBALS['T_actions'] ) ) );
check( 'rest_api_init runs the gate at priority 99', array( 'd5b_gate' ) === $GLOBALS['T_actions']['rest_api_init'] && array( 99 ) === $GLOBALS['T_priorities']['rest_api_init'] );
check( 'admin_menu and network_admin_menu run d5b_settings_menu', array( 'd5b_settings_menu' ) === $GLOBALS['T_actions']['admin_menu'] && array( 'd5b_settings_menu' ) === $GLOBALS['T_actions']['network_admin_menu'] );
check( 'both save hooks run d5b_settings_save', array( 'd5b_settings_save' ) === $GLOBALS['T_actions']['admin_post_d5b_save_switches'] && array( 'd5b_settings_save' ) === $GLOBALS['T_actions']['network_admin_edit_d5b_save_switches'] );
check( 'both notice hooks run d5b_admin_notice, admin_init runs the must-use takeover', array( 'd5b_admin_notice' ) === $GLOBALS['T_actions']['admin_notices'] && array( 'd5b_admin_notice' ) === $GLOBALS['T_actions']['network_admin_notices'] && array( 'd5b_takeover_mu_admin_init' ) === $GLOBALS['T_actions']['admin_init'] );
check( 'exactly one filter: rest_pre_dispatch refuses REST activation of an old plugin', array( 'rest_pre_dispatch' => array( 'd5b_refuse_legacy_rest_activation' ) ) === $GLOBALS['T_filters'] && function_exists( 'd5b_refuse_legacy_rest_activation' ), $GLOBALS['T_filters'] );
check( 'activation hook: this file, d5b_takeover_on_activation', 1 === count( $GLOBALS['T_activation'] ) && norm( $GLOBALS['T_activation'][0][0] ) === norm( $loaderA ) && 'd5b_takeover_on_activation' === $GLOBALS['T_activation'][0][1], $GLOBALS['T_activation'] );
$nopriv = array_values( array_filter( $hooks, function ( $h ) { return false !== strpos( $h, 'nopriv' ); } ) );
check( 'no *_nopriv_* hook: a logged-out visitor can reach nothing', array() === $nopriv, $nopriv );

// ---- gate: run once
echo "the gate runs once per request\n";
$before_routes = $GLOBALS['T_routes']; $before_writes = $GLOBALS['T_writes'];
// What WordPress now holds as registered: this plugin's own routes, probe routes included.
foreach ( $GLOBALS['T_routes'] as $k => $_ ) { $GLOBALS['T_server_routes']['/' . explode( ' ', $k, 2 )[1]] = array(); }
fire( 'rest_api_init' );
check( 'a second rest_api_init registers nothing new', $before_routes === $GLOBALS['T_routes'] );
check( 'a second rest_api_init writes no false stand-aside', array() === $GLOBALS['d5b_stood_aside'] && null === get_option( 'd5b_legacy_seen', null ) && $before_writes === $GLOBALS['T_writes'], array( $GLOBALS['d5b_stood_aside'], $GLOBALS['T_writes'] ) );
check( 'status after the second run still says nothing stood aside', array() === status()['stood_aside'] );
$GLOBALS['T_server_routes'] = array();
// A second WP_REST_Server in the same request (it fires rest_api_init again): it has no routes of its own yet.
echo "a new REST server gets the routes\n";
$GLOBALS['T_routes'] = array();
$s2 = t_new_server();
fire( 'rest_api_init' );
check( 'the gate runs again for a new server: page building routes and /status registered', count( routes_in( 'divi5-builder/v1' ) ) > 10 && isset( $GLOBALS['T_routes']['GET divi5-builder/v1/status'] ), array_keys( $GLOBALS['T_routes'] ) );
check( 'no false stand-aside for the new server', array() === status()['stood_aside'] && null === get_option( 'd5b_legacy_seen', null ), status()['stood_aside'] );
$GLOBALS['T_routes'] = array();
fire( 'rest_api_init' );
check( 're-firing on that same new server registers nothing', array() === $GLOBALS['T_routes'] );
t_new_request(); fire( 'rest_api_init' ); // back to a full route table for the checks below

// ---- Divi 5 present
echo "Divi 5 present: status divi5 true\n";
define( 'ET_BUILDER_5_DIR', '/x/divi5/' );
check( 'status: divi5 true', true === status()['divi5'] );

// ---- multisite
echo "multisite: switches read from the site option\n";
t_set_multisite( true );
$GLOBALS['T_site_options']['d5b_switches'] = array( 'media_audit' => array( 'on' => true, 'changed_at' => '2026-10-27T00:00:00Z', 'changed_by' => 'net' ) );
$GLOBALS['T_options']['d5b_switches']      = array( 'updates' => array( 'on' => true, 'changed_at' => '', 'changed_by' => '' ) ); // a per-site value must be ignored
unset( $GLOBALS['d5b_gate_ran'] ); $GLOBALS['T_routes'] = array();
fire( 'rest_api_init' );
check( 'media audit routes exist (network site option on)', count( routes_in( 'wp-media-audit/v1' ) ) > 0 );
check( 'updates routes do not exist (only a per-site option said on)', array() === routes_in( 'wp-site-updates/v1' ) );
check( 'status shows the network switches', array( 'media_audit' => true, 'media_clean_up' => false, 'updates' => false ) === status()['switches'], status()['switches'] );
t_set_multisite( false );

// ---- loaded twice
echo "loaded twice: second copy returns early\n";
$hooks_before = $GLOBALS['T_actions']; $act_before = $GLOBALS['T_activation']; $filters_before = $GLOBALS['T_filters']; $PHP_WARNINGS = array();
require $loaderB;
check( 'D5B_FILE stays the first copy\'s path', norm( D5B_FILE ) === norm( $loaderA ) && norm( D5B_FILE ) !== norm( $loaderB ), D5B_FILE );
check( 'the second copy registered no hook and no activation hook', $hooks_before === $GLOBALS['T_actions'] && $act_before === $GLOBALS['T_activation'] && $filters_before === $GLOBALS['T_filters'] );
check( 'the second copy raised no warning (no constant defined twice)', array() === $PHP_WARNINGS, $PHP_WARNINGS );

// ---- init is skipped when the old copy is loaded
echo "init stands aside for a loaded old copy\n";
$GLOBALS['T_post_meta'] = array();
define( 'D5B_REST_VERSION', '1.8.5' );
fire( 'init' );
check( 'with D5B_REST_VERSION defined, init registers no meta', array() === $GLOBALS['T_post_meta'], $GLOBALS['T_post_meta'] );

// ---- the must-use loader, next to a folder with another name (a renamed download, "divi5-builder-main/")
echo "must-use loader: header, and no fatal when the folder has another name\n";
$mul = __DIR__ . '/../plugin/mu-loader/divi5-builder-loader.php';
$mulsrc = file_get_contents( $mul );
check( 'it has a Plugin Name header (listed under Must-Use)', 1 === preg_match( '/^\s*\*\s*Plugin Name:\s*Divi 5 Builder \(must-use loader\)\s*$/m', $mulsrc ), substr( $mulsrc, 0, 300 ) );
mkdir( "$tmp/mu2/divi5-builder-main", 0777, true );
copy( $mul, "$tmp/mu2/divi5-builder-loader.php" );
$PHP_WARNINGS = array(); $err = '';
try { require "$tmp/mu2/divi5-builder-loader.php"; } catch ( Throwable $e ) { $err = get_class( $e ) . ': ' . $e->getMessage(); }
check( 'required with no divi5-builder/ folder next to it: no error, no warning', '' === $err && array() === $PHP_WARNINGS, array( $err, $PHP_WARNINGS ) );

remove_tree( $tmp );
echo $failed ? "\n$failed of $n FAILED\n" : "\nall $n passed\n";
exit( $failed ? 1 : 0 );
