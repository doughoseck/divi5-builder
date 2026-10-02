<?php
/*
 * site-updates-test.php: assets/wp-site-updates.php without WordPress. A small fake WordPress (installed plugins and
 * themes, update offers, upgraders that "install" by changing the fake's versions) stands in for the real one.
 *
 *   php scripts/site-updates-test.php
 *
 * Set WPSU_PLUGIN_FILE to test another copy of the plugin (used for mutation testing).
 */
$tmp = sys_get_temp_dir() . '/wpsu-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp, 0777, true );
define( 'ABSPATH', $tmp . '/' );

// ---- the fake site ----
$GLOBALS['W'] = array(
	'caps'     => array( 'update_plugins', 'update_themes', 'update_core', 'update_languages' ),
	'wp'       => '6.8.1',
	'plugins'  => array(
		'akismet/akismet.php' => array( 'Name' => 'Akismet', 'Version' => '5.3' ),
		'forms/forms.php'     => array( 'Name' => 'Forms', 'Version' => '1.9.9' ),
		'pro/pro.php'         => array( 'Name' => 'Pro Thing', 'Version' => '2.0.0' ),
		'old/old.php'         => array( 'Name' => 'Old PHP', 'Version' => '1.0' ),
		'fine/fine.php'       => array( 'Name' => 'Fine', 'Version' => '3.1' ),
		'breaks/breaks.php'   => array( 'Name' => 'Breaks', 'Version' => '1.0' ),
		'liar/liar.php'       => array( 'Name' => 'Liar', 'Version' => '1.0' ),
	),
	'active'   => array( 'akismet/akismet.php', 'pro/pro.php' ),
	'p_offers' => array(
		'akismet/akismet.php' => array( 'new_version' => '5.3.1', 'package' => 'https://x/akismet.zip' ),
		'forms/forms.php'     => array( 'new_version' => '2.0.0', 'package' => 'https://x/forms.zip', 'requires' => '6.0' ),
		'pro/pro.php'         => array( 'new_version' => '2.1.0', 'package' => '' ),
		'old/old.php'         => array( 'new_version' => '1.1', 'package' => 'https://x/old.zip', 'requires_php' => '99.0' ),
		'fine/fine.php'       => array( 'new_version' => '3.1', 'package' => 'https://x/fine.zip' ), // same version: not an update
		'breaks/breaks.php'   => array( 'new_version' => '1.1', 'package' => 'https://x/breaks.zip' ),
		'liar/liar.php'       => array( 'new_version' => '1.1', 'package' => 'https://x/liar.zip' ),
	),
	'themes'   => array( 'Divi' => array( 'Name' => 'Divi', 'Version' => '5.13.1' ), 'divi-child' => array( 'Name' => 'Divi Child', 'Version' => '1.0' ), 'twentyx' => array( 'Name' => 'Twenty X', 'Version' => '1.2' ) ),
	't_offers' => array( 'Divi' => array( 'new_version' => '5.14.0', 'package' => 'https://x/divi.zip' ), 'twentyx' => array( 'new_version' => '1.3', 'package' => 'https://x/tx.zip' ) ),
	'core'     => array( array( 'response' => 'upgrade', 'current' => '6.9' ), array( 'response' => 'upgrade', 'current' => '6.8.3' ), array( 'response' => 'latest', 'current' => '6.8.1' ) ),
	'langs'    => 2,
	'calls'    => array(), 'file_mods' => true, 'cleared' => 0, 'flushed' => 0,
);
class WP_Error { public $code; public $message; public $data; function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_message() { return $this->message; } function has_errors() { return '' !== $this->code; } }
class Req { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
class FakeTheme { private $s; function __construct( $s ) { $this->s = $s; } function exists() { return isset( $GLOBALS['W']['themes'][ $this->s ] ); } function get( $k ) { return $GLOBALS['W']['themes'][ $this->s ][ $k ]; } }
class Automatic_Upgrader_Skin { public $m = array(); function get_upgrade_messages() { return $this->m; } function get_errors() { return new WP_Error(); } }
class Plugin_Upgrader { private $skin; function __construct( $s ) { $this->skin = $s; }
	function bulk_upgrade( $items ) { $out = array(); foreach ( $items as $i ) { $GLOBALS['W']['calls'][] = 'plugin:' . $i; $this->skin->m[] = '<b>Updating</b> ' . $i;
		if ( 'breaks/breaks.php' === $i ) { $out[ $i ] = new WP_Error( 'x', 'Could not copy file.' ); continue; }
		if ( 'liar/liar.php' !== $i ) { $GLOBALS['W']['plugins'][ $i ]['Version'] = $GLOBALS['W']['p_offers'][ $i ]['new_version']; unset( $GLOBALS['W']['p_offers'][ $i ] ); }
		$out[ $i ] = array( 'destination' => 'x' ); } return $out; } }
class Theme_Upgrader { function __construct( $s ) {} function bulk_upgrade( $items ) { $out = array(); foreach ( $items as $i ) { $GLOBALS['W']['calls'][] = 'theme:' . $i; $GLOBALS['W']['themes'][ $i ]['Version'] = $GLOBALS['W']['t_offers'][ $i ]['new_version']; unset( $GLOBALS['W']['t_offers'][ $i ] ); $out[ $i ] = true; } return $out; } }
class Core_Upgrader { function __construct( $s ) {} function upgrade( $u ) { $GLOBALS['W']['calls'][] = 'core:' . $u->current; $GLOBALS['W']['wp'] = $u->current; return $u->current; } }
class Language_Pack_Upgrader { function __construct( $s ) {} function bulk_upgrade() { $GLOBALS['W']['calls'][] = 'translations'; $GLOBALS['W']['langs'] = 0; return array( true ); } }
class ET_Core_PageResource { static function remove_static_resources( $a, $b ) { $GLOBALS['W']['cleared']++; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $hook, $fn ) { $fn(); }
function register_rest_route( $ns, $path, $def ) { $GLOBALS['routes'][ $path ] = $def; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['W']['caps'], true ); }
function wp_is_file_mod_allowed( $c ) { return $GLOBALS['W']['file_mods']; }
function get_site_transient( $k ) { $t = new stdClass(); $t->last_checked = 1790000000; $t->response = array(); foreach ( 'update_plugins' === $k ? $GLOBALS['W']['p_offers'] : $GLOBALS['W']['t_offers'] as $f => $o ) { $t->response[ $f ] = 'update_plugins' === $k ? (object) $o : $o; } return $t; }
function wp_clean_update_cache() { $GLOBALS['W']['calls'][] = 'forget'; }
function wp_version_check() { $GLOBALS['W']['calls'][] = 'check'; } function wp_update_plugins() {} function wp_update_themes() {}
function get_bloginfo( $k ) { return $GLOBALS['W']['wp']; }
function get_plugins() { return $GLOBALS['W']['plugins']; }
function is_plugin_active( $f ) { return in_array( $f, $GLOBALS['W']['active'], true ); }
function wp_get_themes() { $o = array(); foreach ( $GLOBALS['W']['themes'] as $s => $t ) { $o[ $s ] = new FakeTheme( $s ); } return $o; }
function wp_get_theme( $s ) { return new FakeTheme( $s ); }
function get_stylesheet() { return 'divi-child'; } function get_template() { return 'Divi'; }
function get_core_updates() { return array_map( function ( $o ) { return (object) $o; }, $GLOBALS['W']['core'] ); }
function wp_get_translation_updates() { return array_fill( 0, $GLOBALS['W']['langs'], 1 ); }
function home_url( $p ) { return 'https://x.example' . $p; } function is_multisite() { return false; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_clean_plugins_cache( $x ) {} function wp_clean_themes_cache( $x ) {}
function wp_cache_flush() { $GLOBALS['W']['flushed']++; }
$GLOBALS['routes'] = array();
require getenv( 'WPSU_PLUGIN_FILE' ) ?: __DIR__ . '/../assets/wp-site-updates.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function call( $path, $params = array() ) { return call_user_func( $GLOBALS['routes'][ $path ]['callback'], new Req( $params ) ); }
function row( $rows, $item ) { foreach ( $rows as $r ) { if ( $r['item'] === $item ) { return $r; } } return null; }

echo "--- who may\n";
check( 'four routes', 4 === count( $GLOBALS['routes'] ), array_keys( $GLOBALS['routes'] ) );
check( 'all open to a user who may update plugins', ! in_array( false, array_map( function ( $r ) { return call_user_func( $r['permission_callback'] ); }, $GLOBALS['routes'] ), true ) );
$keep = $GLOBALS['W']['caps']; $GLOBALS['W']['caps'] = array();
check( 'and closed to anyone else', ! in_array( true, array_map( function ( $r ) { return call_user_func( $r['permission_callback'] ); }, $GLOBALS['routes'] ), true ) );
$GLOBALS['W']['caps'] = array( 'update_plugins' );
$r = call( '/update', array( 'type' => 'theme', 'item' => 'Divi' ) );
check( 'a user who may update plugins but not themes cannot update a theme', is_wp_error( $r ) && 'wpsu_forbidden' === $r->code && '5.13.1' === $GLOBALS['W']['themes']['Divi']['Version'], $r );
$r = call( '/update', array( 'type' => 'core', 'item' => '6.8.3' ) );
check( '... nor WordPress', is_wp_error( $r ) && 'wpsu_forbidden' === $r->code && '6.8.1' === $GLOBALS['W']['wp'] );
$GLOBALS['W']['caps'] = $keep;

echo "--- how big a step\n";
check( 'patch, minor, major', 'patch' === wpsu_kind( '5.3', '5.3.1' ) && 'minor' === wpsu_kind( '5.13.1', '5.14.0' ) && 'major' === wpsu_kind( '1.9.9', '2.0.0' ) );
check( 'a suffix is ignored (5.0.0-beta.2)', 'patch' === wpsu_kind( '5.0.0-beta.1', '5.0.1' ) );
check( 'WordPress: 6.8.1 -> 6.8.3 is minor, 6.8.1 -> 6.9 is major', 'minor' === wpsu_core_kind( '6.8.1', '6.8.3' ) && 'major' === wpsu_core_kind( '6.8.1', '6.9' ) );

echo "--- status\n";
$s = call( '/status', array() );
check( 'every installed plugin is listed, by name', 7 === count( $s['plugins'] ) && 'Akismet' === $s['plugins'][0]['name'] );
$a = row( $s['plugins'], 'akismet/akismet.php' );
check( 'an update: versions, size of the step, active', '5.3' === $a['version'] && '5.3.1' === $a['new_version'] && 'patch' === $a['kind'] && true === $a['active'] && '' === $a['blocked'], $a );
check( 'a major step is called major', 'major' === row( $s['plugins'], 'forms/forms.php' )['kind'] );
check( 'no download offered (premium without licence) is blocked, with the reason', false !== strpos( row( $s['plugins'], 'pro/pro.php' )['blocked'], 'no download' ) );
check( 'needs a newer PHP: blocked, with both versions', false !== strpos( row( $s['plugins'], 'old/old.php' )['blocked'], 'needs PHP 99.0' ) );
check( 'an "offer" of the installed version is not an update', null === row( $s['plugins'], 'fine/fine.php' )['new_version'] );
check( 'themes: parent and child both count as in use, the spare one does not', true === row( $s['themes'], 'Divi' )['active'] && true === row( $s['themes'], 'divi-child' )['active'] && false === row( $s['themes'], 'twentyx' )['active'] );
check( 'WordPress: newest offer, called major, and every offer listed in order', '6.9' === $s['wordpress']['new_version'] && 'major' === $s['wordpress']['kind'] && array( '6.8.3', '6.9' ) === $s['wordpress']['offered'], $s['wordpress'] );
check( 'translations counted', 2 === $s['translations'] );
check( 'status alone does not ask the update servers', ! in_array( 'check', $GLOBALS['W']['calls'], true ) );
call( '/status', array( 'refresh' => 1 ) );
check( '... with refresh it does, and first forgets what WordPress remembered (else the answer can be 12 hours old)', array( 'forget', 'check' ) === $GLOBALS['W']['calls'], $GLOBALS['W']['calls'] );
$r = wpsu_plugin_rows( array( 'a/a.php' => array( 'Name' => 'A', 'Version' => '1.0' ) ), array( 'a/a.php' => array( 'new_version' => '1.1', 'package' => 'z', 'requires' => '7.0' ) ), array(), '8.1', '6.8.1' );
check( 'needs a newer WordPress: blocked', false !== strpos( $r[0]['blocked'], 'needs WordPress 7.0' ) );

echo "--- update: what it refuses\n";
$GLOBALS['W']['calls'] = array();
$r = call( '/update', array( 'type' => 'server', 'item' => 'x' ) );
check( 'an unknown type', is_wp_error( $r ) && 'wpsu_type' === $r->code );
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'nope/nope.php' ) );
check( 'a plugin that is not installed', is_wp_error( $r ) && 'wpsu_unknown' === $r->code );
$r = call( '/update', array( 'type' => 'plugin', 'item' => array( 'akismet/akismet.php' ) ) );
check( 'an item that is not a text', is_wp_error( $r ) && 'wpsu_unknown' === $r->code );
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'pro/pro.php' ) );
check( 'a blocked plugin (no download)', is_wp_error( $r ) && 'wpsu_blocked' === $r->code && '2.0.0' === $GLOBALS['W']['plugins']['pro/pro.php']['Version'] );
$r = call( '/update', array( 'type' => 'core', 'item' => '7.0' ) );
check( 'a WordPress version that is not on offer', is_wp_error( $r ) && 'wpsu_core_version' === $r->code && '6.8.1' === $GLOBALS['W']['wp'] );
$r = call( '/update', array( 'type' => 'core' ) );
check( 'WordPress without naming the version', is_wp_error( $r ) && 'wpsu_core_version' === $r->code );
$GLOBALS['W']['file_mods'] = false;
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'akismet/akismet.php' ) );
check( 'everything, on a site with file changes switched off', is_wp_error( $r ) && 'wpsu_site_blocked' === $r->code );
$GLOBALS['W']['file_mods'] = true;
check( 'none of the refusals ran an updater or cleared a cache', ! array_filter( $GLOBALS['W']['calls'], function ( $c ) { return 'check' !== $c; } ) && 0 === $GLOBALS['W']['cleared'], $GLOBALS['W']['calls'] );

echo "--- update: dry run and the real thing\n";
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'akismet/akismet.php', 'dry_run' => true ) );
check( 'dry run: says from and to, changes nothing', true === $r['dry_run'] && false === $r['done'] && '5.3' === $r['from'] && '5.3.1' === $r['to'] && '5.3' === $GLOBALS['W']['plugins']['akismet/akismet.php']['Version'] && 0 === $GLOBALS['W']['cleared'], $r );
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'akismet/akismet.php' ) );
check( 'plugin: updated, the version is read back', true === $r['ok'] && true === $r['done'] && '5.3.1' === $r['now'] && '' === $r['error'], $r );
check( '... only that plugin was touched, and the update itself does not wipe the update memory', array( 'plugin:akismet/akismet.php' ) === array_values( array_filter( $GLOBALS['W']['calls'], function ( $c ) { return 'check' !== $c; } ) ) );
check( '... Divi CSS and the object cache were cleared, and it says so', 1 === $GLOBALS['W']['cleared'] && 1 === $GLOBALS['W']['flushed'] && in_array( 'Divi static CSS', $r['caches_cleared'], true ) );
check( '... messages come without HTML', 'Updating akismet/akismet.php' === $r['messages'][0], $r['messages'] );
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'akismet/akismet.php' ) );
check( 'the same again: already up to date, nothing runs', true === $r['ok'] && false === $r['done'] && 'already up to date' === $r['note'] && 1 === $GLOBALS['W']['cleared'] );
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'breaks/breaks.php' ) );
check( 'an updater error is reported as a failure, with its message', false === $r['ok'] && false === $r['done'] && 'Could not copy file.' === $r['error'], $r );
check( '... and the caches are cleared all the same', 2 === $GLOBALS['W']['cleared'] );
$r = call( '/update', array( 'type' => 'plugin', 'item' => 'liar/liar.php' ) );
check( 'an updater that says "fine" but left the old version: a failure', false === $r['ok'] && false !== strpos( $r['error'], 'installed version is 1.0, not 1.1' ), $r );
$r = call( '/update', array( 'type' => 'theme', 'item' => 'Divi' ) );
check( 'theme: updated', true === $r['done'] && '5.14.0' === $r['now'] && '5.14.0' === $GLOBALS['W']['themes']['Divi']['Version'], $r );
$r = call( '/update', array( 'type' => 'core', 'item' => '6.8.3' ) );
check( 'WordPress: goes to the version named, not to the newest', true === $r['done'] && '6.8.3' === $r['now'] && '6.8.3' === $GLOBALS['W']['wp'] && in_array( 'core:6.8.3', $GLOBALS['W']['calls'], true ) && ! in_array( 'core:6.9', $GLOBALS['W']['calls'], true ), $r );
$r = call( '/update', array( 'type' => 'translations' ) );
check( 'translations: updated', true === $r['done'] && 0 === $GLOBALS['W']['langs'] );
$r = call( '/update', array( 'type' => 'translations' ) );
check( '... and then there is nothing to do', false === $r['done'] && 'already up to date' === $r['note'] );
$before = $GLOBALS['W']['cleared'];
$r = call( '/clear-caches' );
check( 'clear-caches on its own', true === $r['ok'] && $before + 1 === $GLOBALS['W']['cleared'] && in_array( 'Divi static CSS', $r['caches_cleared'], true ) );

@rmdir( $tmp );
echo "\n" . ( $failed ? "$failed of $n FAILED" : "all $n checks passed" ) . "\n";
exit( $failed ? 1 : 0 );
