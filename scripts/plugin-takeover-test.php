<?php
/**
 * Takeover from the old plugins (#37).
 *
 *   php scripts/plugin-takeover-test.php
 *
 * Loads the real inc/ and parts/ files with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: the old plugins that can be deactivated are (and only those, matched by whole
 * name), an old copy that cannot be (must-use file, code snippet, plugin switched on again) makes the new
 * code stand aside with a notice that names it, and "switch off but still live" is never shown as quiet.
 *
 * Constants are permanent in a PHP process, so the scenarios that define an old plugin's constant go last,
 * in order: WPMQ_VERSION, then WPSU_VERSION, then WPMA_VERSION.
 */
define( 'D5B_VERSION', '2.0.0' );
require __DIR__ . '/plugin-test-stubs.php';
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/gate.php';
require $dir . '/inc/takeover.php';
foreach ( array( 'page-building', 'media-audit', 'media-clean-up', 'updates' ) as $f ) { require $dir . '/parts/' . $f . '.php'; }

// Subprocess mode: deactivate_plugins and D5B_FILE are both missing. Nothing may fatal.
if ( getenv( 'T_NO_DEACTIVATE' ) ) {
	update_option( 'active_plugins', array( 'wp-site-updates/wp-site-updates.php' ) );
	t_set_caps( array( 'activate_plugins' ) );
	d5b_takeover_mu_admin_init();
	$r = d5b_takeover_deactivate( false );
	echo 'SUBPROCESS-OK ' . json_encode( array( $r, $GLOBALS['T_writes'], function_exists( 'deactivate_plugins' ) ) ) . "\n";
	exit( 0 );
}

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function fresh() { t_reset(); }
function routes_in( $ns ) { $o = array(); foreach ( $GLOBALS['T_routes'] as $k => $_ ) { if ( 0 === strpos( explode( ' ', $k, 2 )[1], $ns . '/' ) ) { $o[] = $k; } } sort( $o ); return $o; }
function texts( $lines ) { return array_column( $lines, 'text' ); }
function has_line( $text ) { return in_array( $text, texts( d5b_takeover_lines() ), true ); }
function red_lines() { return array_values( array_filter( d5b_takeover_lines(), function ( $l ) { return $l['red']; } ) ); }
function writes_of( $name ) { return count( array_filter( $GLOBALS['T_writes'], function ( $w ) use ( $name ) { return $w[1] === $name; } ) ); }

$mu = sys_get_temp_dir() . '/d5b-mu-' . getmypid();
if ( ! is_dir( $mu ) ) { mkdir( $mu ); }
define( 'WPMU_PLUGIN_DIR', $mu );
define( 'D5B_FILE', $mu . '/divi5-builder.php' );

// ---- activation
echo "activation deactivates old normal plugins\n";
fresh();
$active = array( 'wp-site-updates/wp-site-updates.php', 'divi5-builder-rest.php', 'akismet/akismet.php',
	'my-wp-site-updates-helper/x.php', 'wp-site-updates-pro/wp-site-updates-pro.php', 'wp-media-audit/other-file.php' );
update_option( 'active_plugins', $active );
$done = d5b_takeover_deactivate( false );
$old  = array( 'divi5-builder-rest.php', 'wp-site-updates/wp-site-updates.php' );
check( 'returns exactly the two old ones', $old === $done, $done );
check( 'deactivate_plugins got them, silent, not network', array( array( $old, true, false ) ) === $GLOBALS['T_deactivate_calls'], $GLOBALS['T_deactivate_calls'] );
check( 'unrelated plugin is not passed', ! in_array( 'akismet/akismet.php', $GLOBALS['T_deactivated'][0], true ) );
check( 'a plugin whose name merely contains an old slug is not passed', ! in_array( 'my-wp-site-updates-helper/x.php', $GLOBALS['T_deactivated'][0], true ) && ! in_array( 'wp-site-updates-pro/wp-site-updates-pro.php', $GLOBALS['T_deactivated'][0], true ) && ! in_array( 'wp-media-audit/other-file.php', $GLOBALS['T_deactivated'][0], true ), $GLOBALS['T_deactivated'] );
$rec = get_option( 'd5b_takeover' );
check( 'd5b_takeover saved: done_at ISO-8601 UTC and the list', is_array( $rec ) && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $rec['done_at'] ) && $old === $rec['deactivated'], $rec );
check( 'saved through add_option, not autoloaded', 1 === writes_of( 'd5b_takeover' ) && in_array( array( 'add_option', 'd5b_takeover' ), $GLOBALS['T_writes'], true ) && 'no' === $GLOBALS['T_autoload']['d5b_takeover'], array( $GLOBALS['T_writes'], $GLOBALS['T_autoload'] ) );
update_option( 'active_plugins', array_values( array_diff( $active, $old ) ) ); // what WordPress would have done
check( 'the replaced-plugins line lists them', has_line( 'Replaced by Divi 5 Builder, you can delete them: divi5-builder-rest.php, wp-site-updates/wp-site-updates.php' ), texts( d5b_takeover_lines() ) );
fresh();
d5b_takeover_on_activation( false );
check( 'with nothing old active, it saves the record and deactivates nothing', array() === $GLOBALS['T_deactivate_calls'] && array() === get_option( 'd5b_takeover' )['deactivated'] );

echo "network activation\n";
fresh(); t_set_multisite( true );
update_option( 'active_plugins', array( 'wp-media-audit/wp-media-audit.php' ) );
update_site_option( 'active_sitewide_plugins', array( 'wp-media-quarantine/wp-media-quarantine.php' => 1, 'akismet/akismet.php' => 1 ) );
$done = d5b_takeover_on_activation( false );
check( 'not network-wide: the network-active old plugin is left alone', array( array( array( 'wp-media-audit/wp-media-audit.php' ), true, false ) ) === $GLOBALS['T_deactivate_calls'], $GLOBALS['T_deactivate_calls'] );
fresh(); t_set_multisite( true );
update_option( 'active_plugins', array( 'wp-media-audit/wp-media-audit.php' ) );
update_site_option( 'active_sitewide_plugins', array( 'wp-media-quarantine/wp-media-quarantine.php' => 1, 'akismet/akismet.php' => 1 ) );
d5b_takeover_on_activation( true );
check( 'network-wide: network-active old plugin goes, with $network = true, the unrelated one stays', in_array( array( array( 'wp-media-quarantine/wp-media-quarantine.php' ), true, true ), $GLOBALS['T_deactivate_calls'], true ) && in_array( array( array( 'wp-media-audit/wp-media-audit.php' ), true, false ), $GLOBALS['T_deactivate_calls'], true ) && 2 === count( $GLOBALS['T_deactivate_calls'] ), $GLOBALS['T_deactivate_calls'] );
check( 'on multisite the record is a site option', null !== get_site_option( 'd5b_takeover', null ) && null === get_option( 'd5b_takeover', null ) );
t_set_multisite( false );

// ---- must-use mode
echo "mu mode takes over once on admin_init\n";
check( 'a file under the must-use folder is recognised', d5b_takeover_is_mu( $mu . '/divi5-builder.php' ) && d5b_takeover_is_mu( str_replace( '/', '\\', $mu ) . '\\x\\y.php' ) );
check( 'a normal plugin path, or a sibling folder with the same start, is not', ! d5b_takeover_is_mu( '/var/www/wp-content/plugins/divi5-builder/divi5-builder.php' ) && ! d5b_takeover_is_mu( $mu . '-extra/divi5-builder.php' ) );
fresh();
update_option( 'active_plugins', array( 'wp-site-updates/wp-site-updates.php' ) );
$GLOBALS['T_writes'] = array();
d5b_takeover_mu_admin_init();
check( 'a user who cannot activate plugins triggers nothing', array() === $GLOBALS['T_deactivate_calls'] && null === get_option( 'd5b_takeover', null ) );
t_set_caps( array( 'activate_plugins' ) );
d5b_takeover_mu_admin_init();
check( 'first admin_init: runs once', 1 === count( $GLOBALS['T_deactivate_calls'] ) && is_array( get_option( 'd5b_takeover' ) ), $GLOBALS['T_deactivate_calls'] );
$w = count( $GLOBALS['T_writes'] );
d5b_takeover_mu_admin_init(); d5b_takeover_mu_admin_init();
check( 'second call: nothing more happens', 1 === count( $GLOBALS['T_deactivate_calls'] ) && $w === count( $GLOBALS['T_writes'] ) );
t_set_caps( array() );

echo "no deactivate_plugins and no D5B_FILE: never fatal\n";
$php = escapeshellarg( PHP_BINARY ); $self = escapeshellarg( __FILE__ );
$orig_dir = getenv( 'D5B_PLUGIN_DIR' );
putenv( 'T_NO_DEACTIVATE=1' ); putenv( 'D5B_PLUGIN_DIR=' . $dir );
$out = shell_exec( $php . ' ' . $self . ' 2>&1' );
putenv( 'T_NO_DEACTIVATE' ); putenv( false === $orig_dir ? 'D5B_PLUGIN_DIR' : 'D5B_PLUGIN_DIR=' . $orig_dir );
check( 'returns an empty list, saves no takeover record, does not fatal', is_string( $out ) && 0 === strpos( $out, 'SUBPROCESS-OK [[],' ) && false === strpos( $out, 'd5b_takeover' ) && false !== strpos( $out, ',false]' ), $out );

// ---- the gate keeps d5b_legacy_seen, quietly
echo "snippet copy detected by route\n";
fresh();
$GLOBALS['T_server_routes'] = array( '/divi5-builder/v1/version' => array() );
d5b_gate();
check( 'page building stands aside, noted as route; its routes are not registered', 'route' === $GLOBALS['d5b_stood_aside']['page_building'] && array() === routes_in( 'divi5-builder/v1/version' ), $GLOBALS['d5b_stood_aside'] );
check( 'd5b_legacy_seen lists it', array( 'page_building' ) === get_option( 'd5b_legacy_seen' ) );
check( 'the code snippet line shows', has_line( 'An older copy (perhaps a code snippet) is already providing Page building. Remove it to finish the move.' ), texts( d5b_takeover_lines() ) );
check( 'no red line for a part without a switch', array() === red_lines() );
$rep = array_values( array_filter( d5b_legacy_report(), function ( $r ) { return 'page_building' === $r['part']; } ) );
check( 'report row: other, no file, not live while off', array( array( 'part' => 'page_building', 'how' => 'other', 'file' => null, 'live_while_off' => false ) ) === $rep, $rep );
$GLOBALS['T_writes'] = array();
d5b_gate(); d5b_gate(); d5b_gate();
check( 'three more requests with the same copy write nothing', 0 === writes_of( 'd5b_legacy_seen' ), $GLOBALS['T_writes'] );
$GLOBALS['T_server_routes'] = array();
d5b_gate();
check( 'when the copy is gone the set changes, so it is written once', 1 === writes_of( 'd5b_legacy_seen' ) && array() === get_option( 'd5b_legacy_seen' ) );
d5b_gate();
check( 'and an empty set is not rewritten', 1 === writes_of( 'd5b_legacy_seen' ) );
fresh();
d5b_gate();
check( 'a fresh site with no old copy writes no d5b_legacy_seen at all', 0 === writes_of( 'd5b_legacy_seen' ) );
fresh(); t_set_multisite( true );
$GLOBALS['T_server_routes'] = array( '/divi5-builder/v1/version' => array() );
d5b_gate();
check( 'on multisite it is a site option', array( 'page_building' ) === get_site_option( 'd5b_legacy_seen' ) && null === get_option( 'd5b_legacy_seen', null ) );
t_set_multisite( false );

// ---- a must-use copy (constants from here on are permanent)
echo "legacy mu file present: part stands aside, routes once, notice names file\n";
fresh();
file_put_contents( $mu . '/wp-media-quarantine.php', "<?php\n" );
define( 'WPMQ_VERSION', '1.0.0' );
$GLOBALS['T_server_routes'] = array( '/wp-media-quarantine/v1/batches' => array() );
d5b_switch_set( 'media_clean_up', true, 'admin' );
d5b_gate();
check( 'media clean-up: not registered a second time, noted as constant', array() === routes_in( 'wp-media-quarantine/v1' ) && 'constant' === $GLOBALS['d5b_stood_aside']['media_clean_up'] );
$rep = array_values( array_filter( d5b_legacy_report(), function ( $r ) { return 'media_clean_up' === $r['part']; } ) );
check( 'report row: mu, the file, not live while off (the switch is on)', array( array( 'part' => 'media_clean_up', 'how' => 'mu', 'file' => 'wp-media-quarantine.php', 'live_while_off' => false ) ) === $rep, $rep );
check( 'a line names wp-content/mu-plugins/wp-media-quarantine.php', has_line( 'The old file wp-content/mu-plugins/wp-media-quarantine.php is still in charge of Media clean-up. Delete it to finish the move.' ), texts( d5b_takeover_lines() ) );
check( 'switch on: no red line', array() === red_lines() );
d5b_switch_set( 'media_clean_up', false, 'admin' );
check( 'switch off with the mu file still live: red line', has_line( 'Media clean-up is LIVE on this site through the old copy, even though its switch here is off.' ) && 1 === count( red_lines() ), texts( d5b_takeover_lines() ) );
unlink( $mu . '/wp-media-quarantine.php' );
$rep = array_values( array_filter( d5b_legacy_report(), function ( $r ) { return 'media_clean_up' === $r['part']; } ) );
check( 'mu file deleted but constant still defined: other', 'other' === $rep[0]['how'] && null === $rep[0]['file'], $rep );

echo "old updates plugin loaded: red line says live although switch off\n";
fresh();
define( 'WPSU_VERSION', '1.0.0' );
d5b_gate();
$red = array_column( red_lines(), 'text' );
check( 'a red line contains Updates is LIVE', in_array( 'Updates is LIVE on this site through the old copy, even though its switch here is off.', $red, true ), $red );
check( 'the red line is flagged red, the plain notice is not', ! in_array( 'An older copy (perhaps a code snippet) is already providing Updates. Remove it to finish the move.', $red, true ) && has_line( 'An older copy (perhaps a code snippet) is already providing Updates. Remove it to finish the move.' ) );
check( 'updates routes are not registered', array() === routes_in( 'wp-site-updates/v1' ) );
d5b_switch_set( 'updates', true, 'admin' );
check( 'switch turned on: the red line goes', ! in_array( 'Updates is LIVE on this site through the old copy, even though its switch here is off.', array_column( red_lines(), 'text' ), true ) );
d5b_switch_set( 'updates', false, 'admin' );

echo "old plugin reactivated later\n";
fresh();
update_option( 'd5b_takeover', array( 'done_at' => '2026-10-27T00:00:00Z', 'deactivated' => array( 'wp-media-audit/wp-media-audit.php' ) ) );
update_option( 'active_plugins', array( 'wp-media-audit/wp-media-audit.php' ) );
define( 'WPMA_VERSION', '1.1.2' );
d5b_gate();
check( 'media audit stands aside, noted as constant', 'constant' === $GLOBALS['d5b_stood_aside']['media_audit'] );
check( 'nothing is deactivated again', array() === $GLOBALS['T_deactivated'] && array() === $GLOBALS['T_deactivate_calls'] );
check( 'the "active again" line shows', has_line( 'The old plugin wp-media-audit/wp-media-audit.php is active again and is in charge of Media audit. Deactivate and delete it.' ), texts( d5b_takeover_lines() ) );
check( 'it is not also listed as safe to delete', ! in_array( true, array_map( function ( $t ) { return 0 === strpos( $t, 'Replaced by' ); }, texts( d5b_takeover_lines() ) ), true ), texts( d5b_takeover_lines() ) );
check( 'media audit switch is off, so the red line shows too', has_line( 'Media audit is LIVE on this site through the old copy, even though its switch here is off.' ) );

echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
foreach ( glob( $mu . '/*' ) ?: array() as $f ) { @unlink( $f ); }
@rmdir( $mu );
exit( $failed ? 1 : 0 );
