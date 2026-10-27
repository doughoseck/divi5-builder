<?php
/**
 * Switches, the gate and the status route (#37).
 *
 *   php scripts/plugin-switches-test.php
 *
 * Loads the real inc/ and parts/ files with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The two promises under test: a switched-off part does not EXIST (no route, so nothing to refuse),
 * and nothing reachable over REST can flip a switch.
 */
define( 'D5B_VERSION', '2.0.0' );
require __DIR__ . '/plugin-test-stubs.php';
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/gate.php';
foreach ( array( 'page-building', 'media-audit', 'media-clean-up', 'updates' ) as $f ) { require $dir . '/parts/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const SWITCHED = array( 'media_audit', 'media_clean_up', 'updates' );
$NS = array( 'page_building' => 'divi5-builder/v1', 'media_audit' => 'wp-media-audit/v1', 'media_clean_up' => 'wp-media-quarantine/v1', 'updates' => 'wp-site-updates/v1' );

// Every register_setting call seen in any scenario, kept across t_reset().
$SEEN_SETTINGS = array();
function fresh() { global $SEEN_SETTINGS; $SEEN_SETTINGS = array_merge( $SEEN_SETTINGS, $GLOBALS['T_settings'] ); t_reset(); }
function routes_in( $ns ) { $o = array(); foreach ( $GLOBALS['T_routes'] as $k => $_ ) { if ( 0 === strpos( explode( ' ', $k, 2 )[1], $ns . '/' ) ) { $o[] = $k; } } sort( $o ); return $o; }
function direct( $fn ) { fresh(); $fn(); $r = array_keys( $GLOBALS['T_routes'] ); sort( $r ); fresh(); return $r; }
function switch_on( array $parts ) { foreach ( $parts as $p ) { d5b_switch_set( $p, true, 'admin' ); } }

// ---- switch off means no routes
echo "switch off means no routes\n";
fresh();
d5b_gate();
$all = array_keys( $GLOBALS['T_routes'] );
check( 'all three switches default to off', array( false, false, false ) === array_values( array_map( 'd5b_switch_is_on', SWITCHED ) ) );
check( 'page building routes are registered', count( routes_in( 'divi5-builder/v1' ) ) > 10 );
check( '/status is registered', in_array( 'GET divi5-builder/v1/status', $all, true ) );
foreach ( array( 'media_audit', 'media_clean_up', 'updates' ) as $p ) { check( "no $p route exists while off", array() === routes_in( $NS[ $p ] ), routes_in( $NS[ $p ] ) ); }
check( 'switch-less part reports itself on', true === d5b_switch_is_on( 'page_building' ) );

// ---- switch on means all its routes
echo "switch on means all its routes\n";
$fn = array( 'media_audit' => 'd5b_routes_media_audit', 'media_clean_up' => 'd5b_routes_media_clean_up', 'updates' => 'd5b_routes_updates' );
foreach ( SWITCHED as $p ) {
	$expect = direct( $fn[ $p ] );
	fresh(); switch_on( array( $p ) );
	$GLOBALS['T_writes'] = array();
	d5b_gate();
	check( "$p on: same routes as the part registers", $expect === routes_in( $NS[ $p ] ) && count( $expect ) > 0, array( $expect, routes_in( $NS[ $p ] ) ) );
	foreach ( SWITCHED as $q ) { if ( $q !== $p ) { check( "$p on: nothing of $q appears", array() === routes_in( $NS[ $q ] ) ); } }
}

// ---- no REST path flips a switch
echo "no REST path flips a switch\n";
fresh(); switch_on( SWITCHED );
$before = serialize( array( $GLOBALS['T_options'], $GLOBALS['T_site_options'] ) );
$GLOBALS['T_writes'] = array();
d5b_gate();
$admin = array( 'manage_options', 'manage_network_options', 'update_plugins', 'update_themes', 'update_core', 'edit_posts', 'edit_others_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'unfiltered_html', 'edit_theme_options', 'edit_css', 'upload_files' );
t_set_caps( $admin );
$attacks = array(
	array(),
	array( 'name' => 'd5b_switches', 'option' => 'd5b_switches', 'value' => array( 'updates' => array( 'on' => false ) ) ),
	array( 'name' => 'd5b_switches', 'option' => 'd5b_switches', 'switches' => array( 'media_audit' => false ), 'key' => 'd5b_switches', 'updates' => false ),
);
$called = 0; $errors = 0; set_error_handler( function () { return true; } ); // the routes meet a bare WordPress here; warnings are expected
foreach ( $GLOBALS['T_routes'] as $key => $r ) {
	foreach ( $attacks as $params ) {
		foreach ( array( 'permission_callback', 'callback' ) as $cb ) {
			if ( ! isset( $r[ $cb ] ) || ! is_callable( $r[ $cb ] ) ) { continue; }
			try { call_user_func( $r[ $cb ], new T_Request( $params ) ); $called++; } catch ( Throwable $e ) { $errors++; }
		}
	}
}
restore_error_handler();
$after = serialize( array( $GLOBALS['T_options'], $GLOBALS['T_site_options'] ) );
$names = array_map( function ( $w ) { return $w[1]; }, array_filter( $GLOBALS['T_writes'], function ( $w ) { return in_array( $w[0], array( 'update_option', 'update_site_option', 'add_option', 'add_site_option', 'delete_option' ), true ); } ) );
check( 'every recorded route was exercised as administrator', $called > 100, "$called calls, $errors errors" );
check( 'no route wrote d5b_switches (update/add/delete option calls)', ! in_array( 'd5b_switches', $names, true ), $names );
check( 'the stored switches are byte-identical afterwards', $before === $after );
fresh();
d5b_switches(); d5b_gate(); d5b_switch_set( 'updates', false, 'x' ); d5b_switches(); d5b_risk_note( 'updates' );
fresh();
check( 'no register_setting call names d5b_switches', ! in_array( 'd5b_switches', array_column( $SEEN_SETTINGS, 1 ), true ), $SEEN_SETTINGS );
check( 'no register_setting call opens anything to REST', array() === array_filter( $SEEN_SETTINGS, function ( $s ) { return ! empty( $s[2]['show_in_rest'] ); } ) );

// ---- page_building cannot be switched
echo "page_building cannot be switched\n";
$threw = false;
try { d5b_switch_set( 'page_building', false, 'x' ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'switching page_building throws InvalidArgumentException', $threw );
$threw = false;
try { d5b_switch_set( 'nonsense', true, 'x' ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'switching an unknown part throws too', $threw );
check( 'a thrown switch wrote nothing', array() === $GLOBALS['T_writes'] );

// ---- storage
echo "storage\n";
fresh();
d5b_switch_set( 'updates', true, 'doug' );
$s = get_option( 'd5b_switches' );
check( 'single site: add_option, not autoloaded', array( array( 'add_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'] && 'no' === $GLOBALS['T_autoload']['d5b_switches'], array( $GLOBALS['T_writes'], $GLOBALS['T_autoload'] ) );
check( 'stored shape: on, changed_by, ISO-8601 UTC changed_at', true === $s['updates']['on'] && 'doug' === $s['updates']['changed_by'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $s['updates']['changed_at'] ), $s );
check( 'page_building is not stored', ! isset( $s['page_building'] ) );
check( 'missing parts read as off', false === d5b_switches()['media_audit']['on'] && true === d5b_switches()['updates']['on'] );
$GLOBALS['T_writes'] = array();
d5b_switch_set( 'updates', false, 'doug' );
check( 'second write uses update_option', array( array( 'update_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'] && false === d5b_switch_is_on( 'updates' ) );
update_option( 'd5b_switches', array( 'media_audit' => array( 'on' => 'yes' ) ) );
check( 'only a real true counts as on', false === d5b_switch_is_on( 'media_audit' ) );
update_option( 'd5b_switches', 'garbage' );
check( 'a garbage option reads as all off', false === d5b_switch_is_on( 'updates' ) && is_array( d5b_switches() ) );

// ---- multisite
echo "multisite stores a site option\n";
fresh(); t_set_multisite( true );
d5b_switch_set( 'media_audit', true, 'root' );
check( 'writes update_site_option, never update_option', array( array( 'update_site_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'], $GLOBALS['T_writes'] );
check( 'reads it back as a site option', true === d5b_switch_is_on( 'media_audit' ) && array() === $GLOBALS['T_options'] );
check( 'risk note: network sentence on clean-up', ' On a network, check the sites that share these files.' === substr( d5b_risk_note( 'media_clean_up' ), -strlen( ' On a network, check the sites that share these files.' ) ) );
check( 'risk note: network sentence on updates', ' On a multisite network an update applies to every site in it at once.' === substr( d5b_risk_note( 'updates' ), -strlen( ' On a multisite network an update applies to every site in it at once.' ) ) );
check( 'risk note: audit has no network sentence', 0 === strpos( d5b_risk_note( 'media_audit' ), 'Lists every uploaded file' ) && '.' === substr( d5b_risk_note( 'media_audit' ), -1 ) && false === strpos( d5b_risk_note( 'media_audit' ), 'network' ) );
t_set_multisite( false );
check( 'risk notes on a single site are the plain copy', 'Updates plugins, themes and WordPress itself. An update can break a site. Take a backup first, and check your key pages after every update.' === d5b_risk_note( 'updates' ) && '' === d5b_risk_note( 'page_building' ) );
check( 'clean-up note verbatim', 'Moves uploaded files out of your uploads folder into a quarantine folder. Anything moved disappears from the pages that use it until it is moved back. Take a backup first, and check the site after every move.' === d5b_risk_note( 'media_clean_up' ) );

// ---- status route
echo "status route answers\n";
fresh(); d5b_gate();
$st = $GLOBALS['T_routes']['GET divi5-builder/v1/status'];
t_set_caps( array( 'edit_posts' ) );
check( 'edit_posts user may read status', true === call_user_func( $st['permission_callback'] ) );
$out = call_user_func( $st['callback'], new T_Request() );
check( 'status shape', array( 'version', 'divi5', 'switches', 'stood_aside' ) === array_keys( $out ) && '2.0.0' === $out['version'] && false === $out['divi5']
	&& array( 'media_audit' => false, 'media_clean_up' => false, 'updates' => false ) === $out['switches'] && array() === $out['stood_aside'], $out );
t_set_caps( array( 'read' ) );
check( 'user without edit_posts is refused', false === call_user_func( $st['permission_callback'] ) );
fresh(); switch_on( array( 'updates' ) ); d5b_gate();
check( 'status shows a switch that is on', true === call_user_func( $GLOBALS['T_routes']['GET divi5-builder/v1/status']['callback'], new T_Request() )['switches']['updates'] );

// ---- stand-aside: an old copy still active
echo "stand aside for an old copy\n";
fresh(); switch_on( SWITCHED );
$GLOBALS['T_server_routes'] = array( '/wp-site-updates/v1/info' => array(), '/divi5-builder/v1/version' => array() );
d5b_gate();
check( 'old route present: updates not registered, noted as route', array() === routes_in( 'wp-site-updates/v1' ) && 'route' === $GLOBALS['d5b_stood_aside']['updates'] );
check( 'old route present: page building not registered, noted as route', array() === routes_in( 'divi5-builder/v1/version' ) && 'route' === $GLOBALS['d5b_stood_aside']['page_building'] );
check( 'the other switched-on parts still register', count( routes_in( 'wp-media-audit/v1' ) ) > 0 && count( routes_in( 'wp-media-quarantine/v1' ) ) > 0 );
check( '/status still registers and reports the stand-aside', 'route' === call_user_func( $GLOBALS['T_routes']['GET divi5-builder/v1/status']['callback'], new T_Request() )['stood_aside']['updates'] );
// Constants are permanent, so this goes last.
fresh(); switch_on( SWITCHED );
define( 'WPMA_VERSION', '1.1.2' ); define( 'ET_BUILDER_5_DIR', '/x/' );
d5b_gate();
check( 'old constant defined: media audit not registered, noted as constant', array() === routes_in( 'wp-media-audit/v1' ) && 'constant' === $GLOBALS['d5b_stood_aside']['media_audit'] );
check( 'an unrelated part is unaffected by that constant', count( routes_in( 'wp-site-updates/v1' ) ) > 0 && ! isset( $GLOBALS['d5b_stood_aside']['updates'] ) );
check( 'status reports divi5 when Divi 5 is there', true === call_user_func( $GLOBALS['T_routes']['GET divi5-builder/v1/status']['callback'], new T_Request() )['divi5'] );

echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
